<?php
/* Template Name: Gioca */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(add_query_arg(null, null)));
    exit;
}

$invito_uuid = isset($_GET['invito']) ? sanitize_text_field($_GET['invito']) : '';
if (!$invito_uuid) {
    echo '<h2>Invito mancante</h2>';
    get_footer();
    exit;
}

// Verifica sessione e permessi
global $wpdb;
$session = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}game_sessions WHERE invito_uuid = %s", 
    $invito_uuid
));

if (!$session) {
    echo '<h2>Sessione non trovata</h2>';
    get_footer();
    exit;
}
if (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at)) {
    status_header(410);
    echo '<h2>Sessione scaduta</h2>';
    get_footer();
    exit;
}

$current_user = wp_get_current_user();
$can_access = function_exists('gim_game_user_can_access_session')
    ? gim_game_user_can_access_session($session, $current_user)
    : false;

if (!$can_access) {
    status_header(403);
    echo '<h2>Non autorizzato</h2>';
    get_footer();
    exit;
}

if (function_exists('gim_game_bind_invited_user')) {
    gim_game_bind_invited_user($session, $current_user);
}

// Redirect al gioco con parametri corretti
wp_redirect(add_query_arg([
    'invito_uuid' => $invito_uuid
], get_permalink($session->gioco_id)));
exit;