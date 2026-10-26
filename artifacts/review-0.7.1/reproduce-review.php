<?php
$reviewRoot = $argv[1] ?? '/tmp/fic-review-071/fatture-in-cloud-sync';
require rtrim($reviewRoot, '/') . '/tests/webhook-guard.php';
function observation($case,$response,$core){echo json_encode(['case'=>$case,'guard_http'=>$response instanceof WP_REST_Response?$response->status:null,'core'=>$core,'orders'=>count($GLOBALS['orders']),'outcome'=>outcome(),'payment'=>$GLOBALS['orders'][42]['payment_transaction_id']],JSON_UNESCAPED_UNICODE)."\n";}
// Options-store read failure, while the order table remains readable.
$guard=guard_fixture(); $wpdb->atomic_failure=true;
[$response,$core]=deliver($guard); observation('canonical pair read fails',$response,$core);
// Malformed time is unverifiable, but is classified as definitive and falls through.
$guard=guard_fixture(); $sale_responses=[remote_sale(['create_time'=>'not-a-time'])];
[$response,$core]=deliver($guard); observation('sale time unreadable',$response,$core);
// SQL payment binding succeeds; final audit writes fail. No second delivery is manufactured.
$guard=guard_fixture(); $wpdb->after_readback=function(){$GLOBALS['fail_storage']=true;$GLOBALS['fail_meta']=true;};
[$response,$core]=deliver($guard); observation('final audit cannot persist',$response,$core);
$GLOBALS['fail_storage']=false;$GLOBALS['fail_meta']=false;$wpdb->after_readback=null;
$service=new PMPro_FIC_Test_Orders([$plugin,'order_context']);
$responses=[response([['id'=>9,'name'=>'PayPal Test']])];$service->dispatch('load_order',['order_id'=>'42']);
$totals_responses=[response(['amount_net'=>450,'amount_vat'=>99,'amount_gross'=>549,'amount_due'=>549])];
$preview=$service->dispatch('preview_order',customer_input());
echo json_encode(['case'=>'after storage recovers, before any extra delivery','journal_status'=>$settings_store['pmpro_fic_paypal_recovery_42']['status'],'preview_error'=>is_wp_error($preview)?$preview->get_error_code():null])."\n";
// A later daily renewal exists. Even explicitly replaying the initial sale bypasses audit repair.
$orders[43]=array_merge($orders[42],['id'=>43,'code'=>'RENEW43','payment_transaction_id'=>'RENEWAL999']);$sale_responses=[remote_sale()];
[$response,$core]=deliver($guard); observation('replay after daily renewal exists',$response,$core);
echo json_encode(['journal_status_after_replay'=>$settings_store['pmpro_fic_paypal_recovery_42']['status'],'repair_api_calls_remaining'=>count($sale_responses)])."\n";
// Rendering claims active even with the explicit kill-switch set.
$guard=guard_fixture();$filter_values['pmpro_fic_webhook_guard_enabled']=false;
ob_start();PMPro_FIC_Webhook_Guard::render_status();$html=ob_get_clean();
echo json_encode(['case'=>'disabled guard UI','still_says_active'=>str_contains($html,'Attiva per PayPal Sandbox')])."\n";
// Both would be accepted by the 24-hour checkout check; only the availability of the API changes the outcome.
foreach([false,true] as $incomplete){
 $guard=guard_fixture();$orders[42]['timestamp']=strtotime('2026-10-14T05:20:05Z');
 if($incomplete)$sale_responses=[['status'=>200,'body'=>'{}']];
 [$response,$core]=deliver($guard);observation('checkout 3h earlier / API '.($incomplete?'incomplete':'complete'),$response,$core);
}
