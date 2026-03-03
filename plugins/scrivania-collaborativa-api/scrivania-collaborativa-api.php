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
            $plugin_path = plugin_dir_path(__FILE__);

            // Carica Pusher SOLO se abbiamo un vendor Composer completo.
            // Il file Pusher.php moderno dipende da PSR Log + Guzzle: includerlo “a mano”
            // senza vendor completo può causare fatal error (errore critico WordPress).
            $composer_marker = $plugin_path . 'vendor/composer/autoload_real.php';
            $autoload = $plugin_path . 'vendor/autoload.php';
            if (file_exists($composer_marker) && file_exists($autoload)) {
                require_once $autoload;
                return class_exists('\\Pusher\\Pusher');
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
    const DB_VERSION = 2;
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
        
        // Run schema upgrades early
        add_action('plugins_loaded', [$this, 'maybe_upgrade_schema'], 1);

        // Initialize the plugin
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Upgrade DB schema if needed.
     */
    public function maybe_upgrade_schema() {
        $current = intval(get_option('scrivania_db_version', 1));
        if ($current >= self::DB_VERSION) {
            return;
        }

        global $wpdb;
        $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
        $table_invites = $wpdb->prefix . 'scrivania_invitati';

        // Helper: add column if missing
        $column_exists = function ($table_name, $column_name) use ($wpdb) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table_name,
                $column_name
            );
            return intval($wpdb->get_var($sql)) > 0;
        };

        // Helper: add index if missing (by name)
        $index_exists = function ($table_name, $index_name) use ($wpdb) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                $table_name,
                $index_name
            );
            return intval($wpdb->get_var($sql)) > 0;
        };

        // Sessions: state_version
        if (!$column_exists($table_sessions, 'state_version')) {
            $wpdb->query("ALTER TABLE {$table_sessions} ADD COLUMN state_version BIGINT(20) UNSIGNED NOT NULL DEFAULT 1");
        }

        // Invites: user binding + role + status + timestamps + token hash + resend tracking
        $invite_columns = [
            'invitato_user_id' => "BIGINT(20) UNSIGNED NULL DEFAULT NULL",
            'role' => "VARCHAR(20) NOT NULL DEFAULT 'viewer'",
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'verified_at' => "DATETIME NULL DEFAULT NULL",
            'claimed_at' => "DATETIME NULL DEFAULT NULL",
            'consumed_at' => "DATETIME NULL DEFAULT NULL",
            'revoked_at' => "DATETIME NULL DEFAULT NULL",
            'token_hash' => "CHAR(64) NULL DEFAULT NULL",
            'last_sent_at' => "DATETIME NULL DEFAULT NULL",
            'resend_count' => "SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0",
        ];

        foreach ($invite_columns as $name => $ddl) {
            if (!$column_exists($table_invites, $name)) {
                $wpdb->query("ALTER TABLE {$table_invites} ADD COLUMN {$name} {$ddl}");
            }
        }

        // Indexes
        if (!$index_exists($table_invites, 'invitato_user_id')) {
            $wpdb->query("ALTER TABLE {$table_invites} ADD KEY invitato_user_id (invitato_user_id)");
        }
        if (!$index_exists($table_invites, 'status')) {
            $wpdb->query("ALTER TABLE {$table_invites} ADD KEY status (status)");
        }
        if (!$index_exists($table_invites, 'sessione_user')) {
            $wpdb->query("ALTER TABLE {$table_invites} ADD KEY sessione_user (sessione_id, invitato_user_id)");
        }

        update_option('scrivania_db_version', self::DB_VERSION);
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

        // Mark DB version
        update_option('scrivania_db_version', self::DB_VERSION);
    }
}

// Initialize the plugin
Scrivania_Collaborativa_API_Loader::get_instance();