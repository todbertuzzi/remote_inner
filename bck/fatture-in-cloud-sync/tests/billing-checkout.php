<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/billing-checkout.php */
define('PMPRO_FIC_ORDERS_BOOTSTRAP', true);
class BillingStorageFailure extends RuntimeException {}
function add_pmpro_membership_order_meta($id, $key, $value, $unique = false) {
    if (!empty($GLOBALS['fail_billing_storage']) || ($unique && isset($GLOBALS['order_meta'][$id][$key]))) return false;
    $GLOBALS['order_meta'][$id][$key] = $value;
    return true;
}
function get_user_meta($id, $key, $single = false) { return $GLOBALS['user_meta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['user_meta'][$id][$key] = $value; }
function wp_verify_nonce($value, $action) { return $value === 'test-nonce' && $action === PMPro_FIC_Billing::NONCE; }
function pmpro_setMessage($message, $type) { $GLOBALS['billing_message'] = $message; }
function wp_get_current_user() { return (object) ['ID' => get_current_user_id(), 'user_email' => 'buyer@example.test']; }
function wp_die(...$args) { throw new BillingStorageFailure($args[0]); }
require __DIR__ . '/manual-orders.php';

function billing_fixture() {
    global $pmpro_level, $pmpro_review;
    fixture();
    $pmpro_level = (object) ['id' => 4, 'initial_payment' => 122, 'billing_amount' => 0, 'trial_amount' => 0];
    $pmpro_review = null;
    $GLOBALS['user_meta'] = [];
    $GLOBALS['billing_message'] = '';
    $GLOBALS['fail_billing_storage'] = false;
    return new PMPro_FIC_Billing();
}
function private_data() {
    return ['customer_type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'company' => '', 'email' => 'buyer@example.test',
        'street' => 'Via Prova 1', 'zip' => '20100', 'city' => 'Milano', 'province' => 'mi', 'country' => 'it',
        'tax_code' => 'RSSMRA80A01H501U', 'vat_number' => '', 'recipient_code' => '', 'pec' => ''];
}
function business_data() {
    return array_merge(private_data(), ['customer_type' => 'business', 'company' => 'Azienda Test', 'tax_code' => '',
        'vat_number' => '12345678903', 'recipient_code' => 'ABC1234', 'pec' => 'test@pec.example.test']);
}
function checkout_post($data) { $_POST = ['fic_billing_nonce' => 'test-nonce', 'fic_billing' => $data]; }
function capture_snapshot($billing, $data, $id = 42) {
    checkout_post($data);
    check($billing->checkout_checks(true) === true, 'Accepts validated checkout submission');
    $order = new MemberOrder($id);
    $billing->attach_order($order);
    $billing->save_order($order);
    $billing->before_paypal($order);
    return $order;
}
function render_billing($billing) { ob_start(); try { $billing->render(); return ob_get_contents(); } finally { ob_end_clean(); } }

$billing = billing_fixture();
check(PMPro_FIC_Billing::valid_tax_code('RSSMRA80A01H501U'), 'Known personal tax-code checksum');
check(!PMPro_FIC_Billing::valid_tax_code('RSSMRA80A01H501A'), 'Rejects incorrect personal tax-code checksum');
check(PMPro_FIC_Billing::valid_numeric_tax_id('12345678903'), 'Valid numeric tax-ID checksum');
check(!PMPro_FIC_Billing::valid_numeric_tax_id('12345678901') && !PMPro_FIC_Billing::valid_numeric_tax_id('00000000000'), 'Rejects invalid VAT number and zero placeholder');
$private = PMPro_FIC_Billing::validate(private_data());
check($private['name'] === 'Mario Rossi' && $private['country'] === 'IT' && $private['province'] === 'MI', 'Normalizes personal identity and country');
check($private['recipient_code'] === '0000000' && $private['vat_number'] === '', 'Private delivery code and no VAT number');
$provisional = private_data(); $provisional['tax_code'] = '12345678903';
check(PMPro_FIC_Billing::validate($provisional)['tax_code'] === '12345678903', 'Private provisional 11-digit fiscal code supported');
$business = PMPro_FIC_Billing::validate(business_data());
check($business['name'] === 'Azienda Test' && $business['first_name'] === '', 'Business identity uses legal name');
check($business['vat_number'] === '12345678903' && $business['pec'] === 'test@pec.example.test', 'Business VAT and PEC retained');
$input = business_data(); $input['recipient_code'] = $input['pec'] = '';
check(PMPro_FIC_Billing::validate($input)['recipient_code'] === '0000000', 'No PEC/code is permitted for business');
foreach ([['country','FR','billing_country'], ['tax_code','RSSMRA80A01H501A','billing_tax_code'], ['email','bad','billing_email'],
    ['zip','1000','billing_address'], ['province','EE','billing_address'], ['first_name','','billing_name'], ['customer_type','admin','billing_type'],
    ['street',str_repeat('x',201),'billing_length'], ['first_name',['Mario'],'billing_name']] as [$field,$value,$code]) {
    $input = private_data(); $input[$field] = $value;
    error_is(PMPro_FIC_Billing::validate($input), $code);
}
foreach ([['company','','billing_company'], ['vat_number','12345678901','billing_vat'], ['recipient_code','123456','billing_recipient'],
    ['pec','invalid','billing_pec'], ['tax_code','bad','billing_tax_code']] as [$field,$value,$code]) {
    $input = business_data(); $input[$field] = $value;
    error_is(PMPro_FIC_Billing::validate($input), $code);
}
$input = private_data(); $input['company'] = 'Injected'; $input['vat_number'] = '999'; $input['recipient_code'] = 'ABC1234'; $input['pec'] = 'invalid';
$valid = PMPro_FIC_Billing::validate($input);
check($valid['company'] === '' && $valid['pec'] === '' && $valid['vat_number'] === '' && $valid['recipient_code'] === '0000000', 'Private ignores unrelated business fields');

checkout_post(private_data()); $_POST['fic_billing_nonce'] = 'wrong';
check($billing->checkout_checks(true) === false && str_contains($billing_message, 'scaduto'), 'Nonce required before payment');
checkout_post(private_data()); $_POST['fic_billing']['country'] = 'US';
check($billing->checkout_checks(true) === false && str_contains($billing_message, 'Italia'), 'Country restriction enforced on server');
check($billing->checkout_checks(false) === false, 'Preserves earlier checkout failure');
$pmpro_level->initial_payment = 0;
$_POST = [];
check($billing->checkout_checks(true) === true && render_billing($billing) === '', 'Free Welcome requires no fiscal data');
$pmpro_level->billing_amount = 10;
check(PMPro_FIC_Billing::required(), 'Paid recurring plan with free initial payment still collects billing');

$billing = billing_fixture();
$order = capture_snapshot($billing, private_data());
$snapshot = PMPro_FIC_Billing::snapshot(42);
check($snapshot['order_id'] === 42 && $snapshot['user_id'] === 10 && $snapshot['level_id'] === 4 && $snapshot['currency'] === 'EUR', 'Snapshot bound to order, customer, level and currency');
check($snapshot['data']['tax_code'] === 'RSSMRA80A01H501U', 'Fiscal code saved before PayPal redirect');
check(posts() === 0 && count($requests) === 2, 'Snapshot hooks never contact Fatture in Cloud');
check(!str_contains(json_encode($snapshot), 'test-nonce') && !str_contains(json_encode($snapshot), 'access_token'), 'Snapshot stores only billing schema');
$original = $snapshot;
checkout_post(business_data()); $billing->checkout_checks(true); $billing->attach_order($order); $billing->save_order($order);
check(PMPro_FIC_Billing::snapshot(42) === $original, 'Repeated checkout hooks cannot overwrite original snapshot');
$order->status = 'pending'; $billing->save_profile(10, $order);
check(empty($user_meta), 'Profile not saved on pending payment');
$order->status = 'success'; $billing->save_profile(10, $order);
check($user_meta[10][PMPro_FIC_Billing::PROFILE]['name'] === 'Mario Rossi', 'Confirmed checkout saves reusable profile');
$user_meta[10][PMPro_FIC_Billing::PROFILE]['street'] = 'New address';
check(PMPro_FIC_Billing::snapshot(42)['data']['street'] === 'Via Prova 1', 'Profile edit does not change historical order');
$before = $user_meta; $billing->save_profile(99, $order);
check($user_meta === $before, 'Cannot save profile for unrelated user');

// PayPal return is a new request: use server order snapshot, not posted fields/profile.
$return = new PMPro_FIC_Billing(); $pmpro_review = new MemberOrder(42); $admin_id = 10;
$_POST = ['fic_billing' => business_data()]; // No original nonce or original browser fields.
check($return->checkout_checks(true) === true, 'PayPal confirmation works without original POST using persisted snapshot');
check(PMPro_FIC_Billing::snapshot(42) === $original, 'Forged return data cannot rewrite snapshot');
$html = render_billing($return);
check(str_contains($html, 'Mario Rossi') && !str_contains($html, 'Azienda Test'), 'Review displays snapshot identity');
$admin_id = 99;
check($return->checkout_checks(true) === false, 'Review requires original account ownership');
$admin_id = 10; unset($order_meta[42][PMPro_FIC_Billing::META]);
check($return->checkout_checks(true) === false && str_contains($billing_message, 'Riparti'), 'Old/missing snapshot cannot silently complete paid checkout');

$billing = billing_fixture(); $fail_billing_storage = true;
checkout_post(private_data()); $billing->checkout_checks(true); $order = new MemberOrder(42); $billing->attach_order($order);
try { $billing->before_paypal($order); throw new RuntimeException('Storage failure ignored'); }
catch (BillingStorageFailure $e) { check(str_contains($e->getMessage(), 'Impossibile salvare'), 'Storage failure blocks PayPal redirect'); }
check(PMPro_FIC_Billing::snapshot(42) === [], 'No fabricated snapshot after storage failure');
$order->status = 'review';
try { $billing->save_order($order); throw new RuntimeException('Standard PayPal storage failure ignored'); }
catch (BillingStorageFailure $e) { check(str_contains($e->getMessage(), 'Impossibile salvare'), 'PayPal Standard review order cannot proceed without snapshot'); }
$billing = billing_fixture();
$order = new MemberOrder(42); $billing->save_order($order);
check(PMPro_FIC_Billing::snapshot(42) === [], 'Admin/IPN orders without validated checkout are untouched');

// Invoice uses captured identity even if profile/email/posted preview fields differ.
$billing = billing_fixture(); $order = capture_snapshot($billing, business_data());
$service = new PMPro_FIC_Test_Orders([$plugin, 'order_context']);
$edit = load_test_order($service);
check($edit['billing_locked'] === true && $edit['customer']['name'] === 'Azienda Test' && $edit['customer']['vat_number'] === '12345678903', 'Invoice panel loads snapshot automatically');
ob_start(); $service->render($edit, 'load_order'); $html = ob_get_clean();
check(str_contains($html, 'Dati acquisiti dal checkout') && str_contains($html, 'readonly'), 'Invoice form marks immutable captured data');
$input = customer_input(); $input['name'] = 'Forged company'; $input['email'] = 'forged@example.test'; $input['tax_code'] = 'BAD';
$p = $service->dispatch('preview_order', $input);
check($p['payload']['data']['entity']['name'] === 'Azienda Test' && $p['payload']['data']['entity']['email'] === 'buyer@example.test', 'Posted admin identity cannot override order snapshot');
check($p['payload']['data']['entity']['vat_number'] === '12345678903' && $p['payload']['data']['entity']['ei_code'] === 'ABC1234'
    && $p['payload']['data']['entity']['certified_email'] === 'test@pec.example.test', 'Invoice carries VAT, delivery code and PEC');
$post_responses = [document_response()];
check($service->dispatch('create_order', ['preview_id' => $p['preview_id']])['stage'] === 'created', 'Snapshot-backed order can create the reviewed test document');

$billing = billing_fixture(); capture_snapshot($billing, private_data());
$service = new PMPro_FIC_Test_Orders([$plugin, 'order_context']); $p = preview($service);
check($p['payload']['data']['entity']['tax_code'] === 'RSSMRA80A01H501U' && !isset($p['payload']['data']['entity']['vat_number']), 'Private invoice uses captured CF without VAT number');
$order_meta[42][PMPro_FIC_Billing::META]['data']['street'] = 'Changed after preview';
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'order_changed');
check(posts() === 0, 'Snapshot alteration invalidates reviewed document');
$order_meta[42][PMPro_FIC_Billing::META]['user_id'] = 99;
error_is($service->dispatch('load_order', ['order_id' => '42']), 'billing_snapshot');
$order_meta[42][PMPro_FIC_Billing::META] = 'invalid';
error_is(PMPro_FIC_Billing::snapshot(42), 'billing_snapshot');

$billing = billing_fixture(); $_POST = [];
$html = render_billing($billing);
check(str_contains($html, 'fic_billing_nonce') && str_contains($html, 'fic_billing[customer_type]'), 'Checkout includes nonce and customer type');
check(str_contains($html, 'data-fic-private') && str_contains($html, 'data-fic-business') && str_contains($html, 'value="IT"'), 'Checkout has both conditional groups and country field');
check(!str_contains($html, 'value="FR"') && !str_contains($html, 'value="US"'), 'Foreign countries not yet offered');
check(preg_match('/name="fic_billing\[vat_number\]"[^>]+disabled/', $html) === 1, 'Hidden VAT field cannot block private HTML form validation');
checkout_post(business_data());
$html = render_billing($billing);
check(preg_match('/name="fic_billing\[first_name\]"[^>]+disabled/', $html) === 1, 'Hidden personal name fields cannot block business form validation');
checkout_post(array_merge(private_data(), ['first_name' => '<script>alert(1)</script>Mario']));
$html = render_billing($billing);
check(!str_contains($html, '<script>'), 'Checkout escapes posted fields');
$pmpro_level->initial_payment = $pmpro_level->billing_amount = $pmpro_level->trial_amount = 0;
check(render_billing($billing) === '', 'Free checkout remains unaffected');
echo "PASS: {$checks} billing checkout checks; WordPress/PMPro and HTTP simulated.\n";
