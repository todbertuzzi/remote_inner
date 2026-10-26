<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/initial-pair.php */
define('PMPRO_FIC_AUTOMATION_BOOTSTRAP', true);
define('PMPRO_FIC_PAYPAL_BOOTSTRAP', true);
require __DIR__ . '/automation.php';
require __DIR__ . '/paypal-recovery.php';

function add_pmpro_membership_order_meta($id, $key, $value, $unique = false) {
    if (!empty($GLOBALS['fail_pair_meta']) || ($unique && isset($GLOBALS['order_meta'][$id][$key]))) return false;
    $GLOBALS['order_meta'][$id][$key] = $value;
    return true;
}
/** Executes actual SELECTs. Building the fixture does not construct MemberOrder. */
class PairDatabase extends AtomicOptionsDatabase {
    public $pmpro_membership_orders = 'wp_pmpro_membership_orders';
    public $fail_orders = false;
    private function rows($sql) {
        $this->last_error = '';
        if ($this->fail_orders) { $this->last_error = 'test failure'; return []; }
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE wp_pmpro_membership_orders (id INTEGER PRIMARY KEY, code TEXT, user_id INTEGER, membership_id INTEGER, status TEXT, gateway TEXT, gateway_environment TEXT, total TEXT, subscription_transaction_id TEXT, payment_transaction_id TEXT)');
        $columns = ['id','code','user_id','membership_id','status','gateway','gateway_environment','total','subscription_transaction_id','payment_transaction_id'];
        foreach ($GLOBALS['orders'] as $fields) {
            $row = array_merge(get_class_vars(MemberOrder::class), $fields);
            $insert = $db->prepare('INSERT INTO wp_pmpro_membership_orders VALUES (?,?,?,?,?,?,?,?,?,?)');
            $insert->execute(array_map(function ($key) use ($row) { return $row[$key]; }, $columns));
        }
        return $db->query($sql)->fetchAll(PDO::FETCH_OBJ);
    }
    public function get_row($sql) { return $this->rows($sql)[0] ?? null; }
    public function get_results($sql) { return $this->rows($sql); }
}
function pair_fixture() {
    global $orders, $order_meta, $settings_store, $wpdb, $paypal_transactions, $paypal_subscription, $paypal_calls;
    unset($GLOBALS['on_sale_request'], $GLOBALS['on_fic_request'], $GLOBALS['paypal_on_transactions']);
    $auto = automation_fixture();
    $orders[42]['payment_transaction_id'] = '';
    $orders[42]['total'] = '122.00';
    $orders[43] = array_merge($orders[42], ['id'=>43,'code'=>'INCASSO43','payment_transaction_id'=>'8LH19777VD3170157']);
    $order_meta[42][PMPro_FIC_Billing::META]['captured_at'] = '2026-10-14 10:20:00';
    $paypal_subscription = ['id'=>'I-TEST12345678','create_time'=>'2026-10-14T08:20:00Z'];
    $row = payment_row(); $row['amount_with_breakdown']['gross_amount']['value'] = '122.00';
    $paypal_transactions = ['transactions'=>[$row],'total_items'=>1,'total_pages'=>1,'links'=>[]];
    $paypal_calls = []; $GLOBALS['fail_pair_meta'] = false;
    $wpdb = new PairDatabase();
    return [new PMPro_FIC_Test_Orders([$GLOBALS['plugin'], 'order_context']), $auto];
}
function inspect_pair($service) { return $service->dispatch('inspect_initial_pair', ['order_id'=>'42']); }
function confirm_pair($service, $review) { return $service->dispatch('confirm_initial_pair', ['review_id'=>$review['review_id']]); }
function linked_pair() {
    [$service,$auto] = pair_fixture();
    $review = inspect_pair($service);
    check(is_array($review) && $review['stage'] === 'pair_review', 'Pair review available');
    $saved = confirm_pair($service, $review);
    check(is_array($saved) && $saved['stage'] === 'pair_linked', 'Pair confirmed');
    return [$service,$auto];
}
function pair_preview($service) {
    $GLOBALS['responses'] = [response([['id'=>9,'name'=>'PayPal Test']])];
    $loaded = $service->dispatch('load_order', ['order_id'=>'43']);
    check(is_array($loaded) && $loaded['billing_locked'], 'Target uses immutable checkout data');
    return $service->dispatch('preview_order', array_merge(customer_input(), ['order_id'=>'43']));
}
function no_pair() {
    check(PMPro_FIC_Atomic_Store::read(PMPro_FIC_Initial_Pair::key(42)) === null
        && empty($GLOBALS['order_meta'][43][PMPro_FIC_Billing::META]) && posts() === 0
        && $GLOBALS['orders'][42]['payment_transaction_id'] === '', 'Rejected pair cannot link, move IDs or issue documents');
}

[$service,$auto] = pair_fixture();
$before_orders = serialize($orders); $before_meta = serialize($order_meta);
$review = inspect_pair($service);
check(is_array($review) && $review['binding']['target_order_id'] === 43 && $review['binding']['payment']['cents'] === 12200, 'Discovers target rather than assuming adjacent numeric ID');
check(serialize($orders) === $before_orders && serialize($order_meta) === $before_meta && posts() === 0, 'Read-only inspection preserves orders and metadata');
check(count($paypal_calls) === 2 && count($sale_requests) === 1, 'Reads initial window and exact sale independently');
no_pair();
ob_start(); $service->render($review,'inspect_initial_pair'); $html = ob_get_clean();
check(str_contains($html,'Checkout #42') && str_contains($html,'ordine #43') && str_contains($html,'Conferma collegamento fiscale') && !str_contains($html,'fake-paypal-secret'), 'Review explains pair without credentials');
$saved = confirm_pair($service,$review);
check(is_array($saved) && $saved['stage'] === 'pair_linked' && $saved['order_id'] === 43, 'Confirms verified pair');
check(serialize($orders) === $before_orders && posts() === 0, 'Confirmation never alters either order or creates documents');
$copy = PMPro_FIC_Billing::snapshot(43);
check($copy['origin'] === 'initial_pair' && $copy['source_order_id'] === 42 && $copy['data'] === PMPro_FIC_Billing::snapshot(42)['data'], 'Fiscal origin retained from checkout');
check(count($paypal_calls) === 4 && count($sale_requests) === 2, 'Confirmation rechecks remote payment');
check(!str_contains(wp_json_encode(PMPro_FIC_Atomic_Store::read(PMPro_FIC_Initial_Pair::key(42))), 'fake-paypal-secret'), 'Canonical audit contains hashes, never credentials');
check(PMPro_FIC_Initial_Pair::guard(new MemberOrder(43), $plugin->order_context()) === true, 'Confirmed provenance validates');
error_is($service->dispatch('inspect_paypal_payment', ['order_id'=>'42']), 'paypal_recovery_linked');
$meta_saved = serialize($order_meta);
$again = inspect_pair($service);
check(serialize($order_meta) === $meta_saved, 'Repeated inspection remains read-only');
check(confirm_pair($service,$again)['stage'] === 'pair_linked' && serialize($order_meta) === $meta_saved, 'Repeated confirmation is idempotent');
// Pair target can use the normal preview/create flow, with global payment duplicate protection.
$preview = pair_preview($service);
check(is_array($preview) && $preview['stage'] === 'preview' && $preview['payload']['data']['entity']['name'] === 'Azienda Test', 'Preview uses source fiscal identity');
$GLOBALS['post_responses'] = [document_response()];
$created = $service->dispatch('create_order', ['preview_id'=>$preview['preview_id']]);
check(is_array($created) && $created['stage'] === 'created' && posts() === 1, 'One explicit invoice creation on payment order');
check($service->dispatch('load_order',['order_id'=>'43'])['stage'] === 'created' && posts() === 1, 'Reload reuses document');
check(serialize($orders) === $before_orders, 'Invoice creation retains both PMPro rows unchanged');
$authorized = PMPro_FIC_Renewals::authorize(new MemberOrder(43), get_option(PMPro_FIC_Automation::CONFIG));
check(is_array($authorized), 'Confirmed payment order can authorize future renewals');
$orders[44] = array_merge($orders[43], ['id'=>44,'payment_transaction_id'=>'NEWRENEWAL123']);
check(PMPro_FIC_Renewals::capture(new MemberOrder(44), get_option(PMPro_FIC_Automation::CONFIG)) === true, 'Actual later renewal inherits fiscal copy');
check(PMPro_FIC_Initial_Pair::guard(new MemberOrder(43)) === true, 'Later renewals do not invalidate initial provenance');

// Independent guards at initial analysis.
foreach (['third','user','level','total','pending','payment_duplicate','no_snapshot','foreign_snapshot','existing_snapshot','journal','lock','document','claim','multiple_remote','refund','wrong_sale','credentials','db','options_db','admin'] as $case) {
    [$service] = pair_fixture();
    switch ($case) {
        case 'third': $orders[44] = array_merge($orders[43],['id'=>44,'payment_transaction_id'=>'OTHERINCASSO']); break;
        case 'user': $orders[43]['user_id'] = 11; break;
        case 'level': $orders[43]['membership_id'] = 5; break;
        case 'total': $orders[43]['total'] = '121.99'; break;
        case 'pending': $orders[43]['status'] = 'pending'; break;
        case 'payment_duplicate': $orders[44] = array_merge($orders[43],['id'=>44,'subscription_transaction_id'=>'I-OTHER12345']); break;
        case 'no_snapshot': unset($order_meta[42]); break;
        case 'foreign_snapshot': $order_meta[42][PMPro_FIC_Billing::META]['origin'] = 'renewal'; break;
        case 'existing_snapshot': $order_meta[43][PMPro_FIC_Billing::META] = ['old'=>'keep']; break;
        case 'journal': $settings_store['pmpro_fic_test_order_43'] = ['status'=>'uncertain']; break;
        case 'lock': $settings_store['pmpro_fic_lock_42'] = 'locked'; break;
        case 'document': $order_meta[43][PMPro_FIC_Test_Orders::META] = ['document_id'=>999]; break;
        case 'claim': PMPro_FIC_Payments::reserve(new MemberOrder(43),123); break;
        case 'multiple_remote': $paypal_transactions['transactions'][] = array_merge($paypal_transactions['transactions'][0],['id'=>'SECONDINCASSO']); $paypal_transactions['total_items']=2; break;
        case 'refund': $paypal_transactions['transactions'][0]['status'] = 'REFUNDED'; break;
        case 'wrong_sale': $GLOBALS['sale_responses'] = [['status'=>200,'body'=>json_encode(['id'=>'WRONGSALE'])]]; break;
        case 'credentials': $settings_store['pmpro_paypal_client_secret_sandbox']=''; break;
        case 'db': $wpdb->fail_orders = true; break;
        case 'options_db': $wpdb->atomic_failure = true; break;
        case 'admin': $GLOBALS['admin'] = false; break;
    }
    $before = serialize([$orders,$order_meta]);
    check(is_wp_error(inspect_pair($service)), 'Rejects unsafe pair: ' . $case);
    check(serialize([$orders,$order_meta]) === $before && posts() === 0, 'No mutations for ' . $case);
}
// Actual target ID need not equal checkout + 1.
[$service] = pair_fixture(); $orders[91] = array_merge($orders[43],['id'=>91]); unset($orders[43]);
check(inspect_pair($service)['binding']['target_order_id'] === 91, 'Target identified by subscription and payment, not numeric adjacency');

// Review expiry, ownership and local/remote changes before confirmation.
foreach (['expiry','admin','nonce','user','snapshot','scope','remote','late_change'] as $case) {
    [$service] = pair_fixture(); $review=inspect_pair($service);
    switch ($case) {
        case 'expiry': $GLOBALS['clock'] += 601; break;
        case 'admin': $GLOBALS['admin_id'] = 2; break;
        case 'nonce':
            $GLOBALS['nonce'] = false; $_POST=['fic_action'=>'confirm_initial_pair','review_id'=>$review['review_id']];
            $rejected=false; try { $_SERVER['REQUEST_METHOD']='POST'; render_page(); } catch (InvalidNonce $e) { $rejected=true; }
            check($rejected, 'Entry point requires nonce before confirmation'); $_POST=[]; $GLOBALS['nonce']=true;
            no_pair(); continue 2;
        case 'user': $orders[43]['user_id']=11; break;
        case 'snapshot': $order_meta[42][PMPro_FIC_Billing::META]['data']['company']='Other Test'; break;
        case 'scope': $settings_store['pmpro_paypal_client_secret_sandbox']='changed'; break;
        case 'remote': $paypal_transactions['transactions'][0]['status']='REFUNDED'; break;
        case 'late_change': $GLOBALS['on_sale_request']=function () { $GLOBALS['orders'][42]['status']='refunded'; }; break;
    }
    check(is_wp_error(confirm_pair($service,$review)), 'Rejects changed review: ' . $case);
    no_pair();
}
// Persisted binding with an interrupted metadata write: inspection does not repair.
[$service] = pair_fixture(); $review=inspect_pair($service); $GLOBALS['fail_pair_meta']=true;
error_is(confirm_pair($service,$review),'pair_snapshot');
check(PMPro_FIC_Initial_Pair::source_link(42) === 43 && !isset($order_meta[43][PMPro_FIC_Billing::META]), 'Incomplete copy keeps canonical binding');
$GLOBALS['fail_pair_meta']=false; $review=inspect_pair($service);
check(!isset($order_meta[43][PMPro_FIC_Billing::META]), 'Read-only reanalysis never repairs metadata implicitly');
check(confirm_pair($service,$review)['stage']==='pair_linked', 'Explicit confirmation resumes interrupted copy');
check(posts()===0 && $orders[42]['payment_transaction_id']==='', 'Resumed copy has no invoice or ID write');

// A second confirmation cannot replace the canonical binding.
[$service] = pair_fixture(); $one=inspect_pair($service); $two=inspect_pair($service);
confirm_pair($service,$one); $canonical=PMPro_FIC_Atomic_Store::read(PMPro_FIC_Initial_Pair::key(42));
check(confirm_pair($service,$two)['stage']==='pair_linked' && PMPro_FIC_Atomic_Store::read(PMPro_FIC_Initial_Pair::key(42))===$canonical, 'Two reviewers share one immutable link');

// Guard before creation checks BOTH orders and snapshots, beyond target preview fingerprint.
foreach (['source_snapshot','source_status','source_invoice','target_snapshot','refund','scope','during_sale'] as $case) {
    [$service] = linked_pair(); $preview=pair_preview($service);
    switch ($case) {
        case 'source_snapshot': $order_meta[42][PMPro_FIC_Billing::META]['data']['company']='Changed Test'; break;
        case 'source_status': $orders[42]['status']='refunded'; break;
        case 'source_invoice': $settings_store['pmpro_fic_test_order_42']=['status'=>'pending']; break;
        case 'target_snapshot': $order_meta[43][PMPro_FIC_Billing::META]['data']['company']='Changed Test'; break;
        case 'refund': $sale_responses=[['status'=>200,'body'=>json_encode(['id'=>'8LH19777VD3170157','state'=>'refunded','billing_agreement_id'=>'I-TEST12345678'])]]; break;
        case 'scope': $settings_store['pmpro_paypal_client_secret_sandbox']='changed'; break;
        case 'during_sale': $GLOBALS['on_sale_request']=function () { $GLOBALS['order_meta'][42][PMPro_FIC_Billing::META]['data']['company']='Changed Test'; }; break;
    }
    check(is_wp_error($service->dispatch('create_order',['preview_id'=>$preview['preview_id']])), 'Stops invoice after ' . $case);
    check(posts()===0, 'No document POST after ' . $case);
}
[$service,$auto] = pair_fixture(); $auto->on_new_order(new MemberOrder(42));
confirm_pair($service,inspect_pair($service));
check(str_contains($auto->dispatch('auto_retry',['order_id'=>'42'])['message'],'#43'), 'Original queue directs admin to actual payment order');
ob_start(); $auto->render(null,''); $html=ob_get_clean();
check(str_contains($html,'Collegamento confermato') && str_contains($html,'Incasso sull’ordine #43'), 'Queue explains preserved original checkout');
$auto->process(42); check(posts()===0 && $orders[42]['payment_transaction_id']==='', 'Scheduled original job never copies ID or creates a duplicate');
// Malformed canonical record must fail closed without notices or overwrite.
[$service] = pair_fixture(); PMPro_FIC_Atomic_Store::insert(PMPro_FIC_Initial_Pair::key(42), ['schema_version'=>1]);
error_is(inspect_pair($service),'pair_storage');
check(posts()===0,'Corrupt storage cannot issue documents');
echo "PASS: {$checks} initial-pair checks; WordPress and APIs simulated, SQL SELECTs/reservations executed on SQLite.\n";
