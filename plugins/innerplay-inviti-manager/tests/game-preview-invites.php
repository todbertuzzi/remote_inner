<?php
/** Run: php plugins/innerplay-inviti-manager/tests/game-preview-invites.php */
define('ARRAY_A', 'ARRAY_A');

class WP_User {
    public function __construct(public $ID, public $user_email) {}
}
function get_userdata($id) { return new WP_User($id, 'member@example.test'); }
function gim_get_wp_timezone() { return new DateTimeZone('UTC'); }
function current_datetime() { return new DateTimeImmutable('2026-09-14 12:00:00', gim_get_wp_timezone()); }
function home_url($path) { return 'https://example.test' . $path; }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }

// Run the production SELECT on a real SQL engine, without a WordPress install.
class PreviewInvitesDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $users = 'wp_users';
    public $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE wp_posts (ID INTEGER, post_title TEXT, post_type TEXT)');
        $this->db->exec('CREATE TABLE wp_users (ID INTEGER, display_name TEXT)');
        $this->db->exec('CREATE TABLE wp_game_sessions (id INTEGER PRIMARY KEY, gioco_id INTEGER, host_user_id INTEGER,
            invited_user_id INTEGER, invited_email TEXT, status TEXT, invito_uuid TEXT, created_at TEXT, expires_at TEXT)');
    }
    public function prepare($sql, ...$args) {
        return preg_replace_callback('/%[ds]/', function ($match) use (&$args) {
            $value = array_shift($args);
            return $match[0] === '%d' ? (string) intval($value) : $this->db->quote($value);
        }, $sql);
    }
    public function esc_like($value) { return $value; }
    public function get_var($query) { return 'wp_game_sessions'; }
    public function get_results($query, $format) { return $this->db->query($query)->fetchAll(PDO::FETCH_ASSOC); }
}

$source = file_get_contents(dirname(__DIR__) . '/includes/invited-dashboard.php');
foreach (['gim_invited_dashboard_table_exists', 'gim_invited_dashboard_user', 'gim_invited_dashboard_format_datetime',
    'gim_invited_dashboard_status_label', 'gim_get_received_game_invites'] as $name) {
    if (!preg_match('/function ' . $name . '\(.*?^\}/ms', $source, $match)) throw new RuntimeException('Missing ' . $name);
    eval($match[0]);
}
$wpdb = new PreviewInvitesDB();
$insert = $wpdb->db->prepare('INSERT INTO wp_game_sessions VALUES (?, 12, 1, ?, ?, ?, ?, ?, ?)');
$fixtures = [
    [1, 4, 'member@example.test', 'created'],
    [2, 4, 'member@example.test', 'revoked'],
    [3, null, 'MEMBER@example.test', 'created'],
    [4, 99, 'member@example.test', 'created'], // Bound user wins over matching email.
    [5, 4, 'member@example.test', 'admin_preview'],
    [6, 4, 'member@example.test', 'member_preview'],
    [7, null, 'member@example.test', 'member_preview'], // Email fallback must also exclude previews.
];
foreach ($fixtures as [$id, $user, $email, $status]) {
    $insert->execute([$id, $user, $email, $status, 'uuid-' . $id, '2026-09-14 11:00:00', '2026-09-14 13:00:00']);
}
$checks = 0;
function check($condition, $label) {
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    $GLOBALS['checks']++;
}
$invites = gim_get_received_game_invites(4);
check(array_column($invites, 'id') === [3, 2, 1], 'Only actual invites belonging to the recipient appear');
check(array_column($invites, 'status') === ['active', 'revoked', 'active'], 'Invite history retains status');
check(!$invites[1]['can_access'] && $invites[0]['can_access'], 'Revoked invites have no play action');
check(array_column(gim_get_received_game_invites(4, 1), 'id') === [3], 'Preview exclusion precedes LIMIT');
$wpdb->db->exec("DELETE FROM wp_game_sessions WHERE status NOT IN ('admin_preview', 'member_preview')");
check(gim_get_received_game_invites(4, 1) === [], 'Previews alone do not count as received invites');
echo "OK: {$checks} preview/invite SQL checks passed.\n";
