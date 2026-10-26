<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/paypal-recovery.php */
if (!defined('PMPRO_FIC_ORDERS_BOOTSTRAP')) define('PMPRO_FIC_ORDERS_BOOTSTRAP', true);
require_once __DIR__ . '/manual-orders.php';
function wp_timezone() { return new DateTimeZone($GLOBALS['tz'] ?? 'Europe/Rome'); }

class PMPro_PayPal_API {
    public function get_access_token() { return 'fake-sandbox-token'; }
    public function get_subscription($id) {
        $GLOBALS['paypal_calls'][] = ['subscription', $id];
        return $GLOBALS['paypal_subscription'];
    }
    public function get_subscription_transactions($id, $start, $end) {
        $GLOBALS['paypal_calls'][] = ['transactions', $id, $start, $end];
        if (isset($GLOBALS['paypal_on_transactions'])) ($GLOBALS['paypal_on_transactions'])();
        return $GLOBALS['paypal_transactions'];
    }
    public function __call($method, $args) { throw new RuntimeException('Unexpected PayPal write: ' . $method); }
}

/** Executes the conditional update in SQLite, translating only MySQL's UPDATE JOIN syntax. */
class RecoveryDatabase extends AtomicOptionsDatabase {
    public $pmpro_membership_orders = 'wp_pmpro_membership_orders';
    public $queries = 0;
    public $before_update = null;
    public $query_error = false;
    public $after_readback = null;
    private $db;
    public function __construct() { parent::__construct(); $this->db = new PDO('sqlite::memory:'); }
    public function prepare($sql, ...$args) {
        $position = 0;
        $prepared = preg_replace_callback('/%[sd]/', function ($match) use ($args, &$position) {
            $value = $args[$position++];
            return $match[0] === '%d' ? (string) (int) $value : $this->db->quote((string) $value);
        }, $sql);
        if ($position !== count($args)) throw new RuntimeException('Wrong parameter count');
        return $prepared;
    }
    public function query($sql) {
        if (strpos($sql, 'wp_options') !== false) return parent::query($sql);
        $this->queries++;
        if ($this->query_error) return false;
        $this->db->exec('DROP TABLE IF EXISTS wp_pmpro_membership_orders');
        $this->db->exec('CREATE TABLE wp_pmpro_membership_orders (id INTEGER PRIMARY KEY, code TEXT, user_id INTEGER, membership_id INTEGER,
            status TEXT, gateway TEXT, gateway_environment TEXT, subscription_transaction_id TEXT, payment_transaction_id TEXT, total TEXT)');
        $columns = ['id','code','user_id','membership_id','status','gateway','gateway_environment','subscription_transaction_id','payment_transaction_id','total'];
        foreach ($GLOBALS['orders'] as $id => $data) {
            $order = new MemberOrder($id);
            $values = array_map(function ($key) use ($order) { return $order->$key; }, $columns);
            $statement = $this->db->prepare('INSERT INTO wp_pmpro_membership_orders VALUES (?,?,?,?,?,?,?,?,?,?)');
            $statement->execute($values);
        }
        if ($this->before_update) ($this->before_update)($this->db);
        if (!preg_match('/^UPDATE (\w+) AS target LEFT JOIN (\w+) AS taken\s+ON (.*?)\s+SET target.payment_transaction_id = (.*?)\s+WHERE (.*?) AND taken.id IS NULL$/s', $sql, $parts)) {
            throw new RuntimeException('Unexpected SQL for recovery');
        }
        if ($parts[1] !== $parts[2]) throw new RuntimeException('Wrong duplicate table');
        $translated = 'UPDATE ' . $parts[1] . ' AS target SET payment_transaction_id = ' . $parts[4] . ' WHERE ' . $parts[5]
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $parts[2] . ' AS taken WHERE ' . $parts[3] . ')';
        $changed = $this->db->exec($translated);
        foreach ($this->db->query('SELECT * FROM wp_pmpro_membership_orders', PDO::FETCH_ASSOC) as $row) {
            $GLOBALS['orders'][$row['id']] = array_merge($GLOBALS['orders'][$row['id']] ?? [], $row);
        }
        if ($this->after_readback) ($this->after_readback)();
        return $changed;
    }
}

function recovery_fixture() {
    global $orders, $order_meta, $settings_store, $paypal_subscription, $paypal_transactions, $paypal_calls, $wpdb;
    $service = fixture();
    $orders[42] = array_merge($orders[42], ['gateway' => 'paypal', 'subscription_transaction_id' => 'I-TEST12345678',
        'payment_transaction_id' => '', 'total' => '549.00']);
    $settings_store['pmpro_gateway_environment'] = 'sandbox';
    $settings_store['pmpro_paypal_client_id_sandbox'] = 'test-paypal-client';
    $settings_store['pmpro_paypal_client_secret_sandbox'] = 'test-paypal-secret-hidden';
    $order_meta[42][PMPro_FIC_Billing::META] = ['schema_version' => 1, 'order_id' => 42, 'user_id' => 10, 'level_id' => 4,
        'currency' => 'EUR', 'captured_at' => '2026-10-14 10:20:00', 'data' => [
            'customer_type' => 'business', 'company' => 'Azienda Test', 'vat_number' => '12345678903', 'email' => 'billing@example.test',
            'street' => 'Via Prova 1', 'zip' => '20100', 'city' => 'Milano', 'province' => 'MI', 'country' => 'IT',
        ]];
    $paypal_subscription = ['id' => 'I-TEST12345678', 'create_time' => '2026-10-14T08:20:00Z'];
    $paypal_transactions = ['transactions' => [payment_row()], 'total_items' => 1, 'total_pages' => 1, 'links' => []];
    $paypal_calls = [];
    unset($GLOBALS['paypal_on_transactions']);
    $wpdb = new RecoveryDatabase();
    return $service;
}
function payment_row() {
    return ['id' => '8LH19777VD3170157', 'status' => 'COMPLETED', 'time' => '2026-10-14T08:20:03Z',
        'amount_with_breakdown' => ['gross_amount' => ['currency_code' => 'EUR', 'value' => '549.00'],
            'net_amount' => ['currency_code' => 'EUR', 'value' => '530.00']]];
}
function prepare_recovery($service) { return $service->dispatch('inspect_paypal_payment', ['order_id' => '42']); }
function confirm_recovery($service, $review) { return $service->dispatch('confirm_paypal_payment', ['recovery_id' => $review['recovery_id']]); }
function no_write() {
    check($GLOBALS['wpdb']->queries === 0 && posts() === 0 && $GLOBALS['orders'][42]['payment_transaction_id'] === '', 'Rejected recovery cannot mutate order or create an invoice');
}

if (defined('PMPRO_FIC_PAYPAL_BOOTSTRAP')) return;

$service = recovery_fixture();
error_is($service->dispatch('load_order', ['order_id' => '42']), 'transaction_missing');
ob_start(); $service->render(new WP_Error('transaction_missing', 'ID mancante'), 'load_order'); $html = ob_get_clean();
check(str_contains($html, 'Verifica pagamento in PayPal Sandbox') && str_contains($html, '#fic-order-result'), 'Missing payment offers anchored manual verification');
$review = prepare_recovery($service);
check($review['stage'] === 'payment_review' && $review['payment']['cents'] === 54900 && $review['payment']['payment_id'] === '8LH19777VD3170157', 'Finds verified first payment with exact gross amount');
no_write();
check(count($paypal_calls) === 2 && $paypal_calls[1][2] === '2026-10-14T07:20:00Z' && $paypal_calls[1][3] === '2026-10-14T09:20:00Z', 'Reads only subscription and initial-window transactions in UTC');
ob_start(); $service->render($review, 'inspect_paypal_payment'); $html = ob_get_clean();
check(str_contains($html, '549,00') && str_contains($html, 'Associa l’ID verificato all’ordine') && !str_contains($html, 'test-paypal-secret-hidden'), 'Review displays evidence without credentials');
$original = $orders[42]; $original_snapshot = $order_meta[42][PMPro_FIC_Billing::META];
$saved = confirm_recovery($service, $review);
check($saved['stage'] === 'payment_saved' && $orders[42]['payment_transaction_id'] === '8LH19777VD3170157', 'Confirmed recovery updates existing payment ID');
check(count($paypal_calls) === 4 && $wpdb->queries === 1 && posts() === 0 && count($orders) === 1, 'Confirmation re-verifies PayPal, updates once and creates no orders or documents');
foreach ($original as $key => $value) if ($key !== 'payment_transaction_id') check($orders[42][$key] == $value, 'Preserves order property ' . $key);
check($order_meta[42][PMPro_FIC_Billing::META] === $original_snapshot, 'Fiscal snapshot preserved');
check($order_meta[42][PMPro_FIC_PayPal_Recovery::META]['status'] === 'updated' && !isset($settings_store['pmpro_fic_paypal_recovery_lock_42']), 'Recovery audited and lock released');
check(!str_contains(json_encode($settings_store['pmpro_fic_paypal_recovery_42']), 'test-paypal-secret-hidden'), 'Persistent audit excludes credentials');
error_is(confirm_recovery($service, $review), 'paypal_recovery_expired');
check($wpdb->queries === 1, 'Repeated confirmation cannot overwrite or duplicate');
$responses = [response([['id' => 9, 'name' => 'PayPal Test']])];
$edit = $service->dispatch('load_order', ['order_id' => '42']);
check($edit['stage'] === 'edit' && $edit['billing_locked'] && $edit['customer']['name'] === 'Azienda Test', 'Recovered order continues using captured company details');
$totals_responses = [response(['amount_net'=>450,'amount_vat'=>99,'amount_gross'=>549,'amount_due'=>549])];
$preview = $service->dispatch('preview_order', customer_input());
check($preview['gross'] === 549.0 && $preview['net'] === 450.0 && $preview['vat'] === 99.0
    && $preview['payload']['data']['entity']['vat_number'] === '12345678903', 'Recovered company order reaches invoice preview with captured VAT and matching totals');
check($preview['payload']['data']['payments_list'][0]['paid_date'] === '2026-10-14', 'Invoice uses verified PayPal payment date rather than checkout order timestamp');
$post_responses = [document_response(79, 549)];
$created = $service->dispatch('create_order', ['preview_id'=>$preview['preview_id']]);
check($created['stage'] === 'created' && posts() === 1 && count($paypal_calls) === 4, 'Only subsequent explicit invoice action creates the test document');
check($service->dispatch('load_order', ['order_id'=>'42'])['stage'] === 'created' && posts() === 1, 'Recovered order retains invoice duplicate protection');

// PMPro stores total as varchar(16), not a numeric SQL column.
foreach (['549', '549.0', '549.00'] as $stored_total) {
    $service = recovery_fixture(); $orders[42]['total'] = $stored_total;
    $review = prepare_recovery($service);
    $saved = confirm_recovery($service, $review);
    check(is_array($saved) && $saved['stage'] === 'payment_saved', 'Recovery works with PMPro varchar total ' . $stored_total);
    check($orders[42]['total'] === $stored_total && $orders[42]['payment_transaction_id'] === '8LH19777VD3170157', 'Preserves exact stored total ' . $stored_total);
}

$service = recovery_fixture(); $review = prepare_recovery($service); confirm_recovery($service, $review);
$responses = [response([['id'=>9,'name'=>'PayPal Test']])]; $service->dispatch('load_order', ['order_id'=>'42']);
$order_meta[42][PMPro_FIC_PayPal_Recovery::META]['payment']['cents'] = 54800;
error_is($service->dispatch('preview_order', customer_input()), 'payment_recovery_data');
check(posts() === 0, 'Corrupted verified payment blocks invoice preview');

$service = recovery_fixture(); $review = prepare_recovery($service); confirm_recovery($service, $review);
$responses = [response([['id'=>9,'name'=>'PayPal Test']])]; $service->dispatch('load_order', ['order_id'=>'42']);
$totals_responses = [response(['amount_net'=>450,'amount_vat'=>99,'amount_gross'=>549,'amount_due'=>549])];
$preview = $service->dispatch('preview_order', customer_input());
$order_meta[42][PMPro_FIC_PayPal_Recovery::META]['payment']['paid_at'] = '2026-10-14T08:21:00Z';
error_is($service->dispatch('create_order', ['preview_id'=>$preview['preview_id']]), 'order_changed');
check(posts() === 0, 'Recovery data changes invalidate an existing invoice preview');

foreach ([['gateway','paypalexpress','paypal_recovery_gateway'], ['gateway_environment','live','sandbox_required'],
    ['status','token','not_paid'], ['total','0','amount'], ['payment_transaction_id','ALREADYPRESENT','payment_present'],
    ['subscription_transaction_id','','paypal_recovery_subscription']] as [$key,$value,$code]) {
    $service = recovery_fixture(); $orders[42][$key] = $value;
    error_is(prepare_recovery($service), $code);
    check(!$paypal_calls && !$wpdb->queries, 'Eligibility rejects ' . $key . ' before PayPal access');
}
$service = recovery_fixture(); $settings_store['pmpro_gateway_environment'] = 'live';
error_is(prepare_recovery($service), 'paypal_recovery_gateway'); no_write();
$service = recovery_fixture(); unset($settings_store['pmpro_paypal_client_secret_sandbox']);
error_is(prepare_recovery($service), 'paypal_recovery_credentials'); no_write();
$service = recovery_fixture(); unset($order_meta[42][PMPro_FIC_Billing::META]);
error_is(prepare_recovery($service), 'paypal_recovery_snapshot'); no_write();
$service = recovery_fixture(); $order_meta[42][PMPro_FIC_Billing::META]['user_id'] = 99;
error_is(prepare_recovery($service), 'paypal_recovery_snapshot'); no_write();
$service = recovery_fixture(); $settings_store['pmpro_fic_lock_42'] = 'invoice-in-flight';
error_is(prepare_recovery($service), 'paypal_recovery_document'); no_write();
$service = recovery_fixture(); $orders[43] = array_merge($orders[42], ['id' => 43]);
error_is(prepare_recovery($service), 'paypal_recovery_multiple_orders'); no_write();
$service = recovery_fixture(); $paypal_subscription = new WP_Error('api', 'secret email');
error_is(prepare_recovery($service), 'paypal_recovery_api'); no_write();
$service = recovery_fixture(); $paypal_subscription['id'] = 'I-OTHER123456';
error_is(prepare_recovery($service), 'paypal_recovery_remote_subscription'); no_write();
$service = recovery_fixture(); $paypal_subscription['create_time'] = '2026-10-10T08:20:00Z';
error_is(prepare_recovery($service), 'paypal_recovery_time'); no_write();
$service = recovery_fixture(); $paypal_subscription['create_time'] = '2026-02-31T08:20:00Z';
error_is(prepare_recovery($service), 'paypal_recovery_time'); no_write();
$service = recovery_fixture(); $paypal_transactions = new WP_Error('api', 'secret email');
error_is(prepare_recovery($service), 'paypal_recovery_api'); no_write();

foreach (['PENDING' => 'paypal_recovery_not_found','FAILED' => 'paypal_recovery_not_found',
    'REFUNDED' => 'paypal_recovery_refunded','PARTIALLY_REFUNDED' => 'paypal_recovery_refunded','UNKNOWN' => 'paypal_recovery_response'] as $status => $code) {
    $service = recovery_fixture(); $paypal_transactions['transactions'][0]['status'] = $status;
    error_is(prepare_recovery($service), $code); no_write();
}
foreach ([['currency_code','USD'],['value','549.01'],['value','530.00']] as [$key,$value]) {
    $service = recovery_fixture(); $paypal_transactions['transactions'][0]['amount_with_breakdown']['gross_amount'][$key] = $value;
    error_is(prepare_recovery($service), 'paypal_recovery_amount'); no_write();
}
foreach (['549.001', '5.49e2', '-549.00', '0.00'] as $value) {
    $service = recovery_fixture(); $paypal_transactions['transactions'][0]['amount_with_breakdown']['gross_amount']['value'] = $value;
    error_is(prepare_recovery($service), 'paypal_recovery_response'); no_write();
}
$service = recovery_fixture(); $paypal_transactions['transactions'][0]['time'] = '2026-10-15T08:20:03Z';
error_is(prepare_recovery($service), 'paypal_recovery_response'); no_write();
$service = recovery_fixture(); $paypal_transactions['transactions'][] = array_merge(payment_row(), ['id' => 'SECONDTRANSACTION1']); $paypal_transactions['total_items'] = 2;
error_is(prepare_recovery($service), 'paypal_recovery_ambiguous'); no_write();
$service = recovery_fixture(); $paypal_transactions['total_pages'] = 2;
error_is(prepare_recovery($service), 'paypal_recovery_pagination'); no_write();
foreach ([-1, 0, '1'] as $pages) {
    $service = recovery_fixture(); $paypal_transactions['total_pages'] = $pages;
    error_is(prepare_recovery($service), 'paypal_recovery_pagination'); no_write();
}
$service = recovery_fixture(); $paypal_transactions['total_items'] = 2;
error_is(prepare_recovery($service), 'paypal_recovery_pagination'); no_write();
$service = recovery_fixture(); $paypal_transactions['links'] = [['rel' => 'next']];
error_is(prepare_recovery($service), 'paypal_recovery_pagination'); no_write();
$service = recovery_fixture(); $orders[43] = array_merge($orders[42], ['id' => 43, 'subscription_transaction_id' => 'I-OTHER123456', 'payment_transaction_id' => '8LH19777VD3170157']);
error_is(prepare_recovery($service), 'paypal_recovery_duplicate'); no_write();

foreach (['user_id'=>99,'membership_id'=>5,'total'=>'548.00','code'=>'OTHERORDER'] as $key => $value) {
    $service = recovery_fixture(); $review = prepare_recovery($service); $orders[42][$key] = $value;
    $expected = in_array($key, ['user_id','membership_id'], true) ? 'paypal_recovery_snapshot' : 'order_changed';
    error_is(confirm_recovery($service, $review), $expected); no_write();
}
$service = recovery_fixture(); $review = prepare_recovery($service); $order_meta[42][PMPro_FIC_Billing::META]['data']['street'] = 'Changed';
error_is(confirm_recovery($service, $review), 'order_changed'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service); $settings_store['pmpro_paypal_client_secret_sandbox'] = 'changed';
error_is(confirm_recovery($service, $review), 'paypal_recovery_expired'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service); $admin_id = 2;
error_is(confirm_recovery($service, $review), 'paypal_recovery_expired'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service); $clock += 601;
error_is(confirm_recovery($service, $review), 'paypal_recovery_expired'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service); $admin = false;
error_is(confirm_recovery($service, $review), 'forbidden'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service); $paypal_transactions['transactions'][0]['status'] = 'REFUNDED';
error_is(confirm_recovery($service, $review), 'paypal_recovery_refunded'); no_write();
check(!isset($settings_store['pmpro_fic_paypal_recovery_lock_42']), 'Failed re-verification releases lock');
$service = recovery_fixture(); $review = prepare_recovery($service); $paypal_transactions['transactions'][0]['id'] = 'CHANGEDPAYMENT123';
error_is(confirm_recovery($service, $review), 'paypal_recovery_changed'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service); $fail_storage = true;
error_is(confirm_recovery($service, $review), 'paypal_recovery_storage'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service); $settings_store['pmpro_fic_paypal_recovery_lock_42'] = 'other';
error_is(confirm_recovery($service, $review), 'paypal_recovery_locked'); no_write();
$service = recovery_fixture(); $review = prepare_recovery($service);
$paypal_on_transactions = function () { $GLOBALS['settings_store']['pmpro_paypal_client_id_sandbox'] = 'changed-mid-call'; };
error_is(confirm_recovery($service, $review), 'paypal_recovery_expired'); no_write();

// SQL must reject changes after the final application check, without restoring stale values.
foreach (['status' => 'refunded','user_id' => 99,'membership_id' => 5,'code' => 'changed','total' => 548,
    'subscription_transaction_id' => 'I-CHANGED12345','gateway_environment' => 'live','payment_transaction_id' => 'OTHERPAYMENT123'] as $key => $value) {
    $service = recovery_fixture(); $review = prepare_recovery($service);
    $wpdb->before_update = function ($db) use ($key,$value) { $statement = $db->prepare('UPDATE wp_pmpro_membership_orders SET ' . $key . ' = ? WHERE id = 42'); $statement->execute([$value]); };
    error_is(confirm_recovery($service, $review), 'paypal_recovery_storage');
    check($orders[42][$key] == $value && $orders[42]['payment_transaction_id'] !== '8LH19777VD3170157', 'Atomic update protects concurrent ' . $key . ' change');
}
$service = recovery_fixture(); $review = prepare_recovery($service);
$wpdb->before_update = function ($db) { $db->exec("INSERT INTO wp_pmpro_membership_orders SELECT 43, code, user_id, membership_id, status, gateway,
    gateway_environment, 'I-OTHER123456', '8LH19777VD3170157', total FROM wp_pmpro_membership_orders WHERE id = 42"); };
error_is(confirm_recovery($service, $review), 'paypal_recovery_storage');
check($orders[42]['payment_transaction_id'] === '' && $orders[43]['payment_transaction_id'] === '8LH19777VD3170157', 'Atomic anti-join rejects payment assigned elsewhere concurrently');

$service = recovery_fixture(); $review = prepare_recovery($service);
$wpdb->before_update = function ($db) { $db->exec("UPDATE wp_pmpro_membership_orders SET total = '549' WHERE id = 42"); };
error_is(confirm_recovery($service, $review), 'paypal_recovery_storage');
check($orders[42]['payment_transaction_id'] === '' && $orders[42]['total'] === '549', 'Atomic update preserves even a concurrent formatting change');
check($settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'verified', 'Failed update keeps verification without claiming success');
$wpdb->before_update = null;
$review = prepare_recovery($service); $saved = confirm_recovery($service, $review);
check(is_array($saved) && $saved['stage'] === 'payment_saved' && $orders[42]['total'] === '549', 'Fresh verification can recover after a failed update with an existing audit');

$service = recovery_fixture(); $review = prepare_recovery($service); $wpdb->query_error = true;
$error = confirm_recovery($service, $review); error_is($error, 'paypal_recovery_query');
check(str_contains($error->get_error_message(), 'PAYPAL-DB-QUERY') && $orders[42]['payment_transaction_id'] === '' && posts() === 0, 'Database execution error is distinguished from an order conflict without writes');
check(!isset($settings_store['pmpro_fic_paypal_recovery_lock_42']), 'Database error releases recovery lock');

$service = recovery_fixture(); $review = prepare_recovery($service);
$wpdb->after_readback = function () { $GLOBALS['orders'][42]['payment_transaction_id'] = ''; };
$error = confirm_recovery($service, $review); error_is($error, 'paypal_recovery_readback');
check(str_contains($error->get_error_message(), 'PAYPAL-DB-READBACK') && $settings_store['pmpro_fic_paypal_recovery_42']['status'] === 'verified', 'Readback inconsistency does not report success or falsely deny database update');
check(!isset($settings_store['pmpro_fic_paypal_recovery_lock_42']) && posts() === 0, 'Readback failure releases lock and never creates an invoice');

$service = recovery_fixture(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['fic_action' => 'inspect_paypal_payment', 'order_id' => '42']; $nonce = false;
try { render_page(); throw new RuntimeException('Invalid nonce accepted'); } catch (InvalidNonce $e) { check(!$paypal_calls && !$wpdb->queries, 'Page nonce checked before verification'); }
$nonce = true; $html = render_page();
check(str_contains($html, 'Pagamento verificato') && !str_contains($html, 'test-paypal-secret-hidden'), 'Page handles verified payment review');
$token = array_values(array_filter(array_keys($cache), function ($key) { return str_starts_with($key, 'pmpro_fic_paypal_review_'); }))[0];
$_POST = ['fic_action' => 'confirm_paypal_payment', 'recovery_id' => substr($token, strlen('pmpro_fic_paypal_review_'))];
check(str_contains(render_page(), 'ID pagamento recuperato') && $wpdb->queries === 1, 'Page handles explicit confirmation');
check(posts() === 0, 'Recovery never creates Fatture in Cloud documents');
echo "PASS: {$checks} PayPal recovery checks; PayPal/WordPress simulated, conditional update exercised on SQLite.\n";
