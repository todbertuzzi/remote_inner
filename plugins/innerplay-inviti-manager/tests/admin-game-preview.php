<?php
/**
 * Run: php plugins/innerplay-inviti-manager/tests/admin-game-preview.php
 * Real preview helper, access policy, REST handler and template with WP/DB doubles.
 */
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
date_default_timezone_set('UTC');

class WP_User {
    public $user_login;
    public $display_name;
    public function __construct(public $ID, public $user_email) {
        $this->user_login = 'user' . $ID;
        $this->display_name = 'User ' . $ID;
    }
}
class WP_Error {
    public function __construct(public $code, public $message, public $data = []) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
    public function __construct(private $params) {}
    public function get_param($key) { return $this->params[$key] ?? null; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status) {}
}
class PreviewTestDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows = [];
    public $failInsert = false;
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_row($prepared) {
        [$sql, $args] = $prepared;
        foreach (array_reverse($this->rows) as $row) {
            if (str_contains($sql, 'WHERE invito_uuid')) {
                if ($row->invito_uuid === $args[0]) return clone $row;
            } elseif ($row->gioco_id === $args[0] && $row->host_user_id === $args[1]
                && $row->invited_user_id === $args[2] && $row->status === $args[3]
                && $row->expires_at > $args[4]) {
                return clone $row;
            }
        }
        return null;
    }
    public function insert($table, $data, $formats) {
        if ($this->failInsert) return false;
        if ($table !== 'wp_game_sessions') throw new RuntimeException('Preview must not create invitations');
        $data['id'] = ++$this->insert_id;
        $this->rows[$data['id']] = (object) $data;
        return 1;
    }
    public function update(...$args) { throw new RuntimeException('Preview must not bind another user'); }
}
function add_action(...$args) {}
function add_filter(...$args) {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function user_can($user, $cap) { return in_array(is_object($user) ? $user->ID : $user, $GLOBALS['admins'], true); }
function is_user_logged_in() { return $GLOBALS['currentUser']->ID > 0; }
function wp_get_current_user() { return $GLOBALS['currentUser']; }
function get_post_type($id) { return isset($GLOBALS['gameTiers'][$id]) ? 'gioco' : 'page'; }
function get_post_status($id) { return $GLOBALS['postStatuses'][$id] ?? 'publish'; }
function wp_get_object_terms($id, $taxonomy, $args) { return $GLOBALS['gameTiers'][$id] ?? []; }
function apply_filters($hook, $value) { return $value; }
function pmpro_getMembershipLevelsForUser($id, $force) {
    return array_map(fn($level) => (object) ['id' => $level], $GLOBALS['memberships'][$id] ?? []);
}
function home_url($path) { return 'https://example.test' . $path; }

function get_the_ID() { return $GLOBALS['gameId']; }
function current_time($type) { return $type === 'timestamp' ? $GLOBALS['now'] : date('Y-m-d H:i:s', $GLOBALS['now']); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return $value; }
function wp_generate_uuid4() { static $n = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$n); }
function wp_mail(...$args) { throw new RuntimeException('Preview must not send emails'); }
function wp_create_nonce($action) { return 'rest-nonce'; }
function get_field($key, $id) {
    return ['titolo_gioco' => 'Carosello verticale', 'descrizione_gioco' => 'Descrizione', 'unity_build_url' => 'https://example.test/game/index.html'][$key] ?? '';
}
function get_the_title() { return 'Gioco'; }
function get_permalink($id) { return 'https://example.test/giochi/' . $id; }
function get_header() {}
function get_footer() {}
function status_header($code) { $GLOBALS['httpStatus'] = $code; }
function nocache_headers() { $GLOBALS['noCache'] = true; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_js($text) { return addslashes($text); }
function esc_url($url) { return $url; }
function wp_login_url($url) { return 'https://example.test/login'; }
function wp_redirect($url) { $GLOBALS['redirect'] = $url; $GLOBALS['httpStatus'] = 302; }
function wp_safe_redirect($url) { wp_redirect($url); }
function add_query_arg(...$args) { return 'https://example.test/redirect'; }

$root = dirname(__DIR__, 3);
require $root . '/plugins/innerplay-access-control/innerplay-access-control.php';
require $root . '/plugins/innerplay-api-giochi/innerplay-api-giochi.php';
// Load the actual functions without running unrelated plugin installation hooks.
$source = file_get_contents(dirname(__DIR__) . '/innerplay-inviti-manager.php');
foreach (['gim_get_game_preview_session', 'gim_get_admin_game_preview_session', 'gim_game_bind_invited_user'] as $name) {
    if (!preg_match('/function ' . $name . '\(.*?^\}/ms', $source, $match)) throw new RuntimeException('Missing function: ' . $name);
    eval($match[0]);
}
$wpdb = new PreviewTestDB();
$admins = [1, 2];
$owner = new WP_User(1, 'owner@example.test');
$otherAdmin = new WP_User(2, 'other@example.test');
$member = new WP_User(3, 'member@example.test');
$welcome = new WP_User(4, 'welcome@example.test');
$professional = new WP_User(5, 'professional@example.test');
$gold = new WP_User(6, 'gold@example.test');
$otherGold = new WP_User(7, 'othergold@example.test');
$memberships = [4 => [3], 5 => [4], 6 => [5], 7 => [5]];
$gameTiers = [12 => ['professional'], 13 => ['gold'], 14 => ['welcome'], 15 => [], 16 => ['welcome', 'gold'], 17 => ['welcome']];
$postStatuses = [17 => 'draft'];
$currentUser = $owner;
$now = strtotime('2026-09-14 12:00:00');
$gameId = 12;
$httpStatus = 200;
$noCache = false;

if (isset($argv[1])) {
    $_GET = [];
    if ($argv[1] === 'member') $currentUser = $member;
    if ($argv[1] === 'anonymous') $currentUser = new WP_User(0, '');
    if ($argv[1] === 'invalid-invite') $_GET['invito_uuid'] = 'unknown';
    if ($argv[1] === 'db-error') $wpdb->failInsert = true;
    if ($argv[1] === 'welcome') { $currentUser = $welcome; $gameId = 14; }
    if ($argv[1] === 'professional') $currentUser = $professional;
    if ($argv[1] === 'gold') { $currentUser = $gold; $gameId = 13; }
    if ($argv[1] === 'welcome-denied') $currentUser = $welcome;
    if ($argv[1] === 'professional-denied') { $currentUser = $professional; $gameId = 13; }
    if ($argv[1] === 'unclassified') { $currentUser = $gold; $gameId = 15; }
    if ($argv[1] === 'draft') { $currentUser = $gold; $gameId = 17; }
    if (in_array($argv[1], ['shared-preview', 'downgraded-preview', 'own-preview'], true)) {
        $session = gim_get_game_preview_session(13, $gold);
        $_GET['invito_uuid'] = $session->invito_uuid;
        $gameId = 13;
        $currentUser = $argv[1] === 'shared-preview' ? $otherGold : $gold;
        if ($argv[1] === 'downgraded-preview') $memberships[6] = [4];
    }
    ob_start();
    register_shutdown_function(function () {
        $html = ob_get_clean();
        echo json_encode(['status' => $GLOBALS['httpStatus'], 'html' => $html, 'no_cache' => $GLOBALS['noCache'], 'sessions' => count($GLOBALS['wpdb']->rows)]);
    });
    require $root . '/themes/hello-theme-child-master/single-gioco.php';
    exit;
}

$checks = 0;
function check($condition, $label) {
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    $GLOBALS['checks']++;
}
function denied($result, $code) { check(is_wp_error($result) && $result->get_error_code() === $code, 'Expected ' . $code); }
function profile($user, $uuid) {
    $GLOBALS['currentUser'] = $user;
    return game_get_user_profile(new WP_REST_Request(['invito_uuid' => $uuid]));
}

$preview = gim_get_admin_game_preview_session(12, $owner);
check(!is_wp_error($preview) && $preview->status === 'admin_preview', 'Admin can create preview');
check($preview->invited_user_id === 1 && $preview->host_user_id === 1, 'Preview belongs to its admin');
check(strtotime($preview->expires_at) === $now + 3600, 'Preview expires in one hour');
check(gim_get_admin_game_preview_session(12, $owner)->id === $preview->id && count($wpdb->rows) === 1, 'Page reload reuses valid preview');
denied(gim_get_admin_game_preview_session(12, $member), 'preview_forbidden');
denied(gim_get_admin_game_preview_session(99, $owner), 'invalid_game');
check(count($wpdb->rows) === 1, 'Rejected requests create no sessions');
check(gim_get_admin_game_preview_session(13, $owner)->id !== $preview->id, 'Different games have separate previews');
check(gim_get_admin_game_preview_session(12, $otherAdmin)->id !== $preview->id, 'Different admins have separate previews');
check(ipt_validate_game_session_access($preview, $owner, 12) === true, 'Owner may play preview');
denied(ipt_validate_game_session_access($preview, $otherAdmin), 'preview_forbidden');
denied(ipt_validate_game_session_access($preview, $member), 'preview_forbidden');
denied(ipt_validate_game_session_access($preview, $owner, 13), 'wrong_game');
$response = profile($owner, $preview->invito_uuid);
check($response->status === 200 && $response->data['session']['gioco_id'] === 12 && $response->data['user']['id'] === 1, 'Unity user-profile accepts real preview UUID');
check(profile($member, $preview->invito_uuid)->status === 403, 'REST denies shared preview to member');
check(profile($otherAdmin, $preview->invito_uuid)->status === 403, 'REST denies another admin’s preview');
$admins = [2];
check(profile($owner, $preview->invito_uuid)->status === 403, 'REST checks admin capability after role removal');
$admins = [1, 2];
$now += 3601;
check(profile($owner, $preview->invito_uuid)->status === 410, 'REST refuses expired previews');
check(gim_get_admin_game_preview_session(12, $owner)->id !== $preview->id, 'Expired preview is replaced');
$revoked = clone $preview;
$revoked->status = 'revoked';
denied(ipt_validate_game_session_access($revoked, $owner), 'session_forbidden');
$normal = clone $preview;
$normal->status = 'created';
$normal->invited_user_id = 3;
$normal->invited_email = $member->user_email;
$normal->expires_at = date('Y-m-d H:i:s', $now + 3600);
check(ipt_validate_game_session_access($normal, $member) === true, 'Normal invitation still works for its recipient');
denied(ipt_validate_game_session_access($normal, $owner), 'session_forbidden');
$wpdb->failInsert = true;
denied(gim_get_admin_game_preview_session(13, $otherAdmin), 'preview_create_failed');

foreach (['admin' => 200, 'member' => 403, 'anonymous' => 302, 'invalid-invite' => 404, 'db-error' => 500,
    'welcome' => 200, 'professional' => 200, 'gold' => 200, 'welcome-denied' => 403,
    'professional-denied' => 403, 'unclassified' => 403, 'draft' => 403,
    'shared-preview' => 403, 'downgraded-preview' => 403, 'own-preview' => 200] as $case => $status) {
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case));
    $result = json_decode($output, true);
    check(is_array($result) && $result['status'] === $status, 'Template status: ' . $case);
    if ($status === 200) {
        $banner = $case === 'admin' ? 'Anteprima amministratore' : 'Anteprima del gioco';
        check(str_contains($result['html'], $banner) && str_contains($result['html'], 'unityGameFrame'), 'Admin gets preview banner and Unity frame');
        check(str_contains($result['html'], 'const INVITO_UUID = "00000000-'), 'Preview UUID is passed to Unity handshake');
        check($result['no_cache'] && $result['sessions'] === 1, 'Private preview is not cached');
    } else {
        check(!str_contains($result['html'], 'unityGameFrame') && $result['sessions'] === (in_array($case, ['shared-preview', 'downgraded-preview'], true) ? 1 : 0), 'Rejected page creates no preview: ' . $case);
    }
}
// Membership matrix through the real creation helper and Unity REST handler.
$wpdb->failInsert = false;
foreach ([$member, $welcome, $professional, $gold] as $user) {
    $allowed = [3 => [], 4 => [14], 5 => [12, 14], 6 => [12, 13, 14]][$user->ID];
    foreach (array_keys($gameTiers) as $id) {
        $before = count($wpdb->rows);
        $session = gim_get_game_preview_session($id, $user);
        if (!in_array($id, $allowed, true)) {
            denied($session, 'preview_forbidden');
            check(count($wpdb->rows) === $before, 'Denied game creates no session');
            continue;
        }
        check(!is_wp_error($session) && $session->status === 'member_preview', 'Eligible member can preview');
        check($session->host_user_id === $user->ID && $session->invited_user_id === $user->ID, 'Member owns private session');
        check(profile($user, $session->invito_uuid)->status === 200, 'Unity accepts member preview');
        check(gim_get_game_preview_session($id, $user)->id === $session->id, 'Member preview reused on reload');
        check(profile($otherGold, $session->invito_uuid)->status === 403, 'Even Gold cannot use another member preview');
        check(profile($owner, $session->invito_uuid)->status === 403, 'Admin cannot use another member preview');
    }
}
$session = gim_get_game_preview_session(13, $gold);
$memberships[6] = [4];
denied(gim_get_game_preview_session(13, $gold), 'preview_forbidden');
check(profile($gold, $session->invito_uuid)->status === 403, 'Downgrade invalidates existing Gold preview at API');
$memberships[6] = [];
check(profile($gold, $session->invito_uuid)->status === 403, 'Cancelled membership invalidates existing preview');
$memberships[6] = [999];
denied(gim_get_game_preview_session(14, $gold), 'preview_forbidden');
$memberships[6] = [3, 5];
check(profile($gold, $session->invito_uuid)->status === 200, 'Highest active membership restores access');
$postStatuses[13] = 'draft';
check(profile($gold, $session->invito_uuid)->status === 403, 'Unpublished game invalidates preview');
$postStatuses[13] = 'publish';
$gameTiers[13] = ['gold', 'welcome'];
check(profile($gold, $session->invito_uuid)->status === 403, 'Ambiguous categories invalidate preview');
$gameTiers[13] = ['gold'];
$now += 3601;
check(profile($gold, $session->invito_uuid)->status === 410, 'Member preview expires');
check(gim_get_game_preview_session(13, $gold)->id !== $session->id, 'Expired member preview is replaced');
denied(gim_get_game_preview_session(14, new WP_User(0, '')), 'auth_required');
check(profile(new WP_User(0, ''), $session->invito_uuid)->status === 401, 'Anonymous API access denied');
$session = gim_get_game_preview_session(14, $welcome);
$session->host_user_id = $gold->ID;
denied(ipt_validate_game_session_access($session, $welcome), 'preview_forbidden');
$normal->expires_at = date('Y-m-d H:i:s', $now + 3600);
check(ipt_validate_game_session_access($normal, $member) === true, 'Invite recipient without a plan remains authorized');
$normal->status = 'revoked';
denied(ipt_validate_game_session_access($normal, $member), 'session_forbidden');
$normal->status = 'created';
$normal->expires_at = date('Y-m-d H:i:s', $now - 1);
denied(ipt_validate_game_session_access($normal, $member), 'session_expired');
echo "OK: {$checks} game preview checks passed.\n";
