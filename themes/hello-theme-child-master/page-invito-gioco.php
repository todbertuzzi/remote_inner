<?php


/* Template Name: Invito Tool Gioco */

get_header();

$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

if (!$token) {
    echo '<div class="site-main"><div class="container"><h2>Token mancante</h2></div></div>';
    get_footer();
    exit;
}

// Recupera l'invito dal database
global $wpdb;
$table = $wpdb->prefix . 'giochi_invitati';
$invito = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token = %s", $token));

if (!$invito) {
    echo '<div class="site-main"><div class="container"><h2>Invito non trovato</h2></div></div>';
    get_footer();
    exit;
}

// Se il form di registrazione è stato inviato manualmente
/**
 * Gestione della registrazione manuale di un utente invitato.
 * 
 * - Viene attivata solo se l'utente NON è loggato e il form 'custom_register' è stato inviato.
 * - Crea un utente con ruolo 'invitato' tramite wp_insert_user.
 * - Genera una password casuale per l'accesso futuro.
 * - Esegue login automatico e redirect alla stessa pagina.
 * - Invia una email di conferma con la password generata per accedere ad altri inviti.
 */
if (!is_user_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['custom_register'])) {
    $username = sanitize_user($_POST['user_login']);
    $email = sanitize_email($_POST['user_email']);
    $password = sanitize_text_field($_POST['user_pass']);

    $errors = new WP_Error();
    if (username_exists($username)) {
        $errors->add('username', 'Questo nome utente esiste gi\u00e0.');
    }
    if (email_exists($email)) {
        $errors->add('email', 'Questa email \u00e8 gi\u00e0 registrata.');
    }

    if (empty($errors->errors)) {
        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'role' => 'invitato'
        ]);

        if (!is_wp_error($user_id)) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            // Email non necessaria: l'utente ha scelto la sua password, nessun invio automatico.

wp_redirect(add_query_arg(null, null));
            exit;
        } else {
            $errors->add('registrazione', 'Errore nella creazione dell\'utente.');
        }
    }
}

// Se l'utente non è loggato, mostra login e registrazione personalizzata
if (!is_user_logged_in()) {
    echo '<div class="site-main"><div class="container" style="max-width:600px; margin:0 auto; padding:2rem;">';
    echo '<h2>Accedi o registrati per partecipare alla sessione</h2>';

    echo '<div style="margin-bottom: 2rem;">';
    wp_login_form([ 'redirect' => esc_url(add_query_arg(null, null)) ]);
    echo '</div>';

    echo '<div style="border-top:1px solid #ccc; padding-top:2rem;">';
    echo '<h3>Non hai un account?</h3>';
    if (!empty($errors) && is_wp_error($errors)) {
        foreach ($errors->get_error_messages() as $msg) {
            echo '<p style="color:red;">' . esc_html($msg) . '</p>';
        }
    }
    echo '<form method="post">';
    echo '<p><label for="user_login">Nome utente</label><br><input type="text" name="user_login" required></p>';
    echo '<p><label for="user_email">Email</label><br><input type="email" name="user_email" value="' . esc_attr($invito->invitato_email) . '" required></p>';
    echo '<input type="hidden" name="custom_register" value="1">';
    echo '<p><label for="user_pass">Scegli una password</label><br><input type="password" name="user_pass" required></p>';
echo '<p><input type="submit" value="Registrati"></p>';
    echo '</form>';
    echo '</div>';

    echo '</div></div>';
    get_footer();
    exit;
}

// Verifica che l'utente loggato sia l'invitato
$current_user = wp_get_current_user();
if (strtolower($current_user->user_email) !== strtolower($invito->invitato_email)) {
    echo '<div class="site-main"><div class="container"><h2>Non sei autorizzato a visualizzare questo invito.</h2></div></div>';
    get_footer();
    exit;
}

// Se l'invito non ha ancora un utente_id associato, salvalo ora
if (empty($invito->utente_id)) {
    $wpdb->update(
        $table,
        ['utente_id' => $current_user->ID],
        ['token' => $token]
    );
}



// Recupera l'ID del gioco associato all'invito
$gioco_id = intval($invito->gioco_id);

// Costruisci l'URL con il token (opzionale anche l'user_id se necessario)
$redirect_url = add_query_arg([
    'token' => $token
], get_permalink($gioco_id));

// Esegui il redirect
wp_redirect($redirect_url);
exit;


get_footer();


