<?php
/* Template Name: Gioca */

// Gestisce login e registrazione da invito senza scegliere un piano.
$invito_uuid = isset($_GET['invito']) && is_string($_GET['invito']) ? sanitize_text_field(wp_unslash($_GET['invito'])) : '';
if (!function_exists('gim_require_invited_account')) {
    wp_die('Registrazione invitati non disponibile. Aggiorna il plugin Inviti Manager.', 'Servizio non disponibile', array('response' => 503));
}
gim_require_invited_account('game', $invito_uuid);

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
    echo '<p><a href="' . esc_url(wp_logout_url(add_query_arg('invito', $invito_uuid, home_url('/gioca/')))) . '">Esci e accedi con l’account invitato</a></p>';
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
