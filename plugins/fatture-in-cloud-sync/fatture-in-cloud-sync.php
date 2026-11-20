<?php
/**
 * Plugin Name: PMPro → Fatture in Cloud Sync
 * Description: Documenti /TEST e coda per acquisti e rinnovi autorizzati PMPro PayPal Sandbox, senza invio allo SDI.
 * Version: 0.8.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/class-pmpro-fic-admin.php';
require_once __DIR__ . '/includes/class-pmpro-fic-test.php';
require_once __DIR__ . '/includes/class-pmpro-fic-orders.php';
require_once __DIR__ . '/includes/class-pmpro-fic-billing.php';
require_once __DIR__ . '/includes/class-pmpro-fic-paypal-recovery.php';
require_once __DIR__ . '/includes/class-pmpro-fic-payments.php';
require_once __DIR__ . '/includes/class-pmpro-fic-renewals.php';
require_once __DIR__ . '/includes/class-pmpro-fic-plan-rules.php';
require_once __DIR__ . '/includes/class-pmpro-fic-initial-pair.php';
require_once __DIR__ . '/includes/class-pmpro-fic-automation.php';
require_once __DIR__ . '/includes/class-pmpro-fic-webhook-guard.php';
require_once __DIR__ . '/includes/class-pmpro-fic-diagnostics.php';
new PMPro_FIC_Billing();
new PMPro_FIC_Webhook_Guard();
new PMPro_FattureInCloud_Sync();
