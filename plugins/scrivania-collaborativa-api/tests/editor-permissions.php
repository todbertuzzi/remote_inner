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

class ScrivaniaTestRequest {
    public function __construct(private array $params) {}
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

echo "PASS: $checks checks (roles, persistence, removal, revocation, deck and version guards).\n";
