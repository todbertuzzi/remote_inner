<?php

/**
 * Plugin Name: Innerplay - Inviti Manager
 * Description: Gestisce inviti ai giochi e al tool scrivania con limite per Welcome, token univoci e invio email via wp_mail() (FluentSMTP/Elastic).
 * Version: 1.0
 * Author: Emiliano Pallini
 */

if (!defined('ABSPATH')) exit;

// MIGRAZIONE: tabella sessioni di gioco + collegamento inviti
function gim_install_game_sessions_schema() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // 1) Crea/aggiorna tabella sessioni di gioco
    $table_sessions = $wpdb->prefix . 'game_sessions';
    $sql_sessions = "CREATE TABLE {$table_sessions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        host_user_id BIGINT UNSIGNED NOT NULL,
        gioco_id BIGINT UNSIGNED NOT NULL,
        invito_uuid CHAR(36) NOT NULL,
        join_code VARCHAR(64) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'created',
        created_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY invito_uuid (invito_uuid),
        KEY host_user_id (host_user_id),
        KEY gioco_id (gioco_id),
        KEY expires_at (expires_at)
    ) {$charset_collate};";
    dbDelta($sql_sessions);

    // 2) Adegua tabella inviti gioco (riuso)
    $table_inviti_gioco = $wpdb->prefix . 'giochi_invitati';

    // session_id
    $col_session = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'session_id'",
        $table_inviti_gioco
    ));
    if (!$col_session) {
        $wpdb->query("ALTER TABLE {$table_inviti_gioco} ADD COLUMN session_id BIGINT UNSIGNED NULL DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table_inviti_gioco} ADD KEY session_id (session_id)");
    }

    // utente_id (binding dell’invitato dopo il primo accesso)
    $col_user = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'utente_id'",
        $table_inviti_gioco
    ));
    if (!$col_user) {
        $wpdb->query("ALTER TABLE {$table_inviti_gioco} ADD COLUMN utente_id BIGINT UNSIGNED NULL DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table_inviti_gioco} ADD KEY utente_id (utente_id)");
    }

    // created_at
    $col_created = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'created_at'",
        $table_inviti_gioco
    ));
    if (!$col_created) {
        $wpdb->query("ALTER TABLE {$table_inviti_gioco} ADD COLUMN created_at DATETIME NULL DEFAULT NULL");
        $wpdb->query("UPDATE {$table_inviti_gioco} SET created_at = NOW() WHERE created_at IS NULL");
    }

    // indice su invitato_email (usato per binding)
    $has_email_idx = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'invitato_email'",
        $table_inviti_gioco
    ));
    if (intval($has_email_idx) === 0) {
        $wpdb->query("ALTER TABLE {$table_inviti_gioco} ADD KEY invitato_email (invitato_email)");
    }

    // Deprecazione legacy "token": rendi nullable e rimuovi eventuale indice univoco
    $tokenCol = $wpdb->get_row($wpdb->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'token'",
        $table_inviti_gioco
    ));
    if ($tokenCol) {
        if ($tokenCol->IS_NULLABLE !== 'YES') {
            $wpdb->query("ALTER TABLE {$table_inviti_gioco} MODIFY COLUMN token VARCHAR(191) NULL DEFAULT NULL");
        }
        $tokenUniqueIdx = $wpdb->get_row($wpdb->prepare(
            "SELECT INDEX_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'token' AND NON_UNIQUE = 0
             LIMIT 1",
            $table_inviti_gioco
        ));
        if ($tokenUniqueIdx && !empty($tokenUniqueIdx->INDEX_NAME)) {
            $idx = esc_sql($tokenUniqueIdx->INDEX_NAME);
            $wpdb->query("ALTER TABLE {$table_inviti_gioco} DROP INDEX `{$idx}`");
        }
    }
}
register_activation_hook(__FILE__, 'gim_install_game_sessions_schema');



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
remove_action('wp_ajax_attiva_gioco', 'gim_attiva_gioco');
add_action('wp_ajax_attiva_gioco', 'gim_attiva_gioco');
function gim_attiva_scrivania() {
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

    global $wpdb;
    $table = $wpdb->prefix . 'scrivania_invitati';
    $sent = 0;

    foreach ($emails as $email) {
        $token = wp_generate_password(16, false);

        $wpdb->insert($table, [
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
add_action('wp_ajax_attiva_scrivania', 'gim_attiva_scrivania');
/**
 * Funzione AJAX per inviare inviti a un gioco (nuovo flusso basato su UUID).
 *
 * Input (POST):
 * - gioco_id: ID del post 'gioco'
 * - email_destinatario[]: elenco email dei contatti
 *
 * Logica:
 * - Richiede utente loggato.
 * - Valida il post 'gioco'.
 * - Sanifica e limita a massimo 3 email per sessione.
 * - Crea una sessione in wp_game_sessions con invito_uuid unico e stato 'created'.
 * - Collega gli invitati in wp_giochi_invitati impostando session_id (senza usare il token legacy).
 * - Invia email agli invitati con link: /gioca?invito={invito_uuid}.
 * - Risponde con HTML contenente esito e link host da condividere.
 *
 * Note:
 * - Riuso tabella inviti: wp_giochi_invitati (con colonna session_id).
 * - Il campo 'token' è deprecato e non più valorizzato.
 * - Il join_code viene impostato dall’host via REST: POST /wp-json/game/v1/set-join-code.
 */
add_action('wp_ajax_attiva_gioco', 'gim_attiva_gioco');

function gim_attiva_gioco() {
    if (!is_user_logged_in()) {
        echo '<div style="color:red;">Devi essere loggato.</div>';
        wp_die();
    }

    $gioco_id = isset($_POST['gioco_id']) ? intval($_POST['gioco_id']) : 0;
    $emails   = isset($_POST['email_destinatario']) ? (array) $_POST['email_destinatario'] : [];

    if ($gioco_id <= 0 || empty($emails)) {
        echo '<div style="color:red;">Dati mancanti: seleziona un gioco e almeno un contatto.</div>';
        wp_die();
    }

    // Verifica post "gioco"
    $gioco = get_post($gioco_id);
    if (!$gioco || $gioco->post_type !== 'gioco' || $gioco->post_status !== 'publish') {
        echo '<div style="color:red;">Gioco non valido.</div>';
        wp_die();
    }

    // Sanifica e limita a 3
    $emails = array_values(array_unique(array_filter(array_map('sanitize_email', $emails))));
    if (empty($emails)) {
        echo '<div style="color:red;">Nessuna email valida.</div>';
        wp_die();
    }
    if (count($emails) > 3) {
        $emails = array_slice($emails, 0, 3);
    }

    global $wpdb;
    $table_sessions = $wpdb->prefix . 'game_sessions';
    $table_inviti   = $wpdb->prefix . 'giochi_invitati';

    $host_id = get_current_user_id();
    $invito_uuid = wp_generate_uuid4();

    $ttl_seconds = 24 * 3600; // 24h
    $now_ts = current_time('timestamp');
    $expires_at = date('Y-m-d H:i:s', $now_ts + $ttl_seconds);

   // Crea sessione
    $ins = $wpdb->insert($table_sessions, [
        'host_user_id' => $host_id,
        'gioco_id'     => $gioco_id,
        'invito_uuid'  => $invito_uuid,
        'status'       => 'created',
        'created_at'   => current_time('mysql'),
        'expires_at'   => $expires_at,
    ], ['%d','%d','%s','%s','%s','%s']);

    if ($ins === false) {
        echo '<div style="color:red;">Errore creazione sessione.</div>';
        wp_die();
    }

    $session_id = (int) $wpdb->insert_id;

    // Inserisci invitati
    $inviati = 0;
    foreach ($emails as $email) {
        $ok = $wpdb->insert($table_inviti, [
            'invitante_id'   => $host_id,
            'invitato_email' => $email,
            'session_id'     => $session_id,
            'created_at'     => current_time('mysql'),
        ], ['%d','%s','%d','%s']);

        if ($ok !== false) {
            $link = add_query_arg(['invito' => $invito_uuid], home_url('/gioca'));
            wp_mail(
                $email,
                'Sei stato invitato a giocare',
                'Clicca per entrare in partita: ' . esc_url($link),
                ['Content-Type: text/plain; charset=UTF-8']
            );
            $inviati++;
        }
    }

    $link_host = add_query_arg(['invito' => $invito_uuid], home_url('/gioca'));
    echo '<div style="color:green;">Partita creata. Invitati: ' . intval($inviati) . '</div>';
    echo '<div>Link da condividere: <a href="' . esc_url($link_host) . '" target="_blank">' . esc_html($link_host) . '</a></div>';
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
