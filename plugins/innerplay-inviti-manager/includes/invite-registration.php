<?php
/** Account senza piano per i destinatari di inviti a giochi e Scrivania. */
if (!defined('ABSPATH')) exit;

function gim_register_invited_role() {
    if (!get_role('invitato')) {
        add_role('invitato', 'Utente Invitato', array('read' => true));
    }
}
add_action('init', 'gim_register_invited_role');

function gim_invited_users_view($views) {
    $count = count_users()['avail_roles']['invitato'] ?? 0;
    $url = add_query_arg('role', 'invitato', admin_url('users.php'));
    $views['invitato'] = '<a href="' . esc_url($url) . '">Utenti Invitati <span class="count">(' . intval($count) . ')</span></a>';
    return $views;
}
add_filter('views_users', 'gim_invited_users_view');

/** Il piano resta autorevole; non alterare ruoli aggiuntivi o amministrativi. */
function gim_sync_invited_membership_role($level_id, $user_id) {
    $user = get_userdata(intval($user_id));
    if (!$user || !function_exists('pmpro_getMembershipLevelsForUser')) return;
    $was_invited = in_array('invitato', (array) $user->roles, true);
    if (!$was_invited && !get_user_meta($user_id, '_gim_registered_via_invite', true)) return;

    $levels = pmpro_getMembershipLevelsForUser($user_id, false);
    if (!is_array($levels)) return;
    if (!empty($levels) && $was_invited) {
        update_user_meta($user_id, '_gim_registered_via_invite', 1);
        $user->add_role('subscriber');
        $user->remove_role('invitato');
    } elseif (empty($levels) && (array) $user->roles === array('subscriber')) {
        gim_register_invited_role();
        $user->add_role('invitato');
        $user->remove_role('subscriber');
    }
}
add_action('pmpro_after_change_membership_level', 'gim_sync_invited_membership_role', 20, 2);

function gim_invite_registration_nonce_action($kind, $token) {
    return 'gim_register_' . $kind . '_' . hash('sha256', $token);
}

function gim_scrivania_invite_matches_user($invite, $user) {
    if (!$user || intval($user->ID) <= 0) return false;
    $bound_id = intval($invite->invitato_user_id ?? 0);
    return $bound_id > 0 ? $bound_id === intval($user->ID)
        : strcasecmp((string) $invite->invitato_email, (string) $user->user_email) === 0;
}

/** Rilegge sempre l'invito dal database; nessun dato di identità proviene dal form. */
function gim_get_invite_registration_context($kind, $token) {
    global $wpdb;
    if (!is_string($token) || $token === '' || strlen($token) > 191 || !in_array($kind, array('game', 'scrivania'), true)) {
        return new WP_Error('invalid_invite', 'Link di invito non valido.', array('status' => 400));
    }
    $table = $wpdb->prefix . ($kind === 'game' ? 'game_sessions' : 'scrivania_invitati');
    $column = $kind === 'game' ? 'invito_uuid' : 'token';
    $invite = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$column} = %s", $token));
    if (!$invite) return new WP_Error('invite_not_found', 'Invito non trovato.', array('status' => 404));
    $status = strtolower(trim((string) ($invite->status ?? '')));
    if (!empty($invite->revoked_at) || in_array($status, array('revoked', 'cancelled', 'canceled', 'admin_preview', 'member_preview'), true)) {
        return new WP_Error('invite_unavailable', 'Questo invito non è disponibile.', array('status' => 403));
    }
    if ($status === 'expired' || (!empty($invite->expires_at) && current_time('timestamp') > strtotime($invite->expires_at))) {
        return new WP_Error('invite_expired', 'Questo invito è scaduto. Chiedi un nuovo invito a chi ti ha invitato.', array('status' => 410));
    }
    if ($kind === 'game') {
        if (get_post_type($invite->gioco_id) !== 'gioco' || get_post_status($invite->gioco_id) !== 'publish') {
            return new WP_Error('game_unavailable', 'Il gioco non è disponibile.', array('status' => 404));
        }
        $email = (string) ($invite->invited_email ?? '');
        $bound_user_id = intval($invite->invited_user_id ?? 0);
        $url = add_query_arg('invito', $token, home_url('/gioca/'));
    } else {
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scrivania_sessioni WHERE id = %d", intval($invite->sessione_id)));
        $settings = $session ? json_decode((string) ($session->impostazioni ?? '{}'), true) : array();
        if (!$session || !empty($settings['archived'])) {
            return new WP_Error('session_unavailable', 'La sessione Scrivania non è disponibile.', array('status' => 403));
        }
        $email = (string) ($invite->invitato_email ?? '');
        $bound_user_id = intval($invite->invitato_user_id ?? 0);
        $url = add_query_arg('token', $token, home_url('/invito-scrivania/'));
    }
    if (!is_email($email)) return new WP_Error('invalid_recipient', 'L’invito non ha un destinatario valido.', array('status' => 403));
    return array('kind' => $kind, 'token' => $token, 'email' => $email, 'bound_user_id' => $bound_user_id, 'invite' => $invite, 'url' => $url);
}

/** Crea esclusivamente un nuovo account invitato. Non modifica account esistenti. */
function gim_register_invited_user($kind, $token, $input) {
    if (is_user_logged_in()) return new WP_Error('already_logged_in', 'Hai già effettuato l’accesso.', array('status' => 403));
    $nonce = isset($input['gim_invite_nonce']) && is_string($input['gim_invite_nonce']) ? wp_unslash($input['gim_invite_nonce']) : '';
    if (!wp_verify_nonce($nonce, gim_invite_registration_nonce_action($kind, $token))) {
        return new WP_Error('invalid_nonce', 'Il modulo è scaduto. Ricarica la pagina e riprova.', array('status' => 403));
    }
    $context = gim_get_invite_registration_context($kind, $token);
    if (is_wp_error($context)) return $context;
    if ($kind === 'scrivania' && empty($context['invite']->verified_at)) {
        return new WP_Error('invite_not_verified', 'Conferma prima il tuo invito.', array('status' => 403));
    }
    if ($context['bound_user_id'] > 0 || email_exists($context['email'])) {
        return new WP_Error('account_exists', 'Questo invito è associato a un account esistente. Accedi per continuare.', array('status' => 409));
    }
    $username = isset($input['user_login']) && is_string($input['user_login']) ? trim(wp_unslash($input['user_login'])) : '';
    // Le password non vanno passate a sanitize_text_field: altererebbe i caratteri scelti.
    $password = isset($input['user_pass']) && is_string($input['user_pass']) ? wp_unslash($input['user_pass']) : '';
    if ($username === '' || strlen($username) > 60 || !validate_username($username)) {
        return new WP_Error('invalid_username', 'Inserisci un nome utente valido, di massimo 60 caratteri.', array('status' => 400));
    }
    if (username_exists($username)) return new WP_Error('username_exists', 'Questo nome utente è già utilizzato. Scegline un altro.', array('status' => 409));
    if (strlen($password) < 8 || strlen($password) > 4096) {
        return new WP_Error('invalid_password', 'Scegli una password di almeno 8 caratteri.', array('status' => 400));
    }
    gim_register_invited_role();
    return wp_insert_user(array(
        'user_login' => $username,
        'user_email' => $context['email'],
        'user_pass' => $password,
        'role' => 'invitato',
        'meta_input' => array('_gim_registered_via_invite' => 1),
    ));
}

function gim_invite_nocache() {
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers();
}

function gim_render_invite_error($error) {
    gim_invite_nocache();
    $data = $error->get_error_data();
    status_header(is_array($data) && !empty($data['status']) ? intval($data['status']) : 403);
    get_header();
    echo '<main class="site-main"><div style="max-width:700px;margin:40px auto;padding:24px"><h1>Accesso all’invito</h1><p>' . esc_html($error->get_error_message()) . '</p></div></main>';
    get_footer();
    exit;
}

/** UI condivisa: login per account esistenti, registrazione senza piano per i nuovi. */
function gim_require_invited_account($kind, $token) {
    gim_invite_nocache();
    $context = gim_get_invite_registration_context($kind, $token);
    if (is_wp_error($context)) gim_render_invite_error($context);
    if (is_user_logged_in()) return $context;

    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['gim_invite_register'])) {
        $result = gim_register_invited_user($kind, $token, $_POST);
        if (is_wp_error($result)) {
            $error = $result;
        } else {
            wp_set_current_user($result);
            wp_set_auth_cookie($result);
            do_action('wp_login', get_userdata($result)->user_login, get_userdata($result));
            wp_safe_redirect($context['url']);
            exit;
        }
    }
    $has_account = $context['bound_user_id'] > 0 || email_exists($context['email']);
    if ($error) {
        $data = $error->get_error_data();
        status_header(is_array($data) && !empty($data['status']) ? intval($data['status']) : 400);
    }
    wp_enqueue_style('gim-invite-registration', plugins_url('assets/invite-registration.css', GIM_PLUGIN_FILE), array(), (string) filemtime(dirname(__DIR__) . '/assets/invite-registration.css'));
    get_header();
    ?>
    <main class="site-main gim-invite-account">
        <section class="gim-invite-account-card" aria-labelledby="gim-invite-title">
            <p class="gim-invite-eyebrow">Hai ricevuto un invito</p>
            <h1 id="gim-invite-title"><?php echo $has_account ? 'Accedi per partecipare' : 'Crea il tuo account per partecipare'; ?></h1>
            <p><?php echo $kind === 'game' ? 'Accedi alla partita a cui sei stato invitato.' : 'Accedi alla sessione Scrivania a cui sei stato invitato.'; ?> <!-- Non serve scegliere un piano di abbonamento. --></p>
            <?php if ($error): ?><p class="gim-invite-account-error" role="alert"><?php echo esc_html($error->get_error_message()); ?></p><?php endif; ?>
            <?php if ($has_account): ?>
                <p>Usa l’account associato all’invito.</p>
                <?php wp_login_form(array('redirect' => $context['url'])); ?>
                <p><a href="<?php echo esc_url(wp_lostpassword_url($context['url'])); ?>">Hai dimenticato la password?</a></p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url($context['url']); ?>">
                    <?php wp_nonce_field(gim_invite_registration_nonce_action($kind, $token), 'gim_invite_nonce'); ?>
                    <input type="hidden" name="gim_invite_register" value="1">
                    <p><label for="gim-user-login">Nome utente</label><input id="gim-user-login" name="user_login" type="text" maxlength="60" autocomplete="username" required value="<?php echo esc_attr(isset($_POST['user_login']) && is_string($_POST['user_login']) ? wp_unslash($_POST['user_login']) : ''); ?>"></p>
                    <p><label for="gim-invited-email">Email dell’invito</label><input id="gim-invited-email" type="email" value="<?php echo esc_attr($context['email']); ?>" readonly aria-describedby="gim-email-note"></p>
                    <p id="gim-email-note" class="gim-invite-help">L’account verrà creato con l’indirizzo email a cui è stato inviato questo invito.</p>
                    <p><label for="gim-user-pass">Scegli una password</label><input id="gim-user-pass" name="user_pass" type="password" minlength="8" autocomplete="new-password" aria-describedby="gim-password-note" required></p>
                    <p id="gim-password-note" class="gim-invite-help">Almeno 8 caratteri.</p>
                    <button type="submit">Crea account e continua</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <?php
    get_footer();
    exit;
}

/** Recupera i link /login/?redirect_to=... già inviati, senza redirect esterni. */
function gim_redirect_invite_login() {
    if (!is_page('login') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !isset($_GET['redirect_to']) || !is_string($_GET['redirect_to'])) return;
    $target = wp_validate_redirect(wp_unslash($_GET['redirect_to']), '');
    if ($target === '') return;
    $host = wp_parse_url($target, PHP_URL_HOST);
    if ($host && strcasecmp($host, (string) wp_parse_url(home_url('/'), PHP_URL_HOST)) !== 0) return;
    $path = untrailingslashit((string) wp_parse_url($target, PHP_URL_PATH));
    parse_str((string) wp_parse_url($target, PHP_URL_QUERY), $query);
    foreach (array('gioca' => 'invito', 'invito-scrivania' => 'token') as $page => $param) {
        if ($path !== untrailingslashit((string) wp_parse_url(home_url('/' . $page . '/'), PHP_URL_PATH))) continue;
        if (empty($query[$param]) || !is_string($query[$param])) return;
        gim_invite_nocache();
        wp_safe_redirect(add_query_arg($param, $query[$param], home_url('/' . $page . '/')));
        exit;
    }
}
add_action('template_redirect', 'gim_redirect_invite_login', 1);
