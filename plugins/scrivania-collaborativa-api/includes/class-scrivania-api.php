<?php
/**
 * Classe principale per Scrivania Collaborativa API
 *
 * @package Scrivania_Collaborativa_API
 */

class Scrivania_Collaborativa_API {
    /**
     * Istanza di Pusher
     *
     * @var Pusher
     */
    private $pusher;
    
    /**
     * Costruttore
     */
    public function __construct() {
        // Inizializza Pusher con le credenziali
        $this->init_pusher();
        
        // Registra l'endpoint REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Aggiungi le impostazioni nella pagina di amministrazione
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Aggiungi lo script front-end con le chiavi Pusher
        add_action('wp_enqueue_scripts', array($this, 'enqueue_pusher_config'));
    }
    
    /**
     * Inizializza l'istanza di Pusher
     */
    private function init_pusher() {
        $app_key = get_option('scrivania_pusher_app_key', '');
        $app_secret = get_option('scrivania_pusher_app_secret', '');
        $app_id = get_option('scrivania_pusher_app_id', '');
        $cluster = get_option('scrivania_pusher_cluster', 'eu');
        
        if (empty($app_key) || empty($app_secret) || empty($app_id)) {
            return;
        }
        
        $this->pusher = new Pusher\Pusher(
            $app_key,
            $app_secret,
            $app_id,
            array(
                'cluster' => $cluster,
                'useTLS' => true
            )
        );
    }
    
    /**
     * Metodo chiamato durante l'attivazione del plugin
     */
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabella delle sessioni
        $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
        $sql_sessions = "CREATE TABLE IF NOT EXISTS $table_sessions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            creatore_id bigint(20) unsigned NOT NULL,
            nome varchar(255) NOT NULL,
            impostazioni longtext DEFAULT NULL,
            carte longtext DEFAULT NULL,
            creato_il datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            modificato_il datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token)
        ) $charset_collate;";
        
        // Tabella degli inviti
        $table_invites = $wpdb->prefix . 'scrivania_invitati';
        $sql_invites = "CREATE TABLE IF NOT EXISTS $table_invites (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sessione_id bigint(20) unsigned NOT NULL,
            token varchar(64) NOT NULL,
            invitante_id bigint(20) unsigned NOT NULL,
            invitato_email varchar(255) NOT NULL,
            data_invito date DEFAULT NULL,
            ora_invito time DEFAULT NULL,
            creato_il datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY sessione_id (sessione_id),
            KEY invitato_email (invitato_email)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_sessions);
        dbDelta($sql_invites);
    }
    
    /**
     * Registra gli endpoint dell'API REST
     */
    public function register_rest_routes() {
        // Endpoint per ottenere i dati di sessione
        register_rest_route('scrivania/v1', '/get-session', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_session_data'),
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ));
        
        // Endpoint per salvare lo stato della sessione
        register_rest_route('scrivania/v1', '/save-session', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_session_data'),
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ));
        
        // Endpoint per l'autenticazione Pusher
        register_rest_route('scrivania/v1', '/pusher-auth', array(
            'methods' => 'POST',
            'callback' => array($this, 'authenticate_pusher_channel'),
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ));
        
        // Endpoint per creare una nuova sessione
        register_rest_route('scrivania/v1', '/create-session', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_session'),
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ));
    }
    
    /**
     * Gestisce l'autenticazione per i canali privati/presence di Pusher
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return mixed Risposta di autenticazione
     */
    public function authenticate_pusher_channel($request) {
        $params = $request->get_params();
        $socket_id = sanitize_text_field($params['socket_id'] ?? '');
        $channel_name = sanitize_text_field($params['channel_name'] ?? '');
        $user_id = get_current_user_id();
        $user_info = get_userdata($user_id);
        
        if (!$this->pusher) {
            return new WP_Error('pusher_not_initialized', 'Pusher non è inizializzato', array('status' => 500));
        }
        
        // Se è un canale presence, include i dati utente
        if (strpos($channel_name, 'presence-') === 0) {
            $presence_data = array(
                'id' => $user_id,
                'name' => $user_info->display_name,
                'email' => $user_info->user_email
            );
            
            $auth = $this->pusher->presence_auth($channel_name, $socket_id, $user_id, $presence_data);
        } else {
            $auth = $this->pusher->socket_auth($channel_name, $socket_id);
        }
        
        return rest_ensure_response($auth);
    }
    
    /**
     * Ottiene i dati di sessione in base al token
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return array|WP_Error Dati della sessione o errore
     */
    public function get_session_data($request) {
        $params = $request->get_params();
        $token = sanitize_text_field($params['token'] ?? '');
        
        if (empty($token)) {
            return new WP_Error('token_missing', 'Token mancante', array('status' => 400));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';
        
        // Recupera la sessione dal database
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE token = %s",
            $token
        ), ARRAY_A);
        
        if (!$session) {
            return new WP_Error('session_not_found', 'Sessione non trovata', array('status' => 404));
        }
        
        $user_id = get_current_user_id();
        $user_data = get_userdata($user_id);
        
        // Verifica se l'utente è autorizzato a partecipare
        $inviti_table = $wpdb->prefix . 'scrivania_invitati';
        $is_invited = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $inviti_table WHERE sessione_id = %d AND invitato_email = %s",
            $session['id'],
            $user_data->user_email
        ));
        
        $is_admin = intval($session['creatore_id']) === $user_id;
        
        if (!$is_invited && !$is_admin) {
            return new WP_Error('not_authorized', 'Non sei autorizzato a partecipare a questa sessione', array('status' => 403));
        }
        
        // Decodifica le impostazioni della sessione
        $sessione = json_decode($session['impostazioni'] ?? '{}', true);
        
        // Decodifica le carte se presenti
        $carte = array();
        if (!empty($session['carte'])) {
            $carte = json_decode($session['carte'], true) ?: array();
        }
        
        return array(
            'success' => true,
            'session_id' => $session['id'],
            'user_id' => $user_id,
            'user_name' => $user_data->display_name,
            'is_admin' => $is_admin,
            'sessione' => $sessione,
            'carte' => $carte
        );
    }
    
    /**
     * Salva lo stato della sessione
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return array|WP_Error Risposta o errore
     */
    public function save_session_data($request) {
        $params = $request->get_json_params();
        $session_id = intval($params['session_id'] ?? 0);
        $sessione = $params['sessione'] ?? array();
        $carte = $params['carte'] ?? array();
        
        if (!$session_id) {
            return new WP_Error('session_id_missing', 'ID sessione mancante', array('status' => 400));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';
        
        // Recupera la sessione dal database
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            return new WP_Error('session_not_found', 'Sessione non trovata', array('status' => 404));
        }
        
        // Verifica che l'utente corrente sia l'amministratore
        $user_id = get_current_user_id();
        if (intval($session->creatore_id) !== $user_id) {
            return new WP_Error('not_authorized', 'Non sei autorizzato a modificare questa sessione', array('status' => 403));
        }
        
        // Aggiorna i dati della sessione
        $wpdb->update(
            $table,
            array(
                'impostazioni' => wp_json_encode($sessione),
                'carte' => wp_json_encode($carte),
                'modificato_il' => current_time('mysql')
            ),
            array('id' => $session_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        // Emetti un evento Pusher per aggiornare tutti i client
        if ($this->pusher) {
            $channel = 'presence-scrivania-' . $session_id;
            $this->pusher->trigger($channel, 'session-updated', array(
                'sessione' => $sessione,
                'carte' => $carte
            ));
        }
        
        return array(
            'success' => true,
            'message' => 'Sessione aggiornata con successo'
        );
    }
    
    /**
     * Crea una nuova sessione
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return array|WP_Error Risposta o errore
     */
    public function create_session($request) {
        $params = $request->get_params();
        $nome = sanitize_text_field($params['nome'] ?? 'Nuova Sessione');
        
        $user_id = get_current_user_id();
        
        // Genera un token unico
        $token = wp_generate_password(24, false);
        
        // Impostazioni iniziali
        $impostazioni = array(
            'attiva' => false,
            'iniziata' => null,
            'mazzoId' => 0,
            'sfondo' => null
        );
        
        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';
        
        // Inserisci la nuova sessione
        $result = $wpdb->insert(
            $table,
            array(
                'token' => $token,
                'creatore_id' => $user_id,
                'nome' => $nome,
                'impostazioni' => wp_json_encode($impostazioni),
                'creato_il' => current_time('mysql'),
                'modificato_il' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Errore nella creazione della sessione', array('status' => 500));
        }
        
        $session_id = $wpdb->insert_id;
        
        return array(
            'success' => true,
            'session_id' => $session_id,
            'token' => $token,
            'message' => 'Sessione creata con successo'
        );
    }
    
    /**
     * Aggiunge il menu di amministrazione per le impostazioni
     */
    public function add_admin_menu() {
        add_options_page(
            'Impostazioni Scrivania Collaborativa',
            'Scrivania Collaborativa',
            'manage_options',
            'scrivania-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Registra le impostazioni per Pusher
     */
    public function register_settings() {
        register_setting('scrivania_settings', 'scrivania_pusher_app_id');
        register_setting('scrivania_settings', 'scrivania_pusher_app_key');
        register_setting('scrivania_settings', 'scrivania_pusher_app_secret');
        register_setting('scrivania_settings', 'scrivania_pusher_cluster');
        register_setting('scrivania_settings', 'scrivania_pusher_debug');
        
        add_settings_section(
            'scrivania_pusher_section',
            'Impostazioni Pusher',
            array($this, 'section_info'),
            'scrivania-settings'
        );
        
        add_settings_field(
            'scrivania_pusher_app_id',
            'App ID',
            array($this, 'app_id_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );
        
        add_settings_field(
            'scrivania_pusher_app_key',
            'App Key',
            array($this, 'app_key_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );
        
        add_settings_field(
            'scrivania_pusher_app_secret',
            'App Secret',
            array($this, 'app_secret_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );
        
        add_settings_field(
            'scrivania_pusher_cluster',
            'Cluster',
            array($this, 'cluster_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );
        
        add_settings_field(
            'scrivania_pusher_debug',
            'Debug Mode',
            array($this, 'debug_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );
    }
    
    /**
     * Renderizza la pagina delle impostazioni
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Impostazioni Scrivania Collaborativa</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('scrivania_settings');
                do_settings_sections('scrivania-settings');
                submit_button();
                ?>
            </form>
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2>Informazioni sul plugin</h2>
                <p>Questo plugin integra il Tool Scrivania Collaborativa con Pusher per consentire interazioni in tempo reale tra più utenti.</p>
                <p>Per utilizzare correttamente il plugin, è necessario:</p>
                <ol>
                    <li>Creare un account gratuito su <a href="https://pusher.com/" target="_blank">Pusher.com</a></li>
                    <li>Creare una nuova app Pusher e inserire le credenziali nelle impostazioni sopra</li>
                    <li>Nella dashboard di Pusher, abilitare "Client Events" e "Authorized Connections"</li>
                </ol>
                <p>Per assistenza, contatta il supporto tecnico.</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Informazioni sulla sezione
     */
    public function section_info() {
        echo '<p>Inserisci le credenziali Pusher per abilitare le funzionalità collaborative della Scrivania. È necessario avere un account su <a href="https://pusher.com/" target="_blank">Pusher.com</a>.</p>';
    }
    
    /**
     * Callback per App ID
     */
    public function app_id_callback() {
        $value = get_option('scrivania_pusher_app_id', '');
        echo '<input type="text" name="scrivania_pusher_app_id" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    /**
     * Callback per App Key
     */
    public function app_key_callback() {
        $value = get_option('scrivania_pusher_app_key', '');
        echo '<input type="text" name="scrivania_pusher_app_key" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    /**
     * Callback per App Secret
     */
    public function app_secret_callback() {
        $value = get_option('scrivania_pusher_app_secret', '');
        echo '<input type="text" name="scrivania_pusher_app_secret" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    /**
     * Callback per Cluster
     */
    public function cluster_callback() {
        $value = get_option('scrivania_pusher_cluster', 'eu');
        ?>
        <select name="scrivania_pusher_cluster">
            <option value="us1" <?php selected($value, 'us1'); ?>>us1</option>
            <option value="us2" <?php selected($value, 'us2'); ?>>us2</option>
            <option value="eu" <?php selected($value, 'eu'); ?>>eu</option>
            <option value="ap1" <?php selected($value, 'ap1'); ?>>ap1</option>
            <option value="ap2" <?php selected($value, 'ap2'); ?>>ap2</option>
            <option value="ap3" <?php selected($value, 'ap3'); ?>>ap3</option>
            <option value="ap4" <?php selected($value, 'ap4'); ?>>ap4</option>
            <option value="mt1" <?php selected($value, 'mt1'); ?>>mt1</option>
            <option value="sa1" <?php selected($value, 'sa1'); ?>>sa1</option>
        </select>
        <?php
    }
    
    /**
     * Callback per Debug Mode
     */
    public function debug_callback() {
        $value = get_option('scrivania_pusher_debug', '0');
        echo '<input type="checkbox" name="scrivania_pusher_debug" value="1" ' . checked('1', $value, false) . ' /> ';
        echo 'Abilita modalità debug (solo per sviluppo)';
    }
    
    /**
     * Aggiunge lo script con le configurazioni Pusher
     */
    public function enqueue_pusher_config() {
        // Verifica se siamo nella pagina del tool Scrivania
        if (is_page('tool-scrivania') || is_page('invito-scrivania')) {
            // Ottieni le credenziali
            $app_key = get_option('scrivania_pusher_app_key', '');
            $cluster = get_option('scrivania_pusher_cluster', 'eu');
            $debug = get_option('scrivania_pusher_debug', '0') === '1';
            
            // Se le credenziali non sono impostate, non fare nulla
            if (empty($app_key)) return;
            
            // Includi Pusher SDK
            wp_enqueue_script(
                'pusher-js',
                'https://js.pusher.com/7.0/pusher.min.js',
                array(),
                '7.0',
                true
            );
            
            // Aggiungi lo script di configurazione
            wp_enqueue_script(
                'scrivania-pusher-config',
                plugin_dir_url(dirname(__FILE__)) . 'js/pusher-config.js',
                array('pusher-js'),
                '1.0',
                true
            );
            
            // Passa le configurazioni come variabili
            wp_localize_script(
                'scrivania-pusher-config',
                'scrivaniaPusherConfig',
                array(
                    'app_key' => $app_key,
                    'cluster' => $cluster,
                    'auth_endpoint' => rest_url('scrivania/v1/pusher-auth'),
                    'nonce' => wp_create_nonce('wp_rest'),
                    'debug' => $debug
                )
            );
            
            // Script React dell'app Scrivania (se presente)
            if (file_exists(plugin_dir_path(dirname(__FILE__)) . 'js/app/scrivania-app.js')) {
                wp_enqueue_script(
                    'scrivania-app',
                    plugin_dir_url(dirname(__FILE__)) . 'js/app/scrivania-app.js',
                    array('pusher-js', 'scrivania-pusher-config', 'wp-api'),
                    '1.0',
                    true
                );
                
                // CSS dell'app
                if (file_exists(plugin_dir_path(dirname(__FILE__)) . 'js/app/scrivania-assets/index.css')) {
                    wp_enqueue_style(
                        'scrivania-app-style',
                        plugin_dir_url(dirname(__FILE__)) . 'js/app/scrivania-assets/index.css',
                        array(),
                        '1.0'
                    );
                }
            }
        }
    }
}