<?php
/** Local review only: runs the supplied simulated harness, then extra integration cases. */
$root = $argv[1] ?? '/tmp/fic-review-072/fatture-in-cloud-sync';
require rtrim($root, '/') . '/tests/webhook-guard.php';

function review_observe(string $case, $response, $core): void {
    echo json_encode([
        'case' => $case,
        'guard_http' => $response instanceof WP_REST_Response ? $response->status : null,
        'core' => $core,
        'orders' => count($GLOBALS['orders']),
        'invoice_emails' => $GLOBALS['invoice_emails'],
        'outcome' => outcome(),
        'checkout_payment' => $GLOBALS['orders'][42]['payment_transaction_id'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

// The signature helper is simulated as accepting the delivery, as in the supplied tests.
$guard = guard_fixture();
unset($settings_store['pmpro_paypal_client_secret_sandbox']);
[$response, $core] = deliver($guard);
review_observe('missing credentials, signature accepted', $response, $core);

// Same injected exception as the supplied suite, but execute the core afterwards too.
$guard = guard_fixture();
$GLOBALS['on_sale_request'] = function () { throw new RuntimeException('simulated defect'); };
[$response, $core] = deliver($guard);
review_observe('exception after candidate selection', $response, $core);

// Subscription creation is 23h50m after checkout; sale is 30m03s after subscription creation.
// This meets both binding checks, yet sale/checkout exceeds the deferral window.
foreach ([false, true] as $incomplete) {
    $guard = guard_fixture();
    $orders[42]['timestamp'] = strtotime('2026-10-13T08:00:00Z');
    $paypal_subscription['create_time'] = '2026-10-14T07:50:00Z';
    $sale_responses = [$incomplete ? ['status' => 200, 'body' => '{}'] : remote_sale()];
    [$response, $core] = deliver($guard);
    review_observe('near window boundary, API ' . ($incomplete ? 'incomplete' : 'complete'), $response, $core);
}

// Exactly 24 hours sale/checkout: the strict deferral bound and inclusive binding bound differ.
foreach ([false, true] as $incomplete) {
    $guard = guard_fixture();
    $orders[42]['timestamp'] = strtotime('2026-10-13T08:20:03Z');
    $sale_responses = [$incomplete ? ['status' => 200, 'body' => '{}'] : remote_sale()];
    [$response, $core] = deliver($guard);
    review_observe('exactly 24h, API ' . ($incomplete ? 'incomplete' : 'complete'), $response, $core);
}

// Local repair works without redelivery, IF the event has actually been scheduled.
$guard = guard_fixture();
fail_final_audit($wpdb);
[$response, $core] = deliver($guard);
$queued = count($events);
storage_recovers($wpdb);
$sale_responses = [remote_sale()];
$guard->cron_repair(42, 'NEWSALE12345', 1);
echo json_encode(['case' => 'local audit repair', 'initial_http' => $response->status,
    'queued_events' => $queued, 'journal_status' => $settings_store['pmpro_fic_paypal_recovery_42']['status'],
    'outcome' => outcome(), 'orders' => count($orders)]) . "\n";
