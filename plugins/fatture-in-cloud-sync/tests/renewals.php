<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/renewals.php */
define('PMPRO_FIC_AUTOMATION_BOOTSTRAP', true);
require __DIR__ . '/automation.php';
function add_pmpro_membership_order_meta($id, $key, $value, $unique = false) {
    if (!empty($GLOBALS['fail_snapshot']) || ($unique && isset($GLOBALS['order_meta'][$id][$key]))) return false;
    $GLOBALS['order_meta'][$id][$key] = $value;
    return true;
}
function renewal_fixture($authorize = true) {
    unset($GLOBALS['on_sale_request'], $GLOBALS['paypal_transactions']);
    $GLOBALS['fail_snapshot'] = false;
    $auto = automation_fixture();
    // An already confirmed /TEST document on the initial order, as after upgrading 0.5.6.
    $GLOBALS['order_meta'][42][PMPro_FIC_Test_Orders::META] = ['company_id'=>123,'document_id'=>101,'status'=>'created','number'=>'1','numeration'=>'/TEST','marker'=>'existing'];
    if ($authorize) {
        $result = $auto->dispatch('renewal_enable', ['order_id'=>'42','confirm_renewal'=>'1']);
        check(is_array($result), 'Explicit authorization accepts a verified paid initial order');
    }
    $GLOBALS['sale_requests'] = [];
    return $auto;
}
function renewal_order($id = 43, $payment = 'RENEWAL000001') {
    $GLOBALS['orders'][$id] = array_merge($GLOBALS['orders'][42], ['id'=>$id,'code'=>'RENEW'.$id,'payment_transaction_id'=>$payment,'timestamp'=>time()]);
    return new MemberOrder($id);
}
function run_renewal($auto, $id = 43) {
    unset($GLOBALS['events'][PMPro_FIC_Automation::HOOK . ':[' . $id . ']']);
    $GLOBALS['admin'] = false; $GLOBALS['admin_id'] = 0;
    $auto->process($id);
    $GLOBALS['admin'] = true; $GLOBALS['admin_id'] = 1;
}
function renewal_job($id = 43) { return get_option('pmpro_fic_job_' . $id, []); }
function sale_response(array $changes = [], $http = 200) {
    $sale = array_replace_recursive(['id'=>'RENEWAL000001','state'=>'completed','billing_agreement_id'=>'I-TEST12345678',
        'amount'=>['total'=>'122.00','currency'=>'EUR'],'create_time'=>gmdate('Y-m-d\TH:i:s\Z')], $changes);
    return ['status'=>$http,'body'=>json_encode($sale)];
}

$auto = renewal_fixture(false);
$auto->on_renewal(renewal_order());
check(!renewal_job() && !$sale_requests && !isset($order_meta[43]), 'Upgrade does not enable renewals or read profile/network implicitly');
error_is($auto->dispatch('renewal_enable', ['order_id'=>'42']), 'confirm_test');
$admin = false;
foreach (['renewal_enable','renewal_status','renewal_pause','renewal_enqueue'] as $action) error_is($auto->dispatch($action, ['order_id'=>'42']), 'forbidden');
$admin = true; $nonce = false; $_SERVER['REQUEST_METHOD']='POST'; $_POST=['fic_action'=>'renewal_enable','order_id'=>'42','confirm_renewal'=>'1'];
try { render_page(); throw new RuntimeException('Renewal action bypassed nonce'); } catch (InvalidNonce $e) { check(!$sale_requests, 'Nonce precedes renewal API calls'); }

$auto = renewal_fixture();
$initial = $order_meta[42];
$auto->on_renewal(renewal_order());
check(renewal_job()['kind'] === 'renewal' && !$sale_requests && posts() === 0, 'Completed hook only captures and queues, without external calls');
$copy = PMPro_FIC_Billing::snapshot(43);
check($copy['source_order_id'] === 42 && $copy['origin'] === 'renewal' && $copy['order_id'] === 43, 'Renewal has its own fiscal snapshot and provenance');
$GLOBALS['user_meta'][10][PMPro_FIC_Billing::PROFILE] = ['company'=>'Unrelated profile','email'=>'changed@example.test'];
expected_document(); run_renewal($auto);
check(renewal_job()['state'] === 'created' && posts() === 1, 'Paid renewal creates exactly one document');
$payload = json_decode($created_requests[0][1]['body'], true)['data'];
check($payload['entity']['name'] === 'Azienda Test' && $payload['entity']['email'] === 'billing@example.test', 'Confirmed fiscal data wins over changed current profile');
check($payload['e_invoice'] === false && $payload['numeration'] === '/TEST' && $payload['payments_list'][0]['status'] === 'paid', 'Renewal remains paid /TEST, never SDI');
check($order_meta[42] === $initial && PMPro_FIC_Billing::snapshot(43) === $copy, 'Prior order and renewal fiscal copy remain immutable');
check(count($sale_requests) === 2 && $sale_requests[0][1]['redirection'] === 0, 'Payment is read at preview and again before POST, with redirects disabled');
$auto->on_renewal(new MemberOrder(43)); $auto->on_updated_order(new MemberOrder(43)); run_renewal($auto);
check(posts() === 1 && renewal_job()['attempts'] === 1, 'Repeated event and worker never re-invoice');
$auto->on_renewal(renewal_order(44, 'RENEWAL000002')); expected_document(); run_renewal($auto, 44);
check(renewal_job(44)['state'] === 'created' && posts() === 2, 'Different payment on same subscription gets its own document');

$auto = renewal_fixture(); $order = renewal_order(); $orders[43]['status']='pending'; $orders[43]['payment_transaction_id']='';
$auto->on_new_order(new MemberOrder(43)); $auto->on_updated_order(new MemberOrder(43));
check(!renewal_job() && !isset($order_meta[43]), 'Pending/failed order does not capture or bill');
$orders[43]['status']='success'; $orders[43]['payment_transaction_id']='RENEWAL000001';
$auto->on_updated_order(new MemberOrder(43)); expected_document(); run_renewal($auto);
check(renewal_job()['state'] === 'created' && posts() === 1, 'Failed/pending order later paid is acquired via update hook');

foreach (['user_id'=>99,'membership_id'=>5,'subscription_transaction_id'=>'I-OTHER12345','gateway_environment'=>'live','gateway'=>'stripe','status'=>'error'] as $field=>$value) {
    $auto = renewal_fixture(); renewal_order(); $orders[43][$field]=$value; $auto->on_renewal(new MemberOrder(43));
    check(!renewal_job() && posts() === 0, 'Unrelated/invalid order rejected: '.$field);
}
$auto = renewal_fixture(); $auto->on_renewal(renewal_order(43, 'PAYPAL-TEST-1'));
check(!renewal_job(), 'Duplicate initial payment cannot be treated as a renewal');

foreach ([['state'=>'pending'], ['state'=>'refunded'], ['state'=>'partially_refunded'], ['state'=>'denied'], ['amount'=>['total'=>'121.99']],
    ['amount'=>['currency'=>'USD']], ['id'=>'OTHERPAYMENT'], ['billing_agreement_id'=>'I-OTHER12345'], ['create_time'=>'invalid'],
    ['payment_hold_status'=>'HELD'], ['reason_code'=>'CHARGEBACK']] as $changes) {
    $auto = renewal_fixture(); $auto->on_renewal(renewal_order()); expected_document(); $sale_responses=[sale_response($changes)]; run_renewal($auto);
    check(in_array(renewal_job()['state'], ['blocked','waiting'], true) && posts()===0, 'Invalid remote payment fails closed: '.json_encode($changes));
}
foreach ([429,500,502,503,504,404] as $http) {
    $auto=renewal_fixture(); $auto->on_renewal(renewal_order()); expected_document(); $sale_responses=[sale_response([], $http)]; run_renewal($auto);
    check(renewal_job()['state']==='waiting' && posts()===0, 'Transient PayPal HTTP '.$http.' retries only reads');
}
foreach ([401,403,422,302] as $http) {
    $auto=renewal_fixture(); $auto->on_renewal(renewal_order()); expected_document(); $sale_responses=[sale_response([], $http)]; run_renewal($auto);
    check(renewal_job()['state']==='blocked' && posts()===0, 'Access/invalid PayPal HTTP '.$http.' blocks');
}
$auto=renewal_fixture(); $auto->on_renewal(renewal_order());
for ($i=0;$i<6;$i++) { expected_document(); $sale_responses=[['status'=>200,'body'=>'{"private_secret":"never expose"}']]; run_renewal($auto); }
check(renewal_job()['state']==='blocked' && renewal_job()['attempts']===6 && posts()===0, 'Incomplete PayPal replies exhaust six attempts without writes');
check(strpos(renewal_job()['message'], 'never expose')===false, 'Raw remote data is not included in queue messages');

$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); expected_document();
$sale_responses=[sale_response(),sale_response(['state'=>'refunded'])]; run_renewal($auto);
check(renewal_job()['state']==='blocked' && posts()===0, 'Refund between preview and POST blocks creation');
$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); expected_document();
$on_sale_request=function() { if (count($GLOBALS['sale_requests'])===2) update_option(PMPro_FIC_Renewals::key('I-TEST12345678').'_paused',true); };
run_renewal($auto); check(posts()===0 && renewal_job()['state']==='blocked', 'Pause during remote read is rechecked before POST');

$auto=renewal_fixture(); $policy=PMPro_FIC_Renewals::policy(new MemberOrder(42)); $auto->on_renewal(renewal_order()); expected_document();
$sale_responses=[sale_response(['create_time'=>gmdate('Y-m-d\TH:i:s\Z', $policy['effective_from']-1)])]; run_renewal($auto);
check(renewal_job()['state']==='blocked' && posts()===0, 'Late notification for a pre-authorization payment cannot import history');
$auto=renewal_fixture(); $order=renewal_order(); $orders[43]['timestamp']=time()+86400;
$auto->on_renewal(new MemberOrder(43)); expected_document(); $sale_responses=[sale_response(),sale_response()]; run_renewal($auto);
check(renewal_job()['state']==='created', 'Late local order timestamp does not determine payment eligibility');
$payload=json_decode($created_requests[0][1]['body'],true)['data'];
check($payload['payments_list'][0]['paid_date']===gmdate('Y-m-d'), 'Actual PayPal payment date is used, not notification/order date');

$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); $copy=$order_meta[43][PMPro_FIC_Billing::META];
$order_meta[43][PMPro_FIC_Billing::META]['data']['street']='Altered'; expected_document(); run_renewal($auto);
check(renewal_job()['state']==='blocked' && posts()===0, 'Changed renewal snapshot rejected against immutable canonical copy');
$auto=renewal_fixture(); error_is($auto->dispatch('renewal_enable',['order_id'=>'42','confirm_renewal'=>'1']),'renewal_exists');
$auto->dispatch('renewal_pause',['order_id'=>'42']); $auto->on_renewal(renewal_order());
check(!renewal_job() && posts()===0, 'Paused authorization does not accept new renewals');

$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); renewal_order(44,'RENEWAL000001'); $auto->on_renewal(new MemberOrder(44));
expected_document(); run_renewal($auto); expected_document(); run_renewal($auto,44);
check(posts()===0 && renewal_job()['state']==='blocked' && renewal_job(44)['state']==='blocked', 'Concurrent duplicate PMPro orders fail closed before invoicing');

$auto=renewal_fixture(); $order=renewal_order();
$reservation=PMPro_FIC_Payments::reserve($order,123); check(is_array($reservation),'First payment reservation acquired');
error_is(PMPro_FIC_Payments::reserve($order,123),'payment_locked');
PMPro_FIC_Payments::release($reservation);
check(is_array(PMPro_FIC_Payments::reserve($order,123)),'Same owner may retry only after definite release');
unset($orders[43]); $other=renewal_order(44,'RENEWAL000001');
error_is(PMPro_FIC_Payments::reserve($other,123),'payment_duplicate');
error_is(PMPro_FIC_Payments::reserve($other,999),'payment_duplicate');
check(PMPro_FIC_Atomic_Store::read(PMPro_FIC_Payments::key($other))['order_id']===43,'Permanent owner survives release, deleted/changed original order and company change');

$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); account_response(); $post_responses=[new WP_Error('timeout','ambiguous')]; run_renewal($auto);
check(renewal_job()['state']==='attention' && posts()===1,'Timeout after POST requires remote recovery');
error_is($auto->dispatch('auto_retry',['order_id'=>'43']),'uncertain');
check(PMPro_FIC_Atomic_Store::read(PMPro_FIC_Payments::key(new MemberOrder(43)).'_attempt')!==null,'Uncertain payment attempt remains permanently reserved');
$journal=$settings_store['pmpro_fic_test_order_43']; $settings_store['pmpro_fic_test_order_43']['requested_at']-=121;
$responses=[response([])]; $service=new PMPro_FIC_Test_Orders([$plugin,'order_context']);
error_is($service->dispatch('recover_order',['order_id'=>'43']),'not_found_uncertain');
check(posts()===1,'Negative recovery search never permits a new POST');
$responses=[response([['id'=>202,'number'=>2,'numeration'=>'/TEST','amount_gross'=>122,'notes'=>$journal['payload']['data']['notes']]])];
$recovered=$service->dispatch('recover_order',['order_id'=>'43']); check(is_array($recovered),'Uncertain renewal recovered by marker');
$auto->dispatch('auto_retry',['order_id'=>'43']); run_renewal($auto);
check(posts()===1 && renewal_job()['state']==='created','Recovered document reconciles queue without re-invoicing');

$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); account_response(); $post_responses=[response([],429)]; run_renewal($auto);
check(renewal_job()['state']==='waiting' && posts()===1,'Explicit FIC 429 permits bounded retry');
check(PMPro_FIC_Atomic_Store::read(PMPro_FIC_Payments::key(new MemberOrder(43)).'_attempt')===null,'Definite rejection releases only attempt lock');
expected_document(); run_renewal($auto); check(posts()===2 && renewal_job()['state']==='created','Definite rejection allows a later successful request');

$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); $wpdb->atomic_failure=true; expected_document(); run_renewal($auto);
check(posts()===0 && renewal_job()['state']==='attention','Database failure does not fall back to unsafe cache or unlocked creation');
$auto=renewal_fixture(); $fail_snapshot=true; $auto->on_renewal(renewal_order());
check(!renewal_job() && strpos(get_option('pmpro_fic_renewal_error'),'43')!==false,'Snapshot storage failure is visible and does not enqueue');
$fail_snapshot=false; $result=$auto->dispatch('renewal_enqueue',['order_id'=>'43']);
check(is_array($result) && renewal_job()['state']==='queued','Explicit reacquisition repairs missing metadata from immutable canonical copy');

$auto=renewal_fixture(); $auto->on_renewal(renewal_order()); expected_document(); run_renewal($auto);
unset($settings_store['pmpro_fic_test_order_43'], $order_meta[43][PMPro_FIC_Test_Orders::META]);
$settings_store['pmpro_fic_job_43']['state']='queued'; expected_document(); run_renewal($auto);
check(posts()===1 && renewal_job()['state']==='attention', 'Even loss of journal and document metadata cannot permit another POST for a reserved payment');

$auto=renewal_fixture(); birth($auto);
// Initial document association already exists: it must be reused with no new payment request.
run_job($auto); check(job42()['state']==='created' && !$sale_requests && posts()===0, 'Existing initial document remains compatible after upgrade');
$auto=renewal_fixture(false); unset($order_meta[42][PMPro_FIC_Test_Orders::META]); birth($auto); expected_document();
$sale_responses=[sale_response(['id'=>'PAYPAL-TEST-1','state'=>'refunded'])]; run_job($auto);
check(job42()['state']==='blocked' && posts()===0, 'Initial payment with preexisting ID is also verified remotely before any new invoice');

$auto=renewal_fixture(); $_GET['fic_section']='rinnovi'; $html=render_page();
check(strpos($html,'Autorizza la fatturazione dei rinnovi')!==false && strpos($html,'Sospendi fatturazione rinnovi')!==false,'Renewal controls are available in backend');
check(strpos($html,'fake-sandbox-token')===false && strpos($html,'fake-paypal-secret')===false,'Backend excludes credentials');
echo "PASS: $checks renewal/payment checks; HTTP and WordPress simulated; reservation SQL executed on SQLite.\n";
