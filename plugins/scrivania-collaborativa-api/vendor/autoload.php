<?php
/**
 * Autoload semplificato per la libreria Pusher PHP
 */

// Define the base path to the Pusher library
$pusher_base_path = __DIR__ . '/pusher/pusher-php-server/src/';

// Check if the base directory exists
if (!file_exists($pusher_base_path)) {
    return;
}

// List of Pusher files to autoload
$pusher_files = [
    'Pusher.php',
    'PusherException.php',
    'PusherInterface.php',
    'PusherCrypto.php',
    'ApiErrorException.php',
    'Webhook.php',
    'PusherInstance.php'
];

// Load each file if it exists
foreach ($pusher_files as $file) {
    $file_path = $pusher_base_path . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    }
}

// Register a simple autoloader for the Pusher namespace
spl_autoload_register(function($class) use ($pusher_base_path) {
    // Only handle classes in the Pusher namespace
    if (strpos($class, 'Pusher\\') === 0) {
        // Get the class name without the namespace
        $class_name = substr($class, strlen('Pusher\\'));
        $file_path = $pusher_base_path . $class_name . '.php';
        
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
});