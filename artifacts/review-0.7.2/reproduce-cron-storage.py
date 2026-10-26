"""Local simulation: keep production PHP intact; make only the cron stub respect storage failure."""
import pathlib
import subprocess
import sys
import tempfile

root = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/fic-review-072/fatture-in-cloud-sync').resolve()
source = (root / 'tests/webhook-guard.php').read_text()
# Import only the supplied harness definitions, before its test cases.
source = source.split('// 1. The observed failure, through PMPro\'s real handler.')[0]
old = "function wp_schedule_single_event($at, $hook, $args = []) { $GLOBALS['events'][$hook . ':' . json_encode($args)] = $at; return true; }"
new = "function wp_schedule_single_event($at, $hook, $args = []) { if (!empty($GLOBALS['fail_storage'])) return false; $GLOBALS['events'][$hook . ':' . json_encode($args)] = $at; return true; }"
assert old in source, 'Unexpected fixture; review it before adapting this script.'
source = source.replace(old, new)
php_dir = "'" + str(root / 'tests').replace('\\', '\\\\').replace("'", "\\'") + "'"
source = source.replace('__DIR__', php_dir)
source += r'''
$guard = guard_fixture();
$wpdb->after_readback = function () { $GLOBALS['fail_storage'] = true; $GLOBALS['fail_meta'] = true; };
[$response, $core] = deliver($guard);
echo json_encode(['case'=>'audit and cron storage unavailable', 'guard_http'=>$response->status,
    'queued_events'=>count($events), 'orders'=>count($orders),
    'journal_status'=>$settings_store['pmpro_fic_paypal_recovery_42']['status']])."\n";
$GLOBALS['fail_storage']=false; $GLOBALS['fail_meta']=false; $wpdb->after_readback=null;
echo json_encode(['case'=>'storage restored, no extra delivery manufactured', 'queued_events'=>count($events),
    'journal_status'=>$settings_store['pmpro_fic_paypal_recovery_42']['status']])."\n";
$sale_responses=[remote_sale()];
[$response,$core]=deliver($guard);
echo json_encode(['case'=>'PayPal redelivery can still recover', 'core'=>$core,
    'journal_status'=>$settings_store['pmpro_fic_paypal_recovery_42']['status'], 'orders'=>count($orders)])."\n";
'''
with tempfile.TemporaryDirectory(prefix='fic-review-cron-') as temp:
    script = pathlib.Path(temp) / 'cron-storage.php'
    script.write_text(source)
    result = subprocess.run(['php', str(script)])
    sys.exit(result.returncode)
