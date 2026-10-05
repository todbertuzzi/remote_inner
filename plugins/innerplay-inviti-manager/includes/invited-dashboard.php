<?php
/**
 * Dashboard degli inviti ricevuti per Scrivania e giochi.
 */

if (!defined('ABSPATH')) {
    exit;
}

function gim_invited_dashboard_table_exists($table_name) {
    static $cache = array();

    if (isset($cache[$table_name])) {
        return $cache[$table_name];
    }

    global $wpdb;
    $found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)));
    $cache[$table_name] = is_string($found_table) && $found_table === $table_name;
    return $cache[$table_name];
}

function gim_invited_dashboard_user($user_id = 0) {
    $user_id = $user_id > 0 ? intval($user_id) : get_current_user_id();
    $user = $user_id > 0 ? get_userdata($user_id) : false;

    return ($user instanceof WP_User && !empty($user->user_email)) ? $user : false;
}

function gim_invited_dashboard_format_datetime($value) {
    if (empty($value)) {
        return '';
    }

    $datetime = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        (string) $value,
        gim_get_wp_timezone()
    );
    if (!($datetime instanceof DateTimeImmutable)) {
        return '';
    }

    return $datetime->format('d/m/Y H:i');
}

function gim_invited_dashboard_status_label($status) {
    $labels = array(
        'active' => 'Attivo',
        'scheduled' => 'Programmato',
        'consumed' => 'Utilizzato',
        'expired' => 'Scaduto',
        'archived' => 'Concluso',
        'revoked' => 'Revocato',
    );

    return isset($labels[$status]) ? $labels[$status] : ucfirst((string) $status);
}

/**
 * Recupera l'ultimo invito Scrivania per sessione, compreso lo storico.
 */
function gim_get_received_scrivania_invites($user_id = 0, $limit = 100) {
    $user = gim_invited_dashboard_user($user_id);
    if (!$user) {
        return array();
    }

    global $wpdb;
    $table_invites = $wpdb->prefix . 'scrivania_invitati';
    $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
    if (!gim_invited_dashboard_table_exists($table_invites) || !gim_invited_dashboard_table_exists($table_sessions)) {
        return array();
    }

    $columns = $wpdb->get_col("DESC {$table_invites}", 0);
    if (!is_array($columns)) {
        return array();
    }

    $has_user_id = in_array('invitato_user_id', $columns, true);
    $limit = max(1, min(200, intval($limit)));
    $params = array();

    if ($has_user_id) {
        $identity_sql = '(invitato_user_id = %d OR ((invitato_user_id IS NULL OR invitato_user_id = 0) AND LOWER(invitato_email) = LOWER(%s)))';
        $params[] = intval($user->ID);
        $params[] = (string) $user->user_email;
    } else {
        $identity_sql = 'LOWER(invitato_email) = LOWER(%s)';
        $params[] = (string) $user->user_email;
    }

    $sql =
        "SELECT i.*,\n" .
        "  s.token AS session_token, s.nome AS session_name, s.impostazioni AS session_settings,\n" .
        "  s.creatore_id AS session_creator_id, s.creato_il AS session_created_at,\n" .
        "  s.modificato_il AS session_updated_at\n" .
        "FROM {$table_invites} i\n" .
        "INNER JOIN (\n" .
        "  SELECT sessione_id, MAX(id) AS latest_id\n" .
        "  FROM {$table_invites}\n" .
        "  WHERE {$identity_sql}\n" .
        "  GROUP BY sessione_id\n" .
        ") latest ON latest.latest_id = i.id\n" .
        "INNER JOIN {$table_sessions} s ON s.id = i.sessione_id\n" .
        "ORDER BY i.id DESC\n" .
        "LIMIT {$limit}";

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    if (!is_array($rows)) {
        return array();
    }

    $now = function_exists('current_datetime')
        ? current_datetime()->getTimestamp()
        : time();
    $invites = array();

    foreach ($rows as $row) {
        $raw_status = isset($row['status']) ? strtolower((string) $row['status']) : '';
        $is_revoked = !empty($row['revoked_at']) || $raw_status === 'revoked';
        $is_consumed = !empty($row['consumed_at']) || $raw_status === 'consumed';
        $settings = !empty($row['session_settings']) ? json_decode((string) $row['session_settings'], true) : array();
        $is_archived = is_array($settings) && !empty($settings['archived']);
        $scheduled_at = function_exists('gim_parse_scrivania_schedule')
            ? gim_parse_scrivania_schedule($row['data_invito'] ?? '', $row['ora_invito'] ?? '')
            : null;
        $is_scheduled = $scheduled_at instanceof DateTimeInterface && $scheduled_at->getTimestamp() > $now;

        if ($is_revoked) {
            $status = 'revoked';
        } elseif ($is_archived) {
            $status = 'archived';
        } elseif ($is_consumed) {
            $status = 'consumed';
        } elseif ($is_scheduled) {
            $status = 'scheduled';
        } else {
            $status = 'active';
        }

        $inviter_id = intval($row['invitante_id'] ?? $row['session_creator_id'] ?? 0);
        $inviter = $inviter_id > 0 ? get_userdata($inviter_id) : false;
        $invite_token = isset($row['token']) ? (string) $row['token'] : '';
        $session_token = isset($row['session_token']) ? (string) $row['session_token'] : '';
        $access_url = '';

        if (in_array($status, array('active', 'consumed'), true)) {
            if ($invite_token !== '') {
                $access_url = add_query_arg('token', $invite_token, home_url('/invito-scrivania/'));
            } elseif ($session_token !== '') {
                $access_url = add_query_arg('token', $session_token, home_url('/tool-scrivania/'));
            }
        }

        $when = '';
        if ($scheduled_at instanceof DateTimeInterface) {
            $when = function_exists('wp_date')
                ? wp_date('d/m/Y H:i', $scheduled_at->getTimestamp(), gim_get_wp_timezone())
                : date_i18n('d/m/Y H:i', $scheduled_at->getTimestamp());
        }

        $invites[] = array(
            'type' => 'scrivania',
            'id' => intval($row['id'] ?? 0),
            'title' => !empty($row['session_name']) ? (string) $row['session_name'] : 'Sessione Scrivania',
            'inviter_name' => $inviter instanceof WP_User ? (string) $inviter->display_name : '',
            'role' => !empty($row['role']) ? (string) $row['role'] : 'viewer',
            'status' => $status,
            'status_label' => gim_invited_dashboard_status_label($status),
            'when' => $when,
            'received_at' => gim_invited_dashboard_format_datetime($row['creato_il'] ?? ''),
            'expires_at' => '',
            'access_url' => $access_url,
            'can_access' => $access_url !== '',
        );
    }

    return $invites;
}

/**
 * Recupera le sessioni gioco destinate all'utente, compreso lo storico.
 */
function gim_get_received_game_invites($user_id = 0, $limit = 100) {
    $user = gim_invited_dashboard_user($user_id);
    if (!$user) {
        return array();
    }

    global $wpdb;
    $table_sessions = $wpdb->prefix . 'game_sessions';
    if (!gim_invited_dashboard_table_exists($table_sessions)) {
        return array();
    }

    $limit = max(1, min(200, intval($limit)));
    $sql =
        "SELECT gs.*, p.post_title AS game_post_title, u.display_name AS inviter_name\n" .
        "FROM {$table_sessions} gs\n" .
        "LEFT JOIN {$wpdb->posts} p ON p.ID = gs.gioco_id AND p.post_type = 'gioco'\n" .
        "LEFT JOIN {$wpdb->users} u ON u.ID = gs.host_user_id\n" .
        "WHERE gs.status NOT IN ('admin_preview', 'member_preview')\n" .
        "AND (gs.invited_user_id = %d\n" .
        "  OR ((gs.invited_user_id IS NULL OR gs.invited_user_id = 0) AND LOWER(gs.invited_email) = LOWER(%s)))\n" .
        "ORDER BY gs.id DESC\n" .
        "LIMIT {$limit}";

    $rows = $wpdb->get_results($wpdb->prepare($sql, intval($user->ID), (string) $user->user_email), ARRAY_A);
    if (!is_array($rows)) {
        return array();
    }

    $now = function_exists('current_datetime')
        ? current_datetime()->getTimestamp()
        : time();
    $invites = array();

    foreach ($rows as $row) {
        $raw_status = strtolower((string) ($row['status'] ?? ''));
        $expires_at = !empty($row['expires_at'])
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row['expires_at'], gim_get_wp_timezone())
            : false;
        $expires_timestamp = $expires_at instanceof DateTimeImmutable ? $expires_at->getTimestamp() : 0;

        if (in_array($raw_status, array('revoked', 'cancelled', 'canceled'), true)) {
            $status = 'revoked';
        } elseif ($raw_status === 'expired' || ($expires_timestamp > 0 && $expires_timestamp < $now)) {
            $status = 'expired';
        } elseif (
            in_array($raw_status, array('consumed', 'completed'), true)
            || !empty($row['claimed_at'])
            || !empty($row['first_access_at'])
        ) {
            $status = 'consumed';
        } else {
            $status = 'active';
        }

        $game_id = intval($row['gioco_id'] ?? 0);
        $title = '';
        if ($game_id > 0 && function_exists('get_field')) {
            $title = trim((string) get_field('titolo_gioco', $game_id));
        }
        if ($title === '') {
            $title = !empty($row['game_post_title']) ? (string) $row['game_post_title'] : ('Gioco #' . $game_id);
        }

        $uuid = isset($row['invito_uuid']) ? (string) $row['invito_uuid'] : '';
        $access_url = (in_array($status, array('active', 'consumed'), true) && $uuid !== '')
            ? add_query_arg('invito', $uuid, home_url('/gioca/'))
            : '';

        $invites[] = array(
            'type' => 'gioco',
            'id' => intval($row['id'] ?? 0),
            'title' => $title,
            'inviter_name' => isset($row['inviter_name']) ? (string) $row['inviter_name'] : '',
            'role' => '',
            'status' => $status,
            'status_label' => gim_invited_dashboard_status_label($status),
            'when' => '',
            'received_at' => gim_invited_dashboard_format_datetime($row['created_at'] ?? ''),
            'expires_at' => gim_invited_dashboard_format_datetime($row['expires_at'] ?? ''),
            'access_url' => $access_url,
            'can_access' => $access_url !== '',
        );
    }

    return $invites;
}

function gim_user_has_received_invites($user_id = 0) {
    $user_id = $user_id > 0 ? intval($user_id) : get_current_user_id();
    static $cache = array();

    if ($user_id <= 0) {
        return false;
    }
    if (!array_key_exists($user_id, $cache)) {
        $cache[$user_id] = !empty(gim_get_received_scrivania_invites($user_id, 1))
            || !empty(gim_get_received_game_invites($user_id, 1));
    }

    return $cache[$user_id];
}

function gim_invited_dashboard_role_label($role) {
    $labels = array(
        'viewer' => 'Visualizzatore',
        'editor' => 'Editor',
        'admin' => 'Amministratore',
    );

    return isset($labels[$role]) ? $labels[$role] : ucfirst((string) $role);
}

function gim_invited_dashboard_filter_by_availability($invites, $is_available) {
    return array_values(array_filter($invites, static function ($invite) use ($is_available) {
        $available = !empty($invite['can_access'])
            || (isset($invite['status']) && $invite['status'] === 'scheduled');

        return $available === $is_available;
    }));
}

function gim_render_invited_dashboard_section($title, $section_class, $invites, $button_label) {
    ob_start();
    ?>
    <section class="invited-dashboard-section <?php echo esc_attr($section_class); ?>">
        <div class="invited-dashboard-section-heading">
            <h2><?php echo esc_html($title); ?></h2>
            <span class="invited-dashboard-count"><?php echo intval(count($invites)); ?></span>
        </div>

        <?php if (empty($invites)) : ?>
            <p class="invited-dashboard-empty">Non hai ancora ricevuto inviti in questa sezione.</p>
        <?php else : ?>
            <div class="invited-dashboard-list">
                <?php foreach ($invites as $invite) : ?>
                    <article class="invited-dashboard-card invited-dashboard-card--<?php echo esc_attr($invite['status']); ?>">
                        <div class="invited-dashboard-card-main">
                            <div class="invited-dashboard-card-title-row">
                                <h3><?php echo esc_html($invite['title']); ?></h3>
                                <span class="invited-dashboard-status invited-dashboard-status--<?php echo esc_attr($invite['status']); ?>">
                                    <?php echo esc_html($invite['status_label']); ?>
                                </span>
                            </div>

                            <dl class="invited-dashboard-meta">
                                <?php if (!empty($invite['inviter_name'])) : ?>
                                    <div><dt>Invitato da</dt><dd><?php echo esc_html($invite['inviter_name']); ?></dd></div>
                                <?php endif; ?>
                                <?php if (!empty($invite['when'])) : ?>
                                    <div><dt>Quando</dt><dd><?php echo esc_html($invite['when']); ?></dd></div>
                                <?php endif; ?>
                                <?php if (!empty($invite['role'])) : ?>
                                    <div><dt>Ruolo</dt><dd><?php echo esc_html(gim_invited_dashboard_role_label($invite['role'])); ?></dd></div>
                                <?php endif; ?>
                                <?php if (!empty($invite['received_at'])) : ?>
                                    <div><dt>Ricevuto</dt><dd><?php echo esc_html($invite['received_at']); ?></dd></div>
                                <?php endif; ?>
                                <?php if (!empty($invite['expires_at'])) : ?>
                                    <div><dt>Scadenza</dt><dd><?php echo esc_html($invite['expires_at']); ?></dd></div>
                                <?php endif; ?>
                            </dl>
                        </div>

                        <div class="invited-dashboard-card-action">
                            <?php if (!empty($invite['can_access']) && !empty($invite['access_url'])) : ?>
                                <a class="invited-dashboard-button" href="<?php echo esc_url($invite['access_url']); ?>">
                                    <?php echo esc_html($button_label); ?>
                                </a>
                            <?php else : ?>
                                <span class="invited-dashboard-unavailable">Non disponibile</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

function gim_invited_dashboard_shortcode() {
    static $instance = 0;

    if (!is_user_logged_in()) {
        return '<p class="invited-dashboard-login-required"><a href="' . esc_url(wp_login_url(get_permalink())) . '">Accedi per vedere i tuoi inviti</a>.</p>';
    }

    gim_enqueue_invited_dashboard_styles();

    $user = wp_get_current_user();
    $scrivania_invites = gim_get_received_scrivania_invites($user->ID, 100);
    $game_invites = gim_get_received_game_invites($user->ID, 100);
    $active_scrivania = gim_invited_dashboard_filter_by_availability($scrivania_invites, true);
    $active_games = gim_invited_dashboard_filter_by_availability($game_invites, true);
    $history_scrivania = gim_invited_dashboard_filter_by_availability($scrivania_invites, false);
    $history_games = gim_invited_dashboard_filter_by_availability($game_invites, false);
    $active_count = count($active_scrivania) + count($active_games);
    $history_count = count($history_scrivania) + count($history_games);
    $instance++;
    $id_prefix = 'invited-dashboard-' . $instance;

    ob_start();
    ?>
    <div class="invited-dashboard">
        <header class="invited-dashboard-header">
            <p class="invited-dashboard-eyebrow">I miei inviti</p>
            <h1>Ciao, <?php echo esc_html($user->display_name); ?></h1>
            <p>Da qui puoi accedere alle sessioni e ai giochi a cui sei stato invitato.</p>
        </header>

        <div class="invited-dashboard-tabs" data-invited-dashboard-tabs>
            <div class="invited-dashboard-tablist" role="tablist" aria-label="Filtra gli inviti">
                <button
                    class="invited-dashboard-tab is-active"
                    type="button"
                    role="tab"
                    id="<?php echo esc_attr($id_prefix); ?>-tab-active"
                    aria-controls="<?php echo esc_attr($id_prefix); ?>-panel-active"
                    aria-selected="true"
                    tabindex="0"
                    data-invited-dashboard-tab="active"
                >
                    Attivi
                    <span class="invited-dashboard-tab-count"><?php echo intval($active_count); ?></span>
                </button>
                <button
                    class="invited-dashboard-tab"
                    type="button"
                    role="tab"
                    id="<?php echo esc_attr($id_prefix); ?>-tab-history"
                    aria-controls="<?php echo esc_attr($id_prefix); ?>-panel-history"
                    aria-selected="false"
                    tabindex="-1"
                    data-invited-dashboard-tab="history"
                >
                    Non più disponibili
                    <span class="invited-dashboard-tab-count"><?php echo intval($history_count); ?></span>
                </button>
            </div>

            <div
                class="invited-dashboard-tabpanel"
                id="<?php echo esc_attr($id_prefix); ?>-panel-active"
                role="tabpanel"
                aria-labelledby="<?php echo esc_attr($id_prefix); ?>-tab-active"
                data-invited-dashboard-panel="active"
            >
                <?php if ($active_count === 0) : ?>
                    <p class="invited-dashboard-empty">Non hai inviti attivi in questo momento.</p>
                <?php else : ?>
                    <?php if (!empty($active_scrivania)) : ?>
                        <?php echo gim_render_invited_dashboard_section('Tool Scrivania', 'invited-dashboard-section--scrivania', $active_scrivania, 'Accedi alla scrivania'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                    <?php if (!empty($active_games)) : ?>
                        <?php echo gim_render_invited_dashboard_section('Giochi', 'invited-dashboard-section--games', $active_games, 'Gioca'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div
                class="invited-dashboard-tabpanel"
                id="<?php echo esc_attr($id_prefix); ?>-panel-history"
                role="tabpanel"
                aria-labelledby="<?php echo esc_attr($id_prefix); ?>-tab-history"
                data-invited-dashboard-panel="history"
                hidden
            >
                <?php if ($history_count === 0) : ?>
                    <p class="invited-dashboard-empty">Non ci sono inviti non più disponibili.</p>
                <?php else : ?>
                    <?php if (!empty($history_scrivania)) : ?>
                        <?php echo gim_render_invited_dashboard_section('Tool Scrivania', 'invited-dashboard-section--scrivania', $history_scrivania, 'Apri la scrivania'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                    <?php if (!empty($history_games)) : ?>
                        <?php echo gim_render_invited_dashboard_section('Giochi', 'invited-dashboard-section--games', $history_games, 'Gioca ancora'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('innerplay_dashboard_invitato', 'gim_invited_dashboard_shortcode');

function gim_enqueue_invited_dashboard_styles() {
    $asset_path = plugin_dir_path(GIM_PLUGIN_FILE) . 'assets/invited-dashboard.css';
    $version = file_exists($asset_path) ? (string) filemtime($asset_path) : '1.0.0';

    wp_enqueue_style(
        'innerplay-invited-dashboard',
        plugins_url('assets/invited-dashboard.css', GIM_PLUGIN_FILE),
        array(),
        $version
    );

    $script_path = plugin_dir_path(GIM_PLUGIN_FILE) . 'assets/invited-dashboard.js';
    $script_version = file_exists($script_path) ? (string) filemtime($script_path) : '1.0.0';
    wp_enqueue_script(
        'innerplay-invited-dashboard',
        plugins_url('assets/invited-dashboard.js', GIM_PLUGIN_FILE),
        array(),
        $script_version,
        true
    );
}

function gim_maybe_enqueue_invited_dashboard_styles() {
    if (is_page('dashboard-invitato')) {
        gim_enqueue_invited_dashboard_styles();
    }
}
add_action('wp_enqueue_scripts', 'gim_maybe_enqueue_invited_dashboard_styles', 20);

function gim_guard_invited_dashboard_page() {
    if (!is_page('dashboard-invitato')) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();

    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(get_permalink(get_queried_object_id())));
        exit;
    }
}
add_action('template_redirect', 'gim_guard_invited_dashboard_page', 0);

function gim_noindex_invited_dashboard($robots) {
    if (is_page('dashboard-invitato')) {
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
    }

    return $robots;
}
add_filter('wp_robots', 'gim_noindex_invited_dashboard');
