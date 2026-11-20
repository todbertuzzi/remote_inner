<?php
/** Run: php plugins/fatture-in-cloud-sync/tests/plan-rules.php. No external APIs or documents. */
define('PMPRO_FIC_AUTOMATION_BOOTSTRAP', true);
require __DIR__ . '/automation.php';
function add_pmpro_membership_order_meta($id,$key,$value,$unique=false) {
    if ($unique && isset($GLOBALS['order_meta'][$id][$key])) return false;
    $GLOBALS['order_meta'][$id][$key]=$value; return true;
}
class PlanRulesDatabase extends AutomationOptionsDatabase {
    public $pmpro_membership_orders = 'wp_pmpro_membership_orders';
    public $fail_orders = false;
    public function get_var($sql) {
        if (strpos($sql,'MAX(id)')!==false) {
            $this->last_error='';
            if($this->fail_orders) { $this->last_error='unavailable';return null; }
            $db=new PDO('sqlite::memory:'); $db->exec('CREATE TABLE wp_pmpro_membership_orders (id INTEGER PRIMARY KEY)');
            foreach(array_keys($GLOBALS['orders']) as $id) $db->exec('INSERT INTO wp_pmpro_membership_orders VALUES ('.(int)$id.')');
            return $db->query($sql)->fetchColumn();
        }
        return parent::get_var($sql);
    }
    public function get_col($sql) {
        if(strpos($sql,'SUBSTRING(')===false)return [];
        return json_decode($this->get_var('SELECT json_group_array(option_name) FROM ('.$sql.')'),true);
    }
}
function rules_input($first=true,$renewals=true) {
    $rules=PMPro_FIC_Plan_Rules::read();
    return ['confirm_plan_rules'=>'1','rules_revision'=>is_array($rules)?$rules['revision']:'',
        'plans'=>[3=>['present'=>'1'],4=>['present'=>'1','first'=>$first?'1':'0','renewals'=>$renewals?'1':'0'],5=>['present'=>'1']]];
}
function plans_fixture($save=true) {
    unset($GLOBALS['on_sale_request'],$GLOBALS['on_fic_request']);
    $auto=automation_fixture(); $GLOBALS['wpdb']=new PlanRulesDatabase();
    $_GET=[]; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
    if($save)check(!is_wp_error($auto->dispatch('plan_rules_save',rules_input())),'Save explicit per-plan rules');
    return $auto;
}
function plan_order($id=43,$level=4) {
    $GLOBALS['orders'][$id]=array_merge($GLOBALS['orders'][42],['id'=>$id,'membership_id'=>$level,'code'=>'NEW'.$id,
        'payment_transaction_id'=>'NEWPAYMENT'.$id,'subscription_transaction_id'=>'I-NEWPLAN'.$id,'timestamp'=>time()]);
    $GLOBALS['order_meta'][$id]=$GLOBALS['order_meta'][42];
    $GLOBALS['order_meta'][$id][PMPro_FIC_Billing::META]['order_id']=$id;
    $GLOBALS['order_meta'][$id][PMPro_FIC_Billing::META]['level_id']=$level;
    return new MemberOrder($id);
}
function plan_job($id=43) {return get_option('pmpro_fic_job_'.$id,[]);}
function plan_policy($id=43) {return PMPro_FIC_Renewals::policy(new MemberOrder($id));}
function plan_run($auto,$id=43) {
    unset($GLOBALS['events'][PMPro_FIC_Automation::HOOK.':['.$id.']']);
    $GLOBALS['admin']=false;$GLOBALS['admin_id']=0;
    $auto->process($id);
    $GLOBALS['admin']=true;$GLOBALS['admin_id']=1;
}
function auth_failure($code=503) {
    $GLOBALS['on_sale_request']=function() use ($code) {
        if(count($GLOBALS['sale_requests'])===3) $GLOBALS['sale_responses']=[['status'=>$code,'body'=>'{}']];
    };
}
function new_renewal($id=44) {
    $GLOBALS['orders'][$id]=array_merge($GLOBALS['orders'][43],['id'=>$id,'code'=>'RENEW'.$id,'payment_transaction_id'=>'RENEWPAYMENT'.$id,'timestamp'=>time()]);
    return new MemberOrder($id);
}
$auto=plans_fixture(false);$before=get_option(PMPro_FIC_Automation::CONFIG);
$bad=rules_input();unset($bad['confirm_plan_rules']);error_is($auto->dispatch('plan_rules_save',$bad),'confirm_test');
$bad=rules_input(false,true);error_is($auto->dispatch('plan_rules_save',$bad),'plan_input');
$bad=rules_input();$bad['plans'][999]=['present'=>'1'];error_is($auto->dispatch('plan_rules_save',$bad),'plan_input');
$admin=false;error_is($auto->dispatch('plan_rules_save',rules_input()),'forbidden');$admin=true;
$wpdb->fail_orders=true;error_is($auto->dispatch('plan_rules_save',rules_input()),'plan_storage');$wpdb->fail_orders=false;
check(PMPro_FIC_Plan_Rules::read()===null,'Failed settings never opt in');
$_SERVER['REQUEST_METHOD']='POST';$_POST=rules_input()+['fic_action'=>'plan_rules_save'];$nonce=false;
try{render_page();throw new RuntimeException('Nonce bypass');}catch(InvalidNonce $e){check(PMPro_FIC_Plan_Rules::read()===null,'Nonce before saving rules');}$nonce=true;
$html=render_page();check(strpos($html,'Regole salvate')!==false && strpos($html,'<h1>Impostazioni</h1>')!==false,'POST settings result routed correctly');
check(get_option(PMPro_FIC_Automation::CONFIG)===$before,'Plan rules do not reset automation scope or generation');
error_is($auto->dispatch('plan_rules_save',$_POST),'plan_stale');
$saved=PMPro_FIC_Plan_Rules::read();$auto->dispatch('plan_rules_save',rules_input());check(PMPro_FIC_Plan_Rules::read()===$saved,'Identical save preserves original cutoff and actor');
$auto->on_new_order(new MemberOrder(42));check(!job42(),'Old order hook cannot enrol or invoice retrospectively');
$auto->on_new_order(plan_order(43,5));check(!plan_job(),'Unselected plan does not enter queue');
$_SERVER['REQUEST_METHOD']='GET';$_GET=['fic_section'=>'impostazioni'];$before=serialize([$settings_store,$order_meta,$requests,$sale_requests]);render_page();check($before===serialize([$settings_store,$order_meta,$requests,$sale_requests]),'Settings GET has no API calls or writes');

$auto=plans_fixture();$order=plan_order();$requests=[];$sale_requests=[];$auto->on_new_order($order);
check(plan_job()['renewal_auto']['state']==='pending' && !$requests && !$sale_requests,'Checkout captures rule without network');
expected_document();plan_run($auto);
$p=plan_policy();check(plan_job()['state']==='created' && plan_job()['renewal_auto']['state']==='authorized' && posts()===1,'First paid checkout creates one document and auto-enrols');
check(is_array($p) && $p['authorized_by']===1 && $p['authorization_origin']['type']==='plan','Cron attribution comes from the admin who saved rules, not anonymous worker');
check($p['snapshot']===PMPro_FIC_Billing::snapshot(43),'Immutable fiscal copy is initial checkout data');
$copy=$p;$docposts=posts();$auto->on_new_order($order);$auto->on_updated_order($order);plan_run($auto);
check(plan_policy()===$copy && posts()===$docposts,'Replayed hooks and completed worker do not duplicate policy or invoice');
$auto->on_renewal(new_renewal());check(plan_job(44)['kind']==='renewal','Auto-authorized subscription captures subsequent payment');
expected_document();plan_run($auto,44);check(plan_job(44)['state']==='created' && posts()===2,'Renewal creates its own document without a manual authorization');
check(!isset(plan_job(44)['renewal_auto']),'Renewal cannot recursively enrol itself');
$auto->on_renewal(new_renewal(45));$auto->dispatch('renewal_pause',['order_id'=>'43']);$postcount=posts();plan_run($auto,45);
check(plan_job(45)['state']==='blocked' && posts()===$postcount,'Manual pause stops an already queued renewal');
$auto->on_renewal(new_renewal(46));check(!plan_job(46),'Paused subscription does not capture future renewal');
$auto->on_updated_order(new MemberOrder(43));plan_run($auto);check(get_option(PMPro_FIC_Renewals::key($p['subscription_id']).'_paused')===true && plan_policy()===$copy,'No automatic path clears a pause or overwrites fiscal data');

$auto=plans_fixture();$auto->on_new_order(plan_order());$auto->dispatch('plan_rules_save',rules_input(false,false));expected_document();plan_run($auto);
check(plan_job()['renewal_auto']['state']==='authorized','Changes apply to future checkouts; already captured rule is honoured');
$auto->on_new_order(plan_order(44));check(!plan_job(44),'Disabled plan excludes next checkout');
$auto=plans_fixture();$auto->dispatch('plan_rules_save',rules_input(true,false));$auto->on_new_order(plan_order());expected_document();plan_run($auto);
check(plan_job()['state']==='created' && !isset(plan_job()['renewal_auto']) && plan_policy()===null,'Initial-document-only plan never auto-authorizes renewals');
$auto=plans_fixture(false);$auto->on_new_order(plan_order());$auto->dispatch('plan_rules_save',rules_input());expected_document();plan_run($auto);
check(plan_job()['state']==='created' && plan_policy()===null,'Preexisting legacy job does not gain automatic authorization after saving rules');

$auto=plans_fixture();$auto->on_new_order(plan_order());auth_failure();expected_document();plan_run($auto);
check(plan_job()['state']==='created' && plan_job()['renewal_auto']['state']==='pending' && plan_policy()===null && posts()===1,'Transient verification failure keeps initial document and schedules only authorization');
$requests_before=count($requests);unset($on_sale_request);plan_run($auto);
check(plan_job()['renewal_auto']['state']==='authorized' && posts()===1 && count($requests)===$requests_before,'Authorization retry performs no FIC request and never recreates document');

$auto=plans_fixture();$auto->on_new_order(plan_order());auth_failure(403);expected_document();plan_run($auto);
check(plan_job()['renewal_auto']['state']==='attention' && plan_policy()===null && posts()===1,'Definitive verification error requires correction without discarding invoice');
$_GET=['fic_section'=>'rinnovi'];$html=render_page();check(strpos($html,'Abilitazione rinnovi da correggere')!==false && strpos($html,'Riprova solo abilitazione rinnovi')!==false,'Correction visible in Renewals with dedicated retry');
unset($on_sale_request);$auto->dispatch('renewal_auto_retry',['order_id'=>'43']);plan_run($auto);
check(plan_job()['renewal_auto']['state']==='authorized' && posts()===1,'Explicit retry completes only missing authorization');

$auto=plans_fixture();$auto->on_new_order(plan_order());
$on_sale_request=function(){if(count($GLOBALS['sale_requests'])===3){$GLOBALS['admin']=true;$GLOBALS['admin_id']=1;$GLOBALS['pause_result']=$GLOBALS['auto']->dispatch('renewal_pause',['order_id'=>'43']);$GLOBALS['admin']=false;$GLOBALS['admin_id']=0;}};
expected_document();plan_run($auto);
check(!is_wp_error($pause_result) && plan_job()['renewal_auto']['state']==='suspended' && plan_policy()===null,'Pause while verification is in flight prevents authorization and is never cleared');
unset($on_sale_request);

foreach(['payment','snapshot','document','config','duplicate'] as $change){
    $auto=plans_fixture();$auto->on_new_order(plan_order());
    $on_sale_request=function() use($change){if(count($GLOBALS['sale_requests'])!==3)return;
        if($change==='payment')$GLOBALS['orders'][43]['payment_transaction_id']='DIFFERENTPAY';
        if($change==='snapshot')$GLOBALS['order_meta'][43][PMPro_FIC_Billing::META]['data']['company']='Changed';
        if($change==='document')$GLOBALS['order_meta'][43][PMPro_FIC_Test_Orders::META]['document_id']=999;
        if($change==='config')$GLOBALS['settings_store'][PMPro_FIC_Automation::CONFIG]['account_id']=99;
        if($change==='duplicate')$GLOBALS['orders'][99]=array_merge($GLOBALS['orders'][43],['id'=>99]);
    };
    expected_document();plan_run($auto);
    check(plan_job()['renewal_auto']['state']==='attention' && plan_policy()===null && posts()===1,'Changed '.$change.' blocks authorization after verification');
}
unset($on_sale_request);
$auto=plans_fixture();$order=plan_order();$original=$order_meta[43][PMPro_FIC_Billing::META];$order_meta[43][PMPro_FIC_Billing::META]['data']['email']='';$auto->on_new_order($order);
check(plan_job()['state']==='blocked' && plan_job()['capture_error'] && posts()===0,'Invalid fiscal data is reported in queue without invoices or authorization');
$order_meta[43][PMPro_FIC_Billing::META]=$original;$auto->dispatch('auto_retry',['order_id'=>'43']);expected_document();plan_run($auto);
check(plan_job()['renewal_auto']['state']==='authorized' && posts()===1,'Explicit retry after fiscal correction can complete checkout');

$auto=plans_fixture();$auto->on_new_order(plan_order());auth_failure(403);expected_document();plan_run($auto);unset($on_sale_request);
$settings_store['pmpro_fic_job_lock_43']=time();error_is($auto->dispatch('renewal_auto_retry',['order_id'=>'43']),'auto_locked');
$settings_store['pmpro_fic_job_lock_43']=time()-1000;$res=$auto->dispatch('renewal_auto_retry',['order_id'=>'43']);check(!is_wp_error($res),'Explicit authorization retry can recover abandoned lock safely');plan_run($auto);
check(plan_job()['renewal_auto']['state']==='authorized' && posts()===1,'Abandoned authorization recovery never repeats the invoice');

$auto=plans_fixture();$before=PMPro_FIC_Plan_Rules::read();$wpdb->atomic_failure=true;$result=$auto->dispatch('plan_rules_save',rules_input());check(is_wp_error($result),'Storage error cannot fall back to unrestricted legacy rules');$wpdb->atomic_failure=false;check(PMPro_FIC_Plan_Rules::read()===$before,'Failed save preserves canonical rules');
$wpdb->query($wpdb->prepare('UPDATE wp_options SET option_value=%s WHERE option_name=%s','invalid',PMPro_FIC_Plan_Rules::KEY));$auto->on_new_order(plan_order());
check(!plan_job() && get_option('pmpro_fic_plan_error','')!=='','Corrupted rules stop new jobs and expose a persistent warning');
// Bounded retries and a mismatched document must never turn into another invoice.
$auto=plans_fixture();$auto->on_new_order(plan_order());
$on_sale_request=function(){if(count($GLOBALS['sale_requests'])>=3)$GLOBALS['sale_responses']=[['status'=>503,'body'=>'{}']];};
expected_document();plan_run($auto);for($i=0;$i<4;$i++)plan_run($auto);
check(plan_job()['renewal_auto']['attempts']===5 && plan_job()['renewal_auto']['state']==='attention' && posts()===1,'Verification retries are bounded and never resend the invoice');
unset($on_sale_request);
$auto=plans_fixture();$auto->on_new_order(plan_order());account_response();$post_responses=[document_response(101,121.99)];plan_run($auto);
check(plan_job()['state']==='attention' && plan_policy()===null && posts()===1,'Mismatched initial document prevents automatic renewal authorization');
// Recovering a saved policy after interruption is idempotent even if a renewal has since arrived.
$auto=plans_fixture();$auto->on_new_order(plan_order());expected_document();plan_run($auto);$p=plan_policy();
$settings_store['pmpro_fic_job_43']['renewal_auto']['state']='pending';$auto->on_renewal(new_renewal());$sale_before=count($sale_requests);plan_run($auto);
check(plan_job()['renewal_auto']['state']==='authorized' && plan_policy()===$p && posts()===1 && count($sale_requests)===$sale_before,'Already saved authorization is reused after an interrupted completion without another payment read');
// Two administrators cannot overwrite each other, and disallowed environment remains closed.
$auto=plans_fixture();$stale=rules_input(false,false);$auto->dispatch('plan_rules_save',rules_input(true,false));error_is($auto->dispatch('plan_rules_save',$stale),'plan_stale');
$settings_store['pmpro_gateway_environment']='live';error_is($auto->dispatch('plan_rules_save',rules_input()),'auto_sandbox');
// A policy write interrupted by storage failure can be retried separately from document creation.
$auto=plans_fixture();$auto->on_new_order(plan_order());
$on_sale_request=function(){if(count($GLOBALS['sale_requests'])===3)$GLOBALS['wpdb']->atomic_failure=true;};
expected_document();plan_run($auto);$wpdb->atomic_failure=false;unset($on_sale_request);
check(plan_job()['renewal_auto']['state']==='attention' && plan_policy()===null && posts()===1,'Canonical storage failure does not enable renewals');
$auto->dispatch('renewal_auto_retry',['order_id'=>'43']);plan_run($auto);check(plan_job()['renewal_auto']['state']==='authorized' && posts()===1,'Storage recovery preserves the existing invoice');
echo "PASS: $checks plan automation checks; WordPress/PayPal/FIC simulated, rule CAS/high-water SQL on SQLite.\n";
