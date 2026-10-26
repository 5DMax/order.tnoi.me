<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Services\{SlipDecision,EasySlip,QrPayload};
function expect(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$b=['bank_code'=>'004','bank_number'=>'1234567890'];
$r=['success'=>true,'data'=>['isDuplicate'=>false,'matchedAccount'=>null,'amountInSlip'=>'100.01','rawSlip'=>['transRef'=>'REF-123','receiver'=>['bank'=>['id'=>'004'],'account'=>['bank'=>['type'=>'BANKAC','account'=>'123-4-56789-0']]],'amount'=>['amount'=>'100.01','local'=>['amount'=>'100.01','currency'=>'THB']]]]];
expect(SlipDecision::evaluate($r,$b,10001)['status']==='verified','full receiver account verified locally without registration');
foreach(['wrong_account','wrong_bank','masked','currency','duplicate','no_duplicate_flag','different_amount'] as $case){$x=$r;switch($case){case 'wrong_account':$x['data']['rawSlip']['receiver']['account']['bank']['account']='0000000000';break;case 'wrong_bank':$x['data']['rawSlip']['receiver']['bank']['id']='014';break;case 'masked':$x['data']['rawSlip']['receiver']['account']['bank']['account']='123-4-xxxxx-0';break;case 'currency':$x['data']['rawSlip']['amount']['local']['currency']='USD';break;case 'duplicate':$x['data']['isDuplicate']=true;break;case 'no_duplicate_flag':unset($x['data']['isDuplicate']);break;case 'different_amount':$x['data']['rawSlip']['amount']['amount']='100.02';}expect(SlipDecision::evaluate($x,$b,10001)['status']===($case==='masked'?'verified':SlipDecision::evaluate($x,$b,10001)['status'])&&($case==='masked'||SlipDecision::evaluate($x,$b,10001)['status']!=='verified'),$case.' response validation');}
expect(SlipDecision::evaluate($r,$b,10002)['status']==='underpaid','underpayment');
expect(SlipDecision::evaluate($r,$b,10000)['status']==='overpaid','overpayment');
$body=EasySlip::encodeJson(['payload'=>'abc','matchAmount'=>'100.01','checkDuplicate'=>true,'matchAccount'=>false]);
expect(str_contains($body,'"matchAmount":100.01'),'matchAmount is exact JSON number');
expect(json_decode($body,true)['checkDuplicate']===true,'boolean stays boolean');
expect(QrPayload::fromOutput("ABC123\n")==='ABC123','read single QR');
foreach(["", "ONE\nTWO\n",str_repeat('a',129),"x\x00y"] as $output){try{QrPayload::fromOutput($output);throw new RuntimeException('invalid QR accepted');}catch(InvalidArgumentException){echo "PASS invalid or ambiguous QR rejected\n";}}

$mock=new class extends EasySlip {
 public array $sent=[];
 public function request(string $method,string $path,?array $data=null,bool $multipart=false):array {$this->sent=[$method,$path,$data,$multipart];return ['success'=>true];}
};
$mock->verifyPayload('QR123',10001,'ORDER123');
expect($mock->sent[0]==='POST'&&$mock->sent[1]==='/verify/bank'&&!$mock->sent[3],'payload uses JSON verify endpoint');
expect($mock->sent[2]['payload']==='QR123'&&$mock->sent[2]['matchAccount']===false&&$mock->sent[2]['checkDuplicate']===true,'request uses local matching and duplicate detection');
expect(str_contains(EasySlip::encodeJson($mock->sent[2]),'"matchAmount":100.01'),'wire amount retains exact satang');
expect(SlipDecision::evaluate(['success'=>false,'error'=>['code'=>'QR_UNREADABLE']],$b,10001)['status']==='review','unreadable QR requires review');
$minimal=EasySlip::decode('{"success":true,"data":{"isDuplicate":false,"rawSlip":{"transRef":"68370160657749I376388B35","amount":{"amount":1500.00},"receiver":{"bank":{"id":"014"},"account":{"name":{"th":"Receiver"}}}}}}');
expect(SlipDecision::evaluate($minimal,['bank_code'=>'014'],150000)['status']==='verified','documented minimal image response verifies');
expect(SlipDecision::evaluate($minimal,['bank_code'=>'004'],150000)['status']!=='verified','minimal response wrong bank rejected');
expect(SlipDecision::evaluate($minimal,['bank_code'=>'014'],150001)['status']==='underpaid','minimal response amount mismatch');
$mock->verify('/tmp/slip.jpg',150000,'ORDER123');
expect($mock->sent[3]===true&&$mock->sent[2]['image'] instanceof CURLFile,'image sent as multipart file');
expect($mock->sent[2]['matchAccount']==='true'&&$mock->sent[2]['checkDuplicate']==='true'&&$mock->sent[2]['matchAmount']==='1500.00','image enables account matching duplicate check and exact amount');
