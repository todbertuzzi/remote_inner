<?php

/**
 * Plugin Name: Innerplay - API Giochi
 * Description: Gestisce gli endpoint REST per l'interazione tra giochi Unity e WordPress.
 * Version: 1.0
 * Author: Innerplay
 */

// Impedisce accesso diretto
if (!defined('ABSPATH')) exit;


function game__get_session_by_uuid($invito_uuid)
{
    global $wpdb;
    $table_sessions = $wpdb->prefix . 'game_sessions';
    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_sessions} WHERE invito_uuid = %s", $invito_uuid)
    );
}

function game__user_can_access_session($session_id, $current_user) {
    global $wpdb;
    $table_inviti_gioco = $wpdb->prefix . 'giochi_invitati';

    // Match per utente_id
    $byUserId = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_inviti_gioco} WHERE session_id = %d AND utente_id = %d",
        $session_id, $current_user->ID
    ));
    if (intval($byUserId) > 0) return true;

    // Fallback: match per email invitata
    $byEmail = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_inviti_gioco} WHERE session_id = %d AND invitato_email = %s",
        $session_id, $current_user->user_email
    ));
    return intval($byEmail) > 0;
}

function game_get_join_code(WP_REST_Request $request) {
    if (!is_user_logged_in()) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'auth_required'], 401);
    }

    $invito_uuid = sanitize_text_field($request->get_param('invito_uuid'));
    if (!$invito_uuid) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'missing_invito_uuid'], 400);
    }

    $session = game__get_session_by_uuid($invito_uuid);
    if (!$session) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'session_not_found'], 404);
    }
    // Scadenza
    if (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'expired'], 410);
    }

    $current_user = wp_get_current_user();
    $isHost = intval($session->host_user_id) === intval($current_user->ID);

    if (!$isHost && !game__user_can_access_session(intval($session->id), $current_user)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'forbidden'], 403);
    }

    // Bind utente_id alla prima visita se match via email (solo invitati)
    if (!$isHost) {
        global $wpdb;
        $table_inviti_gioco = $wpdb->prefix . 'giochi_invitati';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_inviti_gioco}
             SET utente_id = %d
             WHERE session_id = %d AND utente_id IS NULL AND invitato_email = %s",
            $current_user->ID, $session->id, $current_user->user_email
        ));
    }

    return new WP_REST_Response([
        'status'   => 'ok',
        'joinCode' => $session->join_code ?: null,
        'isGuest'  => !$isHost,
        'guestId'  => $current_user->ID
    ], 200);
}

function game_set_join_code(WP_REST_Request $request) {
    if (!is_user_logged_in()) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'auth_required'], 401);
    }

    $params = $request->get_json_params();
    $invito_uuid = isset($params['invito_uuid']) ? sanitize_text_field($params['invito_uuid']) : '';
    $joinCode    = isset($params['joinCode']) ? sanitize_text_field($params['joinCode']) : '';

    if (!$invito_uuid || !$joinCode) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'missing_params'], 400);
    }

    $session = game__get_session_by_uuid($invito_uuid);
    if (!$session) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'session_not_found'], 404);
    }

    $current_user = wp_get_current_user();
    if (intval($session->host_user_id) !== intval($current_user->ID)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'forbidden'], 403);
    }

    global $wpdb;
    $table_sessions = $wpdb->prefix . 'game_sessions';
   

    //
    if (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'expired'], 410);
    }
    // Aggiorna join_code e, opzionale, estendi finestra (+2h runtime)
    $new_exp = date('Y-m-d H:i:s', current_time('timestamp') + 2*3600);
    $updated = $wpdb->update(
        $table_sessions,
        ['join_code' => $joinCode, 'status' => 'started', 'expires_at' => $new_exp],
        ['id' => intval($session->id)],
        ['%s','%s','%s'],
        ['%d']
    );
    //

    if ($updated === false) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'db_error'], 500);
    }

    return new WP_REST_Response(['status' => 'ok'], 200);
}


add_action('rest_api_init', function () {
    register_rest_route('game/v1', '/get-join-code', [
        'methods'  => 'GET',
        'callback' => 'game_get_join_code',
        'permission_callback' => function () { return is_user_logged_in(); }
    ]);
    register_rest_route('game/v1', '/set-join-code', [
        'methods'  => 'POST',
        'callback' => 'game_set_join_code',
        'permission_callback' => function () { return is_user_logged_in(); }
    ]);
    
    // ⭐ AGGIUNGERE questo endpoint mancante
    /* register_rest_route('giochi/v1', '/valida-token', [
        'methods'  => 'POST',
        'callback' => 'innerplay_valida_token_callback',
        'permission_callback' => function () { return is_user_logged_in(); }
    ]); */
});