<?php
/**
 * Regression test for the real API handlers, with WordPress/database doubles.
 * Run: php plugins/scrivania-collaborativa-api/tests/editor-permissions.php
 */
define('ARRAY_A', 'ARRAY_A');

class WP_Error {
    public function __construct(public $code, public $message, public $data) {}
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function esc_url_raw($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function current_time($type) { return '2026-09-13 12:00:00'; }
function get_current_user_id() { return $GLOBALS['test_user_id']; }
function get_userdata($id) { return (object) ['user_email' => 'guest@example.test', 'display_name' => 'Guest']; }

class ScrivaniaTestRequest extends ArrayObject {
    public function __construct(private array $params) { parent::__construct($params); }
    public function get_params() { return $this->params; }
    public function get_json_params() { return $this->params; }
}

class ScrivaniaTestDatabase {
    public $prefix = 'wp_';
    public $session;
    public $invite;
    public $writes = 0;
    public function prepare($query, ...$params) { return $query; }
    public function get_col($query, $column) { return ['invitato_user_id', 'role', 'status', 'revoked_at', 'verified_at']; }
    public function get_row($query, $output = null) {
        if (str_contains($query, 'scrivania_invitati')) return $this->invite;
        return $output === ARRAY_A ? (array) $this->session : $this->session;
    }
    public function update($table, $data, ...$args) {
        $this->writes++;
        foreach ($data as $key => $value) $this->session->$key = $value;
        return 1;
    }
}

// Exercise the production membership policy, not a mock of its result.
$test_tiers = [1 => 'professional', 2 => 'welcome'];
$test_admins = [];
function user_can($id, $capability) { return in_array($id, $GLOBALS['test_admins'], true); }
function ipt_get_user_access_tier($id) { return $GLOBALS['test_tiers'][$id] ?? ''; }
function add_action(...$args) {}
function is_user_logged_in() { return get_current_user_id() > 0; }
function check_ajax_referer(...$args) { return true; }
function status_header($code) { $GLOBALS['test_http_status'] = $code; }
function wp_die() { throw new AjaxDenied('', $GLOBALS['test_http_status'] ?? 200); }

function scrivania_get_deck($id) { return ['id' => intval($id)]; }
class AjaxDenied extends RuntimeException {}
function wp_send_json_error($data, $status = null) { throw new AjaxDenied($data['message'] ?? '', $status ?? 400); }
$policy = file_get_contents(dirname(__DIR__) . '/scrivania-collaborativa-api.php');
if (!preg_match('/function scrivania_user_can_create_session\(.*?^\}/ms', $policy, $match)) throw new RuntimeException('Missing membership policy');
eval($match[0]);
$invitesSource = file_get_contents(dirname(__DIR__, 2) . '/innerplay-inviti-manager/innerplay-inviti-manager.php');
if (!preg_match('/function gim_attiva_scrivania\(.*?^\}/ms', $invitesSource, $match)) throw new RuntimeException('Missing legacy invitation handler');
eval($match[0]);

require dirname(__DIR__) . '/includes/class-scrivania-ajax.php';
require dirname(__DIR__) . '/includes/class-scrivania-api.php';
$api = (new ReflectionClass(Scrivania_Collaborativa_API::class))->newInstanceWithoutConstructor();
$wpdb = new ScrivaniaTestDatabase();
$test_user_id = 2;
$existing = ['id' => 'c1-100', 'templateId' => 'c1', 'mazzoId' => 1, 'x' => 100];
$added = ['id' => 'c2-200', 'templateId' => 'c2', 'mazzoId' => 1];
$checks = 0;

function check($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
function reset_session($role = 'editor', $cards = null) {
    global $wpdb, $test_user_id, $existing;
    $test_user_id = $role === 'admin' ? 1 : 2;
    $wpdb->session = (object) [
        'id' => 7, 'creatore_id' => 1, 'state_version' => 1,
        'impostazioni' => json_encode(['mazzoId' => 1]),
        'carte' => json_encode($cards ?? [$existing]),
    ];
    $wpdb->invite = ['role' => $role, 'status' => 'consumed', 'verified_at' => '2026-09-13'];
    $wpdb->writes = 0;
}
function save_cards($cards, $extra = []) {
    global $api;
    return $api->save_session_data(new ScrivaniaTestRequest(array_merge([
        'session_id' => 7, 'base_version' => 1, 'snapshot' => ['carte' => $cards],
    ], $extra)));
}
function rejected($result, $code) {
    check(is_wp_error($result) && $result->code === $code, 'Expected rejection: ' . $code);
    check($GLOBALS['wpdb']->writes === 0, 'Rejected request must not write');
}

foreach (['admin', 'editor', 'viewer'] as $role) {
    reset_session($role);
    $session = $api->get_session_data(new ScrivaniaTestRequest(['token' => 'session-token']));
    check($session['permissions']['canSpawn'] === ($role !== 'viewer'), "$role spawn permission");
    check($session['permissions']['canRemove'] === ($role !== 'viewer'), "$role removal permission");
    check($session['permissions']['canManageMembers'] === ($role === 'admin'), "$role member permission");
}

reset_session();
$result = save_cards([$existing, $added]);
check(!is_wp_error($result) && $result['state_version'] === 2, 'Editor can add and advance version');
check(count(json_decode($wpdb->session->carte, true)) === 2, 'New card persisted');

reset_session('editor', []);
check(!is_wp_error(save_cards([$added])), 'Editor can add the first card');

reset_session();
$moved = array_merge($existing, ['x' => 300, 'angle' => 90, 'scale' => 2, 'isFront' => true]);
check(!is_wp_error(save_cards([$added, $moved])), 'Editor can add, reorder and manipulate existing cards');
$saved = json_decode($wpdb->session->carte, true)[1];
check($saved['x'] === 300 && $saved['angle'] === 90 && $saved['scale'] === 2 && $saved['isFront'], 'Manipulations persisted');

reset_session();
check(!is_wp_error(save_cards([])), 'Editor can remove the last card');
check(json_decode($wpdb->session->carte, true) === [], 'Removal persisted');
reset_session();
check(!is_wp_error(save_cards([$added])), 'Editor can remove and add in one snapshot');
reset_session();
check(!is_wp_error(save_cards([array_merge($existing, ['templateId' => 'c2'])])), 'Editor can replace cards');
reset_session('viewer');
rejected(save_cards([$existing, $added]), 'not_authorized');
reset_session('viewer');
rejected(save_cards([]), 'not_authorized');
reset_session();
$wpdb->invite['revoked_at'] = '2026-09-13';
rejected(save_cards([]), 'not_authorized');
reset_session();
$wpdb->invite['revoked_at'] = '2026-09-13';
rejected(save_cards([$existing, $added]), 'not_authorized');
reset_session();
$wpdb->invite = null;
rejected(save_cards([$existing, $added]), 'not_authorized');
reset_session();
rejected(save_cards([$existing, $added], ['sessione' => ['mazzoId' => 0]]), 'deck_immutable');
reset_session();
rejected(save_cards([$existing, $added], ['base_version' => 0]), 'version_conflict');
reset_session();
rejected(save_cards([$existing, $existing]), 'invalid_card_identity');
reset_session('admin');
check(!is_wp_error(save_cards([])), 'Creator can still remove cards');

foreach (['welcome' => false, 'professional' => true, 'gold' => true, '' => false, 'unknown' => false] as $tier => $allowed) {
    $test_tiers[1] = $tier;
    check(scrivania_user_can_create_session(1) === $allowed, 'Creation policy: ' . $tier);
    reset_session('admin');
    $data = $api->get_session_data(new ScrivaniaTestRequest(['token' => 'session-token']));
    check(is_wp_error($data) !== $allowed, 'Existing owner session: ' . $tier);
    $snapshot = $api->get_snapshot(new ScrivaniaTestRequest(['session_id' => 7]));
    check(is_wp_error($snapshot) !== $allowed, 'Existing owner snapshot: ' . $tier);
    if ($allowed) {
        check(!is_wp_error(save_cards([])), 'Eligible owner can save: ' . $tier);
    } else {
        rejected(save_cards([]), 'membership_required');
        rejected($api->create_session(new ScrivaniaTestRequest(['mazzo_id' => 1])), 'membership_required');
        rejected(Scrivania_Ajax::create_session_for_user(1, 1), 'membership_required');
        rejected($api->update_member_role(new ScrivaniaTestRequest(['session_id' => 7, 'user_id' => 2, 'role' => 'editor'])), 'not_authorized');
        rejected($api->list_session_members(new ScrivaniaTestRequest(['session_id' => 7])), 'not_authorized');
        rejected($api->authenticate_pusher_channel(new ScrivaniaTestRequest(['channel_name' => 'presence-scrivania-7', 'socket_id' => '1.2'])), 'not_authorized');
        foreach ([['Scrivania_Ajax', 'attiva_scrivania'], 'gim_attiva_scrivania'] as $handler) {
            $test_http_status = 200;
            ob_start();
            try {
                call_user_func($handler);
                check(false, 'Creation/invitation handler must deny excluded owner');
            } catch (AjaxDenied $error) {
                check($error->getCode() === 403, 'Creation/invitation handler returns 403');
                check($wpdb->writes === 0, 'Rejected invitation creates no data');
            } finally {
                ob_end_clean();
            }
        }
        foreach (['dashboard_get_invites', 'dashboard_update_invite_role', 'dashboard_revoke_invite', 'dashboard_resend_invite'] as $action) {
            try {
                Scrivania_Ajax::$action();
                check(false, 'Dashboard action must be denied: ' . $action);
            } catch (AjaxDenied $error) {
                check($error->getCode() === 403, 'Dashboard AJAX denies excluded owner: ' . $action);
                check($wpdb->writes === 0, 'Denied AJAX request has no writes');
            }
        }
    }
}
$test_admins = [1];
check(scrivania_user_can_create_session(1), 'Site administrator may create without membership');
reset_session('admin');
check(!is_wp_error(save_cards([])), 'Site administrator may use own session');
$test_admins = [];
$test_user_id = 0;
check(!scrivania_user_can_create_session(), 'Anonymous user cannot create');
$test_tiers[1] = 'professional';
foreach (['welcome', ''] as $tier) {
    $test_tiers[2] = $tier;
    reset_session('editor');
    check(!is_wp_error(save_cards([])), 'Invited editor may participate without a paid plan');
    check(!is_wp_error($api->get_snapshot(new ScrivaniaTestRequest(['session_id' => 7]))), 'Invited user may read snapshots');
}
echo "PASS: $checks checks (membership, existing sessions, AJAX, REST, roles, persistence and revocation).\n";
