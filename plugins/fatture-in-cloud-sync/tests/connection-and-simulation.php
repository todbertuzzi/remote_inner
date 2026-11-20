<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/connection-and-simulation.php */
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
class WP_Error {
    public function __construct(public $code, public $message) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class InvalidNonce extends RuntimeException {}
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, ...$args) { $GLOBALS['filters'][$hook][] = $callback; }
function apply_filters($hook, $value, ...$args) { return array_key_exists($hook, $GLOBALS['filter_values'] ?? []) ? $GLOBALS['filter_values'][$hook] : $value; }
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['admin']; }
function get_option($name, $default = []) { return $name === 'pmpro_fic_sync_options' ? $GLOBALS['options'] : ($GLOBALS['settings_store'][$name] ?? $default); }
function get_transient($key) {
    $entry = $GLOBALS['cache'][$key] ?? null;
    return $entry && $entry[1] > $GLOBALS['clock'] ? $entry[0] : false;
}
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = [$value, $GLOBALS['clock'] + $ttl]; }
function delete_transient($key) { unset($GLOBALS['cache'][$key]); }
function add_settings_error($name, $code, $message) { $GLOBALS['errors'][] = $code; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function selected($a, $b, $echo = true) { return $a === $b ? 'selected="selected"' : ''; }
function current_time($format) { return $format === 'Y-m-d' ? '2026-10-06' : '2026-10-06 12:00:00'; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_get($url, $args) {
    if (strpos($url, 'https://api-m.sandbox.paypal.com/v1/payments/sale/') === 0) {
        $GLOBALS['sale_requests'][] = [$url, $args];
        if (isset($GLOBALS['on_sale_request'])) ($GLOBALS['on_sale_request'])($url, $args);
        if (!empty($GLOBALS['sale_responses'])) return array_shift($GLOBALS['sale_responses']);
        $id = basename($url);
        foreach ($GLOBALS['orders'] ?? [] as $oid => $fields) {
            $order = new MemberOrder($oid);
            if ($order->payment_transaction_id !== $id) continue;
            $at = $GLOBALS['paypal_transactions']['transactions'][0]['time'] ?? gmdate('Y-m-d\TH:i:s\Z', $order->timestamp);
            return ['status'=>200, 'body'=>json_encode(['id'=>$id, 'state'=>'completed', 'billing_agreement_id'=>$order->subscription_transaction_id,
                'amount'=>['total'=>number_format((float)$order->total, 2, '.', ''), 'currency'=>'EUR'], 'create_time'=>$at])];
        }
        throw new RuntimeException('Unexpected PayPal sale GET');
    }
    $GLOBALS['requests'][] = [$url, $args];
    if (isset($GLOBALS['on_fic_request'])) ($GLOBALS['on_fic_request'])($url, $args, 'GET');
    if (!$GLOBALS['responses']) throw new RuntimeException('Unexpected GET: ' . $url);
    return array_shift($GLOBALS['responses']);
}
function wp_remote_post($url, $args) {
    if (empty($GLOBALS['allow_document_test'])) throw new RuntimeException('Remote write forbidden');
    $GLOBALS['post_requests'][] = [$url, $args];
    if (isset($GLOBALS['on_fic_request'])) ($GLOBALS['on_fic_request'])($url, $args, 'POST');
    if (substr($url, -24) === '/issued_documents/totals') {
        if (array_key_exists('totals_responses', $GLOBALS) && $GLOBALS['totals_responses']) return array_shift($GLOBALS['totals_responses']);
        return ['status' => 200, 'body' => '{"data":{"amount_net":100,"amount_vat":22,"amount_gross":122,"amount_due":122}}'];
    }
    $GLOBALS['created_requests'][] = [$url, $args];
    if (empty($GLOBALS['post_responses'])) throw new RuntimeException('Unexpected document request');
    return array_shift($GLOBALS['post_responses']);
}
function wp_remote_request(...$args) { throw new RuntimeException('Unexpected HTTP request'); }
function wp_mail(...$args) { throw new RuntimeException('Email forbidden'); }
function update_post_meta(...$args) { throw new RuntimeException('Post metadata forbidden'); }
function update_pmpro_membership_order_meta($id, $key, $value) {
    if (empty($GLOBALS['allow_document_test'])) throw new RuntimeException('Order metadata forbidden');
    if (empty($GLOBALS['fail_meta'])) $GLOBALS['order_meta'][$id][$key] = $value;
}
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function check_admin_referer($action) { if (!$GLOBALS['nonce'] || $action !== PMPro_FattureInCloud_Sync::NONCE) throw new InvalidNonce(); }
function wp_nonce_field($action, $name = '_wpnonce') { echo '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">'; }
function submit_button($label, $type = '') { echo '<button>' . esc_html($label) . '</button>'; }
function settings_errors(...$args) {}
function settings_fields(...$args) {}
function register_setting($group, $name, $args) { $GLOBALS['sanitizer'] = $args['sanitize_callback']; }
function add_settings_section(...$args) {}
function add_settings_field($id, $label, $callback, $page, $section, $args) { $GLOBALS['fields'][$id] = [$callback, $args]; }
function do_settings_sections($page) { foreach ($GLOBALS['fields'] as [$callback, $args]) $callback($args); }
function add_options_page(...$args) {}
function pmpro_getAllLevels($include_hidden = false) {
    return $GLOBALS['test_levels'] ?? [
        3 => (object) ['id' => 3, 'name' => 'Welcome'],
        4 => (object) ['id' => 4, 'name' => 'Professional'],
        5 => (object) ['id' => 5, 'name' => 'Gold'],
    ];
}

$hooks = $fields = [];
require dirname(__DIR__) . '/fatture-in-cloud-sync.php';
$plugin = new PMPro_FattureInCloud_Sync();
$plugin->register_settings();
$checks = 0;
function check($condition, $description) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($description);
}
function error_is($result, $code) { check(is_wp_error($result) && $result->get_error_code() === $code, 'Expected error ' . $code); }
function reset_fixture() {
    $GLOBALS['options'] = ['company_id' => 123, 'access_token' => 'test-secret-not-for-html', 'vat_id' => '', 'document_prefix' => 'Abbonamento', 'plan_descriptions' => []];
    foreach (['cache', 'requests', 'responses', 'errors'] as $key) $GLOBALS[$key] = [];
    $GLOBALS['admin'] = true;
    $GLOBALS['nonce'] = true;
    $GLOBALS['clock'] = 0;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
}
function response($data, $status = 200) { return ['status' => $status, 'body' => json_encode(['data' => $data])]; }
function valid_responses() {
    $GLOBALS['responses'] = [response(['companies' => [['id' => 123, 'name' => 'Azienda test']]]), response([
        ['id' => 0, 'value' => 22, 'description' => 'IVA ordinaria'],
        ['id' => 5, 'value' => 0, 'description' => 'Esente'],
        ['id' => 6, 'value' => 10, 'description' => 'Disabilitata', 'is_disabled' => true],
    ])];
}
function save_vat($id = '0') {
    global $plugin;
    $GLOBALS['options'] = $plugin->sanitize_options(['company_id' => '123', 'access_token' => '', 'vat_id' => $id, 'document_prefix' => 'Abbonamento']);
}
function render_page() { global $plugin; ob_start(); try { $plugin->settings_page(); return ob_get_contents(); } finally { ob_end_clean(); } }

if (defined('PMPRO_FIC_TEST_BOOTSTRAP')) return;

reset_fixture();
check(isset($hooks['pmpro_added_order']) && $hooks['pmpro_added_order'][0][1] === 'save_order', 'Order hook only saves billing snapshot');
$options['enabled'] = 1;
check(count($requests) === 0, 'Legacy enabled flag cannot make requests');
$options = [];
error_is($plugin->test_connection(), 'missing_config');
check(count($requests) === 0, 'Missing settings do not contact API');

reset_fixture();
valid_responses();
$result = $plugin->test_connection();
check($result['company_id'] === 123 && count($result['vats']) === 2, 'Reads company and excludes disabled VAT');
check($requests[0][0] === 'https://api-v2.fattureincloud.it/user/companies', 'Official company endpoint');
check($requests[1][0] === 'https://api-v2.fattureincloud.it/c/123/info/vat_types', 'Official VAT endpoint');
check($requests[0][1]['redirection'] === 0, 'No credential forwarding on redirects');
check($requests[0][1]['headers']['Authorization'] === 'Bearer test-secret-not-for-html', 'Bearer authentication');
check(!str_contains(json_encode($cache), 'test-secret-not-for-html'), 'No token stored in results cache');
save_vat();
check($options['vat_id'] === '0', 'VAT ID zero survives settings validation');
check($options['access_token'] === 'test-secret-not-for-html', 'Blank password preserves token');
check($options['enabled'] === 0, 'Old synchronization disabled on save');
$preview = $plugin->simulate_invoice('122,00');
check($preview['gross'] == 122 && $preview['net'] == 100 && $preview['vat'] == 22, '122 gross = 100 net + 22 VAT');
check($preview['payload']['data']['use_gross_prices'] === true, 'Gross-price mode explicitly selected');
check($preview['payload']['data']['items_list'][0]['gross_price'] == 122, 'No double taxation in payload');
check($preview['payload']['data']['items_list'][0]['vat']['id'] === 0, 'Payload preserves VAT ID zero');
check(count($requests) === 2, 'Simulation performs no additional request');
$preview = $plugin->simulate_invoice('0.01');
check(round($preview['net'] + $preview['vat'], 2) === $preview['gross'], 'Cent rounding preserves total');
$preview = $plugin->simulate_invoice('12.5');
check($preview['gross'] === 12.5, 'One decimal parsed correctly');
save_vat('5');
$preview = $plugin->simulate_invoice('99');
check($preview['net'] == 99 && $preview['vat'] == 0, 'Zero-percent VAT');
foreach (['-1', '1.000,00', '1e3', 'NaN', '', '1.234', '10000000', '1,2,3'] as $invalid) error_is($plugin->simulate_invoice($invalid), 'invalid_amount');
error_is($plugin->simulate_invoice('0'), 'zero_amount');
$options['vat_id'] = '';
error_is($plugin->simulate_invoice('122'), 'vat_required');
save_vat('0');
$clock = 3601;
error_is($plugin->simulate_invoice('122'), 'vat_required');

foreach ([401, 403, 404, 429, 500, 302] as $status) {
    reset_fixture();
    $responses = [['status' => $status, 'body' => 'secret test-secret-not-for-html']];
    $result = $plugin->test_connection();
    error_is($result, 'api_' . $status);
    check(!str_contains($result->get_error_message(), 'test-secret-not-for-html'), 'Raw HTTP response not disclosed');
    check(!$cache, 'No cached success after failure');
}
reset_fixture();
$responses = [new WP_Error('transport', 'secret test-secret-not-for-html')];
$result = $plugin->test_connection();
error_is($result, 'connection');
check(!str_contains($result->get_error_message(), 'test-secret-not-for-html'), 'Transport details not disclosed');
foreach (['<html>proxy error</html>', '{"data":null}', '{"data":"bad"}'] as $body) {
    $responses = [['status' => 200, 'body' => $body]];
    error_is($plugin->test_connection(), 'invalid_response');
}
reset_fixture();
$responses = [response(['companies' => [['id' => 999, 'name' => 'Wrong company']]])];
error_is($plugin->test_connection(), 'company_not_allowed');
check(count($requests) === 1, 'Wrong company stops before VAT request');
valid_responses();
$responses[1] = response([['id' => 0], ['id' => 3, 'value' => -100], ['id' => 4, 'value' => 'bad']]);
error_is($plugin->test_connection(), 'no_vats');

reset_fixture();
valid_responses();
$plugin->test_connection();
save_vat();
$options = $plugin->sanitize_options(['company_id' => '999', 'vat_id' => '0']);
check($options['vat_id'] === '' && !$cache, 'Company change clears stale VAT and old verification');
error_is($plugin->simulate_invoice('122'), 'vat_required');
reset_fixture();
valid_responses();
$plugin->test_connection();
$options = $plugin->sanitize_options(['company_id' => '123', 'access_token' => 'replacement', 'vat_id' => '0']);
check($options['access_token'] === 'replacement' && !$cache && $options['vat_id'] === '', 'Token rotation invalidates verification');
$options = $plugin->sanitize_options(['company_id' => '123', 'forget_token' => 1]);
check($options['access_token'] === '', 'Explicit removal forgets token');
reset_fixture();
$next = $plugin->sanitize_options(['company_id' => '-123', 'access_token' => 'Bearer secret']);
check($next['company_id'] === 123 && $next['access_token'] === $options['access_token'], 'Invalid input does not overwrite valid credentials');
check(in_array('company', $errors) && in_array('token', $errors), 'Invalid settings report errors');
valid_responses();
$plugin->test_connection();
$next = $plugin->sanitize_options(['company_id' => 123, 'vat_id' => 999]);
check($next['vat_id'] === '' && in_array('vat', $errors), 'Cannot inject an unknown VAT ID');

reset_fixture();
$admin = false;
error_is($plugin->test_connection(), 'forbidden');
error_is($plugin->simulate_invoice('122'), 'forbidden');
check($plugin->sanitize_options(['company_id' => 999]) === $options, 'Non-admin cannot save credentials');
check(render_page() === '' && !$requests, 'Non-admin sees no configuration and makes no requests');
$admin = true;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['fic_action' => 'connect'];
$nonce = false;
try { render_page(); throw new RuntimeException('Invalid nonce accepted'); } catch (InvalidNonce $e) { check(!$requests, 'Nonce checked before any API call'); }
$nonce = true;
valid_responses();
$html = render_page();
check(str_contains($html, 'Collegamento riuscito') && str_contains($html, 'Azienda test'), 'Connection result shown on settings page');
check(!str_contains($html, 'test-secret-not-for-html'), 'Saved token never rendered in HTML');
save_vat();
$_POST = ['fic_action' => 'simulate', 'amount' => '122,00'];
$html = render_page();
check(str_contains($html, '100,00') && str_contains($html, '22,00') && str_contains($html, '122,00'), 'Simulation summary rendered');
check(count($requests) === 2, 'Page simulation does not write to remote service');
$_POST = ['fic_action' => 'send'];
check(str_contains(render_page(), 'Operazione non disponibile'), 'No hidden send action');
$_POST = ['fic_action' => ['connect']];
check(str_contains(render_page(), 'Operazione non disponibile'), 'Array input safely rejected');
$_POST = ['fic_action' => 'simulate', 'amount' => ['122']];
check(str_contains(render_page(), 'Inserisci un totale positivo'), 'Invalid amount array rejected');

reset_fixture(); valid_responses(); $plugin->test_connection(); save_vat();
$legacy_scope = $plugin->automation_scope();
$context = $plugin->order_context();
check(!isset($context['plan_descriptions']), 'Empty mapping preserves legacy context and queued jobs');
$next = $plugin->sanitize_options(['company_id'=>'123', 'vat_id'=>'0', 'document_prefix'=>'Abbonamento',
    'plan_descriptions'=>['5'=>'<b>Servizio Gold</b>', '4'=>"Servizio Professional\nannuale"]]);
$options = $next;
check($options['plan_descriptions'] === [4=>'Servizio Professional annuale', 5=>'Servizio Gold'], 'Per-plan text sanitized and stable across input order');
check($options['access_token'] === 'test-secret-not-for-html' && $options['vat_id'] === '0', 'Description save preserves token and VAT');
check($plugin->automation_scope() !== $legacy_scope, 'Plan descriptions bind automation configuration');
check($plugin->order_context()['plan_descriptions'] === $options['plan_descriptions'], 'Order context contains descriptions');
$without_mapping = $plugin->sanitize_options(['company_id'=>'123', 'vat_id'=>'0', 'document_prefix'=>'Abbonamento']);
check($without_mapping['plan_descriptions'] === $options['plan_descriptions'], 'Older form without mapping cannot erase saved descriptions');
$invalid = $plugin->sanitize_options(['company_id'=>'123', 'vat_id'=>'0', 'plan_descriptions'=>['4'=>str_repeat('x',1001)]]);
check($invalid['plan_descriptions'] === $options['plan_descriptions'] && in_array('plan_descriptions',$errors), 'Oversized description retains saved mapping and reports error');
$invalid = $plugin->sanitize_options(['company_id'=>'123', 'vat_id'=>'0', 'plan_descriptions'=>['bad'=>['bad']]]);
check($invalid['plan_descriptions'] === $options['plan_descriptions'], 'Malformed mapping cannot overwrite saved text');
$options['plan_descriptions'][9] = 'Servizio piano storico';
$_GET['fic_section'] = 'impostazioni';
$html = render_page();
check(str_contains($html, 'Professional') && str_contains($html, 'Gold') && str_contains($html, 'plan_descriptions][4]'), 'Admin fields follow real PMPro levels and IDs');
check(str_contains($html, 'Servizio piano storico') && str_contains($html, 'plan_descriptions][9]'), 'Descriptions for retired plans remain editable');
$next = $plugin->sanitize_options(['company_id'=>'123', 'vat_id'=>'0', 'document_prefix'=>'Abbonamento', 'plan_descriptions'=>['4'=>'', '5'=>'  ', '9'=>'']]);
$options = $next;
check($options['plan_descriptions'] === [] && $plugin->automation_scope() === $legacy_scope, 'Cleared descriptions restore original scope');

reset_fixture();
define('PMPRO_FIC_ACCESS_TOKEN', 'server-only-secret');
valid_responses();
$plugin->test_connection();
check($requests[0][1]['headers']['Authorization'] === 'Bearer server-only-secret', 'Server constant overrides database token');
$next = $plugin->sanitize_options(['company_id' => '123', 'access_token' => 'browser-secret']);
check($next['access_token'] === '', 'Server token not copied into database');
$html = render_page();
check(str_contains($html, 'PMPRO_FIC_ACCESS_TOKEN') && !str_contains($html, 'server-only-secret'), 'Constant status shown without secret');
echo "PASS: {$checks} checks. HTTP, settings and WordPress simulated; no real documents created.\n";
