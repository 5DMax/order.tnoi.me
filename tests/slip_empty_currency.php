<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Services\{EasySlip,SlipDecision};
$bank=['bank_code'=>'006','bank_number'=>'1234567890'];
$r=EasySlip::decode('{"success":true,"data":{"isDuplicate":false,"matchedAccount":{"bankNumber":"1234567890","bank":{"code":"006"}},"rawSlip":{"transRef":"N006786933095099867232007","receiver":{"bank":{"id":"006"},"account":{"bank":{"type":"BANKAC","account":"XXX-X-XX991-6"}}},"amount":{"amount":1.00,"local":{"amount":0,"currency":""}}}}}');
function verifyCase(array $r,array $bank,int $total,string $expected,string $label):void {
 $actual=SlipDecision::evaluate($r,$bank,$total)['status'];
 if($actual!==$expected)throw new RuntimeException("$label: expected $expected, got $actual");
 echo "PASS $label\n";
}
verifyCase($r,$bank,100,'verified','empty optional local currency uses primary amount');
verifyCase($r,$bank,101,'underpaid','primary amount mismatch still blocked');
$x=$r;$x['data']['isDuplicate']=true;verifyCase($x,$bank,100,'duplicate','duplicate still blocked');
$x=$r;$x['data']['rawSlip']['amount']['local']=['currency'=>'USD','amount'=>'1.00'];verifyCase($x,$bank,100,'review','explicit foreign currency requires review');
$x=$r;$x['data']['rawSlip']['amount']['local']=['currency'=>'THB','amount'=>'2.00'];verifyCase($x,$bank,100,'review','explicit THB contradiction requires review');
