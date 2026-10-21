<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/automation.php */
if (!defined('PMPRO_FIC_ORDERS_BOOTSTRAP')) define('PMPRO_FIC_ORDERS_BOOTSTRAP', true);
$access_only = in_array('--access-only', $argv, true);
if (!$access_only && !defined('DOING_CRON')) define('DOING_CRON', true);
function wp_next_scheduled($hook, $args = []) { return $GLOBALS['events'][$hook . ':' . json_encode($args)] ?? false; }
function wp_schedule_single_event($at, $hook, $args = [], $wp_error = false) {
    if (!empty($GLOBALS['cron_error'])) return new WP_Error('cron', 'Simulated cron storage error');
    $key = $hook . ':' . json_encode($args);
    if (isset($GLOBALS['events'][$key])) return false;
    $GLOBALS['events'][$key] = $at;
    return true;
}
function wp_cache_delete($key, $group = '') {}
require_once __DIR__ . '/manual-orders.php';

class AutomationOptionsDatabase {
    public $options = 'wp_options';
    public $before_delete = null;
    public function delete($table, $where, $format) {
        if ($this->before_delete) ($this->before_delete)();
        $key = $where['option_name'];
        if (!array_key_exists($key, $GLOBALS['settings_store']) || (string) $GLOBALS['settings_store'][$key] !== $where['option_value']) return 0;
        unset($GLOBALS['settings_store'][$key]);
        return 1;
    }
}
function automation_fixture($enable = true) {
    global $orders, $order_meta, $settings_store, $plugin, $responses, $wpdb;
    fixture();
    $GLOBALS['events'] = []; $GLOBALS['cron_error'] = false;
    unset($GLOBALS['on_fic_request']);
    $orders[42] = array_merge($orders[42], ['gateway' => 'paypal', 'subscription_transaction_id' => 'I-TEST12345678']);
    $settings_store['pmpro_gateway'] = 'paypal'; $settings_store['pmpro_gateway_environment'] = 'sandbox'; $settings_store['pmpro_currency'] = 'EUR';
    $settings_store['pmpro_paypal_client_id_sandbox'] = 'fake-paypal-client'; $settings_store['pmpro_paypal_client_secret_sandbox'] = 'fake-paypal-secret';
    $order_meta[42][PMPro_FIC_Billing::META] = ['schema_version'=>1, 'order_id'=>42, 'user_id'=>10, 'level_id'=>4,
        'currency'=>'EUR', 'captured_at'=>'2026-10-14 12:00:00', 'data'=>['customer_type'=>'business', 'company'=>'Azienda Test',
            'vat_number'=>'12345678903', 'email'=>'billing@example.test', 'street'=>'Via Prova 1', 'zip'=>'20100', 'city'=>'Milano', 'province'=>'MI', 'country'=>'IT']];
    $wpdb = new AutomationOptionsDatabase();
    $auto = new PMPro_FIC_Automation($plugin);
    if ($enable) {
        $responses = [response([['id'=>9, 'name'=>'PayPal Test']])];
        $result = $auto->dispatch('auto_accounts', []);
        check(is_array($result), 'Can load verified company payment accounts');
        $result = $auto->dispatch('auto_enable', ['account_id'=>'9', 'confirm_test'=>'1']);
        check(is_array($result) && $settings_store[PMPro_FIC_Automation::CONFIG]['active'], 'Explicit opt-in enables Sandbox automation');
    }
    return $auto;
}
function job42() { return get_option('pmpro_fic_job_42', []); }
function birth($auto) { $auto->on_new_order(new MemberOrder(42)); }
function run_job($auto) {
    unset($GLOBALS['events'][PMPro_FIC_Automation::HOOK . ':[42]']);
    $GLOBALS['admin'] = false; $GLOBALS['admin_id'] = 0;
    $auto->process(42);
    $GLOBALS['admin'] = true; $GLOBALS['admin_id'] = 1;
}
function account_response() { $GLOBALS['responses'] = [response([['id'=>9, 'name'=>'PayPal Test']])]; }
function expected_document() { account_response(); $GLOBALS['post_responses'] = [document_response(101, 122)]; }
function assert_no_document($label) { check(posts() === 0, $label); }

if (defined('PMPRO_FIC_AUTOMATION_BOOTSTRAP')) return;

if ($access_only) {
    $auto = automation_fixture(); $admin = false; $admin_id = 0;
    birth($auto);
    check(job42()['state'] === 'queued' && posts() === 0, 'Anonymous payment hook can enqueue a verified order');
    $auto->process(42);
    check(job42()['state'] === 'queued' && posts() === 0, 'Anonymous non-cron request cannot execute worker');
    error_is($plugin->background_context(), 'forbidden');
    error_is((new PMPro_FIC_Test_Orders([$plugin,'background_context']))->automate(42, [], 9, function () { throw new RuntimeException('Guard must not run'); }), 'forbidden');
    error_is($auto->dispatch('auto_run', ['order_id'=>'42']), 'forbidden');
    error_is((new PMPro_FIC_Test_Orders([$plugin,'background_context']))->reconcile_payment(42, function () { throw new RuntimeException('Guard must not run'); }), 'forbidden');
    echo "PASS: {$checks} automation access checks; no external writes.\n";
    return;
}

$auto = automation_fixture(false); $options['enabled'] = 1; birth($auto);
check(!job42() && !$events && posts() === 0, 'Legacy flag never enables automation');
$orders[42]['status'] = 'success'; $auto->on_updated_order(new MemberOrder(42));
check(!job42(), 'Updates of historical orders do not backfill jobs');
error_is($auto->dispatch('auto_enable', ['account_id'=>'9', 'confirm_test'=>'1']), 'account_required');
error_is($auto->dispatch('auto_enable', ['account_id'=>'9']), 'confirm_test');
foreach (['pmpro_gateway'=>'paypalexpress', 'pmpro_gateway_environment'=>'live', 'pmpro_currency'=>'USD'] as $key=>$value) {
    $auto = automation_fixture(false); $settings_store[$key] = $value;
    error_is($auto->dispatch('auto_accounts', []), 'auto_sandbox'); assert_no_document('Rejects unsafe activation ' . $key);
}

$auto = automation_fixture(); $requests = []; birth($auto);
check(job42()['state'] === 'queued' && count($events) === 1 && !$requests && posts() === 0, 'Payment hook queues without FIC network activity');
birth($auto); $auto->on_updated_order(new MemberOrder(42)); $auto->after_checkout(10, new MemberOrder(42));
check(count($events) === 1 && count($settings_store[PMPro_FIC_Automation::INDEX]) === 1, 'Repeated lifecycle hooks create one job and one event');
expected_document(); run_job($auto);
check(job42()['state'] === 'created' && job42()['document_id'] === 101 && posts() === 1, 'Anonymous cron creates a document after confirmed payment');
$payload = json_decode($created_requests[0][1]['body'], true);
check($payload['data']['entity']['name'] === 'Azienda Test' && $payload['data']['entity']['vat_number'] === '12345678903', 'Automatic invoice uses captured company fiscal data');
check($payload['data']['numeration'] === '/TEST' && $payload['data']['e_invoice'] === false && $payload['data']['payments_list'][0]['payment_account']['id'] === 9, 'Automatic document stays non-electronic in TEST with chosen account');
check($order_meta[42][PMPro_FIC_Test_Orders::META]['document_id'] === 101, 'Manual and automatic documents share PMPro association');
check(!isset($settings_store['pmpro_fic_job_lock_42']) && !isset($settings_store['pmpro_fic_lock_42']), 'All locks released after confirmed creation');
run_job($auto); birth($auto); $auto->on_updated_order(new MemberOrder(42));
check(posts() === 1, 'Replayed worker and lifecycle hooks cannot create another invoice');
ob_start(); $auto->render(null, ''); $html = ob_get_clean();
check(str_contains($html, 'Documento associato') && str_contains($html, '101') && !str_contains($html, 'test-secret-not-for-html') && !str_contains($html, 'fake-paypal-secret'), 'Backend shows document result without credentials');
check(!str_contains(json_encode(job42()), 'billing@example.test') && !str_contains(json_encode(job42()), 'fake-paypal-secret'), 'Queue stores references and hashes without fiscal data or secrets');
error_is((new PMPro_FIC_Test_Orders([$plugin, 'order_context']))->dispatch('create_order', []), 'preview_missing');
check(posts() === 1, 'Cron does not bypass manual preview requirements');

$auto = automation_fixture();
$orders[42]['status'] = 'token'; $orders[42]['payment_transaction_id'] = ''; $orders[42]['subscription_transaction_id'] = '';
birth($auto); run_job($auto);
check(job42()['state'] === 'waiting' && job42()['attempts'] === 1 && count($events) === 1, 'Checkout captured before subscription assignment waits for payment');
$orders[42]['subscription_transaction_id'] = 'I-TEST12345678'; $auto->on_updated_order(new MemberOrder(42));
check(job42()['subscription_id'] === 'I-TEST12345678', 'Subscription reference captured on subsequent gateway save');
$orders[42]['status'] = 'success'; $orders[42]['payment_transaction_id'] = 'PAYPAL-TEST-1'; $auto->after_checkout(10, new MemberOrder(42));
expected_document(); run_job($auto);
check(job42()['state'] === 'created' && posts() === 1, 'Later successful payment advances same job');

$auto = automation_fixture(); $orders[42]['payment_transaction_id'] = ''; birth($auto);
for ($i=0; $i<6; $i++) run_job($auto);
check(job42()['state'] === 'blocked' && job42()['attempts'] === 6 && !$events && posts() === 0, 'Missing payment ID stops after bounded retries without a document');
$orders[42]['payment_transaction_id'] = 'PAYPAL-TEST-1';
check(is_array($auto->dispatch('auto_retry', ['order_id'=>'42'])), 'Administrator can retry after fixing missing ID');
expected_document(); run_job($auto); check(job42()['state'] === 'created', 'Fixed ID can complete explicitly retried job');

foreach ([['gateway','paypalexpress'],['gateway_environment','live'],['total','0'],['status','refunded']] as [$key,$value]) {
    $auto = automation_fixture(); $orders[42][$key] = $value; birth($auto);
    check(!job42() && !$events && posts() === 0, 'Does not queue ineligible order ' . $key);
}
$auto = automation_fixture(); unset($order_meta[42][PMPro_FIC_Billing::META]); birth($auto);
check(!job42(), 'Renewal without its own checkout snapshot cannot enter queue');
$auto = automation_fixture(); birth($auto); $orders[43] = array_merge($orders[42], ['id'=>43]); run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Ambiguous multiple subscription orders block creation');
$auto = automation_fixture(); birth($auto); $orders[43] = array_merge($orders[42], ['id'=>43, 'subscription_transaction_id'=>'I-OTHER12345']); run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Payment ID shared across orders blocks creation');

foreach (['status'=>'refunded', 'total'=>'123', 'user_id'=>20, 'subscription_transaction_id'=>'I-CHANGED12345'] as $key=>$value) {
    $auto = automation_fixture(); birth($auto); $orders[42][$key] = $value; run_job($auto);
    check(job42()['state'] === 'blocked' && posts() === 0, 'Worker rejects changed order ' . $key);
}
$auto = automation_fixture(); birth($auto); $order_meta[42][PMPro_FIC_Billing::META]['data']['street'] = 'Changed'; run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Worker rejects changed fiscal snapshot');
foreach (['pmpro_gateway_environment'=>'live', 'pmpro_currency'=>'USD', 'pmpro_paypal_client_secret_sandbox'=>'changed'] as $key=>$value) {
    $auto = automation_fixture(); birth($auto); $settings_store[$key] = $value; run_job($auto);
    check(job42()['state'] === 'blocked' && posts() === 0, 'Worker rejects configuration drift ' . $key);
}
$auto = automation_fixture(); birth($auto); $auto->dispatch('auto_pause', []); run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Pause stops already queued work');
$auto = automation_fixture(); birth($auto); $plugin->sanitize_options(['company_id'=>'123', 'access_token'=>'', 'vat_id'=>'0', 'document_prefix'=>'New description']);
check(!$settings_store[PMPro_FIC_Automation::CONFIG]['active'], 'Changing FIC settings disables automation');

$auto = automation_fixture(); birth($auto); $clock += HOUR_IN_SECONDS + 1;
valid_responses(); $responses[] = response([['id'=>9, 'name'=>'PayPal Test']]); $post_responses = [document_response(101,122)]; run_job($auto);
check(job42()['state'] === 'created' && posts() === 1 && count($requests) === 6, 'Cron revalidates company and VAT after connection cache expires');
$auto = automation_fixture(); birth($auto); $clock += HOUR_IN_SECONDS + 1;
$responses = [response(['companies'=>[['id'=>123,'name'=>'Azienda test']]]), response([['id'=>0,'value'=>10,'description'=>'IVA ordinaria']])]; run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Changed remote VAT rate cannot silently alter authorized automation');

$auto = automation_fixture(); birth($auto); $responses = [new WP_Error('network', 'Simulated timeout')]; run_job($auto);
check(job42()['state'] === 'waiting' && posts() === 0, 'Read-only account timeout is safely retried');
$auto = automation_fixture(); birth($auto); account_response(); $totals_responses = [response([],503)]; run_job($auto);
check(job42()['state'] === 'waiting' && posts() === 0, 'Transient totals error is safely retried');
$auto = automation_fixture(); birth($auto); account_response(); $totals_responses = [response([],422)]; run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Definitive totals validation failure needs correction');
$auto = automation_fixture(); birth($auto); account_response(); $totals_responses = [response(['amount_net'=>100,'amount_vat'=>23,'amount_gross'=>123,'amount_due'=>123])]; run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Mismatched totals never reach document creation');
$auto = automation_fixture(); birth($auto); account_response(); $totals_responses = [response(['amount_net'=>101,'amount_vat'=>21,'amount_gross'=>122,'amount_due'=>122])]; run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Same gross with unexpected remote VAT components cannot silently use a changed rate');
$auto = automation_fixture(); birth($auto); $responses = [response([['id'=>10,'name'=>'Another account']])]; run_job($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Deleted configured payment account blocks creation');

foreach ([400=>'blocked',401=>'blocked',422=>'blocked',429=>'waiting',500=>'attention'] as $http=>$state) {
    $auto = automation_fixture(); birth($auto); account_response(); $post_responses = [response([], $http)]; run_job($auto);
    check(job42()['state'] === $state && posts() === 1, 'HTTP ' . $http . ' after POST classified safely');
    if ($state === 'attention') {
        error_is($auto->dispatch('auto_retry', ['order_id'=>'42']), 'uncertain');
        run_job($auto); check(posts() === 1, 'Uncertain POST is never blindly retried');
    }
}
$auto = automation_fixture(); birth($auto); account_response(); $post_responses = [new WP_Error('network','Simulated timeout')]; run_job($auto);
check(job42()['state'] === 'attention' && get_option('pmpro_fic_test_order_42')['status'] === 'uncertain', 'POST timeout keeps uncertain journal and requires remote review');
error_is($auto->dispatch('auto_retry', ['order_id'=>'42']), 'uncertain');

$auto = automation_fixture(); birth($auto);
$service = new PMPro_FIC_Test_Orders([$plugin,'order_context']); account_response(); $service->dispatch('load_order', ['order_id'=>'42']);
$preview = $service->dispatch('preview_order', customer_input()); $post_responses = [document_response(102,122)]; $service->dispatch('create_order', ['preview_id'=>$preview['preview_id']]);
run_job($auto);
check(job42()['state'] === 'created' && job42()['document_id'] === 102 && posts() === 1, 'Automatic worker recognizes manual invoice and repairs queue without duplicate POST');
$auto = automation_fixture(); birth($auto); $fail_meta = true; expected_document(); run_job($auto);
check(job42()['state'] === 'attention' && posts() === 1, 'Remote creation with metadata failure stops repeated POST');
$fail_meta = false; $auto->dispatch('auto_retry', ['order_id'=>'42']); run_job($auto);
check(job42()['state'] === 'created' && posts() === 1 && $order_meta[42][PMPro_FIC_Test_Orders::META]['document_id'] === 101, 'Explicit retry repairs local metadata using existing document');

$auto = automation_fixture(); birth($auto); $settings_store['pmpro_fic_job_lock_42'] = time(); run_job($auto);
check(job42()['state'] === 'queued' && posts() === 0, 'Concurrent worker cannot acquire job lock');
error_is($auto->dispatch('auto_retry',['order_id'=>'42']), 'auto_locked');
$settings_store['pmpro_fic_job_lock_42'] = time() - 901;
check(is_array($auto->dispatch('auto_retry',['order_id'=>'42'])), 'Abandoned old lock with no POST journal can be recovered explicitly');
$auto = automation_fixture(); birth($auto); $settings_store['pmpro_fic_job_lock_42'] = time() - 901;
$settings_store['pmpro_fic_test_order_42'] = ['status'=>'uncertain'];
error_is($auto->dispatch('auto_retry',['order_id'=>'42']), 'auto_locked');
check(isset($settings_store['pmpro_fic_job_lock_42']), 'Old lock is retained while POST result is uncertain');
$auto = automation_fixture(); birth($auto); $settings_store['pmpro_fic_job_lock_42'] = time() - 901;
$wpdb->before_delete = function () { $GLOBALS['settings_store']['pmpro_fic_job_lock_42'] = time(); };
error_is($auto->dispatch('auto_retry',['order_id'=>'42']), 'auto_locked');
check(time() - $settings_store['pmpro_fic_job_lock_42'] < 10, 'Atomic deletion preserves a concurrently replaced lock');

$auto = automation_fixture(); $cron_error = true; birth($auto);
check(job42()['state'] === 'blocked' && posts() === 0, 'Cron scheduling failure is visible');
$auto = automation_fixture(); birth($auto); $fail_storage = true; expected_document(); run_job($auto);
check(posts() === 0, 'Worker does not POST if job progress cannot be persisted');
$fail_storage = false;

foreach (['pause', 'refund', 'token', 'fiscal'] as $change) {
    $auto = automation_fixture(); birth($auto); expected_document();
    $on_fic_request = function ($url) use ($change, $auto) {
        if (substr($url, -24) !== '/issued_documents/totals') return;
        if ($change === 'pause') $auto->invalidate();
        elseif ($change === 'refund') $GLOBALS['orders'][42]['status'] = 'refunded';
        elseif ($change === 'token') $GLOBALS['options']['access_token'] = 'changed-before-post';
        else $GLOBALS['order_meta'][42][PMPro_FIC_Billing::META]['data']['street'] = 'Concurrent change';
    };
    run_job($auto);
    check(job42()['state'] === 'blocked' && posts() === 0, 'Recheck before POST rejects concurrent ' . $change);
    check(!isset($settings_store['pmpro_fic_lock_42']), 'Concurrent ' . $change . ' does not leave document lock');
}
$auto = automation_fixture(); birth($auto);
$generation = $settings_store[PMPro_FIC_Automation::CONFIG]['generation'];
$auto->dispatch('auto_enable', ['account_id'=>'9','confirm_test'=>'1']);
check($settings_store[PMPro_FIC_Automation::CONFIG]['generation'] === $generation, 'Repeated activation of unchanged settings preserves queued authorization');
expected_document(); run_job($auto); check(job42()['state'] === 'created', 'Repeated unchanged activation does not block queued order');

$auto = automation_fixture(); $orders[42]['total'] = '122'; birth($auto); expected_document(); run_job($auto);
check(job42()['state'] === 'created' && $orders[42]['total'] === '122', 'Automation preserves PMPro varchar total format');
$auto = automation_fixture();
$order_meta[42][PMPro_FIC_Billing::META]['data'] = ['customer_type'=>'private','first_name'=>'Mario','last_name'=>'Rossi',
    'tax_code'=>'RSSMRA80A01H501U','email'=>'private@example.test','street'=>'Via Prova 2','zip'=>'00100','city'=>'Roma','province'=>'RM','country'=>'IT'];
birth($auto); expected_document(); run_job($auto);
$payload = json_decode($created_requests[0][1]['body'], true);
check(job42()['state'] === 'created' && $payload['data']['entity']['tax_code'] === 'RSSMRA80A01H501U'
    && !isset($payload['data']['entity']['vat_number']), 'Automatic invoice uses private customer fiscal snapshot');

$auto = automation_fixture();
for ($id=42; $id<64; $id++) {
    $orders[$id] = array_merge($orders[42], ['id'=>$id,'code'=>'ORDER'.$id]);
    $order_meta[$id][PMPro_FIC_Billing::META] = array_merge($order_meta[42][PMPro_FIC_Billing::META], ['order_id'=>$id]);
    $auto->on_new_order(new MemberOrder($id));
}
check(count($settings_store[PMPro_FIC_Automation::INDEX]) === 20 && count($events) === 22, 'Recent list is bounded without dropping older scheduled work');
check(is_array($auto->dispatch('auto_status',['order_id'=>'42'])), 'Older job remains accessible by order ID');

$auto = automation_fixture(false); $admin = false;
error_is($auto->dispatch('auto_enable', ['account_id'=>'9','confirm_test'=>'1']), 'forbidden');
$admin = true; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['fic_action'=>'auto_accounts']; $nonce = false;
try { render_page(); throw new RuntimeException('Invalid nonce accepted'); } catch (InvalidNonce $error) { check(count($requests) === 2, 'Page checks nonce before automation network calls'); }
$nonce = true; account_response(); $html = render_page();
check(str_contains($html, 'Attiva automazione Sandbox') && str_contains($html, '#fic-auto-result'), 'Admin page renders anchored opt-in setup');
$_POST = ['fic_action'=>'auto_enable','account_id'=>'9','confirm_test'=>'1']; $html = render_page();
check(str_contains($html,'Automazione Sandbox attiva'), 'Admin page handles explicit activation');
$auto->on_updated_order(new MemberOrder(42));
check(!job42(), 'Activation still does not import old successful orders');
error_is($auto->dispatch('auto_run',['order_id'=>'42']), 'auto_no_job');

foreach ([4=>'Accesso Professional', 5=>'Accesso Gold'] as $level_id=>$description) {
    $auto = automation_fixture(false);
    $orders[42]['membership_id'] = $level_id;
    $snapshot = $order_meta[42][PMPro_FIC_Billing::META]; $snapshot['level_id'] = $level_id; $order_meta[42][PMPro_FIC_Billing::META] = $snapshot;
    $options['plan_descriptions'] = [4=>'Accesso Professional', 5=>'Accesso Gold'];
    account_response(); $auto->dispatch('auto_accounts', []);
    $auto->dispatch('auto_enable', ['account_id'=>'9','confirm_test'=>'1']);
    birth($auto); expected_document(); run_job($auto);
    $payload = json_decode($created_requests[0][1]['body'],true);
    check(job42()['state'] === 'created' && $payload['data']['items_list'][0]['name'] === $description, 'Automatic document selects description of purchased plan ' . $level_id);
    check($payload['data']['subject'] === $description && str_contains($payload['data']['notes'],'innerplay-test-'), 'Automatic FIC subject is readable while keeping the technical recovery marker for plan ' . $level_id);
}
$auto = automation_fixture(); birth($auto);
$options = $plugin->sanitize_options(['company_id'=>'123','vat_id'=>'0','document_prefix'=>'Abbonamento','plan_descriptions'=>['4'=>'Nuovo testo']]);
check(empty($settings_store[PMPro_FIC_Automation::CONFIG]['active']), 'Saving per-plan text pauses active automation');
run_job($auto); assert_no_document('Job captured under old descriptions cannot create after configuration change');

echo "PASS: {$checks} automation checks; WordPress, cron and HTTP simulated; no real documents, payments or emails.\n";
