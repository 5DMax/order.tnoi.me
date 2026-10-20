<?php
declare(strict_types=1);
use App\Core\DB;
use App\Services\{Orders,Payments,Money};
function paymentFixture(array $base,string $reference,int $delta=0):array{
 $base['idempotency_key']=bin2hex(random_bytes(32));$result=Orders::create($base);$o=Orders::access($result['reference'],$result['token']);
 DB::query('INSERT INTO payment_attempts(order_id,idempotency_key,filename,file_hash) VALUES(?,?,?,?)',[$o['id'],bin2hex(random_bytes(32)),str_repeat('0',48).'.jpg',bin2hex(random_bytes(32))]);$attempt=(int)DB::connection()->lastInsertId();
 $amount=Money::format((int)$o['total_satang']+$delta);
 $response=['success'=>true,'data'=>['isDuplicate'=>false,'matchedAccount'=>['bankNumber'=>'1234567890','bank'=>['code'=>'004']],'amountInSlip'=>$amount,'rawSlip'=>['transRef'=>$reference,'receiver'=>['bank'=>['id'=>'004']],'amount'=>['amount'=>$amount,'local'=>['amount'=>$amount,'currency'=>'THB']]]]];
 return [$o,$attempt,$response];
}
foreach(['verified'=>0,'underpaid'=>-1,'overpaid'=>1] as $expected=>$delta){[$o,$attempt,$response]=paymentFixture($base,'fixture-'.$expected,$delta);$d=Payments::complete($o,$attempt,$response);test($d['status']===$expected,'mock '.$expected);test(DB::one('SELECT status FROM orders WHERE id=?',[$o['id']])['status']===($expected==='verified'?'paid':$expected),'order state '.$expected);$again=Payments::complete($o,$attempt,$response);test($again['status']===$expected,'idempotent completion '.$expected);}
[$o,$attempt,$response]=paymentFixture($base,'fixture-verified');test(Payments::complete($o,$attempt,$response)['status']==='duplicate','local duplicate claim prevents another order payment');test(DB::one('SELECT status FROM orders WHERE id=?',[$o['id']])['status']==='pending_payment','duplicate cannot pay order');
foreach(['TIMEOUT_OR_NETWORK','QUOTA_EXCEEDED','MISSING_API_KEY','API_SERVER_ERROR'] as $code){[$o,$attempt,$response]=paymentFixture($base,'fixture-'.$code);test(Payments::complete($o,$attempt,['success'=>false,'error'=>['code'=>$code]])['status']==='review','API '.$code.' requires manual review');test(DB::one('SELECT paid_at FROM orders WHERE id=?',[$o['id']])['paid_at']===null,'API '.$code.' never pays');}
foreach(['wrong_account','wrong_bank','duplicate'] as $case){[$o,$attempt,$response]=paymentFixture($base,'fixture-'.$case);if($case==='wrong_account')$response['data']['matchedAccount']['bankNumber']='9999999999';elseif($case==='wrong_bank')$response['data']['rawSlip']['receiver']['bank']['id']='014';else $response['data']['isDuplicate']=true;test(Payments::complete($o,$attempt,$response)['status']===($case==='duplicate'?'duplicate':'rejected'),'mock '.$case);}
// Two independent PHP processes race for the same unique transaction reference.
$jobs=[];$ids=[];
foreach([1,2] as $i){[$o,$attempt,$response]=paymentFixture($base,'fixture-concurrent');$ids[]=$o['id'];$payload=tempnam(sys_get_temp_dir(),'shirt-test-');file_put_contents($payload,json_encode([$o,$attempt,$response]));$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/payment_worker.php',$payload],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$jobs[]=[$proc,$pipes,$payload];}
foreach($jobs as [$proc,$pipes,$payload]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);unlink($payload);test($code===0,'concurrent worker completes '.$err);}
$paid=DB::one("SELECT COUNT(*) n FROM orders WHERE id IN (?,?) AND status='paid'",$ids)['n'];test((int)$paid===1,'concurrent reference pays exactly one order');
