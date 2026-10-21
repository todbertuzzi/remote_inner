<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/manual-orders.php */
define('PMPRO_FIC_TEST_BOOTSTRAP', true);
define('MINUTE_IN_SECONDS', 60);
class MemberOrder {
    public $id = 0;
    public $user_id = 10;
    public $membership_id = 4;
    public $code = 'SANDBOX01';
    public $status = 'success';
    public $gateway = 'paypalexpress';
    public $gateway_environment = 'sandbox';
    public $payment_transaction_id = 'PAYPAL-TEST-1';
    public $subscription_transaction_id = '';
    public $total = '122.00';
    public $timestamp = 1791849600;
    public $billing;
    public function __construct($id) {
        if (isset($GLOBALS['orders'][$id])) {
            foreach ($GLOBALS['orders'][$id] as $key => $value) $this->$key = $value;
        }
    }
    public static function get_orders(array $args = []) {
        $found = [];
        foreach ($GLOBALS['orders'] as $id => $data) {
            $order = new self($id);
            foreach ($args as $key => $value) {
                if ($key !== 'limit' && (string) $order->$key !== (string) $value) continue 2;
            }
            $found[] = $order;
            if (count($found) >= ($args['limit'] ?? 100)) break;
        }
        return $found;
    }
}
function add_option($key, $value, $deprecated = '', $autoload = false) {
    if (array_key_exists($key, $GLOBALS['settings_store'])) return false;
    $GLOBALS['settings_store'][$key] = $value;
    return true;
}
function update_option($key, $value, $autoload = false) {
    if (!empty($GLOBALS['fail_storage']) || (!empty($GLOBALS['fail_after_post']) && !empty($GLOBALS['created_requests']))) return false;
    $GLOBALS['settings_store'][$key] = $value;
    return true;
}
function delete_option($key) { unset($GLOBALS['settings_store'][$key]); }
function get_pmpro_membership_order_meta($id, $key, $single = false) { return $GLOBALS['order_meta'][$id][$key] ?? ''; }
function get_current_user_id() { return $GLOBALS['admin_id']; }
function get_userdata($id) { return (object) ['user_email' => 'buyer@example.test']; }
function wp_generate_uuid4() { return sprintf('12345678-1234-4234-8234-%012d', ++$GLOBALS['uuid_count']); }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function wp_unslash($value) { return $value; }
require __DIR__ . '/connection-and-simulation.php';

function fixture() {
    global $plugin, $pmpro_currency;
    reset_fixture();
    $pmpro_currency = 'EUR';
    foreach (['settings_store', 'order_meta', 'post_requests', 'post_responses', 'totals_responses', 'created_requests'] as $key) $GLOBALS[$key] = [];
    foreach (['fail_meta', 'fail_storage', 'fail_after_post'] as $key) $GLOBALS[$key] = false;
    $GLOBALS['allow_document_test'] = true;
    $GLOBALS['admin_id'] = 1;
    $GLOBALS['uuid_count'] = 0;
    $GLOBALS['orders'] = [42 => ['id' => 42, 'billing' => (object) ['name' => 'Cliente Sandbox', 'street' => 'Via Prova 1', 'zip' => '20100', 'city' => 'Milano', 'state' => 'MI']]];
    valid_responses();
    $plugin->test_connection();
    save_vat();
    return new PMPro_FIC_Test_Orders([$plugin, 'order_context']);
}
function load_test_order($service) {
    $GLOBALS['responses'] = [response([['id' => 9, 'name' => 'PayPal Test']])];
    return $service->dispatch('load_order', ['order_id' => '42']);
}
function customer_input() {
    return ['order_id' => '42', 'name' => 'Cliente Sandbox', 'email' => 'buyer@example.test', 'street' => 'Via Prova 1', 'zip' => '20100',
        'city' => 'Milano', 'province' => 'mi', 'account_id' => '9', 'confirm_test' => '1', 'tax_code' => '', 'vat_number' => ''];
}
function preview($service) { load_test_order($service); return $service->dispatch('preview_order', customer_input()); }
function document_response($id = 77, $gross = 122) { return response(['id' => $id, 'number' => 1, 'numeration' => '/TEST', 'amount_gross' => $gross], 201); }
function posts() { return count($GLOBALS['created_requests']); }

if (defined('PMPRO_FIC_ORDERS_BOOTSTRAP')) return;

$service = fixture();
$edit = load_test_order($service);
check($edit['stage'] === 'edit' && $edit['order']->id === 42, 'Reads paid Sandbox order');
check($edit['customer']['name'] === 'Cliente Sandbox' && $edit['customer']['email'] === 'buyer@example.test', 'Reads order billing and user email');
check($edit['accounts'][0]['id'] === 9 && posts() === 0, 'Loads accounts without creation');
$p = $service->dispatch('preview_order', customer_input());
check($p['net'] == 100 && $p['vat'] == 22 && $p['gross'] == 122, 'Order gross total preserved');
check($p['payload']['data']['e_invoice'] === false && $p['payload']['data']['numeration'] === '/TEST', 'Non-electronic test document');
check($p['payload']['data']['payments_list'][0]['status'] === 'paid' && $p['payload']['data']['payments_list'][0]['payment_account']['id'] === 9, 'Correct payment account and status');
check($p['payload']['data']['payments_list'][0]['paid_date'] === wp_date('Y-m-d', 1791849600), 'Uses order payment date');
check(posts() === 0 && !$settings_store, 'Preview does not reserve or create a document');
$post_responses = [document_response()];
$created = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
check($created['stage'] === 'created' && $created['document']['status'] === 'created', 'Remote success recorded');
check($created_requests[0][0] === 'https://api-v2.fattureincloud.it/c/123/issued_documents', 'Only document creation endpoint used');
$payload = json_decode($created_requests[0][1]['body'], true);
check($created_requests[0][1]['body'] === wp_json_encode($p['payload']), 'Creates exact reviewed payload');
check($created_requests[0][1]['redirection'] === 0, 'No token forwarding on POST redirects');
check($order_meta[42][PMPro_FIC_Test_Orders::META]['document_id'] === 77, 'PMPro metadata association');
check(!isset($settings_store['pmpro_fic_lock_42']), 'Lock cleared after confirmed success');
$service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
check(posts() === 1, 'Repeated submission does not duplicate');
$again = $service->dispatch('load_order', ['order_id' => '42']);
check($again['stage'] === 'created' && posts() === 1, 'Reload shows existing association');
unset($settings_store['pmpro_fic_test_order_42']);
$again = $service->dispatch('load_order', ['order_id' => '42']);
check($again['stage'] === 'created' && posts() === 1, 'Order metadata protects against missing journal');

foreach ([['gateway_environment', 'live', 'sandbox_required'], ['gateway', 'stripe', 'sandbox_required'], ['status', 'pending', 'not_paid'],
    ['status', 'refunded', 'not_paid'], ['payment_transaction_id', '', 'transaction_missing'], ['total', '0', 'amount'], ['total', 'NaN', 'amount']] as [$field, $value, $error]) {
    $service = fixture(); $orders[42][$field] = $value;
    error_is($service->dispatch('load_order', ['order_id' => '42']), $error);
    check(posts() === 0 && count($requests) === 2, 'Invalid order stopped before account request');
}
$service = fixture();
$pmpro_currency = 'USD';
error_is($service->dispatch('load_order', ['order_id' => '42']), 'currency');
$pmpro_currency = 'EUR';
error_is($service->dispatch('load_order', ['order_id' => '999']), 'order_missing');
error_is($service->dispatch('load_order', ['order_id' => ['42']]), 'invalid_order');
$admin = false;
error_is($service->dispatch('load_order', ['order_id' => '42']), 'forbidden');

$service = fixture(); load_test_order($service);
foreach (['name', 'email', 'street', 'zip', 'city', 'province'] as $field) {
    $input = customer_input(); $input[$field] = '';
    error_is($service->dispatch('preview_order', $input), 'customer_required');
}
$input = customer_input(); $input['confirm_test'] = '';
error_is($service->dispatch('preview_order', $input), 'confirm_test');
$input = customer_input(); $input['account_id'] = '999';
error_is($service->dispatch('preview_order', $input), 'account_required');
$input = customer_input(); $input['vat_number'] = 'abc';
error_is($service->dispatch('preview_order', $input), 'tax_data');
check(posts() === 0, 'Invalid billing never creates');

$service = fixture(); $p = preview($service);
$orders[42]['total'] = '150';
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'order_changed');
check(posts() === 0, 'Order changes require a new preview');
$service = fixture(); $p = preview($service); $admin_id = 2;
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'preview_expired');
$service = fixture(); $p = preview($service); $options['document_prefix'] = 'Changed';
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'preview_expired');
$service = fixture(); $p = preview($service); $clock = 901;
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'preview_expired');
$service = fixture(); $p = preview($service); $settings_store['pmpro_fic_lock_42'] = 'another-process';
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'locked');
check(posts() === 0, 'Concurrent request cannot post');
$service = fixture(); $p = preview($service); $fail_storage = true;
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'storage');
check(posts() === 0, 'Storage failure stops before POST');

foreach ([new WP_Error('timeout', 'secret test-secret-not-for-html'), ['status' => 500, 'body' => 'secret'],
    ['status' => 200, 'body' => 'not JSON'], response(['number' => 1]), response(['id' => 'bad'])] as $failure) {
    $service = fixture(); $p = preview($service); $post_responses = [$failure];
    error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'unknown_result');
    error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'uncertain');
    check(posts() === 1 && isset($settings_store['pmpro_fic_lock_42']), 'Ambiguous result retains lock and blocks repost');
}
$service = fixture(); $p = preview($service); $post_responses = [response([], 422)];
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'rejected');
check(!isset($settings_store['pmpro_fic_lock_42']), 'Definite rejection permits corrected retry');
$post_responses = [document_response()];
check($service->dispatch('create_order', ['preview_id' => $p['preview_id']])['stage'] === 'created' && posts() === 2, 'Retry after definite rejection');

$service = fixture(); $p = preview($service); $post_responses = [new WP_Error('timeout', 'not shown')];
$service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
error_is($service->dispatch('recover_order', ['order_id' => '42']), 'wait');
$settings_store['pmpro_fic_test_order_42']['requested_at'] -= 121;
$responses = [response([['id' => 77, 'number' => 1, 'numeration' => '/TEST', 'amount_gross' => 122,
    'subject' => $p['payload']['data']['subject'], 'notes' => $p['payload']['data']['notes']]])];
$recovered = $service->dispatch('recover_order', ['order_id' => '42']);
check($recovered['stage'] === 'created' && $recovered['document']['document_id'] === 77 && posts() === 1, 'Recovery associates remote document without another POST');
check(!isset($settings_store['pmpro_fic_lock_42']), 'Recovery clears lock');

$service = fixture(); $p = preview($service); $post_responses = [new WP_Error('timeout', 'not shown')];
$service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
$settings_store['pmpro_fic_test_order_42']['requested_at'] -= 121;
$responses = [response([])];
error_is($service->dispatch('recover_order', ['order_id' => '42']), 'not_found_uncertain');
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'uncertain');
check(posts() === 1, 'No match never automatically authorizes a repost');

$service = fixture(); $p = preview($service); $post_responses = [document_response(77, 150)];
$created = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
check($created['document']['status'] === 'created_mismatch', 'Remote total mismatch requires inspection');
$service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
check(posts() === 1, 'Total mismatch does not create duplicates');
$service = fixture(); $p = preview($service); $post_responses = [document_response()]; $fail_meta = true;
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'metadata');
$fail_meta = false;
check($service->dispatch('load_order', ['order_id' => '42'])['stage'] === 'created' && posts() === 1, 'Repairs order metadata without a second document');
$service = fixture(); $p = preview($service); $post_responses = [document_response()]; $fail_after_post = true;
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'storage_uncertain');
check($settings_store['pmpro_fic_test_order_42']['status'] === 'pending' && isset($settings_store['pmpro_fic_lock_42']), 'Journal protects crash after remote success');
$fail_after_post = false;
$settings_store['pmpro_fic_test_order_42']['requested_at'] -= 121;
$responses = [response([['id' => 77, 'number' => 1, 'numeration' => '/TEST', 'amount_gross' => 122,
    'subject' => $p['payload']['data']['subject'], 'notes' => $p['payload']['data']['notes']]])];
check($service->dispatch('recover_order', ['order_id' => '42'])['stage'] === 'created' && posts() === 1, 'Recovers success after local write failure');

$service = fixture(); $p = preview($service);
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['fic_action' => 'create_order', 'preview_id' => $p['preview_id']]; $nonce = false;
try { render_page(); throw new RuntimeException('Invalid nonce accepted'); } catch (InvalidNonce $e) { check(posts() === 0, 'Page checks nonce before document creation'); }
$nonce = true; $post_responses = [document_response()];
$html = render_page();
check(str_contains($html, 'Documento associato') && !str_contains($html, 'test-secret-not-for-html'), 'Creation UI displays reference without token');
$_POST = ['fic_action' => 'load_order', 'order_id' => '42'];
check(str_contains(render_page(), 'ID Fatture in Cloud') && posts() === 1, 'Page reload preserves association');
check(isset($hooks['pmpro_added_order']) && $hooks['pmpro_added_order'][0][1] === 'save_order', 'Order hook only saves billing snapshot');

// API validation errors follow the documented error.message / validation_result map.
$service = fixture(); $p = preview($service);
$post_responses = [['status' => 422, 'body' => json_encode(['error' => [
    'message' => 'Invalid request.',
    'validation_result' => ['data.items_list.0.vat.id' => ['The VAT ID must exist.'], 'data.payments_list.0.amount' => ['Payment total mismatch.']],
    'extra' => ['totals' => ['amount_gross' => 122, 'amount_payments' => 121, 'debug' => 'test-secret-not-for-html']],
    'debug' => 'This must never be displayed.',
]])]];
$error = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
error_is($error, 'rejected');
check(str_contains($error->get_error_message(), 'Invalid request.') && str_contains($error->get_error_message(), 'data.items_list.0.vat.id'), 'Displays message and rejected field path');
check(str_contains($error->get_error_message(), 'Payment total mismatch.') && str_contains($error->get_error_message(), 'amount_payments: 121,00'), 'Displays numeric totals diagnostics');
check(!str_contains($error->get_error_message(), 'debug') && !str_contains($error->get_error_message(), 'never be displayed'), 'Ignores unrelated response properties');
check($settings_store['pmpro_fic_test_order_42']['last_error'] === $error->get_error_message(), 'Stores only safe validation summary');
check(!isset($settings_store['pmpro_fic_lock_42']), 'Detailed rejection still releases creation lock');
$edit = load_test_order($service);
ob_start(); $service->render($edit, 'load_order'); $html = ob_get_clean();
check(str_contains($html, 'Ultimo rifiuto') && str_contains($html, 'data.items_list.0.vat.id'), 'Reload retains last rejected error');
check(posts() === 1, 'Reading previous error does not create another document');

$service = fixture(); $p = preview($service);
$post_responses = [['status' => 422, 'body' => json_encode(['error' => [
    'message' => '<script>untrusted</script> Token test-secret-not-for-html. Email buyer@example.test. Bearer another-secret',
    'validation_result' => ['data.entity.name' => ['<b>Bad name</b> test-secret-not-for-html']],
]])]];
$error = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
$message = $error->get_error_message();
check(!str_contains($message, 'test-secret-not-for-html') && !str_contains($message, 'another-secret') && !str_contains($message, 'buyer@example.test'), 'Redacts token, authorization and email');
check(!str_contains($message, '<script>') && !str_contains($message, '<b>'), 'Treats validation messages as plain text');
check(!str_contains(json_encode($settings_store), 'another-secret') && !str_contains($settings_store['pmpro_fic_test_order_42']['last_error'], 'test-secret-not-for-html'), 'No response credentials saved in diagnostic');

foreach (['<html>secret test-secret-not-for-html</html>', '{"error":"invalid"}', '{"error":{"message":null,"validation_result":null}}'] as $body) {
    $service = fixture(); $p = preview($service); $post_responses = [['status' => 422, 'body' => $body]];
    $error = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
    check(str_contains($error->get_error_message(), 'non contiene dettagli') && !str_contains($error->get_error_message(), 'test-secret-not-for-html'), 'Malformed validation body uses safe fallback');
}
$service = fixture(); $p = preview($service);
$post_responses = [['status' => 400, 'body' => json_encode(['error' => ['message' => 'Invalid request.', 'validation_result' => ['xml_errors' => ['Campo mancante.']]]])]];
$error = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
check(str_contains($error->get_error_message(), 'Campo mancante.'), 'Handles validation array format and HTTP 400');
$service = fixture(); $p = preview($service);
$post_responses = [['status' => 422, 'body' => json_encode(['error' => ['message' => 'Invalid request.', 'validation_result' => array_fill(0, 1000, str_repeat('x', 1000))]])]];
$error = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
check(strlen($error->get_error_message()) < 3200, 'Bounds validation output size');

// Remote totals calculation must not modify payment to conceal a different paid amount.
$service = fixture(); $p = preview($service);
check(count($post_requests) === 1 && str_ends_with($post_requests[0][0], '/issued_documents/totals'), 'Preview uses documented non-creating totals endpoint');
$calculation = json_decode($post_requests[0][1]['body'], true);
check(!isset($calculation['data']['payments_list']) && !isset($calculation['options']), 'Calculates invoice without conflicting payment sum');
check($p['totals_verified'] === true && $p['preview_version'] === 4, 'Preview records remote totals validation and readable subject format');
check($p['payload']['options']['fix_payments'] === true && $p['payload']['data']['payments_list'][0]['amount'] == 122, 'Payment alignment only after exact paid-total check');
check($p['payload']['data']['stamp_duty'] === 0 && $p['payload']['data']['withholding_tax'] === 0
    && $p['payload']['data']['items_list'][0]['apply_withholding_taxes'] === false, 'Simple test explicitly disables additional charges and withholding defaults');

$service = fixture(); load_test_order($service); $orders[42]['total'] = '19.99';
$totals_responses = [response(['amount_net' => 16.39, 'amount_vat' => 3.60, 'amount_gross' => 19.99, 'amount_due' => 19.99])];
$p = $service->dispatch('preview_order', customer_input());
check($p['gross'] === 19.99 && $p['net'] === 16.39 && $p['vat'] === 3.60, 'Preview displays remote monetary amounts');
check($p['payload']['data']['payments_list'][0]['amount'] === 19.99, 'Fractional payment follows verified total');
check(posts() === 0, 'Remote preview creates no document');

foreach ([['amount_gross', 122.01, 'totals_mismatch'], ['amount_due', 121.99, 'totals_mismatch'],
    ['amount_gross', 0, 'totals_mismatch'], ['amount_net', 90, 'totals_components'], ['amount_due', -1, 'invalid_totals'],
    ['amount_due', 'not-numeric', 'invalid_totals'], ['amount_vat', null, 'invalid_totals']] as [$field, $value, $code]) {
    $service = fixture(); load_test_order($service);
    $totals = ['amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122, 'amount_due' => 122]; $totals[$field] = $value;
    $totals_responses = [response($totals)];
    error_is($service->dispatch('preview_order', customer_input()), $code);
    check(posts() === 0 && !$settings_store, 'Invalid remote total cannot reserve or create a document');
}
$service = fixture(); load_test_order($service);
$totals_responses = [new WP_Error('timeout', 'not shown')];
error_is($service->dispatch('preview_order', customer_input()), 'totals_error');
check(!$settings_store && posts() === 0, 'Failed calculation creates no uncertain invoice journal');
$p = $service->dispatch('preview_order', customer_input());
check($p['stage'] === 'preview' && posts() === 0, 'Calculation can safely retry after timeout');
$cached = get_transient('pmpro_fic_preview_' . $p['preview_id']);
unset($cached['totals_verified'], $cached['preview_version']);
set_transient('pmpro_fic_preview_' . $p['preview_id'], $cached, 900);
error_is($service->dispatch('create_order', ['preview_id' => $p['preview_id']]), 'preview_expired');
check(posts() === 0, 'Old previews cannot bypass remote totals check');

$service = fixture(); $p = preview($service);
$post_responses = [['status' => 422, 'body' => json_encode(['error' => ['message' => 'Il totale dei pagamenti non corrisponde al totale da pagare.'],
    'extra' => ['totals' => ['amount_gross' => 122, 'amount_due' => 122, 'payments_sum' => 121, 'ignored_secret' => 'secret']]])]];
$error = $service->dispatch('create_order', ['preview_id' => $p['preview_id']]);
check(str_contains($error->get_error_message(), 'amount_due: 122,00') && str_contains($error->get_error_message(), 'payments_sum: 121,00'), 'Reads documented top-level extra.totals and payments_sum');
check(!str_contains($error->get_error_message(), 'ignored_secret'), 'Does not expose unrelated totals diagnostics');
$service = fixture();
$options['plan_descriptions'] = [4=>'Accesso al servizio Professional', 5=>'Accesso al servizio Gold'];
$p = preview($service);
check($p['payload']['data']['items_list'][0]['name'] === 'Accesso al servizio Professional', 'Professional order uses its complete custom description');
check($p['payload']['data']['subject'] === 'Accesso al servizio Professional' && !str_contains($p['payload']['data']['subject'], $p['marker']), 'FIC listing subject is the configured Professional description');
check(str_contains($p['payload']['data']['notes'], $p['marker']) && $p['payload']['data']['visible_subject'] === 'TEST — ordine PMPro #42', 'Recovery marker remains in notes without changing the printed test heading');
ob_start(); $service->render($p, 'preview_order'); $preview_html = ob_get_clean();
check(str_contains($preview_html, 'Oggetto nell’elenco Fatture in Cloud:') && str_contains($preview_html, 'Accesso al servizio Professional'), 'Preview displays readable FIC listing subject');
$post_responses = [document_response()];
$service->dispatch('create_order', ['preview_id'=>$p['preview_id']]);
check(json_decode($created_requests[0][1]['body'],true)['data']['items_list'][0]['name'] === 'Accesso al servizio Professional', 'Reviewed plan description reaches the FIC POST unchanged');
$options['plan_descriptions'][4] = 'Descrizione aggiornata';
$existing = $service->dispatch('load_order', ['order_id'=>'42']);
check($existing['stage'] === 'created' && posts() === 1, 'Changing text never rewrites an associated document');
$service = fixture(); $orders[42]['membership_id'] = 5;
$options['plan_descriptions'] = [4=>'Accesso al servizio Professional', 5=>'Accesso al servizio Gold'];
$p = preview($service);
check($p['payload']['data']['items_list'][0]['name'] === 'Accesso al servizio Gold', 'Gold order selects Gold description, without level suffix');
check($p['payload']['data']['subject'] === 'Accesso al servizio Gold', 'Gold listing subject follows its own description');
$options['plan_descriptions'][5] = 'Gold aggiornato';
error_is($service->dispatch('create_order', ['preview_id'=>$p['preview_id']]), 'preview_expired');
check(posts() === 0, 'Changing description invalidates reviewed payload before document creation');
$service = fixture(); $options['plan_descriptions'] = [5=>'Gold configurato'];
$p = preview($service);
check($p['payload']['data']['items_list'][0]['name'] === 'Abbonamento — livello #4', 'Unconfigured plan retains legacy fallback');

// Human subjects are shared: only a unique technical reference may recover an uncertain POST.
$service = fixture(); $p = preview($service);
$cached = get_transient('pmpro_fic_preview_' . $p['preview_id']);
$cached['preview_version'] = 3; $cached['payload']['data']['subject'] = $p['marker'];
set_transient('pmpro_fic_preview_' . $p['preview_id'], $cached, 900);
error_is($service->dispatch('create_order', ['preview_id'=>$p['preview_id']]), 'preview_expired');
check(posts() === 0, 'Old-format preview must be regenerated after upgrade');

$service = fixture(); $p = preview($service); $post_responses = [new WP_Error('timeout','ambiguous')];
$service->dispatch('create_order', ['preview_id'=>$p['preview_id']]);
$settings_store['pmpro_fic_test_order_42']['requested_at'] -= 121;
// Journal and remote document from 0.5.4 or earlier, still recoverable after upgrade.
$settings_store['pmpro_fic_test_order_42']['payload']['data']['subject'] = $p['marker'];
$responses = [response([['id'=>77,'number'=>1,'numeration'=>'/TEST','amount_gross'=>122,'subject'=>$p['marker']]])];
$result = $service->dispatch('recover_order', ['order_id'=>'42']);
check($result['stage'] === 'created' && posts() === 1, 'Legacy UUID subject recovers without another creation');
parse_str(parse_url(end($requests)[0], PHP_URL_QUERY), $recovery_query);
check(in_array('notes', explode(',',$recovery_query['fields']),true), 'Recovery explicitly requests technical notes');

foreach (['unrelated', 'suffix', 'prefix', 'missing'] as $case) {
    $service = fixture(); $p = preview($service); $post_responses = [new WP_Error('timeout','ambiguous')];
    $service->dispatch('create_order', ['preview_id'=>$p['preview_id']]);
    $settings_store['pmpro_fic_test_order_42']['requested_at'] -= 121;
    $notes = ['unrelated'=>'Riferimento: innerplay-test-00000000-0000-4000-8000-000000000000',
        'suffix'=>'Riferimento: ' . $p['marker'] . 'x', 'prefix'=>'Riferimento: x' . $p['marker'], 'missing'=>null][$case];
    $responses = [response([['id'=>78,'number'=>2,'numeration'=>'/TEST','amount_gross'=>122,
        'subject'=>$p['payload']['data']['subject'],'notes'=>$notes]])];
    error_is($service->dispatch('recover_order', ['order_id'=>'42']), 'not_found_uncertain');
    error_is($service->dispatch('create_order', ['preview_id'=>$p['preview_id']]), 'uncertain');
    check(posts() === 1 && isset($settings_store['pmpro_fic_lock_42']), 'Same human subject and non-exact marker never authorize another POST: ' . $case);
}

$service = fixture(); $p = preview($service); $post_responses = [new WP_Error('timeout','ambiguous')];
$service->dispatch('create_order', ['preview_id'=>$p['preview_id']]);
$settings_store['pmpro_fic_test_order_42']['requested_at'] -= 121;
$first_page = [];
for ($i=1; $i<=100; $i++) $first_page[] = ['id'=>$i,'number'=>$i,'numeration'=>'/TEST','amount_gross'=>122,
    'subject'=>$p['payload']['data']['subject'],'notes'=>$i === 77 ? $p['payload']['data']['notes'] : 'Altro documento'];
$responses = [response($first_page), response([['id'=>101,'number'=>101,'numeration'=>'/TEST','amount_gross'=>122,
    'subject'=>$p['payload']['data']['subject'],'notes'=>$p['payload']['data']['notes']]])];
error_is($service->dispatch('recover_order', ['order_id'=>'42']), 'ambiguous_recovery');
check(posts() === 1 && isset($settings_store['pmpro_fic_lock_42']) && !isset($order_meta[42][PMPro_FIC_Test_Orders::META]), 'Repeated marker on another page blocks arbitrary association and preserves duplicate protection');

$service = fixture(); $p = preview($service); $post_responses = [new WP_Error('timeout','ambiguous')];
$service->dispatch('create_order', ['preview_id'=>$p['preview_id']]);
$settings_store['pmpro_fic_test_order_42']['requested_at'] -= 121;
unset($settings_store['pmpro_fic_test_order_42']['marker']);
error_is($service->dispatch('recover_order', ['order_id'=>'42']), 'invalid_attempt');
check(posts() === 1 && isset($settings_store['pmpro_fic_lock_42']), 'Missing journal marker fails closed');

echo "PASS: {$checks} manual-order checks. HTTP and WordPress simulated; no external documents or payments.\n";
