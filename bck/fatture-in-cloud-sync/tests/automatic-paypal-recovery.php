<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/automatic-paypal-recovery.php */
define('PMPRO_FIC_AUTOMATION_BOOTSTRAP', true);
define('PMPRO_FIC_PAYPAL_BOOTSTRAP', true);
require __DIR__ . '/automation.php';
require __DIR__ . '/paypal-recovery.php';

function automatic_recovery_fixture($enable_recovery = true) {
    global $orders, $order_meta, $settings_store, $paypal_subscription, $paypal_transactions, $paypal_calls, $wpdb;
    $auto = automation_fixture();
    $orders[55] = array_merge($orders[42], ['id'=>55, 'code'=>'D47964C9B4', 'total'=>'841.80',
        'subscription_transaction_id'=>'I-8TG2LLTDSLSW', 'payment_transaction_id'=>'']);
    $order_meta[55] = $order_meta[42];
    $order_meta[55][PMPro_FIC_Billing::META]['order_id'] = 55;
    $order_meta[55][PMPro_FIC_Billing::META]['captured_at'] = '2026-10-14 12:37:00';
    unset($orders[42], $order_meta[42], $GLOBALS['paypal_on_transactions']);
    $paypal_subscription = ['id'=>'I-8TG2LLTDSLSW', 'create_time'=>'2026-10-14T10:38:03Z'];
    $row = payment_row(); $row['id'] = '844753021M0826123'; $row['time'] = '2026-10-14T10:38:30Z';
    $row['amount_with_breakdown']['gross_amount']['value'] = '841.80';
    $paypal_transactions = ['transactions'=>[$row], 'total_pages'=>1, 'total_items'=>1, 'links'=>[]];
    $paypal_calls = [];
    $wpdb = new RecoveryDatabase();
    if ($enable_recovery) {
        $result = $auto->dispatch('auto_enable', ['account_id'=>'9', 'confirm_test'=>'1', 'recover_missing_payment'=>'1']);
        check(is_array($result) && $settings_store[PMPro_FIC_Automation::CONFIG]['recover_missing_payment'], 'Verified recovery requires explicit opt-in');
    }
    return $auto;
}
function job55() { return get_option('pmpro_fic_job_55', []); }
function birth55($auto) { $auto->on_new_order(new MemberOrder(55)); }
function run55($auto) {
    unset($GLOBALS['events'][PMPro_FIC_Automation::HOOK . ':[55]']);
    $GLOBALS['admin'] = false; $GLOBALS['admin_id'] = 0; $auto->process(55);
    $GLOBALS['admin'] = true; $GLOBALS['admin_id'] = 1;
}
function automatic_document_response() {
    account_response();
    $GLOBALS['totals_responses'] = [response(['amount_net'=>690,'amount_vat'=>151.80,'amount_gross'=>841.80,'amount_due'=>841.80])];
    $GLOBALS['post_responses'] = [document_response(155,841.80)];
}
function no_recovery_effects($label) {
    check(posts() === 0 && $GLOBALS['wpdb']->queries === 0 && $GLOBALS['orders'][55]['payment_transaction_id'] === '', $label);
}

$auto = automatic_recovery_fixture(false); birth55($auto); run55($auto);
check(job55()['state'] === 'waiting' && !$paypal_calls, 'Default opt-out retains 0.5.0 behavior without PayPal calls');
no_recovery_effects('Opt-out never changes order or invoices');
$auto = automatic_recovery_fixture(); $orders[55]['payment_transaction_id'] = '844753021M0826123'; birth55($auto); automatic_document_response(); run55($auto);
check(job55()['state'] === 'created' && !$paypal_calls && $wpdb->queries === 0 && posts() === 1, 'Normal paid orders retain fast path without unnecessary PayPal recovery calls');
$auto = automatic_recovery_fixture(); birth55($auto); automatic_document_response(); run55($auto);
check(job55()['state'] === 'created' && job55()['payment_recovered'] && job55()['payment_id'] === '844753021M0826123', 'Missing initial payment ID is recovered and invoice is created by same worker');
check(count($paypal_calls) === 4 && $wpdb->queries === 1 && posts() === 1 && count($orders) === 1, 'Only reads PayPal twice and updates one existing order before one invoice POST');
check($orders[55]['payment_transaction_id'] === '844753021M0826123' && $orders[55]['status'] === 'success' && $orders[55]['total'] === '841.80', 'Verified recovery preserves total and paid status');
$audit = $order_meta[55][PMPro_FIC_PayPal_Recovery::META];
check($audit['origin'] === 'automation' && $audit['admin_id'] === 0 && $audit['status'] === 'updated', 'Recovery is audited as anonymous cron work');
$payload = json_decode($created_requests[0][1]['body'], true);
check($payload['data']['payments_list'][0]['paid_date'] === '2026-10-14' && $payload['data']['payments_list'][0]['amount'] === 841.8
    && $payload['data']['entity']['vat_number'] === '12345678903', 'Invoice uses verified payment date, amount and immutable company details');
run55($auto); check(posts() === 1 && $wpdb->queries === 1 && count($paypal_calls) === 4, 'Replayed worker never repeats payment recovery or invoice creation');
ob_start(); $auto->render(null,''); $html = ob_get_clean();
check(str_contains($html, 'ID incasso recuperato: 844753021M0826123') && !str_contains($html,'fake-paypal-secret'), 'Backend explains automatic recovery without credentials');

// Existing 0.5.0 jobs require an explicit retry when recovery is enabled.
$auto = automatic_recovery_fixture(false); birth55($auto); run55($auto);
unset($settings_store[PMPro_FIC_Automation::CONFIG]['recover_missing_payment']);
$legacy = job55(); unset($legacy['recover_missing_payment']); $settings_store['pmpro_fic_job_55'] = $legacy;
run55($auto); check(job55()['state'] === 'waiting' && !$paypal_calls, 'Legacy configuration and jobs without new field remain opt-out');
$auto->dispatch('auto_enable', ['account_id'=>'9','confirm_test'=>'1','recover_missing_payment'=>'1']);
ob_start(); $auto->render(null,''); $html = ob_get_clean();
check(str_contains($html, 'value="auto_retry"'), 'Queued legacy job offers Riprova immediately after recovery authorization changes');
run55($auto);
check(job55()['state'] === 'blocked' && !$paypal_calls, 'Changed recovery permission does not silently migrate old job');
check(is_array($auto->dispatch('auto_retry', ['order_id'=>'55'])) && job55()['recover_missing_payment'], 'Admin retry binds existing job to explicit new recovery permission');
automatic_document_response(); run55($auto); check(job55()['state'] === 'created' && posts() === 1, 'Existing waiting job can complete after reviewed retry');

$auto = automatic_recovery_fixture(); $orders[55]['status'] = 'token'; birth55($auto); run55($auto);
check(job55()['state'] === 'waiting' && !$paypal_calls, 'Recovery never marks token order paid even if remote fixture has a completed payment');
no_recovery_effects('Unpaid PMPro order is never changed');
$auto = automatic_recovery_fixture(); birth55($auto); $paypal_transactions['transactions'] = []; $paypal_transactions['total_items'] = 0;
for ($i=0;$i<6;$i++) run55($auto);
check(job55()['state'] === 'blocked' && job55()['attempts'] === 6 && count($paypal_calls) === 12, 'Remote transaction visibility is retried within bounded budget');
no_recovery_effects('Unavailable completed transaction cannot be associated or billed');
$auto = automatic_recovery_fixture(); birth55($auto); $paypal_subscription = new WP_Error('api', 'Do not expose provider detail'); run55($auto);
check(job55()['state'] === 'waiting' && !str_contains(job55()['message'], 'provider detail'), 'PayPal read failures are retried with sanitized message');
no_recovery_effects('API failure produces no mutations');

foreach (['PENDING'=>'waiting', 'REFUNDED'=>'blocked', 'PARTIALLY_REFUNDED'=>'blocked'] as $state=>$expected) {
    $auto = automatic_recovery_fixture(); birth55($auto); $paypal_transactions['transactions'][0]['status'] = $state; run55($auto);
    check(job55()['state'] === $expected, 'Recovery handles remote ' . $state . ' safely'); no_recovery_effects('No writes for ' . $state);
}
foreach ([['value','841.81'], ['currency_code','USD']] as [$key,$value]) {
    $auto = automatic_recovery_fixture(); birth55($auto); $paypal_transactions['transactions'][0]['amount_with_breakdown']['gross_amount'][$key] = $value; run55($auto);
    check(job55()['state'] === 'blocked', 'Recovery refuses mismatched ' . $key); no_recovery_effects('No writes for mismatched ' . $key);
}
$auto = automatic_recovery_fixture(); birth55($auto); $paypal_transactions['transactions'][] = array_merge($paypal_transactions['transactions'][0], ['id'=>'ANOTHERPAYMENT123']); $paypal_transactions['total_items'] = 2; run55($auto);
check(job55()['state'] === 'blocked', 'Multiple completed payments require manual review'); no_recovery_effects('Ambiguous payments are not billed');
$auto = automatic_recovery_fixture(); birth55($auto); $paypal_transactions['total_pages'] = 2; run55($auto);
check(job55()['state'] === 'blocked', 'Partial remote list cannot authorize ID association'); no_recovery_effects('Incomplete list produces no mutations');
$auto = automatic_recovery_fixture(); birth55($auto); $paypal_subscription['id'] = 'I-OTHER123456'; run55($auto);
check(job55()['state'] === 'blocked', 'Wrong subscription response is rejected'); no_recovery_effects('Wrong subscription produces no mutations');
$auto = automatic_recovery_fixture(); birth55($auto); $orders[56] = array_merge($orders[55], ['id'=>56,'subscription_transaction_id'=>'I-OTHER123456','payment_transaction_id'=>'844753021M0826123']); run55($auto);
check(job55()['state'] === 'blocked', 'Payment already used by another order is rejected'); no_recovery_effects('Already associated payment is not duplicated');

foreach (['pause','permission','credentials','refund','snapshot'] as $change) {
    $auto = automatic_recovery_fixture(); birth55($auto);
    $paypal_on_transactions = function () use ($change, $auto) {
        if ($change === 'pause') $auto->invalidate();
        elseif ($change === 'permission') $GLOBALS['settings_store'][PMPro_FIC_Automation::CONFIG]['recover_missing_payment'] = false;
        elseif ($change === 'credentials') $GLOBALS['settings_store']['pmpro_paypal_client_secret_sandbox'] = 'changed';
        elseif ($change === 'refund') $GLOBALS['orders'][55]['status'] = 'refunded';
        else $GLOBALS['order_meta'][55][PMPro_FIC_Billing::META]['data']['street'] = 'Changed';
    };
    run55($auto); check(job55()['state'] === 'blocked', 'Recovery stops on concurrent ' . $change); no_recovery_effects('No writes after concurrent ' . $change);
}
$auto = automatic_recovery_fixture(); birth55($auto);
$paypal_on_transactions = function () { if (count($GLOBALS['paypal_calls']) >= 4) $GLOBALS['paypal_transactions']['transactions'][0]['status'] = 'REFUNDED'; };
run55($auto); check(job55()['state'] === 'blocked', 'Second remote verification notices a new refund'); no_recovery_effects('Refund between verifications cannot be billed');
$auto = automatic_recovery_fixture(); birth55($auto);
$wpdb->before_update = function ($db) { $db->exec("UPDATE wp_pmpro_membership_orders SET status = 'refunded' WHERE id = 55"); };
run55($auto); check(job55()['state'] === 'attention' && $orders[55]['status'] === 'refunded' && $orders[55]['payment_transaction_id'] === '' && posts() === 0, 'Atomic recovery rejects late DB status change without an invoice');

$auto = automatic_recovery_fixture(); birth55($auto); $settings_store['pmpro_fic_paypal_recovery_lock_55'] = 'other-worker'; run55($auto);
check(job55()['state'] === 'waiting' && posts() === 0 && $wpdb->queries === 0, 'Concurrent payment recovery keeps queue waiting');
$auto = automatic_recovery_fixture(); birth55($auto);
$paypal_on_transactions = function () { $GLOBALS['orders'][55]['payment_transaction_id'] = '844753021M0826123'; };
run55($auto); check(job55()['state'] === 'waiting' && $wpdb->queries === 0 && posts() === 0, 'Gateway assigning ID during API read is preserved and retried');
unset($paypal_on_transactions); automatic_document_response(); run55($auto);
check(job55()['state'] === 'created' && posts() === 1, 'Next worker uses concurrently assigned gateway ID without reassociation');

$auto = automatic_recovery_fixture(); birth55($auto); automatic_document_response(); $post_responses = [new WP_Error('network','Simulated timeout')]; run55($auto);
check(job55()['state'] === 'attention' && $orders[55]['payment_transaction_id'] === '844753021M0826123' && posts() === 1, 'Uncertain FIC POST after verified recovery never repeats payment association');
error_is($auto->dispatch('auto_retry',['order_id'=>'55']), 'uncertain');
run55($auto); check(posts() === 1 && $wpdb->queries === 1, 'Invoice timeout cannot trigger a second invoice or recovery');

$auto = automatic_recovery_fixture();
$service = new PMPro_FIC_Test_Orders([$plugin,'background_context']);
error_is($service->reconcile_payment(55,function(){ return false; }), 'forbidden');
no_recovery_effects('False authorization guard is rejected');
// Missing/null/wrong-type transaction lists are retryable observations, never payment evidence.
foreach ([[], ['transactions'=>null], ['transactions'=>'unavailable'], null] as $incomplete) {
    $auto = automatic_recovery_fixture(); birth55($auto); $valid = $paypal_transactions;
    $paypal_transactions = $incomplete; run55($auto);
    check(job55()['state'] === 'waiting' && job55()['attempts'] === 1, 'Incomplete list schedules bounded retry');
    no_recovery_effects('Incomplete list never updates payment or posts a document');
    $paypal_transactions = $valid; automatic_document_response(); run55($auto);
    check(job55()['state'] === 'created' && posts() === 1 && $wpdb->queries === 1, 'Later verified complete response creates exactly one document');
    run55($auto); check(posts() === 1, 'Repeated worker after eventual success does not duplicate');
}
$auto = automatic_recovery_fixture(); birth55($auto); $paypal_transactions = ['transactions'=>null];
for ($i=0;$i<6;$i++) run55($auto);
check(job55()['state'] === 'blocked' && job55()['attempts'] === 6, 'Persistent incomplete responses exhaust retry budget');
no_recovery_effects('Exhausted budget never authorizes an unverified payment');
$auto = automatic_recovery_fixture(); birth55($auto); $paypal_transactions['transactions'][0]['status'] = 'UNKNOWN'; run55($auto);
check(job55()['state'] === 'blocked', 'Unknown payment state stays a hard block');
no_recovery_effects('Unknown state cannot be mistaken for temporary absence');

echo "PASS: {$checks} automatic PayPal recovery checks; PayPal/WordPress simulated, conditional SQL on SQLite, no real payments or documents.\n";
