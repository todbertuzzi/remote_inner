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

function game__user_can_access_session($session_id, $current_user)
{
    global $wpdb;
    $table_inviti_gioco = $wpdb->prefix . 'giochi_invitati';

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

function game_get_join_code(WP_REST_Request $request)
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
    if (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'expired'], 410);
    }

    $current_user = wp_get_current_user();
    $isHost = intval($session->host_user_id) === intval($current_user->ID);

    if (!$isHost && !game__user_can_access_session(intval($session->id), $current_user)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'forbidden'], 403);
    }

    if (!$isHost) {
        global $wpdb;
        $table_inviti_gioco = $wpdb->prefix . 'giochi_invitati';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_inviti_gioco}
             SET utente_id = %d
             WHERE session_id = %d AND utente_id IS NULL AND invitato_email = %s",
            $current_user->ID,
            $session->id,
            $current_user->user_email
        ));
    }

    // Arricchimento risposta
    return new WP_REST_Response([
        'status'      => 'ok',
        'joinCode'    => $session->join_code ?: null,
        'isGuest'     => !$isHost,
        'role'        => $isHost ? 'host' : 'guest',
        'sessionId'   => intval($session->id),
        'invito_uuid' => $invito_uuid,
        'user' => [
            'id'           => intval($current_user->ID),
            'username'     => $current_user->user_login,
            'display_name' => $current_user->display_name
        ],
        'hostUserId'  => intval($session->host_user_id)
    ], 200);
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
    if (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'expired'], 410);
    }
    $current_user = wp_get_current_user();
    $isHost = intval($session->host_user_id) === intval($current_user->ID);
    if (!$isHost && !game__user_can_access_session(intval($session->id), $current_user)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'forbidden'], 403);
    }
    return new WP_REST_Response([
        'status' => 'ok',
        'role' => $isHost ? 'host' : 'guest',
        'user' => [
            'id'           => intval($current_user->ID),
            'username'     => $current_user->user_login,
            'display_name' => $current_user->display_name,
            'email'        => $current_user->user_email
        ],
        'session' => [
            'id'         => intval($session->id),
            'gioco_id'   => intval($session->gioco_id),
            'hostUserId' => intval($session->host_user_id),
            'hasJoinCode' => !empty($session->join_code)
        ]
    ], 200);
}

function game__has_column($table, $column)
{
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        $table,
        $column
    ));
}

function game__ensure_sessions_schema()
{
    global $wpdb;
    $table = $wpdb->prefix . 'game_sessions';

    // join_code
    if (!game__has_column($table, 'join_code')) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN join_code VARCHAR(64) NULL DEFAULT NULL");
    }
    // status
    if (!game__has_column($table, 'status')) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'created'");
    }
    // expires_at
    if (!game__has_column($table, 'expires_at')) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN expires_at DATETIME NULL DEFAULT NULL, ADD KEY expires_at (expires_at)");
    }
}


function game_set_join_code(WP_REST_Request $request)
{
    if (!is_user_logged_in()) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'auth_required'], 401);
    }

    // Assicura lo schema prima dell'update
    game__ensure_sessions_schema();

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

    // Scadenza (se presente)
    if (!empty($session->expires_at) && current_time('timestamp') > strtotime($session->expires_at)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'expired'], 410);
    }

    // Aggiornamento dinamico (senza rompere se expires_at non esistesse)
    $data = ['join_code' => $joinCode, 'status' => 'started'];
    $format = ['%s', '%s'];

    if (game__has_column($table_sessions, 'expires_at')) {
        $data['expires_at'] = date('Y-m-d H:i:s', current_time('timestamp') + 2 * 3600);
        $format[] = '%s';
    }

    $updated = $wpdb->update(
        $table_sessions,
        $data,
        ['id' => intval($session->id)],
        $format,
        ['%d']
    );

    if ($updated === false) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[game_set_join_code] DB error: ' . $wpdb->last_error);
        }
        return new WP_REST_Response(['status' => 'error', 'message' => 'db_error'], 500);
    }

    return new WP_REST_Response(['status' => 'ok'], 200);
}


add_action('rest_api_init', function () {
    register_rest_route('game/v1', '/get-join-code', [
        'methods'  => 'GET',
        'callback' => 'game_get_join_code',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
    register_rest_route('game/v1', '/set-join-code', [
        'methods'  => 'POST',
        'callback' => 'game_set_join_code',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
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
