<?php

/**
 * Plugin Name: Innerplay - Inviti Manager
 * Description: Gestisce inviti ai giochi e al tool scrivania con limite per Welcome, token univoci e invio email via wp_mail() (FluentSMTP/Elastic).
 * Version: 1.0
 * Author: Emiliano Pallini
 */

if (!defined('ABSPATH')) exit;

/**
 * Funzione AJAX per inviare inviti al Tool Scrivania.
 *
 * - Riceve: email dei contatti, data e orario della sessione
 * - Genera un token univoco per ogni invito
 * - Salva ogni invito nella tabella `wp_scrivania_invitati`
 * - Invia un'email al contatto con link e dettagli (data e ora)
 *
 * Chiamata da JavaScript con `action: 'attiva_scrivania'`
 * dalla modale dedicata nel frontend della dashboard utente.
 */
add_action('wp_ajax_attiva_scrivania', 'gim_attiva_scrivania');
function gim_attiva_scrivania()
{
    if (!is_user_logged_in()) {
        wp_send_json_error('Utente non loggato.');
    }

    $user_id = get_current_user_id();
    $emails = $_POST['email_destinatario'] ?? [];
    $data = sanitize_text_field($_POST['data_invito'] ?? '');
    $ora = sanitize_text_field($_POST['ora_invito'] ?? '');

    if (!is_array($emails) || !$data || !$ora) {
        echo '<div style="color:red;">Dati mancanti o non validi.</div>';
        wp_die();
    }

    $emails = array_filter(array_map('sanitize_email', $emails));
    if (empty($emails)) {
        echo '<div style="color:red;">Nessun contatto valido.</div>';
        wp_die();
    }

    // Verifica limiti abbonamento
    if (function_exists('pmpro_getMembershipLevelForUser')) {
        $membership = pmpro_getMembershipLevelForUser($user_id);

        if ($membership && strtolower($membership->name) === 'welcome') {
            global $wpdb;
            $table_sessioni = $wpdb->prefix . 'scrivania_sessioni';
            $sessioni = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_sessioni WHERE creatore_id = %d",
                $user_id
            ));

            if ($sessioni >= 1) {
                echo '<div style="color:red;">Gli utenti Welcome possono creare massimo 1 sessione. Fai upgrade del tuo piano per creare più sessioni.</div>';
                wp_die();
            }
        }
    }

    // Crea o recupera una sessione
    $session_id = gim_create_or_get_session($user_id);
    if (!$session_id) {
        echo '<div style="color:red;">Errore nella creazione della sessione.</div>';
        wp_die();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'scrivania_invitati';
    $sent = 0;

    foreach ($emails as $email) {
        $token = wp_generate_password(16, false);

        $wpdb->insert($table, [
            'sessione_id' => $session_id, // Aggiungi il riferimento alla sessione
            'invitante_id' => $user_id,
            'invitato_email' => $email,
            'data_invito' => $data,
            'ora_invito' => $ora,
            'token' => $token
        ]);

        $link = home_url('/invito-scrivania/?token=' . $token);
        $subject = 'Invito al Tool Scrivania';
        $body = '
            <p>Hai ricevuto un invito al Tool Scrivania!</p>
            <p><strong>Quando:</strong> ' . date_i18n('d/m/Y', strtotime($data)) . ' alle ' . esc_html($ora) . '</p>
            <p><a href="' . esc_url($link) . '">Clicca qui per partecipare</a></p>
        ';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($email, $subject, $body, $headers);
        $sent++;
    }

    echo "<div style='color:green;'>Inviti inviati: $sent</div>";
    wp_die();
}

/**
 * Crea o recupera una sessione esistente per l'utente
 */
function gim_create_or_get_session($user_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'scrivania_sessioni';

    // Verifica se esiste già una sessione
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE creatore_id = %d ORDER BY id DESC LIMIT 1",
        $user_id
    ));

    if ($session) {
        return $session->id;
    }

    // Crea una nuova sessione
    $token = wp_generate_password(24, false);
    $nome = 'Sessione di ' . get_userdata($user_id)->display_name;

    // Impostazioni iniziali
    $impostazioni = [
        'attiva' => false,
        'iniziata' => null,
        'mazzoId' => 0,
        'sfondo' => null
    ];

    $wpdb->insert($table, [
        'token' => $token,
        'creatore_id' => $user_id,
        'nome' => $nome,
        'impostazioni' => wp_json_encode($impostazioni),
        'creato_il' => current_time('mysql'),
        'modificato_il' => current_time('mysql')
    ]);

    return $wpdb->insert_id;
}

/**
 * Funzione AJAX per inviare inviti a un gioco.
 *
 * - Controlla il livello di abbonamento dell'utente.
 * - Per utenti Welcome, limita a un massimo di 3 giochi attivati.
 * - Salva ogni invito nella tabella `wp_giochi_invitati` con token univoco.
 * - Invia un'email all'invitato con link personalizzato contenente il token.
 * 
 * Chiamata tramite AJAX con `action: 'attiva_gioco'` dalla modale di invito.
 */
add_action('wp_ajax_attiva_gioco', 'gim_attiva_gioco');

function gim_attiva_gioco()
{
    if (!is_user_logged_in()) {
        wp_send_json_error('Non sei loggato.');
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;
    $gioco_id = intval($_POST['gioco_id']);
    $emails = $_POST['email_destinatario'] ?? [];
    
    if (!is_array($emails)) {
        $emails = explode(',', $emails);
    }

    $emails = array_filter(array_map('sanitize_email', $emails));

    if (empty($emails)) {
        echo '<div style="color:red;">Nessuna email valida inserita.</div>';
        wp_die();
    }

    global $wpdb;

    $membership = function_exists('pmpro_getMembershipLevelForUser')
        ? pmpro_getMembershipLevelForUser($user_id)
        : null;

    $table = $wpdb->prefix . 'giochi_invitati';

    // Limite per Welcome
    if ($membership && strtolower($membership->name) === 'welcome') {
        $attivi = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE invitante_id = %d",
            $user_id
        ));

        $max = 3 - intval($attivi);
        if ($max <= 0) {
            echo '<div style="color:red;">Hai già raggiunto il limite massimo di 3 giochi attivi.</div>';
            wp_die();
        }

        $emails = array_slice($emails, 0, $max);
    }

    $inserted = 0;
    foreach ($emails as $email) {
        $token = wp_generate_password(16, false);
        
        $wpdb->insert($table, [
            'gioco_id' => $gioco_id,
            'invitante_id' => $user_id,
            'invitato_email' => $email,
            'tipo_abbonamento' => $membership ? $membership->name : 'N/A',
            'token' => $token
        ]);

        // Prepara email
        $subject = 'Hai ricevuto un invito a un gioco!';
        $link = home_url('/invito-gioco/?token=' . $token);
        $body = 'Ciao! Hai ricevuto un invito a partecipare a un gioco. Clicca qui per accedere: <a href="' . esc_url($link) . '">' . esc_html($link) . '</a>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($email, $subject, $body, $headers);

        $inserted++;
    }

    echo "<div style='color:green;'>Inviti inviati: $inserted</div>";
    wp_die();
}


/**
 * Funzione AJAX per aggiungere un contatto alla rubrica personale dell'utente loggato.
 *
 * - Valida nome ed email ricevuti via POST.
 * - Verifica che l'email non sia già registrata nella rubrica dell'utente.
 * - Salva il contatto nella tabella `wp_contatti_utente`.
 * - Restituisce un messaggio HTML come feedback.
 *
 * Chiamata tramite AJAX con `action: 'aggiungi_contatto_utente'`.
 */
// Aggiunta contatto
add_action('wp_ajax_aggiungi_contatto_utente', 'cim_aggiungi_contatto_utente');

function cim_aggiungi_contatto_utente()
{
    if (!is_user_logged_in()) {
        wp_die('Non autorizzato');
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $nome = sanitize_text_field($_POST['nome']);
    $email = sanitize_email($_POST['email']);

    if (!is_email($email)) {
        echo '<div style="color:red;">Email non valida.</div>';
        wp_die();
    }

    $table = $wpdb->prefix . 'contatti_utente';

    // Verifica se esiste già
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE utente_id = %d AND email = %s",
        $user_id,
        $email
    ));

    if ($exists) {
        echo '<div style="color:orange;">Questo contatto è già presente.</div>';
        wp_die();
    }

    $wpdb->insert($table, [
        'utente_id' => $user_id,
        'nome' => $nome,
        'email' => $email
    ]);

    echo '<div style="color:green;">Contatto aggiunto correttamente.</div>';
    wp_die();
}


/**
 * Funzione AJAX per caricare i contatti dell'utente loggato.
 *
 * - Se chiamata senza parametro 'modal', restituisce l'elenco contatti in formato lista semplice.
 * - Se chiamata con `modal: true`, restituisce i contatti come checkbox per la selezione da modale.
 * - Utilizzata per popolare la rubrica nella dashboard e la lista nella modale d'invito.
 *
 * Chiamata tramite AJAX con `action: 'carica_contatti_utente'`.
 */
// Caricamento contatti (normale o per modale)
add_action('wp_ajax_carica_contatti_utente', 'cim_carica_contatti_utente');

function cim_carica_contatti_utente()
{
    if (!is_user_logged_in()) {
        wp_die();
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'contatti_utente';

    $contatti = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE utente_id = %d ORDER BY nome ASC",
        $user_id
    ));

    if (isset($_POST['modal'])) {
        // Vista per la modale (checkbox)
        if (!$contatti) {
            echo '<p>Nessun contatto trovato.</p>';
        } else {
            echo '<ul style="max-height:200px; overflow:auto; padding-left:0;">';
            foreach ($contatti as $c) {
                echo '<li style="list-style:none; margin-bottom:6px;">';
                echo '<label><input type="checkbox" name="contatto_modal_check[]" value="' . esc_attr($c->email) . '"> ';
                echo esc_html($c->nome) . ' (' . esc_html($c->email) . ')';
                echo '</label></li>';
            }
            echo '</ul>';
        }
    } else {
        // Vista rubrica
        if (!$contatti) {
            echo '<p>La rubrica è vuota.</p>';
        } else {
            echo '<ul>';
            foreach ($contatti as $c) {
                echo '<li><strong>' . esc_html($c->nome) . '</strong> &lt;' . esc_html($c->email) . '&gt;</li>';
            }
            echo '</ul>';
        }
    }

    wp_die();
}
