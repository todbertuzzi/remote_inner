<?php
// api-proxy.php
require_once('wp-load.php');

// Verifica il token
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!wp_verify_nonce($token, 'unity_game_' . get_current_user_id())) {
    wp_send_json_error('Invalid token', 401);
}

// Restituisci i dati utente
$user = wp_get_current_user();
if ($user->ID) {
    wp_send_json_success([
        'id' => $user->ID,
        'username' => $user->user_login,
        'email' => $user->user_email,
        'display_name' => $user->display_name
    ]);
} else {
    wp_send_json_error('User not found', 404);
}