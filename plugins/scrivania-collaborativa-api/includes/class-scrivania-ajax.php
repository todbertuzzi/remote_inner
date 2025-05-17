<?php
/**
 * Gestione delle chiamate AJAX per il Tool Scrivania
 */
class Scrivania_Ajax {
    /**
     * Inizializza le funzioni AJAX
     */
    public static function init() {
        add_action('wp_ajax_attiva_scrivania', [self::class, 'attiva_scrivania']);
    }
    
    /**
     * Invia inviti al Tool Scrivania
     */
    public static function attiva_scrivania() {
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

        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_invitati';
        $sent = 0;

        foreach ($emails as $email) {
            $token = wp_generate_password(16, false);

            $wpdb->insert($table, [
                'sessione_id' => $session_id,
                'token' => $token,
                'invitante_id' => $user_id,
                'invitato_email' => $email,
                'data_invito' => $data,
                'ora_invito' => $ora
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
        
        $wpdb->insert($table, [
            'token' => $token,
            'creatore_id' => $user_id,
            'nome' => $nome,
            'impostazioni' => json_encode(['attiva' => false]),
            'creato_il' => current_time('mysql'),
            'modificato_il' => current_time('mysql')
        ]);
        
        return $wpdb->insert_id;
    }
}

// Inizializza le funzioni AJAX
Scrivania_Ajax::init();