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

function game__user_can_access_session($session, $current_user)
{
    if (function_exists('gim_game_user_can_access_session')) {
        return gim_game_user_can_access_session($session, $current_user);
    }

    global $wpdb;
    $table_inviti_gioco = $wpdb->prefix . 'giochi_invitati';
    $session_id = is_object($session) ? intval($session->id) : intval($session);

    // Match per utente_id
    $byUserId = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_inviti_gioco} WHERE session_id = %d AND utente_id = %d",
        $session_id,
        $current_user->ID
    ));
    if (intval($byUserId) > 0) return true;

    // Fallback: match per email invitata
    $byEmail = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_inviti_gioco} WHERE session_id = %d AND invitato_email = %s",
        $session_id,
        $current_user->user_email
    ));
    return intval($byEmail) > 0;
}

function game__bind_invited_user($session, $current_user)
{
    if (function_exists('gim_game_bind_invited_user')) {
        return gim_game_bind_invited_user($session, $current_user);
    }

    return false;
}

function game_get_user_profile(WP_REST_Request $request)
{
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
    $current_user = wp_get_current_user();
    $access_result = function_exists('ipt_validate_game_session_access')
        ? ipt_validate_game_session_access($session, $current_user)
        : new WP_Error('access_control_unavailable', 'Controllo accessi non disponibile.', array('status' => 503));

    if (is_wp_error($access_result)) {
        $status = function_exists('ipt_access_error_status')
            ? ipt_access_error_status($access_result)
            : 403;
        return new WP_REST_Response([
            'status' => 'error',
            'message' => $access_result->get_error_code(),
        ], $status);
    }

    game__bind_invited_user($session, $current_user);

    return new WP_REST_Response([
        'status' => 'ok',
        'user' => [
            'id'           => intval($current_user->ID),
            'username'     => $current_user->user_login,
            'display_name' => $current_user->display_name,
            'email'        => $current_user->user_email
        ],
        'session' => [
            'id'         => intval($session->id),
            'gioco_id'   => intval($session->gioco_id),
            'invito_uuid' => sanitize_text_field($request->get_param('invito_uuid')),
            'expires_at' => $session->expires_at,
        ]
    ], 200);
}


add_action('rest_api_init', function () {
    register_rest_route('game/v1', '/user-profile', [
        'methods'  => 'GET',
        'callback' => 'game_get_user_profile',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);

    // ⭐ AGGIUNGERE questo endpoint mancante
    /* register_rest_route('giochi/v1', '/valida-token', [
        'methods'  => 'POST',
        'callback' => 'innerplay_valida_token_callback',
        'permission_callback' => function () { return is_user_logged_in(); }
    ]); */
});
