<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$argv = ['list-browser-fixture', '--forms', '--init-isolated-admin'];
ob_start(); require __DIR__ . '/admin-fixture.php'; ob_end_clean();
$database = Illuminate\Support\Facades\DB::getFacadeRoot();
$database->table('pays')->where('id', 1)->update(['pay_handleroute'=>'pay/payjs','pay_check'=>'payjswescan','pay_name'=>'Fixture Payjs','merchant_id'=>'100001','merchant_key'=>'','merchant_pem'=>'SYNTHETIC-PAYJS-SECRET','is_open'=>1]);
$source = (array) $database->table('pays')->where('id',1)->first();
for ($id=2; $id<=31; $id++) {
    $copy=$source; $copy['id']=$id; $copy['pay_handleroute']='pay/tokenpay'; $copy['pay_check']='tokenpay-fixture-'.$id; $copy['pay_name']='Fixture TokenPay '.$id;
    $copy['merchant_id']='USDT_TRON'; $copy['merchant_key']='SYNTHETIC-TOKENPAY-KEY'; $copy['merchant_pem']='https://payments.example.test'; $copy['is_open']=$id%2;
    $database->table('pays')->insertOrIgnore($copy);
}
echo json_encode(['status'=>'PASS','fixture'=>'admin-forms-root','pays'=>$database->table('pays')->count()]), PHP_EOL;
