<?php
/* Template Name: Gioca */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(add_query_arg(null, null)));
    exit;
}

$invito_uuid = isset($_GET['invito']) ? sanitize_text_field($_GET['invito']) : '';
if (!$invito_uuid) {
    status_header(400);
    get_header();
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
    status_header(404);
    get_header();
    echo '<h2>Sessione non trovata</h2>';
    get_footer();
    exit;
}

$current_user = wp_get_current_user();
$access_result = function_exists('ipt_validate_game_session_access')
    ? ipt_validate_game_session_access($session, $current_user)
    : new WP_Error('access_control_unavailable', 'Controllo accessi non disponibile.', array('status' => 503));

if (is_wp_error($access_result)) {
    $status = function_exists('ipt_access_error_status')
        ? ipt_access_error_status($access_result)
        : 403;
    status_header($status);
    get_header();
    echo '<h2>' . esc_html($access_result->get_error_message()) . '</h2>';
    get_footer();
    exit;
}

if (function_exists('gim_game_bind_invited_user')) {
    gim_game_bind_invited_user($session, $current_user);
}

// Redirect al gioco con parametri corretti
wp_safe_redirect(add_query_arg([
    'invito_uuid' => $invito_uuid
], get_permalink($session->gioco_id)));
exit;
