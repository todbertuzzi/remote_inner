<?php
/** Run: php plugins/scrivania-collaborativa-api/tests/membership-templates.php */
$root = dirname(__DIR__, 3);
define('WP_PLUGIN_DIR', $root . '/plugins');
$case = $argv[1] ?? '';
$parts = explode(':', $case);
$tier = $parts[1] ?? 'welcome';
$mode = $parts[2] ?? '';
$status = 200;
$headers = 0;
$user = (object) ['ID' => 1, 'user_email' => 'member@example.test', 'display_name' => 'Member'];
function get_current_user_id() { return 1; }
function is_user_logged_in() { return true; }
function wp_get_current_user() { return $GLOBALS['user']; }
function get_userdata($id) { return $GLOBALS['user']; }
function user_can($id, $cap) { return $GLOBALS['tier'] === 'admin'; }
function current_user_can($cap) { return user_can(1, $cap); }
function ipt_get_user_access_tier($id) { return $GLOBALS['tier']; }
function pmpro_getMembershipLevelForUser($id) { return $GLOBALS['tier'] === '' ? null : (object) ['id' => 3]; }
function nocache_headers() {}
function get_header() { $GLOBALS['headers']++; }
function get_footer() {}
function status_header($code) { $GLOBALS['status'] = $code; }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return $s; }
function esc_js($s) { return addslashes($s); }
function sanitize_text_field($s) { return $s; }
function do_shortcode($s) { return ''; }
function pmpro_url($s) { return '/membership/' . $s; }
function admin_url($s) { return '/wp-admin/' . $s; }
function home_url($s) { return 'https://example.test' . $s; }
function wp_create_nonce($s) { return 'nonce'; }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_add_inline_script(...$args) {}
function wp_json_encode($value) { return json_encode($value); }
function plugins_url($s) { return '/plugins/' . $s; }
function rest_url($s) { return '/wp-json/' . $s; }
function get_option($s, $default = false) { return $default; }
class WP_Query {
    public function __construct($args) {}
    public function have_posts() { return false; }
}
class TemplateDB {
    public $prefix = 'wp_';
    public function prepare($sql, ...$args) { return $sql; }
    public function get_col(...$args) { return ['invitato_user_id', 'role', 'status']; }
    public function get_row($query) {
        if (str_contains($query, 'scrivania_invitati')) return (object) ['id' => 4, 'role' => 'viewer', 'status' => 'consumed'];
        return (object) ['id' => 7, 'creatore_id' => $GLOBALS['mode'] === 'invite' ? 2 : 1, 'token' => 'session-token'];
    }
}
$wpdb = new TemplateDB();
$source = file_get_contents($root . '/plugins/scrivania-collaborativa-api/scrivania-collaborativa-api.php');
preg_match('/function scrivania_user_can_create_session\(.*?^\}/ms', $source, $match);
eval($match[0]);

if ($case !== '') {
    $_GET = $mode === '' ? [] : ['token' => 'session-token'];
    ob_start();
    register_shutdown_function(function () {
        $html = ob_get_clean();
        echo json_encode(['status' => $GLOBALS['status'], 'headers' => $GLOBALS['headers'], 'html' => $html]);
    });
    require $root . '/themes/hello-theme-child-master/' . ($parts[0] === 'dashboard' ? 'page-dashboard-utente.php' : 'tool-scrivania.php');
    exit;
}
$checks = 0;
function check($ok, $label) {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
function render($case) {
    $result = json_decode(shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case)), true);
    check(is_array($result) && $result['headers'] === 1, 'Template renders once: ' . $case);
    return $result;
}
foreach (['welcome', 'professional', 'gold', 'admin'] as $tier) {
    $result = render('dashboard:' . $tier);
    $allowed = $tier !== 'welcome';
    foreach (['id="openScrivaniaModal"', 'id="scrivaniaInviteModal"', 'id="gestione-inviti"', 'data-tab-target="gestione-inviti"'] as $element) {
        check(str_contains($result['html'], $element) === $allowed, 'Dashboard host controls for ' . $tier);
    }
    check(str_contains($result['html'], 'id="inviteModal"') && str_contains($result['html'], 'id="rubrica-contatti"'), 'Games and contacts remain available');
}
foreach (['welcome', '', 'professional', 'gold', 'admin'] as $tier) {
    foreach (['', 'token', 'invite'] as $mode) {
        $result = render('tool:' . $tier . ':' . $mode);
        $allowed = $mode === 'invite' || in_array($tier, ['professional', 'gold', 'admin'], true);
        check($result['status'] === ($allowed ? 200 : 403), 'Tool status: ' . $tier . ':' . $mode);
        check(str_contains($result['html'], 'id="root"') === $allowed, 'App mounts only when authorized: ' . $tier . ':' . $mode);
    }
}
echo "PASS: {$checks} dashboard/tool membership checks.\n";
