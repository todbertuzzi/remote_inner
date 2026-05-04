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

    gim_ensure_contacts_table();
}
register_activation_hook(__FILE__, 'gim_install_game_sessions_schema');

function gim_ensure_contacts_table() {
    static $table_ready = null;

    if ($table_ready === true) {
        return true;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'contatti_utente';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if (!empty($exists)) {
        $table_ready = true;
        return true;
    }

    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        utente_id BIGINT UNSIGNED NOT NULL,
        nome VARCHAR(191) NOT NULL,
        email VARCHAR(191) NOT NULL,
        creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY utente_id (utente_id),
        KEY utente_email (utente_id, email)
    ) {$charset_collate};";

    dbDelta($sql);

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $table_ready = !empty($exists);
    return $table_ready;
}

function cim_rubrica_feedback($message, $status = 'info') {
    $colors = array(
        'success' => 'green',
        'warning' => 'orange',
        'error' => 'red',
        'info' => '#444',
    );

    $color = isset($colors[$status]) ? $colors[$status] : $colors['info'];

    echo '<div class="rubrica-feedback" data-status="' . esc_attr($status) . '" style="color:' . esc_attr($color) . ';">' . esc_html($message) . '</div>';
}

function gim_get_wp_timezone() {
    if (function_exists('wp_timezone')) {
        return wp_timezone();
    }

    $timezone_string = function_exists('wp_timezone_string') ? wp_timezone_string() : '';
    if (empty($timezone_string)) {
        $timezone_string = 'UTC';
    }

    try {
        return new DateTimeZone($timezone_string);
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
}

function gim_parse_scrivania_schedule($data, $ora) {
    $date = trim((string) $data);
    $time = trim((string) $ora);

    if ($date === '' || $time === '') {
        return null;
    }

    $timezone = gim_get_wp_timezone();
    $formats = array('Y-m-d H:i:s', 'Y-m-d H:i');

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $date . ' ' . $time, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $has_errors = is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']));
        if ($dt instanceof DateTimeImmutable && !$has_errors) {
            return $dt;
        }
    }

    return null;
}

function gim_format_scrivania_schedule_html($data, $ora) {
    $scheduled_at = gim_parse_scrivania_schedule($data, $ora);
    if (!$scheduled_at) {
        return '';
    }

    $date_label = function_exists('wp_date')
        ? wp_date('d/m/Y', $scheduled_at->getTimestamp(), gim_get_wp_timezone())
        : date_i18n('d/m/Y', $scheduled_at->getTimestamp());

    return '<p><strong>Quando:</strong> ' . esc_html($date_label) . ' alle ' . esc_html($scheduled_at->format('H:i')) . '</p>';
}

if (!function_exists('gim_get_scrivania_deck_options')) {
    function gim_get_scrivania_deck_options() {
        return array(
            array('id' => 0, 'label' => 'Mazzo 0 (verticale)'),
            array('id' => 1, 'label' => 'Mazzo 1 (orizzontale 4/3)'),
        );
    }
}

if (!function_exists('gim_normalize_scrivania_deck_id')) {
    function gim_normalize_scrivania_deck_id($value) {
        $deck_id = intval($value);
        $valid_ids = array();

        foreach (gim_get_scrivania_deck_options() as $option) {
            if (isset($option['id'])) {
                $valid_ids[] = intval($option['id']);
            }
        }

        return in_array($deck_id, $valid_ids, true) ? $deck_id : 0;
    }
}

if (!function_exists('gim_apply_scrivania_deck_to_session')) {
    function gim_apply_scrivania_deck_to_session($session_id, $deck_id) {
        global $wpdb;

        $session_id = intval($session_id);
        if ($session_id <= 0) {
            return false;
        }

        $table = $wpdb->prefix . 'scrivania_sessioni';
        $settings_json = $wpdb->get_var($wpdb->prepare(
            "SELECT impostazioni FROM {$table} WHERE id = %d",
            $session_id
        ));

        if ($settings_json === null) {
            return false;
        }

        $settings = array();
        if (!empty($settings_json)) {
            $decoded = json_decode($settings_json, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $settings['mazzoId'] = gim_normalize_scrivania_deck_id($deck_id);

        return $wpdb->update(
            $table,
            array(
                'impostazioni' => wp_json_encode($settings),
                'modificato_il' => current_time('mysql'),
            ),
            array('id' => $session_id),
            array('%s', '%s'),
            array('%d')
        ) !== false;
    }
}



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

if (!function_exists('gim_create_or_get_session')) {
    /**
     * Fallback: crea o recupera una sessione scrivania per l'utente.
     * Nota: se il plugin Scrivania Collaborativa API è attivo, questo handler viene disabilitato.
     */
    function gim_create_or_get_session($user_id, $deck_id = 0)
    {
        global $wpdb;

        $deck_id = gim_normalize_scrivania_deck_id($deck_id);

        $table = $wpdb->prefix . 'scrivania_sessioni';

        // Se la tabella non esiste, evita fatal e segnala errore.
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (empty($table_exists)) {
            return false;
        }

        $session_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE creatore_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        if (!empty($session_id)) {
            gim_apply_scrivania_deck_to_session($session_id, $deck_id);
            return (int) $session_id;
        }

        $user = get_userdata($user_id);
        $display_name = $user ? $user->display_name : 'utente';

        $token = wp_generate_password(24, false);
        $nome = 'Sessione di ' . $display_name;
        $impostazioni = [
            'attiva' => false,
            'iniziata' => null,
            'mazzoId' => $deck_id,
            'sfondo' => null,
        ];

        $ins = $wpdb->insert($table, [
            'token' => $token,
            'creatore_id' => $user_id,
            'nome' => $nome,
            'impostazioni' => wp_json_encode($impostazioni),
            'creato_il' => current_time('mysql'),
            'modificato_il' => current_time('mysql'),
        ]);

        if ($ins === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }
}

function gim_attiva_scrivania() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Utente non loggato.');
    }
    $user_id = get_current_user_id();

    $raw_emails = $_POST['email_destinatario'] ?? [];
    if (is_string($raw_emails)) {
        $emails = preg_split('/[\s,;]+/', $raw_emails, -1, PREG_SPLIT_NO_EMPTY);
    } else {
        $emails = (array) $raw_emails;
    }
    $data = sanitize_text_field($_POST['data_invito'] ?? '');
    $ora = sanitize_text_field($_POST['ora_invito'] ?? '');
    $deck_id = gim_normalize_scrivania_deck_id($_POST['mazzo_id'] ?? 0);

    if (!is_array($emails) || !$data || !$ora) {
        echo '<div style="color:red;">Dati mancanti o non validi.</div>';
        wp_die();
    }

    $scheduled_at = gim_parse_scrivania_schedule($data, $ora);
    if (!$scheduled_at) {
        echo '<div style="color:red;">Data o orario non validi.</div>';
        wp_die();
    }

    $data = $scheduled_at->format('Y-m-d');
    $ora = $scheduled_at->format('H:i');

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
    $session_id = gim_create_or_get_session($user_id, $deck_id);
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
            ' . gim_format_scrivania_schedule_html($data, $ora) . '
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

// Single source of truth: se è attivo il plugin Scrivania Collaborativa API,
// la gestione inviti scrivania deve essere delegata a quel plugin (evita doppie mail/doppie insert).
add_action('plugins_loaded', function () {
    if (class_exists('Scrivania_Ajax')) {
        remove_action('wp_ajax_attiva_scrivania', 'gim_attiva_scrivania');
    }
}, 20);
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

function gim_attiva_gioco()
{
    if (!is_user_logged_in()) {
        echo '<div style="color:red;">Devi essere loggato.</div>';
        wp_die();
    }

    $gioco_id = isset($_POST['gioco_id']) ? intval($_POST['gioco_id']) : 0;
    $raw_emails = $_POST['email_destinatario'] ?? [];
    if (is_string($raw_emails)) {
        $emails = preg_split('/[\s,;]+/', $raw_emails, -1, PREG_SPLIT_NO_EMPTY);
    } else {
        $emails = $raw_emails;
    }

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

    if (!check_ajax_referer('rubrica_contatti', 'nonce', false)) {
        cim_rubrica_feedback('Sessione scaduta. Ricarica la pagina e riprova.', 'error');
        wp_die();
    }

    global $wpdb;

    if (!gim_ensure_contacts_table()) {
        cim_rubrica_feedback('Rubrica non disponibile in questo momento.', 'error');
        wp_die();
    }

    $user_id = get_current_user_id();
    $nome = isset($_POST['nome']) ? sanitize_text_field(wp_unslash($_POST['nome'])) : '';
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

    if ($nome === '') {
        cim_rubrica_feedback('Inserisci un nome.', 'error');
        wp_die();
    }

    if (!is_email($email)) {
        cim_rubrica_feedback('Email non valida.', 'error');
        wp_die();
    }

    $table = $wpdb->prefix . 'contatti_utente';

    // Verifica se esiste già
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE utente_id = %d AND LOWER(email) = LOWER(%s)",
        $user_id,
        $email
    ));

    if ($exists) {
        cim_rubrica_feedback('Questo contatto è già presente.', 'warning');
        wp_die();
    }

    $inserted = $wpdb->insert($table, [
        'utente_id' => $user_id,
        'nome' => $nome,
        'email' => $email
    ], ['%d', '%s', '%s']);

    if ($inserted === false) {
        cim_rubrica_feedback('Errore durante il salvataggio del contatto.', 'error');
        wp_die();
    }

    cim_rubrica_feedback('Contatto aggiunto correttamente.', 'success');
    wp_die();
}

/**
 * Funzione AJAX per eliminare un contatto dalla rubrica personale.
 *
 * Chiamata tramite AJAX con `action: 'elimina_contatto_utente'`.
 */
add_action('wp_ajax_elimina_contatto_utente', 'cim_elimina_contatto_utente');

function cim_elimina_contatto_utente()
{
    if (!is_user_logged_in()) {
        wp_die('Non autorizzato');
    }

    if (!check_ajax_referer('rubrica_contatti', 'nonce', false)) {
        cim_rubrica_feedback('Sessione scaduta. Ricarica la pagina e riprova.', 'error');
        wp_die();
    }

    global $wpdb;

    if (!gim_ensure_contacts_table()) {
        cim_rubrica_feedback('Rubrica non disponibile in questo momento.', 'error');
        wp_die();
    }

    $user_id = get_current_user_id();
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

    if (!is_email($email)) {
        cim_rubrica_feedback('Contatto non valido.', 'error');
        wp_die();
    }

    $table = $wpdb->prefix . 'contatti_utente';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE utente_id = %d AND LOWER(email) = LOWER(%s) LIMIT 1",
        $user_id,
        $email
    ));

    if ($deleted === false) {
        cim_rubrica_feedback('Errore durante l\'eliminazione del contatto.', 'error');
        wp_die();
    }

    if ((int) $deleted === 0) {
        cim_rubrica_feedback('Contatto non trovato.', 'warning');
        wp_die();
    }

    cim_rubrica_feedback('Contatto eliminato correttamente.', 'success');
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

    if (!gim_ensure_contacts_table()) {
        echo '<p style="color:red;">Rubrica non disponibile in questo momento.</p>';
        wp_die();
    }

    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'contatti_utente';

    $contatti = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE utente_id = %d ORDER BY nome ASC",
        $user_id
    ));

    if ($contatti === null) {
        echo '<p style="color:red;">Errore nel caricamento contatti.</p>';
        wp_die();
    }

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
            echo '<p class="rubrica-empty-state">La rubrica è vuota.</p>';
        } else {
            echo '<div class="rubrica-contact-list">';
            foreach ($contatti as $c) {
                echo '<div class="rubrica-contact-item">';
                echo '<div class="rubrica-contact-main">';
                echo '<strong class="rubrica-contact-name">' . esc_html($c->nome) . '</strong>';
                echo '<span class="rubrica-contact-email">' . esc_html($c->email) . '</span>';
                echo '</div>';
                echo '<button type="button" class="rubrica-delete-btn" data-contact-name="' . esc_attr($c->nome) . '" data-contact-email="' . esc_attr($c->email) . '">Elimina</button>';
                echo '</div>';
            }
            echo '</div>';
        }
    }

    wp_die();
}
