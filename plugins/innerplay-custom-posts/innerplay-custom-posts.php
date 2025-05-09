<?php
/**
 * Plugin Name: Innerplay - Custom Post Types
 * Description: Registra i Custom Post Type per la piattaforma Innerplay.
 * Version: 1.0
 * Author: Emiliano Pallini
 */

defined('ABSPATH') || exit;

// Include i file dei singoli post type
require_once plugin_dir_path(__FILE__) . 'post-types/gioco.php';
// Qui puoi aggiungere altri CPT in futuro:
// require_once plugin_dir_path(__FILE__) . 'post-types/corso.php';
// require_once plugin_dir_path(__FILE__) . 'post-types/mazzo.php';
