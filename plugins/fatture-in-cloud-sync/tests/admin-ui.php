<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/admin-ui.php [--preview]
 * PHP views and navigation with the existing isolated WP/API fixture; real policy SQL on SQLite.
 */
define('PMPRO_FIC_AUTOMATION_BOOTSTRAP', true);
require __DIR__ . '/automation.php';
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/fatture-in-cloud-sync/'; }
function wp_enqueue_style(...$args) { $GLOBALS['ui_styles'][] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['ui_scripts'][] = $args; }
class UIOptionsDatabase extends AutomationOptionsDatabase {
    public $col_failure = false;
    public function get_col($sql) {
        if ($this->col_failure) { $this->last_error = 'Unavailable'; return null; }
        // Recovery journal enumeration is covered by webhook-guard.php, not this fixture.
        if (strpos($sql, 'SUBSTRING(') === false) return [];
        $json = $this->get_var('SELECT json_group_array(option_name) FROM (' . $sql . ')');
        return json_decode($json, true);
    }
}
function ui_fixture() {
    global $wpdb;
    $auto = automation_fixture();
    $wpdb = new UIOptionsDatabase();
    $_GET = []; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['ui_styles'] = []; $GLOBALS['ui_scripts'] = [];
    return $auto;
}
function ui_policy($number) {
    $config = get_option(PMPro_FIC_Automation::CONFIG);
    return ['schema_version'=>1,'source_order_id'=>100+$number,'user_id'=>10,'level_id'=>4,'company_id'=>123,'account_id'=>9,
        'effective_from'=>1792663200,'authorized_by'=>1,'subscription_id'=>'I-UITEST'.sprintf('%08d',$number),
        'scope'=>$config['scope'],'source_payment_id'=>'UI-PAYMENT-'.$number,
        'snapshot'=>get_pmpro_membership_order_meta(42,PMPro_FIC_Billing::META,true)];
}
function ui_seed_job($id, $state) {
    $GLOBALS['settings_store']['pmpro_fic_job_'.$id] = ['order_id'=>$id,'state'=>$state,'message'=>$state==='created'?'Documento /TEST creato e associato all’ordine.':'In attesa della prossima elaborazione.',
        'attempts'=>1,'updated_at'=>1792663200,'document_id'=>$state==='created'?9000+$id:'','payment_id'=>'UI-PAYMENT-'.$id,
        'kind'=>'renewal','company_id'=>123,'generation'=>get_option(PMPro_FIC_Automation::CONFIG)['generation']];
}
$auto = ui_fixture();
set_error_handler(function ($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$before = serialize([$settings_store,$options,$order_meta,$orders,$cache,$events,$requests,$sale_requests]);
foreach (PMPro_FIC_Admin::SECTIONS as $section=>$label) {
    $_GET=['fic_section'=>$section]; $html=render_page();
    check(strpos($html,'<h1>'.$label.'</h1>')!==false,'Renders selected '.$section);
    check(substr_count($html,'aria-current="page"')===1,'One active section '.$section);
    check(strpos($html,'test-secret-not-for-html')===false && strpos($html,'fake-paypal-secret')===false,'No credentials in '.$section);
    check(serialize([$settings_store,$options,$order_meta,$orders,$cache,$events,$requests,$sale_requests])===$before,'GET is read-only and offline '.$section);
}
foreach ([[],['fic_section'=>['impostazioni']],['fic_section'=>'<script>evil</script>']] as $query) {
    $_GET=$query; check(strpos(render_page(),'<h1>Panoramica</h1>')!==false,'Safe default section for malformed or absent input');
}
$_GET=['fic_section'=>'rinnovi']; $_SERVER['REQUEST_METHOD']='POST'; $_POST=['fic_action'=>'auto_status','order_id'=>'999'];
$html=render_page();
check(strpos($html,'<h1>Documenti</h1>')!==false && strpos($html,'notice-error')!==false,'POST error returns to operation section despite conflicting GET');
$_POST=['fic_action'=>'simulate','amount'=>'122']; $html=render_page();
check(strpos($html,'<h1>Assistenza</h1>')!==false && strpos($html,'fic-disclosure" open')!==false && strpos($html,'122,00')!==false,'Simulation result is visible inside open disclosure');
$_POST=['fic_action'=>'load_order','order_id'=>'42']; account_response(); $html=render_page();
check(strpos($html,'fic-disclosure" open')!==false && strpos($html,'name="fic_action" value="preview_order"')!==false,'Manual flow opens with confirmation controls intact');
check(strpos($html,'fic_section=documenti#fic-order-result')!==false,'Manual confirmation posts back to documents');
foreach (['auto_accounts'=>'impostazioni','auto_enable'=>'impostazioni','auto_pause'=>'impostazioni','renewal_enable'=>'rinnovi','renewal_status'=>'rinnovi','renewal_pause'=>'rinnovi','renewal_enqueue'=>'rinnovi','diag_cron'=>'assistenza','confirm_initial_pair'=>'documenti'] as $action=>$section) check(PMPro_FIC_Admin::action_section($action)===$section,'Routing '.$action);
$nonce=false; $_POST=['fic_action'=>'auto_run','order_id'=>'42'];
$before=serialize([$requests,$settings_store]);
try { render_page(); throw new RuntimeException('Invalid nonce accepted'); } catch (InvalidNonce $e) { check($before===serialize([$requests,$settings_store]),'UI keeps nonce before mutations'); }
$nonce=true; $admin=false;
check(render_page()==='','Non-admin has no page or dispatch'); $admin=true;
$_SERVER['REQUEST_METHOD']='GET'; $_POST=[];

$auto=ui_fixture();
PMPro_FIC_Admin::enqueue('dashboard'); check(!$ui_styles && !$ui_scripts,'Assets absent on unrelated admin pages');
PMPro_FIC_Admin::enqueue('settings_page_pmpro-fic-sync');
check(count($ui_styles)===1 && count($ui_scripts)===1 && substr($ui_styles[0][1],-16)==='assets/admin.css','Scoped assets use plugin URL and version');
$admin=false; PMPro_FIC_Admin::enqueue('settings_page_pmpro-fic-sync'); check(count($ui_styles)===1,'Assets capability checked'); $admin=true;

$settings_store[PMPro_FIC_Automation::INDEX]=[142,143,144];
ui_seed_job(142,'created');ui_seed_job(143,'waiting');ui_seed_job(144,'attention');
$settings_store['pmpro_fic_job_144']['message']='<script>queueInjection()</script>';
$_GET=['fic_section'=>'documenti']; $html=render_page();
check(strpos($html,'&lt;script&gt;queueInjection()')!==false && strpos($html,'<script>queueInjection')===false,'Queue text is escaped');
check(strpos($html,'900142')===false && strpos($html,'9142')!==false,'Saved document reference shown');
check(strpos($html,'data-fic-state="created"')!==false && strpos($html,'Ultimi 20 ordini')!==false,'Searchable rows with honest scope');
check(strpos($html,'UI-PAYMENT-142')!==false && strpos($html,'<details class="fic-job-detail">')!==false,'Existing job identifiers in disclosure');
$settings_store['pmpro_fic_job_144']['message']='Verifica l’incasso prima di riprovare.';
$_GET=['fic_section'=>'panoramica']; $html=render_page();
check(substr_count($html,'Nel campione di 3 ordini')===3,'Summary denominators refer to recent queue');
$settings_store[PMPro_FIC_Automation::INDEX][] = 999;
check(is_wp_error(PMPro_FIC_Admin::recent_jobs()),'Missing job cannot silently become a zero count');
check(strpos(render_page(),'Coda recente non disponibile')!==false,'Incomplete queue has explicit warning');
array_pop($settings_store[PMPro_FIC_Automation::INDEX]);
$settings_store[PMPro_FIC_Automation::CONFIG]['scope']='changed';
check(strpos(render_page(),'Non operativa')!==false && strpos(render_page(),'Attivazione salvata: sì')!==false,'Effective and configured activation differ visibly');

$auto=ui_fixture();
for($i=1;$i<=23;$i++) { $p=ui_policy($i); PMPro_FIC_Atomic_Store::insert(PMPro_FIC_Renewals::key($p['subscription_id']),$p); }
$p=ui_policy(5); $paused_key=PMPro_FIC_Renewals::key($p['subscription_id']).'_paused'; $settings_store[$paused_key]=true;
PMPro_FIC_Atomic_Store::insert($paused_key,['not'=>'a policy']);
PMPro_FIC_Atomic_Store::insert('pmpro_fic_renewal_snapshot_999',['not'=>'a policy']);
$list=PMPro_FIC_Admin::policies(1); $last=PMPro_FIC_Admin::policies(2);
check(count($list['rows'])===20 && $list['more'] && count($last['rows'])===3 && !$last['more'],'Policy SQL paginates all canonical records excluding pause/snapshot');
$ids=[]; foreach(array_merge($list['rows'],$last['rows']) as $row) { check(is_array($row),'Valid policy read from canonical JSON'); $ids[]=$row['policy']['subscription_id']; }
check(count(array_unique($ids))===23,'Pages contain no duplicates or skipped authorizations');
$_GET=['fic_section'=>'rinnovi']; $html=render_page();
check(strpos($html,'Fatturazione autorizzata')!==false && strpos($html,'fic_policies_page=2')!==false,'Real policies and next-page link rendered');
$_GET['fic_policies_page']=2; $all=$html.render_page();
check(strpos($all,'Fatturazione sospesa')!==false,'Pause flag rendered separately');
check(strpos($all,'billing@example.test')===false && strpos($all,'Via Prova')===false,'Policy list does not expose fiscal snapshots');
$wpdb->col_failure=true;
check(is_wp_error(PMPro_FIC_Admin::policies(1)) && strpos(render_page(),'Impossibile leggere')!==false,'DB failure is not an empty policy list');
$wpdb->col_failure=false;
$wpdb->query($wpdb->prepare('UPDATE wp_options SET option_value=%s WHERE option_name=%s','bad-json',PMPro_FIC_Renewals::key(ui_policy(1)['subscription_id'])));
$rows=array_merge(PMPro_FIC_Admin::policies(1)['rows'],PMPro_FIC_Admin::policies(2)['rows']);
check(count(array_filter($rows,'is_wp_error'))===1,'Corrupted canonical authorization is reported, not skipped');

// Optional browser fixture: actual PHP views, synthetic data and a minimal WP-style outer shell.
if(in_array('--preview',$argv,true)) {
    $auto=ui_fixture(); $settings_store[PMPro_FIC_Automation::INDEX]=[142,143,144,145];
    foreach([142=>'created',143=>'created',144=>'waiting',145=>'attention'] as $id=>$state) ui_seed_job($id,$state);
    $settings_store['pmpro_fic_job_143']['renewal_auto']=['state'=>'attention','attempts'=>5,'message'=>'Verifica PayPal non disponibile. Il documento iniziale resta associato.'];
    $settings_store['pmpro_fic_job_142']['renewal_auto']=['state'=>'authorized','attempts'=>1,'message'=>'Fatturazione dei rinnovi abilitata automaticamente.'];
    PMPro_FIC_Atomic_Store::insert(PMPro_FIC_Plan_Rules::KEY,['schema_version'=>1,'revision'=>'ui-fixture','plans'=>[3=>['first'=>false,'renewals'=>false,'after_order_id'=>100,'authorized_by'=>1,'saved_at'=>1792663200],4=>['first'=>true,'renewals'=>true,'after_order_id'=>100,'authorized_by'=>1,'saved_at'=>1792663200],5=>['first'=>true,'renewals'=>false,'after_order_id'=>100,'authorized_by'=>1,'saved_at'=>1792663200]]]);
    $settings_store['pmpro_fic_job_145']['message']='Verifica l’incasso prima di riprovare.';
    for($i=1;$i<=2;$i++){ $p=ui_policy($i); PMPro_FIC_Atomic_Store::insert(PMPro_FIC_Renewals::key($p['subscription_id']),$p); }
    $settings_store[PMPro_FIC_Diagnostics::CRON_OPTION]=['due_at'=>1792663200,'ran_at'=>1792663280];
    $settings_store[PMPro_FIC_Webhook_Guard::LOG]=[['at'=>'2026-10-22T14:37:27Z','outcome'=>'bound'],['at'=>'2026-10-22T14:36:59Z','outcome'=>'token_retry']];
    $out=dirname(__DIR__,3).'/artifacts/fic-ui-0.8.0'; if(!is_dir($out))mkdir($out,0755,true);
    $css=file_get_contents(dirname(__DIR__).'/assets/admin.css'); $js=file_get_contents(dirname(__DIR__).'/assets/admin.js');
    foreach(PMPro_FIC_Admin::SECTIONS as $section=>$label) {
        $_GET=['fic_section'=>$section]; $html=render_page();
        foreach(PMPro_FIC_Admin::SECTIONS as $key=>$unused) $html=str_replace(esc_url(PMPro_FIC_Admin::url($key)),$key.'.html',$html);
        $html=preg_replace('/<button>/', '<button class="button">',$html);
        $shell='body{margin:0;background:#f0f0f1;font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#3c434a}#wpbar{height:32px;background:#1d2327;color:#eee;padding:0 12px;line-height:32px;position:fixed;top:0;left:0;right:0;z-index:9}#wpside{position:fixed;top:32px;bottom:0;left:0;width:160px;background:#1d2327;color:#eee}#wpside div{padding:12px 16px}#wpside .selected{background:#2271b1}#wpcontent{margin-left:160px;padding:32px 20px 20px}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}input,select,textarea,button{font:inherit}input,select,textarea{padding:5px 8px;border:1px solid #8c8f94}input[type=checkbox]{width:16px;height:16px}.regular-text{width:25em}.widefat{width:100%;text-align:left}.button{cursor:pointer}.notice{padding:1px 12px;border-left:4px solid #72aee6;background:white}.notice-error{border-left-color:#d63638}h1,h2,h3{line-height:1.3}label{display:inline-block}.submit{margin:14px 0}#fixture-note{padding:8px 20px;color:#62717c}@media(max-width:960px){#wpside{width:36px}#wpside div{font-size:0;padding:14px 6px}#wpcontent{margin-left:36px;padding:32px 10px 10px}}@media(max-width:782px){#wpside{display:none}#wpcontent{margin-left:0}#wpbar{height:46px;line-height:46px}#wpcontent{padding-top:46px}}';
        file_put_contents($out.'/'.$section.'.html','<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$label.' · verifica UI</title><style>'.$shell.$css.'</style><body><div id="wpbar">◉ &nbsp; Inplayce &nbsp; + Nuovo</div><aside id="wpside"><div>Bacheca</div><div>Articoli</div><div>Media</div><div>Pagine</div><div>Iscrizioni</div><div>Tutor LMS</div><div>Plugin</div><div>Utenti</div><div class="selected">Impostazioni</div></aside><div id="wpcontent">'.$html.'<p id="fixture-note">Verifica locale: viste PHP del plugin con dati sintetici e cornice WordPress simulata. I moduli non sono collegati al sito.</p></div><script>'.$js.'</script></body></html>');
    }
}
restore_error_handler();
echo "PASS: $checks admin UI checks; views/POST routing, offline GET, escaping, access, real policy SQL on SQLite.\n";
