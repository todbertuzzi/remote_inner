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
function innerplay_crea_tabella_accessi() {
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
});

function innerplay_valida_token_callback($request) {
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
    if ($utente_id_corrente !== intval($invito->utente_id)) {
        return new WP_REST_Response([
            'status' => 'error', 
            'message' => 'Token non autorizzato per questo utente.',
            'token_ricevuto' => $token,
            'query_eseguita' => $wpdb->last_query
        ], 403);
    }

    $user = get_user_by('ID', $invito->utente_id);
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
