<?php
/**
 * Template Name: Invito Tool Scrivania

 * - Riconosce l'invito tramite token (?token=...)
 * - Se l'utente non è loggato, mostra login e form di registrazione (con ruolo 'invitato')
 * - Verifica che l'email dell'utente loggato corrisponda all'invitato
 * - Mostra un messaggio se l'invito è scaduto o non ancora attivo
 * - Se l'orario è valido, carica il componente React per il Tool
 
 * Questo template gestisce la verifica dell'invito e il reindirizzamento a tool-scrivania
 */

// Evita che plugin/cache CDN servano una pagina con nonce utente "stale".
if (!defined('DONOTCACHEPAGE')) {
    define('DONOTCACHEPAGE', true);
}
if (function_exists('nocache_headers')) {
    nocache_headers();
}

get_header();

// Mantieni i redirect sullo stesso host della richiesta (evita mismatch cookie tra www/non-www).
function scrivania_invite_build_url_on_request_host($path_with_query) {
    $scheme = is_ssl() ? 'https' : 'http';
    $raw = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $raw = strtolower(preg_replace('/:\\d+$/', '', $raw));

    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $site_host = wp_parse_url(site_url('/'), PHP_URL_HOST);
    $allowed = array_filter(array_map('strtolower', array($home_host, $site_host)));
    $normalize = function ($h) {
        return preg_replace('/^www\\./', '', (string) $h);
    };

    $host = !empty($home_host) ? $home_host : $site_host;
    if (!empty($raw)) {
        if (in_array($raw, $allowed, true)) {
            $host = $raw;
        } else {
            $raw_n = $normalize($raw);
            foreach ($allowed as $h) {
                if ($raw_n === $normalize($h)) {
                    $host = $raw;
                    break;
                }
            }
        }
    }

    $path_with_query = '/' . ltrim((string) $path_with_query, '/');
    return $scheme . '://' . $host . $path_with_query;
}

$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

if (!$token) {
    echo '<div class="site-main"><div class="container"><h2>Token mancante</h2></div></div>';
    get_footer();
    exit;
}

// Recupera l'invito dal database
global $wpdb;
$table = $wpdb->prefix . 'scrivania_invitati';
$invito = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token = %s", $token));

$errors = new WP_Error();

if (!$invito) {
    echo '<div class="site-main"><div class="container"><h2>Invito non trovato</h2></div></div>';
    get_footer();
    exit;
}

// Revocato?
if (!empty($invito->revoked_at) || (!empty($invito->status) && $invito->status === 'revoked')) {
    echo '<div class="site-main"><div class="container"><h2>Invito revocato</h2><p>Questo invito non è più valido.</p></div></div>';
    get_footer();
    exit;
}

// Consumato?
if (!empty($invito->consumed_at) || (!empty($invito->status) && $invito->status === 'consumed')) {
    // Se l'utente non è loggato, chiedi login.
    if (!is_user_logged_in()) {
        echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
        echo '<h2>Invito già utilizzato</h2>';
        echo '<p>Questo link invito è stato già utilizzato. Accedi con l’account invitato per entrare nella scrivania.</p>';
        wp_login_form([ 'redirect' => esc_url(add_query_arg(null, null)) ]);
        echo '</div></div>';
        get_footer();
        exit;
    }

    // Se è loggato con l'email invitata, vai direttamente al tool.
    $current_user = wp_get_current_user();
    if (!$current_user || strtolower($current_user->user_email) !== strtolower($invito->invitato_email)) {
        $logout_url = wp_logout_url(add_query_arg(null, null));
        echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
        echo '<h2>Account errato</h2>';
        echo '<p>Sei loggato con un account diverso da quello invitato. Per continuare devi uscire e accedere con l’email invitata.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($logout_url) . '">Esci e continua</a></p>';
        echo '</div></div>';
        get_footer();
        exit;
    }

    // Redirect al tool con token sessione
    $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
    $session = $wpdb->get_row($wpdb->prepare("SELECT token FROM $table_sessions WHERE id = %d", intval($invito->sessione_id)));
    if ($session && !empty($session->token)) {
        wp_redirect(scrivania_invite_build_url_on_request_host('/tool-scrivania/?token=' . urlencode($session->token) . '&invite_token=' . urlencode($token)));
        exit;
    }

    echo '<div class="site-main"><div class="container"><h2>Sessione non trovata</h2></div></div>';
    get_footer();
    exit;
}

// STEP 1: Conferma email (per evitare prefetch e garantire azione esplicita)
if (empty($invito->verified_at)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scrivania_verify_invite'])) {
        check_admin_referer('scrivania_verify_invite_' . intval($invito->id));

        $updated = $wpdb->update(
            $table,
            [
                'verified_at' => current_time('mysql'),
                'status' => 'verified',
            ],
            ['id' => intval($invito->id)],
            ['%s', '%s'],
            ['%d']
        );

        // Ricarica pagina
        wp_redirect(add_query_arg(null, null));
        exit;
    }

    echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
    echo '<h2>Conferma la tua email</h2>';
    echo '<p>Per continuare devi confermare di avere accesso alla casella email invitata.</p>';
    echo '<p><strong>Email invitata:</strong> ' . esc_html($invito->invitato_email) . '</p>';
    echo '<form method="post" style="margin-top:1.5rem;">';
    wp_nonce_field('scrivania_verify_invite_' . intval($invito->id));
    echo '<input type="hidden" name="scrivania_verify_invite" value="1" />';
    echo '<button type="submit" class="button button-primary">Confermo</button>';
    echo '</form>';
    echo '</div></div>';
    get_footer();
    exit;
}

// Se il form di registrazione è stato inviato manualmente
if (!is_user_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['custom_register'])) {
    $username = sanitize_user($_POST['user_login']);
    // Email vincolata all'invito
    $email = sanitize_email($invito->invitato_email);
    $password = sanitize_text_field($_POST['user_pass']);

    $errors = new WP_Error();
    if (username_exists($username)) {
        $errors->add('username', 'Questo nome utente esiste già.');
    }
    if (email_exists($email)) {
        $errors->add('email', 'Questa email è già registrata.');
    }

    if (empty($errors->errors)) {
        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            // V1: ruolo WP standard, permessi scrivania gestiti per-stanza
            'role' => 'subscriber'
        ]);

        if (!is_wp_error($user_id)) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            wp_redirect(add_query_arg(null, null));
            exit;
        } else {
            $errors->add('registrazione', 'Errore nella creazione dell\'utente.');
        }
    }
}

// Se l'utente non è loggato, mostra login o registrazione in base all'esistenza account
if (!is_user_logged_in()) {
    echo '<div class="site-main"><div class="container" style="max-width:600px; margin:0 auto; padding:2rem;">';
    echo '<h2>Accedi per partecipare alla sessione</h2>';

    $email_exists = email_exists($invito->invitato_email);

    if ($email_exists) {
        echo '<div style="margin-bottom: 2rem;">';
        wp_login_form([ 'redirect' => esc_url(add_query_arg(null, null)) ]);
        echo '</div>';
        echo '<p style="margin-top:1rem;"><a href="' . esc_url(wp_lostpassword_url()) . '">Hai dimenticato la password?</a></p>';
    } else {
        echo '<p>Non risulta un account con questa email. Crea un account per continuare.</p>';
        echo '<div style="border-top:1px solid #ccc; padding-top:2rem;">';
        echo '<h3>Crea account</h3>';
    }

    if (!empty($errors) && is_wp_error($errors)) {
        foreach ($errors->get_error_messages() as $msg) {
            echo '<p style="color:red;">' . esc_html($msg) . '</p>';
        }
    }

    if (!$email_exists) {
        echo '<form method="post">';
        echo '<p><label for="user_login">Nome utente</label><br><input type="text" name="user_login" required></p>';
        echo '<p><label>Email</label><br><input type="email" value="' . esc_attr($invito->invitato_email) . '" readonly></p>';
        echo '<input type="hidden" name="custom_register" value="1">';
        echo '<p><label for="user_pass">Scegli una password</label><br><input type="password" name="user_pass" required></p>';
        echo '<p><input type="submit" value="Registrati"></p>';
        echo '</form>';
        echo '</div>';
    }

    echo '</div></div>';
    get_footer();
    exit;
}

// Verifica che l'utente loggato sia l'invitato (obbligo logout se account sbagliato)
$current_user = wp_get_current_user();
if (strtolower($current_user->user_email) !== strtolower($invito->invitato_email)) {
    $logout_url = wp_logout_url(add_query_arg(null, null));
    echo '<div class="site-main"><div class="container" style="max-width:700px; margin:0 auto; padding:2rem;">';
    echo '<h2>Account errato</h2>';
    echo '<p>Sei loggato con un account diverso da quello invitato. Per continuare devi uscire e accedere con l’email invitata.</p>';
    echo '<p><a class="button button-primary" href="' . esc_url($logout_url) . '">Esci e continua</a></p>';
    echo '</div></div>';
    get_footer();
    exit;
}

// Claim + consume (one-time): lega l'invito al user_id e consuma il token
$now = current_time('mysql');

// Compat schema: aggiorna solo colonne esistenti.
$invite_columns = $wpdb->get_col("DESC {$table}", 0);
if (!is_array($invite_columns)) {
    $invite_columns = array();
}

$set_parts = array();
$params = array();

if (in_array('invitato_user_id', $invite_columns, true)) {
    $set_parts[] = 'invitato_user_id = %d';
    $params[] = intval($current_user->ID);
}
if (in_array('claimed_at', $invite_columns, true)) {
    $set_parts[] = 'claimed_at = %s';
    $params[] = $now;
}
if (in_array('consumed_at', $invite_columns, true)) {
    $set_parts[] = 'consumed_at = %s';
    $params[] = $now;
}
if (in_array('status', $invite_columns, true)) {
    $set_parts[] = 'status = %s';
    $params[] = 'consumed';
}

// Difensivo: se c'è verified_at ma per qualche ragione è vuoto, valorizzalo qui.
if (in_array('verified_at', $invite_columns, true)) {
    $set_parts[] = 'verified_at = COALESCE(verified_at, %s)';
    $params[] = $now;
}

if (!empty($set_parts)) {
    $sql = "UPDATE {$table} SET " . implode(', ', $set_parts) . ' WHERE id = %d';
    $params[] = intval($invito->id);

    // Se esiste consumed_at nello schema, mantieni l'idempotenza one-time.
    if (in_array('consumed_at', $invite_columns, true)) {
        $sql .= ' AND consumed_at IS NULL';
    }

    $updated = $wpdb->query($wpdb->prepare($sql, ...$params));
}

// Recupera token sessione e reindirizza al tool
$table_sessions = $wpdb->prefix . 'scrivania_sessioni';
$session = $wpdb->get_row($wpdb->prepare("SELECT token FROM $table_sessions WHERE id = %d", intval($invito->sessione_id)));
if (!$session || empty($session->token)) {
    echo '<div class="site-main"><div class="container"><h2>Sessione non trovata</h2></div></div>';
    get_footer();
    exit;
}

$redirect_url = scrivania_invite_build_url_on_request_host('/tool-scrivania/?token=' . urlencode($session->token) . '&invite_token=' . urlencode($token));
wp_redirect($redirect_url);
exit;

// Non dovremmo mai arrivare qui, ma nel caso...
get_footer();
?>