<?php

/**
 * Plugin Name: Innerplay - API Giochi
 * Description: Gestisce gli endpoint REST per l'interazione tra giochi Unity e WordPress.
 * Version: 1.0
 * Author: Innerplay
 */

// Impedisce accesso diretto
if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, 'innerplay_crea_tabella_accessi');
function innerplay_crea_tabella_accessi()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'giochi_accessi';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        utente_id BIGINT UNSIGNED NOT NULL,
        gioco_id BIGINT UNSIGNED NOT NULL,
        token VARCHAR(255) NOT NULL,
        accesso_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY utente_id (utente_id),
        KEY gioco_id (gioco_id),
        KEY token (token)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('rest_api_init', function () {
    register_rest_route('giochi/v1', '/valida-token', [
        'methods' => 'POST',
        'callback' => 'innerplay_valida_token_callback',
        'permission_callback' => function () {
            return current_user_can('read');
        },
    ]);
    register_rest_route('giochi/v1', '/auth', [
        'methods' => 'POST',
        'callback' => 'innerplay_auth_callback',
        'permission_callback' => function () {
            return current_user_can('read');
        },
    ]);

    register_rest_route('giochi/v1', '/salva-punteggio', [
        'methods' => 'POST',
        'callback' => 'innerplay_salva_punteggio_callback',
        'permission_callback' => function () {
            return current_user_can('read');
        },
    ]);
});

function innerplay_valida_token_callback($request)
{
    $params = $request->get_json_params();
    $token = sanitize_text_field($params['token'] ?? '');

    if (empty($token)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Token mancante.',
            'debug_token' => $token,
            'raw_json' => $params
        ], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'giochi_invitati';

    $invito = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE token = %s",
        $token
    ));

    if (!$invito) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Token non valido.'], 404);
    }



    // Protezione: il token deve appartenere all'utente loggato
    $utente_id_corrente = get_current_user_id();
    if ($utente_id_corrente !== intval($invito->invitante_id)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Token non autorizzato per questo utente.',
            'token_ricevuto' => $token,
            "utente_id_corrente" => $utente_id_corrente,
            "invitante_id" => $invito->invitante_id,
           
        ], 403);
    }

    $user = get_user_by('ID', $invito->invitante_id);
    $gioco_id = intval($invito->gioco_id);

    if (!$user || !$gioco_id) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Dati incompleti.'], 400);
    }

    // REGISTRA ACCESSO AL GIOCO
    $log_table = $wpdb->prefix . 'giochi_accessi';
    $wpdb->insert($log_table, [
        'utente_id' => $user->ID,
        'gioco_id' => $gioco_id,
        'token'     => $token,
        'accesso_at' => current_time('mysql', 1)
    ]);

    return new WP_REST_Response([
        'status' => 'success',
        'user_id' => $user->ID,
        'nome' => $user->display_name,
        'email' => $user->user_email,
        'gioco_id' => $gioco_id,
        'titolo_gioco' => get_the_title($gioco_id),
    ], 200);
}

function innerplay_auth_callback($request) {
    if (!is_user_logged_in()) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Utente non autenticato'
        ], 401);
    }

    $user = wp_get_current_user();
    return new WP_REST_Response([
        'status' => 'success',
        'user_id' => $user->ID,
        'nome' => $user->display_name,
        'email' => $user->user_email,
        'wp_nonce' => wp_create_nonce('wp_rest')
    ], 200);

    
}

function innerplay_salva_punteggio_callback($request) {
    $params = $request->get_json_params();

    $gioco_id = intval($params['gioco_id'] ?? 0);
    $punteggio = intval($params['punteggio'] ?? 0);
    $user_id = get_current_user_id();

    if (!$gioco_id || !$punteggio || !$user_id) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Dati mancanti o non validi.'
        ], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'giochi_punteggi';

    $wpdb->insert($table, [
        'utente_id' => $user_id,
        'gioco_id' => $gioco_id,
        'punteggio' => $punteggio,
        'inviato_at' => current_time('mysql', 1)
    ]);

    return new WP_REST_Response([
        'status' => 'success',
        'message' => 'Punteggio salvato.'
    ], 200);
}