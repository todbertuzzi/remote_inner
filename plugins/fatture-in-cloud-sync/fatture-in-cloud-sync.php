<?php
/**
 * Plugin Name: PMPro → Fatture in Cloud Sync
 * Description: Collaudo Fatture in Cloud, documenti manuali e coda automatica per primi acquisti PMPro PayPal Sandbox, senza invio allo SDI.
 * Version: 0.5.5
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/class-pmpro-fic-test.php';
require_once __DIR__ . '/includes/class-pmpro-fic-orders.php';
require_once __DIR__ . '/includes/class-pmpro-fic-billing.php';
require_once __DIR__ . '/includes/class-pmpro-fic-paypal-recovery.php';
require_once __DIR__ . '/includes/class-pmpro-fic-automation.php';
require_once __DIR__ . '/includes/class-pmpro-fic-diagnostics.php';
new PMPro_FIC_Billing();
new PMPro_FattureInCloud_Sync();
