<?php
/**
 * Plugin Name: Scrivania Collaborativa API
 * Description: API e integrazione con Pusher per il tool Scrivania
 * Version: 1.0
 * Author: Emiliano Pallini
 */

defined('ABSPATH') || exit;

// Includi la libreria Pusher (versione bundle)
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Includi la classe principale del plugin
require_once plugin_dir_path(__FILE__) . 'includes/class-scrivania-api.php';

// Includi le funzioni AJAX
require_once plugin_dir_path(__FILE__) . 'includes/class-scrivania-ajax.php';

// Inizializza il plugin
$scrivania_api = new Scrivania_Collaborativa_API();

// Attivazione hook
register_activation_hook(__FILE__, array('Scrivania_Collaborativa_API', 'activate'));