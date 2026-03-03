<?php
/**
 * Gestione delle chiamate AJAX per il Tool Scrivania
 */
class Scrivania_Ajax {
    /**
     * Ritorna un host "sicuro" per costruire URL assoluti coerenti con i cookie.
     * Se l'host corrente è equivalente a home/site (es. www/non-www), usa quello.
     */
    private static function get_safe_request_host() {
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $site_host = wp_parse_url(site_url('/'), PHP_URL_HOST);

        $raw = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $raw = strtolower(preg_replace('/:\\d+$/', '', $raw));

        $allowed = array_filter(array_map('strtolower', array($home_host, $site_host)));
        $normalize = function ($h) {
            return preg_replace('/^www\\./', '', (string) $h);
        };

        if (!empty($raw)) {
            if (in_array($raw, $allowed, true)) {
                return $raw;
            }
            $raw_n = $normalize($raw);
            foreach ($allowed as $h) {
                if ($raw_n === $normalize($h)) {
                    return $raw;
                }
            }
        }

        return !empty($home_host) ? $home_host : $site_host;
    }

    /**
     * Costruisce una URL assoluta usando l'host corrente (se compatibile) per evitare mismatch www/non-www.
     */
    private static function build_url_on_request_host($path_with_query) {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = self::get_safe_request_host();
        $path_with_query = '/' . ltrim((string) $path_with_query, '/');
        return $scheme . '://' . $host . $path_with_query;
    }

    /**
     * Inizializza le funzioni AJAX
     */
    public static function init() {
        add_action('wp_ajax_attiva_scrivania', [self::class, 'attiva_scrivania']);
        add_action('wp_ajax_scrivania_rest_nonce', [self::class, 'rest_nonce']);
        // Se per qualsiasi motivo i cookie non arrivano (cache/CDN/domain mismatch),
        // evita la risposta default "0" e torna un JSON chiaro.
        add_action('wp_ajax_nopriv_scrivania_rest_nonce', [self::class, 'rest_nonce']);
    }

    /**
     * Ritorna un nonce REST fresco per l'utente loggato.
     * Utile quando una cache serve HTML con nonce "stale" (rest_cookie_invalid_nonce).
     */
    public static function rest_nonce() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Utente non loggato'), 401);
            wp_die();
        }

        wp_send_json_success(array(
            'nonce' => wp_create_nonce('wp_rest'),
        ));
        wp_die();
    }
    
    /**
     * Invia inviti al Tool Scrivania
     */
    public static function attiva_scrivania() {
        if (!is_user_logged_in()) {
            wp_send_json_error('Utente non loggato.');
            return;
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $raw_emails = $_POST['email_destinatario'] ?? [];
        if (is_string($raw_emails)) {
            $emails = preg_split('/[\s,;]+/', $raw_emails, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $emails = (array) $raw_emails;
        }
        $data = isset($_POST['data_invito']) ? sanitize_text_field($_POST['data_invito']) : '';
        $ora = isset($_POST['ora_invito']) ? sanitize_text_field($_POST['ora_invito']) : '';

        if (empty($emails) || empty($data) || empty($ora)) {
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
                $table = $wpdb->prefix . 'scrivania_sessioni';
                $sessioni = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE creatore_id = %d",
                    $user_id
                ));
                
                if ($sessioni >= 1) {
                    echo '<div style="color:red;">Gli utenti Welcome possono creare massimo 1 sessione. Upgrade il tuo piano per creare più sessioni.</div>';
                    wp_die();
                }
            }
        }

        // Crea o recupera la sessione
        $session_id = self::create_or_get_session($user_id);
        if (!$session_id) {
            echo '<div style="color:red;">Errore nella creazione della sessione.</div>';
            wp_die();
        }

        // Link diretto per l'invitante (token sessione)
        $session_link = '';
        $session_token = '';
        $session_creator_id = 0;
        try {
            $sessions_table = $wpdb->prefix . 'scrivania_sessioni';
            $session_token = $wpdb->get_var($wpdb->prepare(
                "SELECT token FROM {$sessions_table} WHERE id = %d",
                intval($session_id)
            ));
            $session_creator_id = intval($wpdb->get_var($wpdb->prepare(
                "SELECT creatore_id FROM {$sessions_table} WHERE id = %d",
                intval($session_id)
            )));
            if (!empty($session_token)) {
                $session_link = self::build_url_on_request_host('/tool-scrivania/?token=' . urlencode($session_token));
            }
        } catch (Throwable $e) {
            // no-op: manteniamo compatibilità anche se qualcosa va storto
        }

        $table = $wpdb->prefix . 'scrivania_invitati';
        $sent = 0;

        // Cache colonne per compatibilità schema legacy
        $columns = $wpdb->get_col("DESC {$table}", 0);

        foreach ($emails as $email) {
            $token = wp_generate_password(16, false);

            // Pre-bind se l'utente esiste già
            $existing_user = get_user_by('email', $email);
            $invitato_user_id = $existing_user ? intval($existing_user->ID) : null;

            // Hash token (non rimuoviamo il token in chiaro per compatibilità V1)
            $token_hash = hash_hmac('sha256', $token, wp_salt('auth'));

            $insert = [
                'sessione_id' => $session_id,
                'token' => $token,
                'invitante_id' => $user_id,
                'invitato_email' => $email,
                'data_invito' => $data,
                'ora_invito' => $ora,
                // Nuovi campi (se esistono nello schema)
                'invitato_user_id' => $invitato_user_id,
                'role' => 'viewer',
                'status' => 'pending',
                'token_hash' => $token_hash,
                'last_sent_at' => current_time('mysql'),
                'resend_count' => 0,
            ];

            // Rimuovi chiavi non supportate se la colonna non esiste (schema legacy)
            foreach (array_keys($insert) as $key) {
                if (!in_array($key, $columns, true)) {
                    unset($insert[$key]);
                }
            }

            $wpdb->insert($table, $insert);

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

        // Mail di riepilogo all'invitante con link diretto alla sessione (utile per copia/incolla)
        $inviter = get_userdata($user_id);
        $inviter_email = ($inviter && !empty($inviter->user_email)) ? $inviter->user_email : '';
        if (!empty($inviter_email) && !empty($session_link)) {
            $subject_owner = 'Link sessione Tool Scrivania (solo creatore)';
            $body_owner =
                '<p>Hai creato una sessione del Tool Scrivania.</p>' .
                '<p><strong>Quando:</strong> ' . date_i18n('d/m/Y', strtotime($data)) . ' alle ' . esc_html($ora) . '</p>' .
                '<p><strong>Dettagli tecnici:</strong> sessione ID ' . intval($session_id) . ' &middot; creatore ID ' . intval($session_creator_id ?: $user_id) . ' &middot; invitante ID ' . intval($user_id) . '</p>' .
                '<p><strong>Link per accedere alla tua sessione (solo creatore):</strong><br />' .
                '<a href="' . esc_url($session_link) . '">' . esc_html($session_link) . '</a></p>' .
                '<p style="margin-top:12px; color:#555;">Nota: gli invitati devono entrare dal link <strong>/invito-scrivania/?token=...</strong> ricevuto nella loro email (non da questo link).</p>' .
                '<p><strong>Inviti inviati:</strong> ' . intval($sent) . '</p>';
            $headers_owner = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($inviter_email, $subject_owner, $body_owner, $headers_owner);
        }

        echo "<div style='color:green; font-weight:600;'>Inviti inviati: " . intval($sent) . "</div>";
        echo "<div style='margin-top:6px; color:#444;'>Stai invitando come utente ID " . intval($user_id) . "</div>";
        echo "<div style='margin-top:2px; color:#444;'>Sessione ID " . intval($session_id) . " &middot; Creatore ID " . intval($session_creator_id ?: $user_id) . "</div>";
        if (!empty($session_link)) {
            echo "<div style='margin-top:10px;'><strong>Link per accedere alla sessione (solo creatore):</strong><br /><a href='" . esc_url($session_link) . "' target='_blank' rel='noopener noreferrer'>" . esc_html($session_link) . "</a></div>";
            echo "<div style='margin-top:6px; color:#666;'>Gli invitati entreranno invece dal loro link <strong>/invito-scrivania/?token=...</strong>.</div>";
        } else {
            echo "<div style='margin-top:10px; color:#666;'>Link sessione non disponibile: apri <a href='" . esc_url(home_url('/tool-scrivania/')) . "' target='_blank' rel='noopener noreferrer'>Tool Scrivania</a> (sei il creatore).</div>";
        }
        wp_die();
    }
    
    /**
     * Crea o recupera una sessione esistente per l'utente
     */
    private static function create_or_get_session($user_id) {
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
}

// Inizializza le funzioni AJAX
Scrivania_Ajax::init();