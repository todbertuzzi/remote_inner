<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/webhook-guard.php
 * Simulates PMPro 3.8.9 + addon PayPal. After the guard, the REAL PMPro 3.8.9 handler
 * (tests/fixtures/pmpro-3.8.9-recurring-payment-handler.php, verbatim) is executed exactly as the addon's
 * pmpro_paypal_handle_sale_completed() would: the assertions are on the orders and e-mails it produces,
 * not on a lookup. WordPress, PayPal and the PMPro data layer are simulated; SQL runs on SQLite.
 */
define('PMPRO_FIC_PAYPAL_BOOTSTRAP', true);
require_once __DIR__ . '/paypal-recovery.php';

class WP_REST_Response {
    public $headers = [];
    public function __construct(public $data = null, public $status = 200) {}
    public function header($name, $value) { $this->headers[$name] = $value; }
}
function do_action($hook, ...$args) { $GLOBALS['actions'][] = $hook; }
function pmpro_paypal_verify_webhook($request) { $GLOBALS['signature_checks']++; return $GLOBALS['signature_ok']; }
function wp_next_scheduled($hook, $args = []) { return $GLOBALS['events'][$hook . ':' . json_encode($args)] ?? false; }
function wp_schedule_single_event($at, $hook, $args = []) { if (!empty($GLOBALS['cron_fail'])) return false; $GLOBALS['events'][$hook . ':' . json_encode($args)] = $at; return true; }
class PMProEmail { public function sendInvoiceEmail($user, $order) { $GLOBALS['invoice_emails']++; } }

/** PMPro_Subscription as the core uses it: it exists only once an order of the subscription is `success`. */
class PMPro_Subscription {
    public function __construct(private string $sid) {}
    public static function get_subscription_from_subscription_transaction_id($sid, $gateway, $env) {
        foreach ($GLOBALS['orders'] as $id => $row) {
            $o = new MemberOrder($id);
            if ($o->subscription_transaction_id === $sid && $o->status === 'success') return new self($sid);
        }
        return null;
    }
    public function get_user_id() { return 10; }
    public function get_id() { return 1; }
    public function get_membership_level_id() { return 4; }
    public function get_orders(array $args = []) {
        $found = [];
        foreach ($GLOBALS['orders'] as $id => $row) {
            $o = new MemberOrder($id);
            if ($o->subscription_transaction_id !== $this->sid) continue;
            foreach (['status', 'payment_transaction_id'] as $key) if (array_key_exists($key, $args) && (string) $o->$key !== (string) $args[$key]) continue 2;
            $found[] = $o;
            if (count($found) >= ($args['limit'] ?? 100)) break;
        }
        return $found;
    }
}
require __DIR__ . '/fixtures/pmpro-3.8.9-recurring-payment-handler.php';

class WebhookRequest {
    public function __construct(public $body, public $method = 'POST', public $route = '/pmpro-paypal/v1/webhook') {}
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_body() { return $this->body; }
}
function sale_event(array $resource = [], string $type = 'PAYMENT.SALE.COMPLETED') {
    return json_encode(['id' => 'WH-TEST-0001', 'event_type' => $type, 'resource' => array_merge(['id' => 'NEWSALE12345',
        'billing_agreement_id' => 'I-TEST12345678', 'create_time' => '2026-10-14T08:20:03Z', 'amount' => ['total' => '549.00', 'currency' => 'EUR']], $resource)]);
}
function remote_sale(array $changes = []) {
    return ['status' => 200, 'body' => json_encode(array_merge(['id' => 'NEWSALE12345', 'state' => 'completed',
        'billing_agreement_id' => 'I-TEST12345678', 'amount' => ['total' => '549.00', 'currency' => 'EUR'],
        'create_time' => '2026-10-14T08:20:03Z'], $changes))];
}

/** Executes the real SELECTs and the conditional UPDATE on SQLite. `timestamp` is stored in UTC, as PMPro does. */
class GuardDatabase extends RecoveryDatabase {
    public $fail_select = false;
    private function select_db() {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE wp_pmpro_membership_orders (id INTEGER PRIMARY KEY, code TEXT, user_id INTEGER, membership_id INTEGER, status TEXT,
            gateway TEXT, gateway_environment TEXT, total TEXT, subscription_transaction_id TEXT, payment_transaction_id TEXT, timestamp TEXT)');
        foreach ($GLOBALS['orders'] as $id => $data) {
            $o = new MemberOrder($id);
            $when = is_numeric($o->timestamp) ? gmdate('Y-m-d H:i:s', (int) $o->timestamp) : $o->timestamp;
            $db->prepare('INSERT INTO wp_pmpro_membership_orders VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$o->id, $o->code, $o->user_id,
                $o->membership_id, $o->status, $o->gateway, $o->gateway_environment, $o->total, $o->subscription_transaction_id, $o->payment_transaction_id, $when]);
        }
        return $db;
    }
    public $options = 'wp_options';
    public function get_col($sql) { return preg_match("/LIKE 'pmpro.*recovery.*%'/", $sql) ? array_values(array_filter(array_keys($GLOBALS['settings_store']), fn($k) => str_starts_with($k, 'pmpro_fic_paypal_recovery_'))) : []; }
    public function get_results($sql) { $this->last_error = $this->fail_select ? 'test failure' : ''; return $this->fail_select ? [] : $this->select_db()->query($sql)->fetchAll(PDO::FETCH_OBJ); }
    public function get_var($sql) {
        if (strpos($sql, 'wp_options') !== false) return parent::get_var($sql);
        $this->last_error = $this->fail_select ? 'test failure' : '';
        if ($this->fail_select) return null;
        $v = $this->select_db()->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
}

function guard_fixture() {
    global $orders, $settings_store, $wpdb, $paypal_subscription, $paypal_calls, $sale_requests, $sale_responses, $order_meta, $filter_values, $invoice_emails;
    recovery_fixture();
    $settings_store['pmpro_gateway'] = 'paypal';
    $orders[42]['timestamp'] = strtotime('2026-10-14T08:18:00Z'); // checkout order created two minutes before the buyer approved at PayPal
    $order_meta = [42 => $order_meta[42]];
    $paypal_subscription = ['id' => 'I-TEST12345678', 'create_time' => '2026-10-14T08:20:00Z'];
    $sale_requests = []; $sale_responses = [remote_sale()]; $filter_values = []; $invoice_emails = 0;
    $GLOBALS['signature_ok'] = true; $GLOBALS['signature_checks'] = 0; $GLOBALS['tz'] = 'Europe/Rome'; $GLOBALS['fail_meta'] = false; $GLOBALS['fail_storage'] = false; $GLOBALS['events'] = [];
    unset($GLOBALS['on_sale_request']);
    $wpdb = new GuardDatabase();
    return new PMPro_FIC_Webhook_Guard();
}
/** What the addon would do for this notification after the REST filters: the guard, then (if not short-circuited) the real core. */
function deliver($guard, $body = null, $method = 'POST', $route = '/pmpro-paypal/v1/webhook') {
    $body = $body ?? sale_event();
    $response = $guard->pre_dispatch(null, null, new WebhookRequest($body, $method, $route));
    $event = json_decode($body, true);
    $core = null;
    if ($response === null && is_array($event) && ($event['event_type'] ?? '') === 'PAYMENT.SALE.COMPLETED') {
        $r = $event['resource'];
        $core = pmpro_handle_recurring_payment_succeeded_at_gateway(['gateway' => 'paypal', 'gateway_environment' => 'sandbox',
            'subscription_transaction_id' => $r['billing_agreement_id'], 'payment_transaction_id' => $r['id'],
            'total' => $r['amount']['total'], 'payment_type' => 'PayPal']);
    }
    return [$response, $core];
}
function dispatch($guard, $body = null, $method = 'POST', $route = '/pmpro-paypal/v1/webhook') {
    return $guard->pre_dispatch(null, null, new WebhookRequest($body ?? sale_event(), $method, $route)); // The guard alone: no core afterwards.
}
function outcome() { return $GLOBALS['settings_store'][PMPro_FIC_Webhook_Guard::OUTCOME]['outcome'] ?? ''; }
function untouched($label) {
    check($GLOBALS['orders'][42]['payment_transaction_id'] === '' && count($GLOBALS['orders']) === 1 && $GLOBALS['wpdb']->queries === 0
        && !isset($GLOBALS['settings_store']['pmpro_fic_paypal_recovery_42']), $label);
}
function no_calls($label) { check(!$GLOBALS['paypal_calls'] && !$GLOBALS['sale_requests'], $label); }
function is_retry($response, $label) { check($response instanceof WP_REST_Response && $response->status === 503 && ($response->headers['Retry-After'] ?? '') === '60', $label); }
function unbound_one_order($label) { check(count($GLOBALS['orders']) === 1 && $GLOBALS['invoice_emails'] === 0 && $GLOBALS['orders'][42]['payment_transaction_id'] === '', $label); }
function one_order($label) { check(count($GLOBALS['orders']) === 1 && $GLOBALS['invoice_emails'] === 0 && $GLOBALS['orders'][42]['payment_transaction_id'] === 'NEWSALE12345', $label); }

// 1. The observed failure, through PMPro's real handler.
$guard = guard_fixture();
$unguarded = pmpro_handle_recurring_payment_succeeded_at_gateway(['gateway' => 'paypal', 'gateway_environment' => 'sandbox',
    'subscription_transaction_id' => 'I-TEST12345678', 'payment_transaction_id' => 'NEWSALE12345', 'total' => '549.00', 'payment_type' => 'PayPal']);
check(str_contains($unguarded, 'Created new order') && count($orders) === 2 && $invoice_emails === 1, 'Reproduction: without the guard the real PMPro 3.8.9 handler creates a second order and sends an invoice e-mail');
$guard = guard_fixture();
[$response, $core] = deliver($guard);
check($response === null && str_contains((string) $core, 'already processed') && str_contains($core, '#42'), 'With the guard the real handler answers "already processed" for order #42');
one_order('One order, no second invoice e-mail, payment bound to the checkout');
check(count($sale_requests) === 1 && count($paypal_calls) === 1 && $paypal_calls[0][0] === 'subscription' && $signature_checks === 1, 'One signature check, one exact sale GET, one subscription GET');
check($sale_requests[0][1]['redirection'] === 0, 'Sale GET does not follow redirects');
check($wpdb->queries === 1 && outcome() === 'bound', 'One conditional UPDATE and an auditable outcome');
$audit = $order_meta[42][PMPro_FIC_PayPal_Recovery::META];
check($audit['status'] === 'updated' && $audit['origin'] === 'webhook' && $audit['payment']['payment_id'] === 'NEWSALE12345' && $audit['payment']['cents'] === 54900, 'Order audit records webhook origin and verified payment');
check($settings_store['pmpro_fic_paypal_recovery_42'] === $audit, 'Journal agrees with order metadata, as the invoice flow requires');
foreach (['code', 'user_id', 'membership_id', 'status', 'gateway', 'subscription_transaction_id', 'total'] as $key) check($orders[42][$key] == (new MemberOrder(42))->$key, 'Preserves ' . $key);
check(!str_contains(json_encode($settings_store), 'fake-paypal-secret') && !str_contains(json_encode($settings_store[PMPro_FIC_Webhook_Guard::OUTCOME]), 'billing@'), 'Outcome records have no credentials or personal data');
$entry = $settings_store[PMPro_FIC_Webhook_Guard::LOG][0];
check($entry['event_id'] === 'WH-TEST-0001' && $entry['order_id'] === 42 && $entry['payment_before'] === '' && $entry['payment_after'] === 'NEWSALE12345', 'Event log correlates webhook ID, order and payment before/after');

// 2. Replay / retry of the same notification.
$calls = [count($sale_requests), count($paypal_calls), $wpdb->queries];
[$response, $core] = deliver($guard);
check($response === null && str_contains($core, 'already processed') && [count($sale_requests), count($paypal_calls), $wpdb->queries] === $calls, 'Replay does nothing: the real handler again says "already processed"');
one_order('Replay creates no order and no e-mail');

// 3. Not applicable requests are ignored without any network or database work.
foreach ([['GET', '/pmpro-paypal/v1/webhook', null], ['POST', '/wp/v2/posts', null], ['POST', '/pmpro-paypal/v1/webhook', sale_event([], 'BILLING.SUBSCRIPTION.ACTIVATED')],
        ['POST', '/pmpro-paypal/v1/webhook', 'not json'], ['POST', '/pmpro-paypal/v1/webhook', sale_event(['id' => 'x'])],
        ['POST', '/pmpro-paypal/v1/webhook', sale_event(['billing_agreement_id' => 'I-bad'])], ['POST', '/pmpro-paypal/v1/webhook', sale_event(['billing_agreement_id' => ''])]] as [$method, $route, $body]) {
    $guard = guard_fixture();
    check(dispatch($guard, $body, $method, $route) === null, 'Ignored: ' . $method . ' ' . $route);
    untouched('Ignored request leaves orders alone'); no_calls('Ignored request does no PayPal call');
}
$guard = guard_fixture();
check($guard->pre_dispatch('already-handled', null, new WebhookRequest(sale_event())) === 'already-handled', 'Respects a response set by another filter');
$guard = guard_fixture(); $filter_values['pmpro_fic_webhook_guard_enabled'] = false;
check(dispatch($guard) === null && $signature_checks === 0, 'Kill switch filter disables the guard'); untouched('Disabled guard leaves orders alone');
$guard = guard_fixture(); $settings_store['pmpro_gateway_environment'] = 'live';
check(dispatch($guard) === null && $signature_checks === 0, 'Guard is Sandbox-only like the rest of the plugin'); untouched('Live environment untouched');
$guard = guard_fixture(); $settings_store['pmpro_gateway'] = 'stripe';
check(dispatch($guard) === null && $signature_checks === 0, 'Other gateways ignored');
$guard = guard_fixture(); $orders[44] = array_merge($orders[42], ['id' => 44, 'code' => 'OTHERSUB44', 'subscription_transaction_id' => 'I-OTHER1234567', 'payment_transaction_id' => 'NEWSALE12345']);
check(dispatch($guard) === null && $orders[42]['payment_transaction_id'] === '' && !$sale_requests && $wpdb->queries === 0, 'A sale already owned by any Sandbox order is never rebound');

// 4. Invalid signature: the official handler rejects it with 401; nothing is written.
$guard = guard_fixture(); $signature_ok = false;
check(dispatch($guard) === null && $signature_checks === 1, 'Signature checked'); untouched('Unsigned webhook cannot bind a payment'); no_calls('No PayPal call before the signature passes');

// 5. A renewal is never attached to the checkout.
$guard = guard_fixture(); $sale_responses = [remote_sale(['create_time' => '2026-10-15T08:20:00Z'])];
check(dispatch($guard, sale_event(['create_time' => '2026-10-15T08:20:00Z'])) === null && outcome() === 'not_initial', 'Sale a day later is a renewal'); untouched('Renewal does not touch the checkout order');
$guard = guard_fixture(); $orders[43] = array_merge($orders[42], ['id' => 43, 'code' => 'RENEW43', 'payment_transaction_id' => 'EARLIERSALE1']);
check(dispatch($guard) === null && $orders[42]['payment_transaction_id'] === '' && !$sale_requests && $wpdb->queries === 0, 'More than one order for the subscription: official behaviour only');
$guard = guard_fixture(); $orders[42]['timestamp'] = strtotime('2026-10-11T08:20:00Z');
check(dispatch($guard, sale_event(['create_time' => '2026-10-11T08:20:03Z'])) === null, 'Checkout unrelated in time to the subscription is not used'); untouched('Mismatched checkout untouched');

// 6. Definitive remote rejections: official behaviour, nothing written.
foreach ([['amount', ['amount' => ['total' => '1.00', 'currency' => 'EUR']], 'sale_rejected_payment_amount'],
        ['currency', ['amount' => ['total' => '549.00', 'currency' => 'USD']], 'sale_rejected_payment_amount'],
        ['other subscription', ['billing_agreement_id' => 'I-OTHER1234567'], 'sale_rejected_payment_identity'],
        ['refunded', ['state' => 'refunded'], 'sale_rejected_payment_state'],
        ['other id', ['id' => 'ANOTHER12345'], 'sale_rejected_payment_identity']] as [$label, $changes, $expected]) {
    $guard = guard_fixture(); $sale_responses = [remote_sale($changes)];
    check(dispatch($guard) === null && outcome() === $expected, 'Rejected remote sale: ' . $label . ' (' . outcome() . ')'); untouched('Rejected sale ' . $label . ' writes nothing');
}

// 7. R2: anything not verifiable while the sale looks like the first payment → 503, never the core.
foreach ([['sale 404', ['status' => 404, 'body' => '{}'], 'sale_unverifiable_payment_api'], ['sale 503', ['status' => 503, 'body' => ''], 'sale_unverifiable_payment_api'],
        ['sale pending', remote_sale(['state' => 'pending']), 'sale_unverifiable_payment_pending'],
        ['HTTP 200 with empty JSON', ['status' => 200, 'body' => '{}'], 'sale_unverifiable_payment_incomplete'],
        ['HTTP 200 not JSON', ['status' => 200, 'body' => 'oops'], 'sale_unverifiable_payment_incomplete'],
        ['HTTP 403', ['status' => 403, 'body' => '{}'], 'sale_unverifiable_payment_access']] as [$label, $reply, $expected]) {
    $guard = guard_fixture(); $sale_responses = [$reply];
    [$response, $core] = deliver($guard);
    is_retry($response, 'Retry requested: ' . $label); check($core === null && outcome() === $expected, 'Core not reached, outcome ' . $expected . ' (' . outcome() . ')'); untouched('Retry writes nothing: ' . $label);
}
// ... and PayPal's redelivery, once the data is complete, binds and the real core finds no work to do.
$guard = guard_fixture(); $sale_responses = [['status' => 200, 'body' => '{}'], remote_sale()];
is_retry(dispatch($guard), 'First delivery with an incomplete answer is deferred'); untouched('Nothing written meanwhile');
[$response, $core] = deliver($guard);
check($response === null && str_contains($core, 'already processed'), 'Redelivery binds and the real core reports "already processed"'); one_order('Still one order and no e-mail after the redelivery');
// A renewal with the same uncertainty is not blocked.
$guard = guard_fixture(); $sale_responses = [['status' => 200, 'body' => '{}']];
check(dispatch($guard, sale_event(['create_time' => '2026-10-15T08:20:03Z'])) === null && outcome() === 'sale_unverifiable_payment_incomplete_passthrough', 'Uncertain but far from the checkout (renewal): official behaviour, not blocked'); untouched('Renewal path writes nothing');
$guard = guard_fixture(); $paypal_subscription = new WP_Error('http', 'down');
is_retry(dispatch($guard), 'Retry requested when the subscription cannot be read'); untouched('Subscription outage writes nothing');
$guard = guard_fixture(); $paypal_subscription = ['id' => 'I-OTHER1234567', 'create_time' => '2026-10-14T08:20:00Z'];
is_retry(dispatch($guard), 'Subscription response for another ID is not trusted: retry'); check(outcome() === 'subscription_unverified', 'Outcome subscription_unverified'); untouched('Wrong subscription untouched');
$guard = guard_fixture(); $paypal_subscription = ['id' => 'I-TEST12345678'];
is_retry(dispatch($guard), 'Subscription without a creation time: retry'); untouched('Incomplete subscription untouched');
$guard = guard_fixture(); $wpdb->fail_select = true;
is_retry(dispatch($guard), 'Retry requested when orders cannot be read'); no_calls('Unreadable orders: no PayPal call');

// 8. R3: PMPro stores `timestamp` in UTC; the site timezone must not matter.
foreach (['Europe/Rome', 'America/New_York', 'Pacific/Auckland', 'UTC'] as $zone) {
    $guard = guard_fixture(); $GLOBALS['tz'] = $zone; $orders[42]['status'] = 'token'; $orders[42]['timestamp'] = time() - 120;
    [$response, $core] = deliver($guard, sale_event(['create_time' => gmdate('Y-m-d\TH:i:s\Z')]));
    is_retry($response, 'Fresh token order is deferred whatever the site timezone: ' . $zone); check($core === null, 'Core not reached while activation is pending');
}
$guard = guard_fixture(); $orders[42]['status'] = 'token'; $orders[42]['timestamp'] = time() - 3 * 86400;
check(dispatch($guard, sale_event(['create_time' => gmdate('Y-m-d\TH:i:s\Z')])) === null, 'Abandoned token order: official behaviour (no endless retries)');
$guard = guard_fixture(); $orders[42]['status'] = 'pending';
check(dispatch($guard) === null && $wpdb->queries === 0 && !$sale_requests, 'Pending order left to the official handler (it reuses pending orders without ID)');
$guard = guard_fixture(); $GLOBALS['tz'] = 'Pacific/Auckland';
[$response, $core] = deliver($guard); check($response === null && str_contains($core, 'already processed') && outcome() === 'bound', 'Checkout/subscription comparison also independent of the site timezone'); one_order('Bound under a far-east timezone');

// 9. R1: concurrency, always judged by what the real core then does.
// 9a. A second delivery runs entirely inside the first one's UPDATE window.
$guard = guard_fixture(); $sale_responses = [remote_sale(), remote_sale()]; $inner = null;
$wpdb->before_update = function ($db) use (&$inner, $guard) { $GLOBALS['wpdb']->before_update = null; $inner = deliver($guard); };
[$outer_response, $outer_core] = deliver($guard);
check($inner[0] === null && str_contains($inner[1], 'already processed'), 'Inner delivery binds and gets "already processed" from the real core');
check($outer_response === null && str_contains($outer_core, 'already processed'), 'Outer delivery lost the UPDATE race but re-read the order and also gets "already processed"');
one_order('Interleaved deliveries: exactly one order, no e-mail');
check($settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'updated' && $order_meta[42][PMPro_FIC_PayPal_Recovery::META] === $settings_store['pmpro_fic_paypal_recovery_42'], 'Audit is complete and consistent after the race');
// 9b. A verification journal left behind (crash, concurrent recovery) no longer lets the core run.
$guard = guard_fixture(); $settings_store['pmpro_fic_paypal_recovery_42'] = ['status' => 'verified', 'order_id' => 42, 'origin' => 'automation'];
[$response, $core] = deliver($guard);
check($response === null && str_contains($core, 'already processed') && outcome() === 'bound', 'A leftover journal does not block binding'); one_order('Leftover journal: still one order');
// 9c. Another writer sets the same payment first (queue recovery): our conditional UPDATE matches nothing, we re-read and succeed.
$guard = guard_fixture(); $wpdb->before_update = function ($db) { $db->exec("UPDATE wp_pmpro_membership_orders SET payment_transaction_id = 'NEWSALE12345'"); };
[$response, $core] = deliver($guard);
check($response === null && str_contains($core, 'already processed'), 'Same payment bound meanwhile by recovery: success after re-reading'); one_order('Recovery race: one order');
// 9d. Another writer sets a DIFFERENT payment: unresolved, so 503 and the winner's journal is not deleted.
$guard = guard_fixture(); $wpdb->before_update = function ($db) { $db->exec("UPDATE wp_pmpro_membership_orders SET payment_transaction_id = 'OTHERSALE999'"); };
[$response, $core] = deliver($guard);
is_retry($response, 'Different payment written meanwhile: deferred, never handed to the core'); check($core === null && $orders[42]['payment_transaction_id'] === 'OTHERSALE999', 'Concurrent writer never overwritten');
$guard = guard_fixture(); $wpdb->before_update = function ($db) { $GLOBALS['settings_store']['pmpro_fic_paypal_recovery_42'] = ['status' => 'verified', 'origin' => 'automation', 'owner' => 'someone-else'];
    $db->exec("UPDATE wp_pmpro_membership_orders SET payment_transaction_id = 'OTHERSALE999'"); };
dispatch($guard); check(($settings_store['pmpro_fic_paypal_recovery_42']['origin'] ?? '') === 'automation', 'A concurrent process journal is never deleted by a losing request');
$guard = guard_fixture(); $wpdb->query_error = true;
[$response, $core] = deliver($guard); is_retry($response, 'Database failure on the UPDATE: deferred, not passed to the core');
check($core === null && outcome() === 'bind_paypal_recovery_query' && !isset($settings_store['pmpro_fic_paypal_recovery_42']), 'Own journal removed after a failure that changed nothing');
$guard = guard_fixture(); $wpdb->after_readback = function () { $GLOBALS['orders'][42]['payment_transaction_id'] = ''; };
is_retry(dispatch($guard), 'Readback mismatch: deferred'); check(outcome() === 'bind_paypal_recovery_readback' && $settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'verified', 'Journal kept as unconfirmed evidence for manual review');

// 10. R4/N3: an audit that cannot be completed. The ID stays bound, PayPal is asked to redeliver and a durable local job is queued.
function invoice_preview($service) {
    $GLOBALS['responses'] = [response([['id' => 9, 'name' => 'PayPal Test']])];
    $service->dispatch('load_order', ['order_id' => '42']);
    $GLOBALS['totals_responses'] = [response(['amount_net' => 450, 'amount_vat' => 99, 'amount_gross' => 549, 'amount_due' => 549])];
    return $service->dispatch('preview_order', customer_input());
}
function fail_final_audit($wpdb) { $wpdb->after_readback = function () { $GLOBALS['fail_storage'] = true; $GLOBALS['fail_meta'] = true; }; }
function storage_recovers($wpdb) { $GLOBALS['fail_storage'] = false; $GLOBALS['fail_meta'] = false; $wpdb->after_readback = null; }
$repair_args = [42, 'NEWSALE12345', 1]; $repair_key = PMPro_FIC_Webhook_Guard::REPAIR_HOOK . ':' . json_encode($repair_args);
$guard = guard_fixture(); fail_final_audit($wpdb);
[$response, $core] = deliver($guard);
is_retry($response, 'Audit not persisted: 503 so that PayPal redelivers; the 2xx must not close the delivery'); check($core === null, 'Core not reached (the outcome record itself cannot be written while storage is failing)');
one_order('The payment stays bound and no order or e-mail exists');
check(isset($events[$repair_key]), 'A durable local repair job is queued');
storage_recovers($wpdb);
check(($settings_store['pmpro_fic_paypal_recovery_42']['status'] ?? '') === 'verified' || !isset($order_meta[42][PMPro_FIC_PayPal_Recovery::META]), 'Audit incomplete, as simulated');
$service = new PMPro_FIC_Test_Orders([$plugin, 'order_context']);
error_is(invoice_preview($service), 'payment_recovery_data');
// 10a. The local job alone repairs it: no second delivery is manufactured.
$sale_responses = [remote_sale()];
$guard->cron_repair(42, 'NEWSALE12345', 1);
check(outcome() === 'audit_repaired' && $order_meta[42][PMPro_FIC_PayPal_Recovery::META] === $settings_store['pmpro_fic_paypal_recovery_42'] && $settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'updated', 'Queued job completes the audit autonomously');
one_order('Repair job creates no order and no e-mail');
$preview = invoice_preview($service); check(is_array($preview) && $preview['stage'] === 'preview', 'Invoice preview is no longer blocked after the repair');
$repaired = $order_meta[42][PMPro_FIC_PayPal_Recovery::META]; $calls = count($sale_requests);
$guard->cron_repair(42, 'NEWSALE12345', 1); check($order_meta[42][PMPro_FIC_PayPal_Recovery::META] === $repaired && count($sale_requests) === $calls, 'Job is idempotent: complete audit left alone, no PayPal call');
// 10b. Or PayPal's redelivery does it, even after a daily renewal exists.
$guard = guard_fixture(); fail_final_audit($wpdb); dispatch($guard); storage_recovers($wpdb);
$orders[43] = array_merge($orders[42], ['id' => 43, 'code' => 'RENEW43', 'payment_transaction_id' => 'RENEWAL999']); $sale_responses = [remote_sale()];
[$response, $core] = deliver($guard);
check($response === null && str_contains($core, 'already processed') && outcome() === 'audit_repaired' && $settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'updated', 'Replay of the initial sale repairs the audit although a renewal order now exists');
check(count($orders) === 2 && $invoice_emails === 0, 'Repair with a renewal present creates nothing');
// 10c. A repair that cannot verify is retried by PayPal and by the job (bounded); a refunded payment is never repaired.
$guard = guard_fixture(); fail_final_audit($wpdb); dispatch($guard); storage_recovers($wpdb); $sale_responses = [['status' => 503, 'body' => '']];
is_retry(dispatch($guard), 'Repair not yet verifiable: redelivery requested'); check(outcome() === 'audit_repair_retry', 'Outcome audit_repair_retry');
$events = []; $sale_responses = [['status' => 503, 'body' => '']]; $guard->cron_repair(42, 'NEWSALE12345', 1);
check(isset($events[PMPro_FIC_Webhook_Guard::REPAIR_HOOK . ':' . json_encode([42, 'NEWSALE12345', 2])]), 'Failed job reschedules itself with the next attempt');
$events = []; $sale_responses = [['status' => 503, 'body' => '']]; $guard->cron_repair(42, 'NEWSALE12345', PMPro_FIC_Webhook_Guard::REPAIR_ATTEMPTS);
check(!$events, 'Job stops after the attempt limit');
$sale_responses = [remote_sale(['state' => 'refunded'])];
check(dispatch($guard) === null && outcome() === 'audit_repair_rejected' && ($settings_store['pmpro_fic_paypal_recovery_42']['status'] ?? '') === 'verified', 'Refunded payment: no audit is written and PayPal is not retried forever');
// 10d. Orders the addon bound natively (62/67 style) are never given a webhook audit.
$guard = guard_fixture(); $orders[42]['payment_transaction_id'] = 'NEWSALE12345';
[$response, $core] = deliver($guard); check($response === null && str_contains($core, 'already processed') && !$sale_requests && !isset($order_meta[42][PMPro_FIC_PayPal_Recovery::META]), 'Normal orders (ID set by the addon) are untouched');
$guard = guard_fixture(); fail_final_audit($wpdb); dispatch($guard); storage_recovers($wpdb);
check($guard->cron_repair(0, 'x', 'y') === null && $guard->cron_repair(42, 'bad', 1) === null && $guard->cron_repair(42, 'NEWSALE12345', '1') === null, 'Job ignores malformed arguments');

// 10e. N1: an error reading the manual-link store is not "no link".
$guard = guard_fixture(); $wpdb->atomic_failure = true;
[$response, $core] = deliver($guard); is_retry($response, 'Unreadable link store while the orders are readable: deferred'); check($core === null && outcome() === 'pair_link_unreadable', 'Core not reached, outcome pair_link_unreadable'); untouched('Unreadable link store writes nothing');
$guard = guard_fixture(); PMPro_FIC_Atomic_Store::insert(PMPro_FIC_Initial_Pair::key(42), ['schema_version' => 99, 'junk' => true]);
[$response, $core] = deliver($guard); is_retry($response, 'Corrupt link record: deferred'); check($core === null && outcome() === 'pair_link_unreadable', 'Corrupt record is an error, not a link'); untouched('Corrupt link record writes nothing');
$guard = guard_fixture(); $wpdb->atomic_failure = true; $orders[42]['timestamp'] = strtotime('2026-10-01T00:00:00Z');
check(dispatch($guard, sale_event(['create_time' => '2026-10-14T08:20:03Z'])) === null, 'Same unreadable store for a sale far from the checkout: official behaviour');

// 11a. N2: malformed or incomplete remote data is "not verifiable", never a definitive rejection.
foreach ([['create_time not-a-time', ['create_time' => 'not-a-time'], 'sale_unverifiable_payment_incomplete'],
        ['create_time missing', ['create_time' => null], 'sale_unverifiable_payment_incomplete'],
        ['amount total not numeric', ['amount' => ['total' => 'abc', 'currency' => 'EUR']], 'sale_unverifiable_payment_incomplete'],
        ['amount total missing', ['amount' => ['currency' => 'EUR']], 'sale_unverifiable_payment_incomplete'],
        ['currency missing', ['amount' => ['total' => '549.00']], 'sale_unverifiable_payment_incomplete'],
        ['create_time in the future', ['create_time' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)], 'sale_unverifiable_payment_time']] as [$label, $changes, $expected]) {
    $guard = guard_fixture(); $sale_responses = [remote_sale($changes)];
    [$response, $core] = deliver($guard); is_retry($response, 'Deferred: ' . $label); check($core === null && outcome() === $expected, 'Outcome ' . $expected . ' (' . outcome() . ')'); untouched('Nothing written: ' . $label);
}
$guard = guard_fixture(); $sale_responses = [remote_sale(['amount' => ['total' => '1.00', 'currency' => 'EUR']])];
check(dispatch($guard) === null && outcome() === 'sale_rejected_payment_amount', 'A well-formed but different amount is still a definitive rejection');

// 11b. N4: one window for binding and for deferral, whatever the PayPal answer.
foreach ([false, true] as $incomplete) {
    $guard = guard_fixture(); $orders[42]['timestamp'] = strtotime('2026-10-14T05:20:05Z'); // checkout 3 hours before the subscription
    $sale_responses = [$incomplete ? ['status' => 200, 'body' => '{}'] : remote_sale()];
    [$response, $core] = deliver($guard);
    if ($incomplete) { is_retry($response, 'Checkout 3h earlier, incomplete answer: deferred (same protection as a complete answer)'); check($core === null, 'Core not reached'); }
    else { check($response === null && str_contains($core, 'already processed') && outcome() === 'bound', 'Checkout 3h earlier, complete answer: bound'); one_order('One order'); }
}
$guard = guard_fixture(); $orders[42]['timestamp'] = strtotime('2026-10-13T08:00:00Z'); $sale_responses = [['status' => 200, 'body' => '{}']];
check(dispatch($guard, sale_event(['create_time' => '2026-10-14T08:20:03Z'])) === null && outcome() === 'sale_unverifiable_payment_incomplete_passthrough', 'A sale 24h20m after the checkout is beyond the window: official behaviour');
$guard = guard_fixture(); $orders[42]['timestamp'] = strtotime('2026-10-14T08:18:00Z'); $sale_responses = [['status' => 200, 'body' => '{}']];
check(dispatch($guard, sale_event(['create_time' => '2026-10-15T08:18:00Z'])) === null && outcome() === 'sale_unverifiable_payment_incomplete_passthrough', 'First daily renewal (exactly 24h after the checkout) is never deferred');
$guard = guard_fixture(); $orders[42]['timestamp'] = strtotime('2026-10-14T08:18:00Z'); $sale_responses = [['status' => 200, 'body' => '{}']];
is_retry(dispatch($guard, sale_event(['create_time' => '2026-10-15T08:17:59Z'])), 'Anything closer than 24h to the checkout is protected (documented assumption: PayPal cycles are at least daily)');

// 11c. N5: the panel reports the effective state.
$guard = guard_fixture(); $filter_values['pmpro_fic_webhook_guard_enabled'] = false;
ob_start(); PMPro_FIC_Webhook_Guard::render_status(); $html = ob_get_clean();
check(str_contains($html, 'NON attiva') && str_contains($html, 'disattiva la guardia') && !str_contains($html, 'Stato effettivo: attiva'), 'Panel shows the guard as inactive when the kill switch is set');
$guard = guard_fixture(); $settings_store['pmpro_gateway_environment'] = 'live';
ob_start(); PMPro_FIC_Webhook_Guard::render_status(); $html = ob_get_clean();
check(str_contains($html, 'NON attiva') && str_contains($html, 'live'), 'Panel shows the guard as inactive outside Sandbox');
$guard = guard_fixture();
ob_start(); PMPro_FIC_Webhook_Guard::render_status(); $html = ob_get_clean();
check(str_contains($html, 'Stato effettivo: attiva') && str_contains($html, 'non prova che non sia arrivato alcun webhook') && !str_contains($html, 'passiv'), 'Panel shows the effective state and does not claim an empty list proves anything');

// 11. P1: with a coeval candidate, neither an internal defect nor missing credentials may let the core create the duplicate.
$guard = guard_fixture(); $GLOBALS['on_sale_request'] = function () { throw new RuntimeException('simulated defect'); };
[$response, $core] = deliver($guard);
is_retry($response, 'Exception after the candidate is selected: deferred (503)'); check($core === null && outcome() === 'guard_exception', 'Exception: core not reached, outcome recorded'); unbound_one_order('Exception: still one order, unbound'); check($GLOBALS['invoice_emails'] === 0, 'Exception: no invoice email');
$GLOBALS['on_sale_request'] = null;
$guard = guard_fixture(); unset($settings_store['pmpro_paypal_client_secret_sandbox']);
[$response, $core] = deliver($guard);
is_retry($response, 'Missing credentials, coeval candidate: deferred (503)'); check($core === null && outcome() === 'gateway_unavailable', 'Missing credentials: core not reached'); unbound_one_order('Missing credentials: still one order, unbound'); check($GLOBALS['invoice_emails'] === 0, 'Missing credentials: no invoice email');
$guard = guard_fixture(); unset($settings_store['pmpro_paypal_client_secret_sandbox']); $orders[42]['timestamp'] = time() - 3 * 86400;
check(dispatch($guard) === null && outcome() === 'gateway_unavailable_passthrough', 'Missing credentials, distant sale (a renewal): official behaviour');
$guard = guard_fixture(); $GLOBALS['on_sale_request'] = function () { throw new RuntimeException('simulated defect'); }; $orders[42]['timestamp'] = time() - 3 * 86400;
check(dispatch($guard) === null && outcome() === 'guard_exception', 'Exception, distant sale: official behaviour'); untouched('Exception writes nothing');
$GLOBALS['on_sale_request'] = null;
$guard = guard_fixture(); $GLOBALS['on_sale_request'] = function () { throw new RuntimeException('simulated defect'); };
check(dispatch($guard, json_encode(['not' => 'a sale'])) === null, 'Exception handling does not affect unrelated events');
$GLOBALS['on_sale_request'] = null;

// 11d. P2: same extremes for binding and deferral (checkout <-> sale, strict 24h), tested at the edges with complete and incomplete answers.
foreach ([['23h59m59s', 86399, true], ['exactly 24h', 86400, false], ['24h20m', 87603, false]] as [$label, $gap, $protected]) {
    foreach ([false, true] as $incomplete) {
        $guard = guard_fixture(); $sale_at = strtotime('2026-10-14T08:20:03Z');
        $orders[42]['timestamp'] = $sale_at - $gap; $paypal_subscription['create_time'] = '2026-10-14T08:10:00Z';
        $sale_responses = [$incomplete ? ['status' => 200, 'body' => '{}'] : remote_sale()];
        [$response, $core] = deliver($guard);
        $kind = $incomplete ? 'incomplete' : 'complete';
        if ($protected) check($core === null || str_contains($core, 'already processed'), "Gap $label, API $kind: protected");
        if ($protected && $incomplete) is_retry($response, "Gap $label, API incomplete: deferred");
        if ($protected && !$incomplete) check(outcome() === 'bound', "Gap $label, API complete: bound");
        if (!$protected) check(!in_array(outcome(), ['bound'], true), "Gap $label, API $kind: not bound (outside the supported set, same as when incomplete)");
    }
}
$guard = guard_fixture(); $orders[42]['timestamp'] = strtotime('2026-10-13T08:00:00Z'); $paypal_subscription['create_time'] = '2026-10-14T07:50:00Z';
check(dispatch($guard) === null && outcome() === 'checkout_mismatch', 'Reviewer case (checkout 24h20m before the sale, subscription 23h50m after the checkout): complete answer is NOT bound, like the incomplete one');

// 11e. P3: a repair that is not really queued is reported and never presented as guaranteed; the backend can resume it.
$cron_fail = true;
$guard = guard_fixture(); fail_final_audit($wpdb);
[$response, $core] = deliver($guard);
is_retry($response, 'Audit pending and cron storage down: still 503'); check(count($events) === 0, 'Nothing was queued'); check(!isset($settings_store['pmpro_fic_guard_noop']) && $settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'verified', 'Storage down: the journal still marks the audit as pending (the event log could not be written)');
storage_recovers($wpdb);
ob_start(); PMPro_FIC_Webhook_Guard::render_status(); $html = ob_get_clean();
check(str_contains($html, 'Audit incompleti: 1') && str_contains($html, 'pmpro_fic_guard_resume'), 'Panel lists the incomplete audit with a resume button');
$sale_responses = [remote_sale()];
[$done, $left] = PMPro_FIC_Webhook_Guard::resume_pending();
check($done === 1 && $left === 0 && $settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'updated', 'Backend resume completes the audit without any new delivery'); one_order('Resume creates no order'); check($GLOBALS['invoice_emails'] === 0, 'Resume sends no email');
ob_start(); PMPro_FIC_Webhook_Guard::render_status(); $html = ob_get_clean();
check(!str_contains($html, 'Audit incompleti'), 'Panel no longer lists it');
$guard = guard_fixture(); $wpdb->after_readback = function () { $GLOBALS['fail_meta'] = true; }; $events = [];
[$response, $core] = deliver($guard); $notes = array_column($settings_store[PMPro_FIC_Webhook_Guard::LOG], 'outcome');
check(in_array('repair_not_scheduled', $notes, true) && count($events) === 0, 'Cron down but options writable: the failed queueing is recorded, never presented as queued'); $GLOBALS['fail_meta'] = false; $wpdb->after_readback = null;
$cron_fail = false;
$guard = guard_fixture(); fail_final_audit($wpdb); $GLOBALS['events'] = []; $events = [];
deliver($guard); check(count($events) === 1, 'With a working cron the repair is queued');
storage_recovers($wpdb);
$GLOBALS['on_sale_request'] = function () { throw new RuntimeException('simulated defect'); }; $sale_responses = [remote_sale()];
$events = []; $guard->cron_repair(42, 'NEWSALE12345', 1);
check(count($events) === 1, 'An exception inside the repair job reschedules it'); $GLOBALS['on_sale_request'] = null;

// 12. After binding, the invoice flow accepts the order; the status panel and the event log are bounded and clean.
$guard = guard_fixture(); dispatch($guard);
$service = new PMPro_FIC_Test_Orders([$plugin, 'order_context']);
$GLOBALS['responses'] = [response([['id' => 9, 'name' => 'PayPal Test']])];
$loaded = $service->dispatch('load_order', ['order_id' => '42']);
check(is_array($loaded) && $loaded['stage'] === 'edit', 'Bound order is accepted by the invoice flow without manual recovery');
for ($i = 0; $i < 45; $i++) { $orders[42]['payment_transaction_id'] = ''; $sale_responses = [remote_sale()]; dispatch($guard, sale_event(['id' => 'X' . $i . 'ABCDE'])); }
check(count($settings_store[PMPro_FIC_Webhook_Guard::LOG]) === PMPro_FIC_Webhook_Guard::LOG_LIMIT, 'Event log is bounded');
ob_start(); PMPro_FIC_Webhook_Guard::render_status(); $html = ob_get_clean();
check(str_contains($html, 'Guardia webhook primo incasso') && str_contains($html, 'WH-TEST-0001') && !str_contains($html, 'fake-paypal-secret'), 'Backend status shows recent events without secrets');

echo "PASS: $checks webhook-guard checks; the real PMPro 3.8.9 recurring-payment handler is executed after the guard; PayPal, WordPress and the PMPro data layer simulated, SQL on SQLite; no real payments, orders or documents.\n";
