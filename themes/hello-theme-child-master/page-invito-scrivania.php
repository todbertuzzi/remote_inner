<?php
/**
 * Template personalizzato per gestire gli inviti al Tool Scrivania.
 *
 * - Riconosce l'invito tramite token (?token=...)
 * - Se l'utente non è loggato, mostra login e form di registrazione (con ruolo 'invitato')
 * - Verifica che l'email dell'utente loggato corrisponda all'invitato
 * - Mostra un messaggio se l'invito è scaduto o non ancora attivo
 * - Se l'orario è valido, carica il componente React per il Tool
 */
/* Template Name: Invito Tool Scrivania */

get_header();

$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

if (!$token) {
    echo '<div class="site-main"><div class="container"><h2>Token mancante</h2></div></div>';
    get_footer();
    exit;
}

// Recupera l'invito dal database
global $wpdb;
$table = $wpdb->prefix . 'scrivania_invitati';
$invito = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token = %s", $token));

if (!$invito) {
    echo '<div class="site-main"><div class="container"><h2>Invito non trovato</h2></div></div>';
    get_footer();
    exit;
}

// Se il form di registrazione è stato inviato manualmente
if (!is_user_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['custom_register'])) {
    $username = sanitize_user($_POST['user_login']);
    $email = sanitize_email($_POST['user_email']);
    $password = sanitize_text_field($_POST['user_pass']);

    $errors = new WP_Error();
    if (username_exists($username)) {
        $errors->add('username', 'Questo nome utente esiste già.');
    }
    if (email_exists($email)) {
        $errors->add('email', 'Questa email è già registrata.');
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

// Valori temporali
$data = $invito->data_invito;
$ora = $invito->ora_invito;
$timestamp_invito = strtotime("$data $ora");
$timestamp_corrente = current_time('timestamp');

// Layout principale
echo '<main class="site-main"><div class="container" style="max-width:800px; margin:0 auto; padding:2rem;">';

if ($timestamp_corrente > $timestamp_invito + 7200) {
    echo '<h2>Questo invito è scaduto.</h2>';
} elseif ($timestamp_corrente < $timestamp_invito) {
    $tempo_rimanente = $timestamp_invito - $timestamp_corrente;
    echo '<h2>La sessione inizierà il ' . date_i18n('d/m/Y', strtotime($data)) . ' alle ' . esc_html($ora) . '</h2>';
    echo '<p id="countdown"></p>';
    echo '<script>
    const end = new Date(' . ($timestamp_invito * 1000) . ');
    const el = document.getElementById("countdown");
    const interval = setInterval(() => {
        const now = new Date().getTime();
        const distance = end - now;
        if (distance <= 0) {
            clearInterval(interval);
            location.reload();
        } else {
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            el.innerHTML = `Inizia tra ${minutes}m ${seconds}s`;
        }
    }, 1000);
    </script>';
} else {
    // Container per l'app React, simile a quello nella pagina tool-scrivania
    echo '<div id="react-tool-root" 
             data-token="' . esc_attr($token) . '"
             data-user-id="' . esc_attr($current_user->ID) . '"
             data-user-name="' . esc_attr($current_user->display_name) . '">
         </div>';
    
    // Messaggio di caricamento
    echo '<div class="loading-message" style="text-align: center; padding: 50px;">
            <p>Caricamento del Tool Scrivania in corso...</p>
          </div>';
}

echo '</div></main>';

get_footer();