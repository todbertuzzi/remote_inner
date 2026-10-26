<?php
/** Local-only review: all payments, orders and emails are simulated by the supplied harness. */
$root = $argv[1] ?? '/tmp/fic-review-073/fatture-in-cloud-sync';
require rtrim($root, '/') . '/tests/webhook-guard.php';

// Unlike the original get_col stub, respect the query's ordering and LIMIT.
// Fixture options are inserted in chronological order, equivalent here to option_id.
class ReviewLimitedGuardDatabase extends GuardDatabase {
    public function get_col($sql) {
        $names = parent::get_col($sql);
        if (str_contains($sql, 'ORDER BY option_id DESC')) $names = array_reverse($names);
        if (preg_match('/LIMIT (\d+)/', $sql, $match)) $names = array_slice($names, 0, (int) $match[1]);
        return $names;
    }
}

// A failed final audit, with both event log and cron persistence unavailable.
$guard = guard_fixture();
$wpdb = new ReviewLimitedGuardDatabase();
$cron_fail = true;
fail_final_audit($wpdb);
[$response, $core] = deliver($guard);
storage_recovers($wpdb);
echo json_encode(['case' => 'pending audit immediately after recovery', 'http' => $response->status,
    'jobs' => count($events), 'pending' => PMPro_FIC_Webhook_Guard::pending_audits()]) . "\n";

// One hundred newer, already completed journals. These should not hide order 42.
$template = $settings_store['pmpro_fic_paypal_recovery_42'];
for ($id = 100; $id < 200; $id++) {
    $sale = 'NEWSALE' . $id;
    $orders[$id] = array_merge($orders[42], ['id' => $id, 'code' => 'ORDER' . $id, 'payment_transaction_id' => $sale]);
    $journal = $template;
    $journal['status'] = 'updated';
    $journal['order_id'] = $id;
    $journal['payment']['payment_id'] = $sale;
    $settings_store['pmpro_fic_paypal_recovery_' . $id] = $journal;
    $order_meta[$id][PMPro_FIC_PayPal_Recovery::META] = $journal;
}
unset($settings_store[PMPro_FIC_Webhook_Guard::LOG]);
$pending = PMPro_FIC_Webhook_Guard::pending_audits();
[$done, $left] = PMPro_FIC_Webhook_Guard::resume_pending();
echo json_encode(['case' => 'same pending audit after 100 newer completed journals',
    'pending' => $pending, 'resumed' => $done, 'reported_remaining' => $left,
    'actual_order_42_journal' => $settings_store['pmpro_fic_paypal_recovery_42']['status']]) . "\n";

// The original stub ignores LIMIT and reports the same data as recoverable.
$wpdb = new GuardDatabase();
echo json_encode(['case' => 'same data with original unlimited stub',
    'pending' => PMPro_FIC_Webhook_Guard::pending_audits()]) . "\n";
