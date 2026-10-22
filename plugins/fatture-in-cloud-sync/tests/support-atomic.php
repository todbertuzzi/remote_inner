<?php
/** Real SQL semantics in SQLite; only INSERT IGNORE syntax is translated. Not a MySQL certification. */
class AtomicOptionsDatabase {
    public $options = 'wp_options';
    public $last_error = '';
    public $atomic_failure = false;
    public $before_atomic_query = null;
    private $options_db;
    public function __construct($dsn = 'sqlite::memory:') {
        $this->options_db = new PDO($dsn);
        $this->options_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->options_db->exec('PRAGMA busy_timeout = 10000');
        $this->options_db->exec('CREATE TABLE IF NOT EXISTS wp_options (option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL, autoload TEXT)');
    }
    public function prepare($sql, ...$args) {
        $position = 0;
        $query = preg_replace_callback('/%[sd]/', function ($m) use ($args, &$position) {
            $value = $args[$position++];
            return $m[0] === '%d' ? (string) (int) $value : $this->options_db->quote((string) $value);
        }, $sql);
        if ($position !== count($args)) throw new RuntimeException('Wrong SQL parameter count');
        return $query;
    }
    public function query($sql) {
        $this->last_error = '';
        if ($this->before_atomic_query) ($this->before_atomic_query)($sql);
        if ($this->atomic_failure) { $this->last_error = 'simulated database unavailable'; return false; }
        return $this->options_db->exec(str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql));
    }
    public function get_var($sql) {
        $this->last_error = '';
        if ($this->atomic_failure) { $this->last_error = 'simulated database unavailable'; return null; }
        $value = $this->options_db->query($sql)->fetchColumn();
        return $value === false ? null : $value;
    }
}
