<?php
/** Passive HTTP/REST observers with real bounded file writes, no remote calls. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public function __construct(public $code, public $message) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class PMPro_FattureInCloud_Sync { const NONCE = 'pmpro_fic_test'; const PAGE = 'pmpro-fic-sync'; }
class MemberOrder {
    public $status = 'success';
    public $payment_transaction_id = '';
    public function __construct($id) { throw new RuntimeException('PMPro constructor may create subscriptions or call gateway APIs'); }
    public function saveOrder() { throw new RuntimeException('Diagnostics must not save orders'); }
}
class Request {
    public function __construct(public $payload, public $route = '/pmpro-paypal/v1/webhook') {}
    public function get_route() { return $this->route; }
    public function get_json_params() { return $this->payload; }
}
class RestResponse { public function __construct(public $status) {} public function get_status() { return $this->status; } }
class DiagnosticsDB {
    public $pmpro_membership_orders = 'wp_pmpro_membership_orders';
    public $queries = [];
    public function prepare($query, $id) { $this->queries[] = [$query, $id]; return str_replace('%d', (string) (int) $id, $query); }
    public function get_row($query) {
        if (str_starts_with($query, 'SELECT id, status, payment_transaction_id FROM wp_pmpro_membership_orders WHERE id = ')) {
            return (object) ['id' => 56, 'status' => 'success', 'payment_transaction_id' => $GLOBALS['payment']];
        }
        throw new RuntimeException('Unexpected database operation');
    }
}
$wpdb = new DiagnosticsDB();
function add_action($name, $callback, $priority, $count) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter($name, $callback, $priority, $count) { add_action($name, $callback, $priority, $count); }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; }
function current_user_can($cap) { return $GLOBALS['admin']; }
function check_admin_referer($nonce) { if (!$GLOBALS['nonce'] || $nonce !== PMPro_FattureInCloud_Sync::NONCE) throw new RuntimeException('nonce'); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_remote_get(...$args) { throw new RuntimeException('Extra API calls forbidden'); }
function wp_remote_post(...$args) { throw new RuntimeException('Extra API calls forbidden'); }
function wp_mail(...$args) { throw new RuntimeException('Email forbidden'); }
function update_pmpro_membership_order_meta(...$args) { throw new RuntimeException('Order writes forbidden'); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function admin_url($path) { return 'https://test.invalid/wp-admin/' . $path; }
function wp_nonce_field($v) { echo '<input name="nonce">'; }
function submit_button($v, $type) { echo '<button>' . esc_html($v) . '</button>'; }
function pmpro_get_restricted_file_path($dir, $file) { return $GLOBALS['unavailable'] ? '' : $GLOBALS['log_dir'] . '/' . $file; }
$log_dir = sys_get_temp_dir() . '/fic-diag-' . bin2hex(random_bytes(8));
mkdir($log_dir, 0700);
register_shutdown_function(function () use ($log_dir) { foreach (glob($log_dir . '/*') as $path) unlink($path); rmdir($log_dir); });
$hooks = [];
$options = ['pmpro_gateway' => 'paypal', 'pmpro_gateway_environment' => 'sandbox'];
$admin = $nonce = true;
$unavailable = false;
$payment = '';
require dirname(__DIR__) . '/includes/class-pmpro-fic-diagnostics.php';
$diag = new PMPro_FIC_Diagnostics();
$checks = 0;
function check($value, $description) { $GLOBALS['checks']++; if (!$value) throw new RuntimeException($description); }
function logfile() { return $GLOBALS['log_dir'] . '/' . PMPro_FIC_Diagnostics::FILE; }
function entries() {
    if (!file_exists(logfile())) return [];
    $lines = file(logfile(), FILE_IGNORE_NEW_LINES);
    if (array_shift($lines) !== '<?php exit; ?>') throw new RuntimeException('Missing direct access protection');
    return array_map(function ($line) { $entry = json_decode($line, true); if (!is_array($entry)) throw new RuntimeException('Invalid JSON line'); return $entry; }, $lines);
}
function last_entry() { $rows = entries(); return end($rows); }
function response($body, $status = 200) { return ['response' => ['code' => $status], 'body' => is_string($body) ? $body : json_encode($body), 'headers' => ['secret' => 'PRIVATE_SENTINEL']]; }
function exchange($url, $response, $args = ['method' => 'GET'], $filtered = null) {
    $diag = $GLOBALS['diag'];
    check($diag->request_args($args, $url) === $args, 'HTTP args including credentials unchanged');
    check($diag->pre_request(false, $args, $url) === false, 'Network execution unchanged');
    $diag->http_debug($response, 'response', 'transport', $args, $url);
    $returned = is_wp_error($response) ? $response : $diag->http_response($filtered ?? $response, $args, $url);
    check($returned === ($filtered ?? $response), 'API result unchanged');
    return $returned;
}
function pmpro_paypal_handle_subscription_activated($url, $response) { return exchange($url, $response); }
class PMPro_FIC_PayPal_Recovery { public function read($url, $response) { return exchange($url, $response); } }
class PMPro_FIC_Automation {
    public function process($url, $response) { return (new PMPro_FIC_PayPal_Recovery())->read($url, $response); }
    public function on_updated_order($url, $response) { return exchange($url, $response); }
}
class PMProGateway_paypal { public function check_token_order($url, $response) { return exchange($url, $response); } }
$url = 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-8TG2LLTDSLSW/transactions?start_time=2026-10-14T10%3A00%3A00Z&end_time=2026-10-14T12%3A00%3A00Z&secret=PRIVATE_SENTINEL';
$empty = response(['transactions' => [], 'total_items' => 0, 'total_pages' => 0, 'customer' => 'PRIVATE_SENTINEL']);
$full = response(['transactions' => [['id' => '844753021M0826123', 'status' => 'COMPLETED', 'time' => '2026-10-14T10:38:30Z',
    'payer_email' => 'PRIVATE_SENTINEL', 'amount_with_breakdown' => ['gross_amount' => ['value' => '841.80', 'currency_code' => 'EUR']]]], 'total_items' => 1, 'total_pages' => 1]);
exchange($url, $empty);
check(!file_exists(logfile()), 'Off by default, no file writes');
$admin = false;
check(is_wp_error($diag->dispatch('diag_enable')), 'Non-admin cannot enable');
$admin = true; $nonce = false;
try { $diag->dispatch('diag_enable'); check(false, 'Nonce must fail'); } catch (RuntimeException $e) { check($e->getMessage() === 'nonce', 'CSRF rejected'); }
$nonce = true;
foreach (['live', ''] as $env) { $options['pmpro_gateway_environment'] = $env; check(is_wp_error($diag->dispatch('diag_enable')), 'Sandbox only'); }
$options['pmpro_gateway_environment'] = 'sandbox'; $options['pmpro_gateway'] = 'paypalexpress';
check(is_wp_error($diag->dispatch('diag_enable')), 'Modern gateway required');
$options['pmpro_gateway'] = 'paypal'; $unavailable = true;
check(is_wp_error($diag->dispatch('diag_enable')), 'Unavailable protected path rejected');
$unavailable = false;
check(!is_wp_error($diag->dispatch('diag_enable')), 'Enable diagnostics');
check($options[PMPro_FIC_Diagnostics::OPTION]['until'] > time() + 3590, '60 minute expiry');
check(count($hooks) === 7, 'All passive observers registered');
$request = new Request(['id' => 'WH-EXAMPLE12345', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
    'resource' => ['id' => 'I-8TG2LLTDSLSW', 'create_time' => '2026-10-14T10:37:00Z', 'subscriber' => ['email_address' => 'PRIVATE_SENTINEL']]]);
check($diag->rest_before(null, null, $request) === null, 'REST dispatch unchanged');
$received = last_entry();
check($received['phase'] === 'webhook_received' && !$received['signature_checked'], 'Receipt before signature validation');
check($received['event']['resource_create_time'] !== '', 'Resource create time identifies skipped API branch');
pmpro_paypal_handle_subscription_activated($url, $empty);
$api = last_entry();
check($api['context'] === 'subscription_activation', 'Identify gateway activation request');
check($api['response']['http_status'] === 200 && $api['response']['count'] === 0, 'Empty API result recorded');
check($api['event']['correlation_id'] === $received['event']['correlation_id'], 'Correlate request and webhook');
check(isset($api['started_unix_ms'], $api['unix_ms']) && $api['duration_ms'] >= 0, 'Request start/end available');
$diag->webhook_processed('BILLING.SUBSCRIPTION.ACTIVATED', [], 'Order #56 completed successfully. PRIVATE_SENTINEL');
$processed = last_entry();
check($processed['order_id'] === 56 && $processed['result'] === 'completed', 'Completion classified');
check($processed['order_status'] === 'success' && $processed['payment_id_present'] === false, 'Completed order without payment reference recorded');
check($processed['signature_verified'] === true, 'Verified handler completion recorded');
$rest = new RestResponse(200);
check($diag->rest_after($rest, null, $request) === $rest, 'REST response unchanged');
check(last_entry()['http_status'] === 200, 'Webhook HTTP acknowledgment recorded');
(new PMPro_FIC_Automation())->process($url, $full);
$api = last_entry();
check($api['context'] === 'queue_recovery' && $api['event'] === [], 'Separate queue request from previous webhook');
check($api['response']['completed_count'] === 1 && $api['response']['transactions'][0]['id'] === '844753021M0826123', 'Later incasso recorded');
check($api['response']['transactions'][0]['amount'] === '841.80', 'Gross amount recorded');
check(count($wpdb->queries) === 1 && $wpdb->queries[0][1] === 56 && strpos($wpdb->queries[0][0], 'SELECT *') === false, 'Prepared read of exactly three order fields, no domain constructor');
(new PMPro_FIC_Automation())->on_updated_order($url, $full);
check(last_entry()['context'] === 'queue_order_hook', 'Hook-induced gateway sync is not a recovery worker');
exchange($url, response(['links' => [], 'PRIVATE_SENTINEL' => 'PRIVATE_SENTINEL']));
check(last_entry()['response']['transactions_type'] === 'missing' && last_entry()['response']['known_keys'] === ['links'], 'Absent field distinguished; unknown key names never stored');
exchange($url, response(['transactions' => null]));
check(last_entry()['response']['transactions_type'] === 'null', 'Null transactions distinguished from empty array');
(new PMPro_FIC_PayPal_Recovery())->read($url, $full); check(last_entry()['context'] === 'manual_recovery', 'Manual recovery distinguished');
(new PMProGateway_paypal())->check_token_order($url, $full); check(last_entry()['context'] === 'checkout_poll', 'Browser polling distinguished');
foreach ([401, 403, 429, 500] as $code) {
    exchange($url, response(['name' => 'AUTHENTICATION_FAILURE', 'message' => 'PRIVATE_SENTINEL'], $code));
    check(last_entry()['response']['http_status'] === $code, 'HTTP error recorded');
}
$error = new WP_Error('http_request_failed', 'Credentials PRIVATE_SENTINEL connection failed');
exchange($url, $error); check(last_entry()['response']['transport_error'] === 'http_request_failed', 'Network error recorded without raw message');
exchange($url, response('PRIVATE_SENTINEL')); check(!last_entry()['response']['json_readable'], 'Invalid JSON recorded');
exchange($url, $full, ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer PRIVATE_SENTINEL']], $empty);
$api = last_entry(); check($api['transport_response']['count'] === 1 && $api['response']['count'] === 0, 'Detect response filters');
exchange('https://api-m.sandbox.paypal.com/v1/oauth2/token', response(['access_token' => 'PRIVATE_SENTINEL', 'client_secret' => 'PRIVATE_SENTINEL']), ['method' => 'POST', 'body' => 'PRIVATE_SENTINEL']);
check(last_entry()['response']['token_present'] === true, 'Authentication outcome without token');
exchange('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature', response(['verification_status' => 'FAILURE', 'message' => 'PRIVATE_SENTINEL']), ['method' => 'POST']);
check(last_entry()['response']['verification_status'] === 'FAILURE', 'Signature verification outcome');
exchange('https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-8TG2LLTDSLSW', response(['id' => 'I-8TG2LLTDSLSW', 'status' => 'ACTIVE', 'create_time' => '2026-10-14T10:37:00Z', 'subscriber' => 'PRIVATE_SENTINEL']));
check(last_entry()['response']['status'] === 'ACTIVE', 'Subscription state recorded');
$before = filesize(logfile());
foreach ([str_replace('sandbox.', '', $url), str_replace('paypal.com', 'paypal.com.evil.test', $url), str_replace('https:', 'http:', $url),
    str_replace('https://', 'https://user:PRIVATE_SENTINEL@', $url), str_replace('/transactions?', '/cancel?', $url), 'https://api-v2.fattureincloud.it/c/1/issued_documents'] as $ignored) {
    exchange($ignored, $empty); clearstatcache(); check(filesize(logfile()) === $before, 'Unrelated/live/unsafe URL not logged');
}
exchange($url, $empty, ['method' => 'POST']); clearstatcache(); check(filesize(logfile()) === $before, 'Subscription writes not logged');
$diag->request_args(['method' => 'GET'], $url);
check($diag->pre_request($empty, ['method' => 'GET'], $url) === $empty, 'Short-circuit response unchanged');
check(last_entry()['source'] === 'pre_http_request', 'Short-circuit visible');
check(!str_contains(file_get_contents(logfile()), 'PRIVATE_SENTINEL'), 'No credentials, customer data, raw URL, request/response bodies or arbitrary errors written');
$options[PMPro_FIC_Diagnostics::OPTION]['until'] = time() - 1;
clearstatcache(); $before = filesize(logfile()); exchange($url, $full); clearstatcache(); check(filesize(logfile()) === $before, 'Automatic expiry stops logging');
check(!is_wp_error($diag->dispatch('diag_enable')), 'Can enable a new session');
check(!is_wp_error($diag->dispatch('diag_disable')), 'Explicit disable');
clearstatcache(); $before = filesize(logfile()); exchange($url, $full); clearstatcache(); check(filesize(logfile()) === $before, 'Disable effective');
ob_start(); $diag->render(['message' => 'Done'], 'diag_disable'); $html = ob_get_clean();
check(str_contains($html, '#fic-diagnostics') && str_contains($html, 'Scarica registro diagnostico PayPal'), 'Panel anchor and restricted download survive disable');
check(!str_contains($html, $log_dir), 'No filesystem path in UI');
$options[PMPro_FIC_Diagnostics::OPTION] = ['until' => time() + 3600];
$lock = fopen(logfile(), 'r+b'); flock($lock, LOCK_EX);
$started = microtime(true); exchange($url, $full); check(microtime(true) - $started < 1, 'Occupied log does not block checkout');
flock($lock, LOCK_UN); fclose($lock);
$cap = fopen(logfile(), 'r+b'); ftruncate($cap, PMPro_FIC_Diagnostics::LIMIT); fclose($cap);
exchange($url, $full); clearstatcache(); check(filesize(logfile()) === PMPro_FIC_Diagnostics::LIMIT, 'File size cap enforced');
check(is_wp_error($diag->dispatch('diag_enable')) && $options[PMPro_FIC_Diagnostics::OPTION]['until'] === 0, 'Full log cannot silently enable');
echo "PASS: $checks checks. Passive diagnostics, privacy, timing, errors, expiry and real file locks; no remote operations.\n";
