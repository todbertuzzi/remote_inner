<?php
/**
 * Plugin Name: Scrivania Collaborativa API
 * Description: API e integrazione con Pusher per il tool Scrivania
 * Version: 1.0
 * Author: Emiliano Pallini
 */

defined('ABSPATH') || exit;

// Make sure all required directories exist
function scrivania_check_directories() {
    $dirs = [
        plugin_dir_path(__FILE__) . 'vendor',
        plugin_dir_path(__FILE__) . 'includes',
        plugin_dir_path(__FILE__) . 'js'
    ];
    
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
scrivania_check_directories();

// Includi la classe principale del plugin
if (file_exists(plugin_dir_path(__FILE__) . 'includes/class-scrivania-api.php')) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-scrivania-api.php';
}

// Includi le funzioni AJAX
if (file_exists(plugin_dir_path(__FILE__) . 'includes/class-scrivania-ajax.php')) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-scrivania-ajax.php';
}

// We'll add a simplified Pusher loader instead of relying on the vendor/autoload.php
// This way we can activate the plugin without the Pusher library and then add it later
if (!class_exists('\\Pusher\\Pusher')) {
    class Pusher_Loader {
        public static function initialize() {
            // Check if Pusher files exist
            $pusher_file = plugin_dir_path(__FILE__) . 'vendor/pusher/pusher-php-server/src/Pusher.php';
            
            if (file_exists($pusher_file)) {
                // If Pusher exists, load the classes
                if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
                    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
                } else {
                    // Manual loading of essential Pusher files
                    $base_path = plugin_dir_path(__FILE__) . 'vendor/pusher/pusher-php-server/src/';
                    $files = ['Pusher.php', 'PusherException.php', 'PusherInterface.php', 'PusherCrypto.php', 'ApiErrorException.php', 'Webhook.php'];
                    
                    foreach ($files as $file) {
                        if (file_exists($base_path . $file)) {
                            require_once $base_path . $file;
                        }
                    }
                }
                return true;
            }
            return false;
        }
    }
    
    // Only initialize Pusher if the files exist
    Pusher_Loader::initialize();
}

/**
 * Main plugin class that handles the initialization
 */
class Scrivania_Collaborativa_API_Loader {
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Add activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);
        
        // Initialize the plugin
        add_action('plugins_loaded', [$this, 'init']);
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Initialize the main API class if it exists
        if (class_exists('Scrivania_Collaborativa_API')) {
            new Scrivania_Collaborativa_API();
        }
    }
    
    /**
     * Activation function
     */
    public function activate() {
        // Create necessary tables
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Sessions table
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
        
        // Invites table
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
        
        // Run the SQL
        if (function_exists('dbDelta')) {
            dbDelta($sql_sessions);
            dbDelta($sql_invites);
        } else {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_sessions);
            dbDelta($sql_invites);
        }
        
        // Add default options
        add_option('scrivania_pusher_app_id', '');
        add_option('scrivania_pusher_app_key', '');
        add_option('scrivania_pusher_app_secret', '');
        add_option('scrivania_pusher_cluster', 'eu');
        add_option('scrivania_pusher_debug', '0');
    }
}

// Initialize the plugin
Scrivania_Collaborativa_API_Loader::get_instance();