<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Services\Money;
use App\Services\SlipDecision;
$n=0;
function check(bool $ok,string $name): void { global $n; $n++; if(!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
check(class_exists(Money::class),'money service exists');
check(Money::parse('123.45')===12345,'exact satang');
foreach (['-1','1.001','1e3','NaN','99999999999999999'] as $bad) { try {Money::parse($bad);check(false,'reject '.$bad);}catch(InvalidArgumentException){check(true,'reject '.$bad);} }
check(Money::format(12345)==='123.45','decimal wire value');
$bank=['bank_code'=>'004','bank_number'=>'1234567890'];
$r=['success'=>true,'data'=>['isDuplicate'=>false,'matchedAccount'=>['bankNumber'=>'123-4-56789-0','bank'=>['code'=>'004']],'amountInSlip'=>'100.00','rawSlip'=>['transRef'=>'TEST-001','receiver'=>['bank'=>['id'=>'004']],'amount'=>['amount'=>'100.00','local'=>['amount'=>'100.00','currency'=>'THB']]]]];
check(SlipDecision::evaluate($r,$bank,10000)['status']==='verified','exact payment');
check(SlipDecision::evaluate($r,$bank,11000)['status']==='underpaid','underpayment');
check(SlipDecision::evaluate($r,$bank,9000)['status']==='overpaid','overpayment');
foreach (['duplicate','wrong_account','wrong_bank','raw_bank','currency','missing','api_error','inconsistent'] as $case) {
 $x=$r;
 switch($case){case 'duplicate':$x['data']['isDuplicate']=true;break;case 'wrong_account':$x['data']['matchedAccount']['bankNumber']='9999999999';break;case 'wrong_bank':$x['data']['matchedAccount']['bank']['code']='014';break;case 'raw_bank':$x['data']['rawSlip']['receiver']['bank']['id']='014';break;case 'currency':$x['data']['rawSlip']['amount']['local']['currency']='USD';break;case 'missing':unset($x['data']['isDuplicate']);break;case 'api_error':$x=['success'=>false];break;case 'inconsistent':$x['data']['rawSlip']['amount']['amount']='99.00';}
 check(SlipDecision::evaluate($x,$bank,10000)['status']!=='verified',$case.' fails closed');
}
$zones=require __DIR__.'/../database/seeders/master.php';
check(count($zones)===5 && count(array_merge(...array_values($zones)))===20,'5 zones and 20 provinces');
require __DIR__.'/extra.php';
echo "$n assertions passed\n";
