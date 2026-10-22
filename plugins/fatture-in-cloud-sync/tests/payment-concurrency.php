<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/payment-concurrency.php
 * Multiple PHP processes, shared SQLite UNIQUE index. Repeat on WordPress/MySQL before production.
 */
define('ABSPATH', __DIR__);
require __DIR__ . '/support-atomic.php';
require dirname(__DIR__) . '/includes/class-pmpro-fic-payments.php';
class WP_Error {
    public function __construct(public $code, public $message) {}
    public function get_error_code() { return $this->code; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value); }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
class MemberOrder {
    public static function get_orders($args) { return [$GLOBALS['order']]; }
}
if (($argv[1] ?? '') === '--worker') {
    $wpdb = new AtomicOptionsDatabase('sqlite:' . $argv[2]);
    $order = (object) ['id'=>(int)$argv[3], 'subscription_transaction_id'=>'I-CONCURRENT1','payment_transaction_id'=>'CONCURRENT123','total'=>'122.00'];
    // Synchronize all contenders at the INSERT boundary, without a long blocking wait.
    for ($i=0; $i<100 && !file_exists($argv[4]); $i++) usleep(10000);
    $reservation = PMPro_FIC_Payments::reserve($order, 123);
    echo json_encode(is_wp_error($reservation) ? ['error'=>$reservation->code] : ['winner'=>$order->id, 'reservation'=>$reservation]);
    exit;
}
$checks=0;
function verify($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); }
foreach (['same_order', 'different_orders'] as $scenario) {
    $path=tempnam(sys_get_temp_dir(), 'fic-atomic-'); $barrier=$path.'.start';
    $wpdb=new AtomicOptionsDatabase('sqlite:'.$path);
    $children=[];
    try {
        for ($i=0;$i<8;$i++) {
            $id=$scenario==='same_order' ? 43 : 43+$i;
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$path,(string)$id,$barrier], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot launch contender');
            fclose($pipes[0]); $children[]=[$process,$pipes];
        }
        touch($barrier); $winners=[];
        foreach ($children as [$process,$pipes]) {
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            verify(proc_close($process)===0 && $err==='', 'Worker completes without warnings: '.$err);
            $result=json_decode($out,true); verify(is_array($result), 'Worker returned a structured result');
            if (isset($result['winner'])) $winners[]=$result;
            else verify(in_array($result['error'],['payment_duplicate','payment_locked'],true), 'Loser fails closed');
        }
        verify(count($winners)===1, 'Exactly one POST permit across eight processes: '.$scenario);
        $winner=$winners[0]; $order=(object)['id'=>$winner['winner'],'subscription_transaction_id'=>'I-CONCURRENT1','payment_transaction_id'=>'CONCURRENT123','total'=>'122.00'];
        $key=PMPro_FIC_Payments::key($order);
        $wpdb=new AtomicOptionsDatabase('sqlite:'.$path);
        verify(PMPro_FIC_Atomic_Store::read($key)['order_id']===$winner['winner'], 'Winner not overwritten by another process');
        verify(is_wp_error(PMPro_FIC_Payments::reserve($order,123)), 'Reservation survives connection restart / process exit');
        $wrong=$winner['reservation']['value']; $wrong['token']='not-owner';
        verify(!PMPro_FIC_Atomic_Store::release($key.'_attempt',$wrong), 'A different attempt cannot release the lock');
        PMPro_FIC_Payments::release($winner['reservation']);
        verify(is_array(PMPro_FIC_Payments::reserve($order,123)), 'Known pre-POST rejection can retry under same owner');
    } finally { unset($wpdb); @unlink($barrier); @unlink($path); }
}
echo "PASS: $checks checks, 16 competing PHP processes, persistent UNIQUE reservations on SQLite; MySQL deployment test still required.\n";
