<?php
/** Run: php plugins/innerplay-inviti-manager/tests/invite-registration.php */
define('ABSPATH', __DIR__ . '/');
define('GIM_PLUGIN_FILE', dirname(__DIR__) . '/innerplay-inviti-manager.php');
date_default_timezone_set('UTC');
class WP_Error {
    public function __construct(public $code = '', public $message = '', public $data = []) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_User {
    public function __construct(public $ID, public $user_email, public $roles = ['invitato'], public $user_login = 'guest') {}
    public function add_role($role) { $this->roles = array_values(array_unique([...$this->roles, $role])); }
    public function remove_role($role) { $this->roles = array_values(array_diff($this->roles, [$role])); }
}
class InviteDB {
    public $prefix = 'wp_';
    public $game;
    public $desk;
    public $session;
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_row($query) {
        [$sql, $args] = $query;
        if (str_contains($sql, 'scrivania_sessioni')) return $args[0] === 9 ? $this->session : null;
        if (str_contains($sql, 'game_sessions')) return $args[0] === 'game-token' ? clone $this->game : null;
        return $args[0] === 'desk-token' ? clone $this->desk : null;
    }
    public function get_col(...$args) { return ['id', 'invitato_user_id', 'consumed_at', 'claimed_at', 'status', 'verified_at']; }
    public function query($query) { $GLOBALS['writes'][] = $query; return 1; }
    public function update($table, $data, ...$args) { $GLOBALS['writes'][] = [$table, $data]; return 1; }
}
function add_action(...$args) {}
function add_filter(...$args) {}
function do_action(...$args) { $GLOBALS['actions'][] = $args; }
function get_role($role) { return $GLOBALS['roles'][$role] ?? null; }
function add_role($role, $label, $caps) { $GLOBALS['roles'][$role] = $caps; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function get_user_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function pmpro_getMembershipLevelsForUser($id, $include_inactive = false) {
    if ($include_inactive) throw new RuntimeException('Inactive plans must never count');
    return $GLOBALS['levels'][$id] ?? [];
}
function is_user_logged_in() { return $GLOBALS['current'] > 0; }
function wp_get_current_user() { return get_userdata($GLOBALS['current']); }
function user_can($user, $cap) { return in_array('administrator', $user->roles, true); }
function wp_unslash($value) { return stripslashes($value); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function validate_username($value) { return preg_match('/^[a-zA-Z0-9_.-]+$/', $value); }
function username_exists($name) { foreach ($GLOBALS['users'] as $user) if ($user->user_login === $name) return $user->ID; return false; }
function email_exists($email) { foreach ($GLOBALS['users'] as $user) if (strcasecmp($user->user_email, $email) === 0) return $user->ID; return false; }
function wp_insert_user($data) {
    $GLOBALS['insertions'][] = $data;
    $id = count($GLOBALS['users']) + 100;
    $GLOBALS['users'][$id] = new WP_User($id, $data['user_email'], [$data['role']], $data['user_login']);
    $GLOBALS['meta'][$id] = $data['meta_input'];
    return $id;
}
function wp_verify_nonce($value, $action) { return hash_equals(hash('sha256', $action), $value); }
function wp_nonce_field($action, $name = '_wpnonce') { echo '<input type="hidden" name="' . $name . '" value="' . hash('sha256', $action) . '">'; }
function current_time($format) { return $format === 'timestamp' ? 1800000000 : date('Y-m-d H:i:s', 1800000000); }
function get_post_type($id) { return $id === 12 ? 'gioco' : 'page'; }
function get_post_status($id) { return $GLOBALS['postStatus']; }
function home_url($path) { return 'https://example.test' . $path; }
function site_url($path) { return home_url($path); }
function is_ssl() { return true; }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wp_date($format, $timestamp, $timezone) { return date($format, $timestamp); }
function get_permalink($id) { return home_url('/giochi/' . $id . '/'); }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function wp_validate_redirect($url, $fallback) { return $url; } // Test our own host restriction too.
function is_page($slug) { return $GLOBALS['page'] === $slug; }
function add_query_arg($key, $value = null, $url = null) {
    if ($key === null) return home_url('/invito-scrivania/?token=desk-token');
    if (is_array($key)) { $url = $value; $query = $key; } else { $query = [$key => $value]; }
    return $url . '?' . http_build_query($query);
}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function wp_lostpassword_url($url) { return home_url('/lostpassword/'); }
function wp_logout_url($url) { return home_url('/logout/'); }
function wp_login_form($args) { echo '<form class="login" data-redirect="' . esc_attr($args['redirect']) . '">Accedi</form>'; }
function plugins_url($path, $file) { return home_url('/plugin/' . $path); }
function wp_enqueue_style(...$args) {}
function nocache_headers() { $GLOBALS['nocache'] = true; }
function status_header($status) { $GLOBALS['status'] = $status; }
function get_header() { $GLOBALS['headers']++; echo '<header>Header</header>'; }
function get_footer() { $GLOBALS['footers']++; echo '<footer>Footer</footer>'; }
function wp_set_current_user($id) { $GLOBALS['current'] = $id; }
function wp_set_auth_cookie($id) {
    if ($GLOBALS['headers']) throw new RuntimeException('Cookie set after header output');
    $GLOBALS['cookie'] = $id;
}
function wp_safe_redirect($url) { wp_redirect($url); }
function wp_redirect($url) {
    if ($GLOBALS['headers']) throw new RuntimeException('Redirect after header output');
    $GLOBALS['redirect'] = $url;
}
function wp_mail(...$args) { throw new RuntimeException('Unexpected email'); }
require dirname(__DIR__) . '/includes/invite-registration.php';
$root = dirname(__DIR__, 3);
require $root . '/plugins/innerplay-access-control/innerplay-access-control.php';
$source = file_get_contents(dirname(__DIR__) . '/innerplay-inviti-manager.php');
preg_match('/function gim_game_bind_invited_user\(.*?^\}/ms', $source, $match);
eval($match[0]);
function reset_fixture() {
    foreach (['users', 'roles', 'meta', 'levels', 'insertions', 'writes', 'actions'] as $key) $GLOBALS[$key] = [];
    foreach (['current', 'headers', 'footers', 'cookie'] as $key) $GLOBALS[$key] = 0;
    $GLOBALS['status'] = 200; $GLOBALS['redirect'] = ''; $GLOBALS['nocache'] = false;
    $GLOBALS['postStatus'] = 'publish'; $GLOBALS['page'] = 'login';
    $GLOBALS['wpdb'] = new InviteDB();
    $GLOBALS['wpdb']->game = (object) ['id' => 1, 'gioco_id' => 12, 'invited_email' => 'guest@example.test', 'invited_user_id' => 0, 'status' => 'pending', 'expires_at' => date('Y-m-d H:i:s', 1800003600)];
    $GLOBALS['wpdb']->desk = (object) ['id' => 2, 'sessione_id' => 9, 'invitato_email' => 'guest@example.test', 'invitato_user_id' => 0, 'status' => 'verified', 'verified_at' => '2026-09-16 12:00:00'];
    $GLOBALS['wpdb']->session = (object) ['token' => 'session-token', 'impostazioni' => '{}'];
    $_GET = []; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
}
function input_for($kind, $token) {
    return ['gim_invite_nonce' => hash('sha256', gim_invite_registration_nonce_action($kind, $token)), 'user_login' => 'new-guest', 'user_pass' => addslashes('P@ss<word>\\"123')];
}
reset_fixture();
if (isset($argv[1])) {
    $scenario = $argv[1];
    $kind = str_starts_with($scenario, 'desk') ? 'scrivania' : 'game';
    $token = $kind === 'game' ? 'game-token' : 'desk-token';
    if (str_contains($scenario, 'existing') || str_contains($scenario, 'authorized') || str_contains($scenario, 'wrong-account')) $users[7] = new WP_User(7, str_contains($scenario, 'wrong-account') ? 'other@example.test' : 'guest@example.test', ['subscriber']);
    if (str_contains($scenario, 'authorized') || str_contains($scenario, 'wrong-account')) $current = 7;
    if (str_contains($scenario, 'submit')) { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = input_for($kind, $token) + ['gim_invite_register' => '1']; }
    if (str_contains($scenario, 'bad-nonce')) $_POST['gim_invite_nonce'] = 'wrong';
    if ($scenario === 'desk-unverified') $wpdb->desk->verified_at = null;
    if ($scenario === 'game-revoked') $wpdb->game->status = 'revoked';
    if ($scenario === 'desk-archived') $wpdb->session->impostazioni = '{"archived":true}';
    if ($scenario === 'desk-consumed') { $wpdb->desk->status = 'consumed'; $wpdb->desk->invitato_user_id = 7; }
    if ($scenario === 'desk-future-authorized') { $wpdb->desk->data_invito = '2099-01-01'; $wpdb->desk->ora_invito = '12:00'; }
    ob_start();
    register_shutdown_function(function () {
        $html = ob_get_clean();
        echo json_encode(['html' => $html, 'headers' => $GLOBALS['headers'], 'footers' => $GLOBALS['footers'], 'status' => $GLOBALS['status'], 'redirect' => $GLOBALS['redirect'], 'cookie' => $GLOBALS['cookie'], 'nocache' => $GLOBALS['nocache'], 'insertions' => $GLOBALS['insertions'], 'writes' => $GLOBALS['writes']]);
    });
    if (str_starts_with($scenario, 'legacy-')) {
        $_GET['redirect_to'] = match ($scenario) {
            'legacy-game' => '/gioca/?invito=game-token',
            'legacy-desk' => home_url('/invito-scrivania/?token=desk-token'),
            'legacy-external' => 'https://evil.test/gioca/?invito=game-token',
            'legacy-array' => '/gioca/?invito[]=game-token',
            default => '/dashboard-utente/',
        };
        gim_redirect_invite_login(); exit;
    }
    $_GET[$kind === 'game' ? 'invito' : 'token'] = $token;
    require $root . '/themes/hello-theme-child-master/' . ($kind === 'game' ? 'page-gioca.php' : 'page-invito-scrivania.php');
    exit;
}
$checks = 0;
function check($condition, $label) { if (!$condition) throw new RuntimeException('FAIL: ' . $label); $GLOBALS['checks']++; }
function denied($result, $code) { check(is_wp_error($result) && $result->get_error_code() === $code, 'Expected ' . $code); }
foreach (['game' => 'game-token', 'scrivania' => 'desk-token'] as $kind => $token) {
    reset_fixture();
    $input = input_for($kind, $token) + ['user_email' => 'attacker@example.test', 'role' => 'administrator', 'user_role' => 'administrator'];
    denied(gim_register_invited_user($kind, $token, []), 'invalid_nonce');
    denied(gim_register_invited_user($kind, $token, array_replace($input, ['gim_invite_nonce' => input_for($kind, 'another-token')['gim_invite_nonce']])), 'invalid_nonce');
    denied(gim_register_invited_user($kind, $token, array_replace($input, ['user_pass' => 'short'])), 'invalid_password');
    denied(gim_register_invited_user($kind, $token, array_replace($input, ['user_login' => ['bad']])), 'invalid_username');
    check(!$insertions, 'Invalid forms never create accounts');
    $id = gim_register_invited_user($kind, $token, $input);
    check(is_int($id) && $users[$id]->roles === ['invitato'], 'New account has only invited role');
    check($insertions[0]['user_email'] === 'guest@example.test', 'Recipient identity comes exclusively from invite');
    check($insertions[0]['user_pass'] === 'P@ss<word>\\"123', 'Password punctuation preserved');
    check($roles['invitato'] === ['read' => true] && !$levels, 'Minimum role, no membership created');
    denied(gim_register_invited_user($kind, $token, $input), 'account_exists');
    check(count($insertions) === 1, 'Existing account is never replaced');
    $current = $id;
    denied(gim_register_invited_user($kind, $token, $input), 'already_logged_in');
    check(gim_require_invited_account($kind, $token)['email'] === 'guest@example.test', 'Authenticated user continues without registration');
    check(ipt_validate_game_session_access($wpdb->game, $users[$id]) === true, 'Invited role can play without plan');
    check(gim_scrivania_invite_matches_user($wpdb->desk, $users[$id]), 'Invited role matches desk recipient');
    $levels[$id] = [(object) ['id' => 4]];
    gim_sync_invited_membership_role(4, $id);
    check($users[$id]->roles === ['subscriber'] && $users[$id]->ID === $id, 'Upgrade uses the same account');
    $levels[$id] = [];
    gim_sync_invited_membership_role(0, $id);
    check($users[$id]->roles === ['invitato'], 'Cancellation restores invited role');
}
reset_fixture();
foreach (['revoked', 'cancelled', 'canceled', 'admin_preview', 'member_preview'] as $status) {
    $wpdb->game->status = $status;
    denied(gim_register_invited_user('game', 'game-token', input_for('game', 'game-token')), 'invite_unavailable');
}
$wpdb->game->status = 'expired';
denied(gim_get_invite_registration_context('game', 'game-token'), 'invite_expired');
$wpdb->game->status = 'pending'; $wpdb->game->expires_at = '2020-01-01 00:00:00';
denied(gim_get_invite_registration_context('game', 'game-token'), 'invite_expired');
reset_fixture(); $postStatus = 'draft';
denied(gim_get_invite_registration_context('game', 'game-token'), 'game_unavailable');
reset_fixture(); $wpdb->game->invited_user_id = 99;
denied(gim_register_invited_user('game', 'game-token', input_for('game', 'game-token')), 'account_exists');
denied(gim_get_invite_registration_context('game', 'unknown'), 'invite_not_found');
denied(gim_get_invite_registration_context('game', []), 'invalid_invite');
reset_fixture(); $wpdb->desk->verified_at = null;
denied(gim_register_invited_user('scrivania', 'desk-token', input_for('scrivania', 'desk-token')), 'invite_not_verified');
$wpdb->session->impostazioni = '{"archived":true}';
denied(gim_get_invite_registration_context('scrivania', 'desk-token'), 'session_unavailable');
reset_fixture(); $wpdb->desk->invitato_user_id = 7;
check(!gim_scrivania_invite_matches_user($wpdb->desk, new WP_User(8, 'guest@example.test')), 'Bound ID prevents reuse by matching email');
check(gim_scrivania_invite_matches_user($wpdb->desk, new WP_User(7, 'changed@example.test')), 'Bound recipient retains access after email change');
$users[7] = new WP_User(7, 'guest@example.test', ['invitato', 'administrator']); $levels[7] = [(object) ['id' => 5]];
gim_sync_invited_membership_role(5, 7);
check(in_array('administrator', $users[7]->roles, true), 'Upgrade preserves administrative role');
$levels[7] = []; gim_sync_invited_membership_role(0, 7);
check(in_array('administrator', $users[7]->roles, true), 'Cancellation preserves administrative role');
$users[8] = new WP_User(8, 'regular@example.test', ['subscriber']);
gim_sync_invited_membership_role(0, 8);
check($users[8]->roles === ['subscriber'], 'Ordinary subscriber untouched');
function render($scenario) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario);
    exec($command, $lines, $exit);
    $result = json_decode(implode("\n", $lines), true);
    check($exit === 0 && is_array($result), 'Template completes: ' . $scenario);
    return $result;
}
foreach (['game', 'desk'] as $kind) {
    $result = render($kind . '-new');
    check(str_contains($result['html'], 'Crea account e continua') && str_contains($result['html'], 'Non serve scegliere un piano'), 'Invitation registration without plan');
    check($result['headers'] === 1 && $result['footers'] === 1 && $result['nocache'], 'Theme frame once, page uncached');
    $result = render($kind . '-existing');
    check(str_contains($result['html'], 'class="login"') && !str_contains($result['html'], 'gim_invite_register'), 'Existing accounts only log in');
    $result = render($kind . '-submit');
    check($result['cookie'] > 0 && !$result['headers'] && count($result['insertions']) === 1, 'Registration logs in before output');
    check(str_contains($result['redirect'], $kind === 'game' ? '/gioca/?invito=game-token' : '/invito-scrivania/?token=desk-token'), 'Registration returns to its invite');
    $result = render($kind . '-submit-bad-nonce');
    check($result['status'] === 403 && !$result['insertions'] && !$result['cookie'], 'POST rejects invalid nonce');
    $result = render($kind . '-authorized');
    check(str_contains($result['redirect'], $kind === 'game' ? '/giochi/12/?invito_uuid=game-token' : '/tool-scrivania/?token=session-token') && !$result['headers'], 'Invited account reaches game/tool');
    $result = render($kind . '-wrong-account');
    check(!$result['redirect'] && !$result['writes'], 'Wrong logged-in account cannot claim invitation');
}
$result = render('desk-unverified');
check(str_contains($result['html'], 'Confermo') && !str_contains($result['html'], 'gim_invite_register'), 'Desk confirmation precedes registration');
$result = render('desk-consumed');
check(str_contains($result['html'], 'class="login"') && !str_contains($result['html'], 'gim_invite_register'), 'Consumed desk invitation only offers login');
$result = render('desk-future-authorized');
check(!$result['redirect'] && !$result['writes'] && str_contains($result['html'], 'non ancora attivo'), 'Future desk cannot be claimed early');
foreach (['game-revoked', 'desk-archived'] as $scenario) {
    $result = render($scenario);
    check($result['status'] === 403 && !str_contains($result['html'], 'gim_invite_register'), 'Unavailable invite never offers registration');
}
foreach (['legacy-game', 'legacy-desk'] as $scenario) {
    $result = render($scenario);
    check(str_starts_with($result['redirect'], home_url('/')) && !$result['headers'], 'Saved login links return to invitation');
}
foreach (['legacy-external', 'legacy-array', 'legacy-ordinary'] as $scenario) {
    $result = render($scenario);
    check(!$result['redirect'], 'Invalid or ordinary login target left untouched');
}
echo "OK: {$checks} invite registration checks\n";
