<?php
/**
 * Gestione delle chiamate AJAX per il Tool Scrivania
 */
class Scrivania_Ajax {
    private const DASHBOARD_INVITES_NONCE_ACTION = 'scrivania_dashboard_invites';

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

        // Dashboard: gestione completa inviti Scrivania (solo creatore)
        add_action('wp_ajax_scrivania_dashboard_get_invites', [self::class, 'dashboard_get_invites']);
        add_action('wp_ajax_scrivania_dashboard_update_invite_role', [self::class, 'dashboard_update_invite_role']);
        add_action('wp_ajax_scrivania_dashboard_revoke_invite', [self::class, 'dashboard_revoke_invite']);
        add_action('wp_ajax_scrivania_dashboard_resend_invite', [self::class, 'dashboard_resend_invite']);
    }

    private static function dashboard_require_nonce() {
        $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, self::DASHBOARD_INVITES_NONCE_ACTION)) {
            wp_send_json_error(array('message' => 'Nonce non valido'), 403);
            wp_die();
        }
    }

    private static function get_owned_session($session_id, $user_id) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
        if ($session_id <= 0 || $user_id <= 0) {
            return null;
        }

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, token, nome, impostazioni, creato_il, modificato_il FROM {$table_sessions} WHERE id = %d AND creatore_id = %d",
                intval($session_id),
                intval($user_id)
            ),
            ARRAY_A
        );
        return $session ?: null;
    }

    private static function parse_session_archived_flag($impostazioni_json) {
        if (empty($impostazioni_json)) {
            return false;
        }
        $decoded = json_decode((string) $impostazioni_json, true);
        if (!is_array($decoded)) {
            return false;
        }
        return !empty($decoded['archived']);
    }

    private static function normalize_invite_status($row, $has_status, $has_verified_at, $has_consumed_at, $has_revoked_at) {
        $status = $has_status && isset($row['status']) ? (string) $row['status'] : '';
        $revoked_at = $has_revoked_at && !empty($row['revoked_at']);
        $consumed_at = $has_consumed_at && !empty($row['consumed_at']);
        $verified_at = $has_verified_at && !empty($row['verified_at']);

        if ($revoked_at || $status === 'revoked') {
            return 'revoked';
        }
        if ($consumed_at || $status === 'consumed') {
            return 'consumed';
        }
        if ($verified_at || $status === 'verified') {
            return 'verified';
        }
        return !empty($status) ? $status : 'pending';
    }

    private static function get_wp_timezone() {
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

    private static function parse_invite_schedule($data, $ora) {
        $date = trim((string) $data);
        $time = trim((string) $ora);

        if ($date === '' || $time === '') {
            return null;
        }

        $timezone = self::get_wp_timezone();
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

    private static function format_invite_schedule_html($data, $ora) {
        $scheduled_at = self::parse_invite_schedule($data, $ora);
        if (!$scheduled_at) {
            return '';
        }

        $date_label = function_exists('wp_date')
            ? wp_date('d/m/Y', $scheduled_at->getTimestamp(), self::get_wp_timezone())
            : date_i18n('d/m/Y', $scheduled_at->getTimestamp());

        return '<p><strong>Quando:</strong> ' . esc_html($date_label) . ' alle ' . esc_html($scheduled_at->format('H:i')) . '</p>';
    }

    private static function get_scrivania_deck_options() {
        if (function_exists('gim_get_scrivania_deck_options')) {
            $options = gim_get_scrivania_deck_options();
            if (is_array($options) && !empty($options)) {
                return $options;
            }
        }

        return array(
            array('id' => 0, 'label' => 'Mazzo 0 (verticale)'),
            array('id' => 1, 'label' => 'Mazzo 1 (orizzontale 4/3)'),
        );
    }

    private static function normalize_deck_id($value) {
        $deck_id = intval($value);
        $valid_ids = array();

        foreach (self::get_scrivania_deck_options() as $option) {
            if (isset($option['id'])) {
                $valid_ids[] = intval($option['id']);
            }
        }

        return in_array($deck_id, $valid_ids, true) ? $deck_id : 0;
    }

    private static function send_scrivania_invite_email($email, $token, $data, $ora) {
        $link = home_url('/invito-scrivania/?token=' . $token);
        $subject = 'Invito al Tool Scrivania';

        $when = self::format_invite_schedule_html($data, $ora);

        $body =
            '<p>Hai ricevuto un invito al Tool Scrivania!</p>' .
            $when .
            '<p><a href="' . esc_url($link) . '">Clicca qui per partecipare</a></p>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return wp_mail($email, $subject, $body, $headers);
    }

    /**
     * Dashboard: lista sessioni (del creatore) + inviti della sessione selezionata.
     */
    public static function dashboard_get_invites() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Utente non loggato'), 401);
            wp_die();
        }

        self::dashboard_require_nonce();

        global $wpdb;
        $user_id = get_current_user_id();
        $requested_session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;

        $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
        $table_invites = $wpdb->prefix . 'scrivania_invitati';

        // Sessioni disponibili (ultime 30)
        $session_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, token, nome, impostazioni, creato_il, modificato_il FROM {$table_sessions} WHERE creatore_id = %d ORDER BY id DESC LIMIT 30",
                intval($user_id)
            ),
            ARRAY_A
        );
        if (!is_array($session_rows)) {
            $session_rows = array();
        }

        $sessions = array();
        $session_ids = array();
        foreach ($session_rows as $s) {
            $sid = intval($s['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $archived = self::parse_session_archived_flag($s['impostazioni'] ?? '');
            $sessions[] = array(
                'id' => $sid,
                'name' => isset($s['nome']) ? (string) $s['nome'] : ('Sessione ' . $sid),
                'archived' => $archived,
                'created_at' => $s['creato_il'] ?? null,
                'updated_at' => $s['modificato_il'] ?? null,
                'tool_link' => !empty($s['token']) ? self::build_url_on_request_host('/tool-scrivania/?token=' . urlencode((string) $s['token'])) : null,
            );
            $session_ids[] = $sid;
        }

        $selected_session_id = 0;
        if ($requested_session_id > 0 && in_array($requested_session_id, $session_ids, true)) {
            $selected_session_id = $requested_session_id;
        } elseif (!empty($session_ids)) {
            $selected_session_id = intval($session_ids[0]);
        }

        if ($selected_session_id <= 0) {
            wp_send_json_success(array(
                'sessions' => $sessions,
                'selected_session_id' => 0,
                'invites' => array(),
                'features' => array('role' => false, 'status' => false, 'revoked_at' => false, 'verified_at' => false, 'consumed_at' => false, 'last_sent_at' => false, 'resend_count' => false),
            ));
            wp_die();
        }

        // Feature detection (compat schema)
        $cols = $wpdb->get_col("DESC {$table_invites}", 0);
        $cols = is_array($cols) ? $cols : array();
        $has_role = in_array('role', $cols, true);
        $has_status = in_array('status', $cols, true);
        $has_revoked_at = in_array('revoked_at', $cols, true);
        $has_verified_at = in_array('verified_at', $cols, true);
        $has_consumed_at = in_array('consumed_at', $cols, true);
        $has_last_sent_at = in_array('last_sent_at', $cols, true);
        $has_resend_count = in_array('resend_count', $cols, true);
        $has_invitato_user_id = in_array('invitato_user_id', $cols, true);

        // Lista inviti: un record per email (ultimo invito per email) nella sessione selezionata.
        $invites_sql = $wpdb->prepare(
            "SELECT i.*\n" .
            "FROM {$table_invites} i\n" .
            "INNER JOIN (\n" .
            "  SELECT MAX(id) AS id\n" .
            "  FROM {$table_invites}\n" .
            "  WHERE sessione_id = %d AND invitante_id = %d\n" .
            "  GROUP BY LOWER(invitato_email)\n" .
            ") latest ON i.id = latest.id\n" .
            "ORDER BY i.id DESC",
            intval($selected_session_id),
            intval($user_id)
        );
        $rows = $wpdb->get_results($invites_sql, ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        $invites = array();
        foreach ($rows as $row) {
            $invite_user_id = $has_invitato_user_id ? intval($row['invitato_user_id'] ?? 0) : 0;
            $invite_user_name = null;
            if ($invite_user_id > 0) {
                $ud = get_userdata($invite_user_id);
                if ($ud) {
                    $invite_user_name = $ud->display_name;
                }
            }

            $invites[] = array(
                'id' => intval($row['id'] ?? 0),
                'email' => isset($row['invitato_email']) ? (string) $row['invitato_email'] : '',
                'role' => $has_role && !empty($row['role']) ? (string) $row['role'] : 'viewer',
                'status' => self::normalize_invite_status($row, $has_status, $has_verified_at, $has_consumed_at, $has_revoked_at),
                'data_invito' => $row['data_invito'] ?? null,
                'ora_invito' => $row['ora_invito'] ?? null,
                'created_at' => $row['creato_il'] ?? null,
                'verified_at' => $has_verified_at ? ($row['verified_at'] ?? null) : null,
                'consumed_at' => $has_consumed_at ? ($row['consumed_at'] ?? null) : null,
                'revoked_at' => $has_revoked_at ? ($row['revoked_at'] ?? null) : null,
                'last_sent_at' => $has_last_sent_at ? ($row['last_sent_at'] ?? null) : null,
                'resend_count' => $has_resend_count ? intval($row['resend_count'] ?? 0) : null,
                'invitato_user_id' => $invite_user_id ?: null,
                'invitato_name' => $invite_user_name,
            );
        }

        wp_send_json_success(array(
            'sessions' => $sessions,
            'selected_session_id' => $selected_session_id,
            'invites' => $invites,
            'features' => array(
                'role' => $has_role,
                'status' => $has_status,
                'revoked_at' => $has_revoked_at,
                'verified_at' => $has_verified_at,
                'consumed_at' => $has_consumed_at,
                'last_sent_at' => $has_last_sent_at,
                'resend_count' => $has_resend_count,
            ),
        ));
        wp_die();
    }

    public static function dashboard_update_invite_role() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Utente non loggato'), 401);
            wp_die();
        }
        self::dashboard_require_nonce();

        $invite_id = isset($_POST['invite_id']) ? intval($_POST['invite_id']) : 0;
        $new_role = isset($_POST['role']) ? sanitize_text_field((string) $_POST['role']) : '';
        if ($invite_id <= 0 || !in_array($new_role, array('viewer', 'editor'), true)) {
            wp_send_json_error(array('message' => 'Parametri non validi'), 400);
            wp_die();
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $table_invites = $wpdb->prefix . 'scrivania_invitati';

        $cols = $wpdb->get_col("DESC {$table_invites}", 0);
        $cols = is_array($cols) ? $cols : array();
        if (!in_array('role', $cols, true)) {
            wp_send_json_error(array('message' => 'Schema non supporta la modifica del ruolo'), 500);
            wp_die();
        }

        $invite = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_invites} WHERE id = %d", intval($invite_id)),
            ARRAY_A
        );
        if (!$invite) {
            wp_send_json_error(array('message' => 'Invito non trovato'), 404);
            wp_die();
        }

        $session_id = intval($invite['sessione_id'] ?? 0);
        $email = isset($invite['invitato_email']) ? (string) $invite['invitato_email'] : '';
        $target_user_id = in_array('invitato_user_id', $cols, true) ? intval($invite['invitato_user_id'] ?? 0) : 0;

        $session = self::get_owned_session($session_id, $user_id);
        if (!$session) {
            wp_send_json_error(array('message' => 'Non autorizzato'), 403);
            wp_die();
        }

        // Aggiorna tutte le righe di quell'email nella sessione (e anche per user_id se presente).
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_invites} SET role = %s WHERE sessione_id = %d AND LOWER(invitato_email) = LOWER(%s)",
                $new_role,
                $session_id,
                $email
            )
        );

        if ($target_user_id > 0) {
            $wpdb->update(
                $table_invites,
                array('role' => $new_role),
                array('sessione_id' => $session_id, 'invitato_user_id' => $target_user_id),
                array('%s'),
                array('%d', '%d')
            );
        }

        wp_send_json_success(array('message' => 'Ruolo aggiornato'));
        wp_die();
    }

    public static function dashboard_revoke_invite() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Utente non loggato'), 401);
            wp_die();
        }
        self::dashboard_require_nonce();

        $invite_id = isset($_POST['invite_id']) ? intval($_POST['invite_id']) : 0;
        if ($invite_id <= 0) {
            wp_send_json_error(array('message' => 'Parametri non validi'), 400);
            wp_die();
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $table_invites = $wpdb->prefix . 'scrivania_invitati';

        $invite = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_invites} WHERE id = %d", intval($invite_id)),
            ARRAY_A
        );
        if (!$invite) {
            wp_send_json_error(array('message' => 'Invito non trovato'), 404);
            wp_die();
        }

        $session_id = intval($invite['sessione_id'] ?? 0);
        $email = isset($invite['invitato_email']) ? (string) $invite['invitato_email'] : '';

        $session = self::get_owned_session($session_id, $user_id);
        if (!$session) {
            wp_send_json_error(array('message' => 'Non autorizzato'), 403);
            wp_die();
        }

        $cols = $wpdb->get_col("DESC {$table_invites}", 0);
        $cols = is_array($cols) ? $cols : array();
        $has_revoked_at = in_array('revoked_at', $cols, true);
        $has_status = in_array('status', $cols, true);
        $has_invitato_user_id = in_array('invitato_user_id', $cols, true);

        $target_user_id = ($has_invitato_user_id) ? intval($invite['invitato_user_id'] ?? 0) : 0;

        if (!$has_revoked_at && !$has_status) {
            // Schema legacy: revoca = elimina.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_invites} WHERE sessione_id = %d AND LOWER(invitato_email) = LOWER(%s)",
                    $session_id,
                    $email
                )
            );
            if ($target_user_id > 0) {
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table_invites} WHERE sessione_id = %d AND invitato_user_id = %d",
                        $session_id,
                        $target_user_id
                    )
                );
            }

            wp_send_json_success(array('message' => 'Invito revocato'));
            wp_die();
        }

        $set_parts = array();
        $params = array();
        if ($has_revoked_at) {
            $set_parts[] = 'revoked_at = %s';
            $params[] = current_time('mysql');
        }
        if ($has_status) {
            $set_parts[] = 'status = %s';
            $params[] = 'revoked';
        }

        $sql = "UPDATE {$table_invites} SET " . implode(', ', $set_parts) . " WHERE sessione_id = %d AND LOWER(invitato_email) = LOWER(%s)";
        $params[] = $session_id;
        $params[] = $email;
        $wpdb->query($wpdb->prepare($sql, ...$params));

        if ($target_user_id > 0) {
            $wpdb->update(
                $table_invites,
                array_filter(array(
                    'revoked_at' => $has_revoked_at ? current_time('mysql') : null,
                    'status' => $has_status ? 'revoked' : null,
                ), function ($v) {
                    return $v !== null;
                }),
                array('sessione_id' => $session_id, 'invitato_user_id' => $target_user_id),
                array_values(array_filter(array(
                    $has_revoked_at ? '%s' : null,
                    $has_status ? '%s' : null,
                ))),
                array('%d', '%d')
            );
        }

        wp_send_json_success(array('message' => 'Invito revocato'));
        wp_die();
    }

    public static function dashboard_resend_invite() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Utente non loggato'), 401);
            wp_die();
        }
        self::dashboard_require_nonce();

        $invite_id = isset($_POST['invite_id']) ? intval($_POST['invite_id']) : 0;
        if ($invite_id <= 0) {
            wp_send_json_error(array('message' => 'Parametri non validi'), 400);
            wp_die();
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $table_invites = $wpdb->prefix . 'scrivania_invitati';

        $cols = $wpdb->get_col("DESC {$table_invites}", 0);
        $cols = is_array($cols) ? $cols : array();
        $has_status = in_array('status', $cols, true);
        $has_revoked_at = in_array('revoked_at', $cols, true);
        $has_last_sent_at = in_array('last_sent_at', $cols, true);
        $has_resend_count = in_array('resend_count', $cols, true);
        $has_role = in_array('role', $cols, true);
        $has_token_hash = in_array('token_hash', $cols, true);
        $has_invitato_user_id = in_array('invitato_user_id', $cols, true);

        $invite = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_invites} WHERE id = %d", intval($invite_id)),
            ARRAY_A
        );
        if (!$invite) {
            wp_send_json_error(array('message' => 'Invito non trovato'), 404);
            wp_die();
        }

        $session_id = intval($invite['sessione_id'] ?? 0);
        $email = isset($invite['invitato_email']) ? (string) $invite['invitato_email'] : '';
        $data = isset($invite['data_invito']) ? (string) $invite['data_invito'] : '';
        $ora = isset($invite['ora_invito']) ? (string) $invite['ora_invito'] : '';
        $role = ($has_role && !empty($invite['role'])) ? (string) $invite['role'] : 'viewer';
        $target_user_id = ($has_invitato_user_id) ? intval($invite['invitato_user_id'] ?? 0) : 0;

        $session = self::get_owned_session($session_id, $user_id);
        if (!$session) {
            wp_send_json_error(array('message' => 'Non autorizzato'), 403);
            wp_die();
        }

        $is_revoked = false;
        if ($has_revoked_at && !empty($invite['revoked_at'])) {
            $is_revoked = true;
        }
        if (!$is_revoked && $has_status && !empty($invite['status']) && (string) $invite['status'] === 'revoked') {
            $is_revoked = true;
        }

        $token_to_send = isset($invite['token']) ? (string) $invite['token'] : '';
        $new_invite_id = 0;

        if ($is_revoked) {
            // Re-invita: crea un nuovo token e una nuova riga (lascia la vecchia revocata).
            $token_to_send = wp_generate_password(16, false);
            $token_hash = $has_token_hash ? hash_hmac('sha256', $token_to_send, wp_salt('auth')) : null;

            $insert = array(
                'sessione_id' => $session_id,
                'token' => $token_to_send,
                'invitante_id' => $user_id,
                'invitato_email' => $email,
                'data_invito' => $data,
                'ora_invito' => $ora,
                'role' => $role,
                'status' => 'pending',
                'token_hash' => $token_hash,
                'last_sent_at' => current_time('mysql'),
                'resend_count' => 0,
                'invitato_user_id' => $target_user_id ?: null,
            );

            foreach (array_keys($insert) as $k) {
                if (!in_array($k, $cols, true)) {
                    unset($insert[$k]);
                }
            }

            $ok = $wpdb->insert($table_invites, $insert);
            if ($ok === false) {
                wp_send_json_error(array('message' => 'Errore DB durante reinvito'), 500);
                wp_die();
            }
            $new_invite_id = intval($wpdb->insert_id);
        } else {
            // Reinvia: stesso token, aggiorna tracking se possibile.
            if ($has_resend_count) {
                // Incrementa in modo atomico
                if ($has_last_sent_at) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$table_invites} SET resend_count = resend_count + 1, last_sent_at = %s WHERE id = %d",
                            current_time('mysql'),
                            intval($invite_id)
                        )
                    );
                } else {
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$table_invites} SET resend_count = resend_count + 1 WHERE id = %d",
                            intval($invite_id)
                        )
                    );
                }
            } elseif ($has_last_sent_at) {
                $wpdb->update(
                    $table_invites,
                    array('last_sent_at' => current_time('mysql')),
                    array('id' => intval($invite_id)),
                    array('%s'),
                    array('%d')
                );
            }
        }

        if (empty($email) || empty($token_to_send)) {
            wp_send_json_error(array('message' => 'Dati invito incompleti'), 500);
            wp_die();
        }

        $sent = self::send_scrivania_invite_email($email, $token_to_send, $data, $ora);
        if (!$sent) {
            wp_send_json_error(array('message' => 'Invio email fallito'), 500);
            wp_die();
        }

        wp_send_json_success(array(
            'message' => $is_revoked ? 'Reinvito inviato' : 'Invito reinviato',
            'new_invite_id' => $new_invite_id ?: null,
        ));
        wp_die();
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
        $deck_id = self::normalize_deck_id($_POST['mazzo_id'] ?? 0);

        if (empty($emails) || empty($data) || empty($ora)) {
            echo '<div style="color:red;">Dati mancanti o non validi.</div>';
            wp_die();
        }

        $scheduled_at = self::parse_invite_schedule($data, $ora);
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

        // Strategia sessione:
        // - Welcome: riusa la sessione esistente, ma resetta stato + revoca inviti precedenti ad ogni nuovo batch.
        // - Non-Welcome: crea sempre una nuova sessione e archivia/chiude le precedenti (revocando i relativi inviti).
        $is_welcome = false;
        if (function_exists('pmpro_getMembershipLevelForUser')) {
            $membership = pmpro_getMembershipLevelForUser($user_id);
            if ($membership && strtolower((string) $membership->name) === 'welcome') {
                $is_welcome = true;
            }
        }

        $session_id = 0;

        if ($is_welcome) {
            $existing_session_id = self::get_latest_session_id_for_creator($user_id);

            if (!empty($existing_session_id)) {
                $session_id = (int) $existing_session_id;
                // Welcome: reset sessione e revoca tutti gli inviti precedenti.
                self::reset_session_state($session_id, $deck_id);
                self::revoke_invites_for_session($session_id);
            } else {
                // Welcome: può creare la prima e unica sessione.
                // Difensivo: se per qualche motivo esistono già sessioni (schema inconsistente), blocca.
                $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
                $sessioni = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_sessions} WHERE creatore_id = %d",
                    $user_id
                ));
                if ($sessioni >= 1) {
                    echo '<div style="color:red;">Gli utenti Welcome possono creare massimo 1 sessione. Upgrade il tuo piano per creare più sessioni.</div>';
                    wp_die();
                }

                $session_id = (int) self::create_new_session($user_id, $deck_id);
            }
        } else {
            // Non-Welcome: nuova sessione sempre
            $session_id = (int) self::create_new_session($user_id, $deck_id);
            if (!empty($session_id)) {
                self::archive_previous_sessions_for_creator($user_id, $session_id);
            }
        }

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

    /**
     * Ritorna l'ultima sessione per un creatore (id), oppure 0.
     */
    private static function get_latest_session_id_for_creator($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE creatore_id = %d ORDER BY id DESC LIMIT 1",
            intval($user_id)
        ));
        return $id ? (int) $id : 0;
    }

    /**
     * Crea SEMPRE una nuova sessione per l'utente (no riuso).
     */
    private static function create_new_session($user_id, $deck_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';

        $deck_id = self::normalize_deck_id($deck_id);

        $token = wp_generate_password(24, false);
        $ud = get_userdata($user_id);
        $display = ($ud && !empty($ud->display_name)) ? $ud->display_name : 'utente';
        $nome = 'Sessione di ' . $display;

        $impostazioni = [
            'attiva' => false,
            'iniziata' => null,
            'mazzoId' => $deck_id,
            'sfondo' => null
        ];

        $ok = $wpdb->insert($table, [
            'token' => $token,
            'creatore_id' => intval($user_id),
            'nome' => $nome,
            'impostazioni' => wp_json_encode($impostazioni),
            'carte' => wp_json_encode([]),
            'creato_il' => current_time('mysql'),
            'modificato_il' => current_time('mysql')
        ]);

        if ($ok === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Reset dello stato sessione (plancia/carte) mantenendo lo stesso session_id.
     */
    private static function reset_session_state($session_id, $deck_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';

        $deck_id = self::normalize_deck_id($deck_id);

        $impostazioni = [
            'attiva' => false,
            'iniziata' => null,
            'mazzoId' => $deck_id,
            'sfondo' => null
        ];

        // Compat schema: state_version potrebbe non esistere.
        $cols = $wpdb->get_col("DESC {$table}", 0);
        $has_state_version = is_array($cols) && in_array('state_version', $cols, true);

        if ($has_state_version) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET impostazioni = %s, carte = %s, modificato_il = %s, state_version = state_version + 1 WHERE id = %d",
                    wp_json_encode($impostazioni),
                    wp_json_encode([]),
                    current_time('mysql'),
                    intval($session_id)
                )
            );
            return;
        }

        $wpdb->update(
            $table,
            array(
                'impostazioni' => wp_json_encode($impostazioni),
                'carte' => wp_json_encode([]),
                'modificato_il' => current_time('mysql')
            ),
            array('id' => intval($session_id)),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Revoca (o elimina, se schema legacy) tutti gli inviti legati a una sessione.
     */
    private static function revoke_invites_for_session($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_invitati';

        $cols = $wpdb->get_col("DESC {$table}", 0);
        $cols = is_array($cols) ? $cols : array();
        $has_revoked_at = in_array('revoked_at', $cols, true);
        $has_status = in_array('status', $cols, true);

        if ($has_revoked_at || $has_status) {
            $set = array();
            $fmt = array();

            if ($has_revoked_at) {
                $set['revoked_at'] = current_time('mysql');
                $fmt[] = '%s';
            }
            if ($has_status) {
                $set['status'] = 'revoked';
                $fmt[] = '%s';
            }

            $wpdb->update(
                $table,
                $set,
                array('sessione_id' => intval($session_id)),
                $fmt,
                array('%d')
            );
            return;
        }

        // Schema legacy senza campi revoca: elimina gli inviti.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE sessione_id = %d",
            intval($session_id)
        ));
    }

    /**
     * Archivia tutte le sessioni precedenti del creatore (eccetto quella corrente) e revoca gli inviti associati.
     */
    private static function archive_previous_sessions_for_creator($creator_id, $keep_session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, impostazioni FROM {$table} WHERE creatore_id = %d AND id <> %d",
                intval($creator_id),
                intval($keep_session_id)
            ),
            ARRAY_A
        );

        if (empty($rows) || !is_array($rows)) {
            return;
        }

        // Compat schema: state_version potrebbe non esistere.
        $cols = $wpdb->get_col("DESC {$table}", 0);
        $has_state_version = is_array($cols) && in_array('state_version', $cols, true);

        foreach ($rows as $row) {
            $sid = intval($row['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }

            $settings = array();
            if (!empty($row['impostazioni'])) {
                $decoded = json_decode($row['impostazioni'], true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }

            $settings['archived'] = true;
            $settings['archived_at'] = current_time('mysql');

            if ($has_state_version) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table} SET impostazioni = %s, modificato_il = %s, state_version = state_version + 1 WHERE id = %d",
                        wp_json_encode($settings),
                        current_time('mysql'),
                        $sid
                    )
                );
            } else {
                $wpdb->update(
                    $table,
                    array(
                        'impostazioni' => wp_json_encode($settings),
                        'modificato_il' => current_time('mysql')
                    ),
                    array('id' => $sid),
                    array('%s', '%s'),
                    array('%d')
                );
            }

            self::revoke_invites_for_session($sid);
        }
    }
}

// Inizializza le funzioni AJAX
Scrivania_Ajax::init();