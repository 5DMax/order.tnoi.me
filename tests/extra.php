<?php
declare(strict_types=1);
use App\Core\Http;
use App\Core\Security;
use App\Services\{Money,SlipDecision,EasySlip};
check(Money::parse('0.01')===1,'smallest satang');
check(Money::parse('99999999.99')===9999999999,'maximum amount');
check(Money::parse('1.1')===110,'one decimal digit');
foreach([true,1.25,[],null,'01',' 1','1.','+1'] as $bad){try{Money::parse($bad);check(false,'strict money type');}catch(InvalidArgumentException){check(true,'strict money type');}}
foreach(['-1','1.5','1e3',0,1000,true] as $bad){try{Http::integer($bad,1,999);check(false,'invalid quantity');}catch(InvalidArgumentException){check(true,'invalid quantity');}}
check(Http::integer('12',1,999)===12,'valid quantity');
check(strlen(Security::token(str_repeat('a',64)))===64,'256-bit access token');
check(Security::token(str_repeat('a',64))!==Security::token(str_repeat('b',64)),'access tokens isolated');
check(Security::token(str_repeat('a',64))===Security::token(str_repeat('a',64)),'retry derives same access token');
foreach(['matchedAccount','rawSlip','amountInSlip'] as $missing){$x=$r;unset($x['data'][$missing]);check(SlipDecision::evaluate($x,$bank,10000)['status']==='review','missing '.$missing.' requires review');}
$x=$r;$x['data']['matchedAccount']['bankNumber']='123-4-xxxxx-0';check(SlipDecision::evaluate($x,$bank,10000)['status']==='rejected','masked matched account cannot authorize');
$x=$r;$x['data']['isDuplicate']='false';check(SlipDecision::evaluate($x,$bank,10000)['status']==='review','duplicate flag must be boolean');
$x=$r;$x['data']['rawSlip']['transRef']='';check(SlipDecision::evaluate($x,$bank,10000)['status']==='review','empty reference rejected');
putenv('EASYSLIP_API_KEY=');check((new EasySlip())->verify('/nonexistent.jpg',10000,'test')['error']['code']==='MISSING_API_KEY','missing key never sends request');
check(method_exists(EasySlip::class,'decode'),'lossless response decoder exists');
$decoded=EasySlip::decode('{"success":true,"data":{"amountInSlip":123.45,"number":100,"name":"a: 12.34, b","list":[1.01,2.02]}}');
check($decoded['data']['amountInSlip']==='123.45','API decimal lexeme preserved');
check($decoded['data']['name']==='a: 12.34, b','JSON strings preserved');
check($decoded['data']['list']===['1.01','2.02'],'array numeric lexemes preserved');
check($decoded['success']===true,'boolean preserved');
$x=$r;$x['data']['isDuplicate']=true;check(SlipDecision::evaluate($x,$bank,10000)['reference']==='TEST-001','duplicate retains provider reference');
check(method_exists(Security::class,'clientIp'),'trusted proxy resolution exists');
putenv('APP_TRUSTED_PROXIES=172.20.0.0/16');
$_SERVER['REMOTE_ADDR']='172.20.0.2';$_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.1';check(Security::clientIp()==='203.0.113.1','trusted proxy identifies client');
$_SERVER['REMOTE_ADDR']='203.0.113.2';check(Security::clientIp()==='203.0.113.2','untrusted peer cannot spoof forwarded IP');
$_SERVER['REMOTE_ADDR']='172.20.0.2';$_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.99, 203.0.113.3';check(Security::clientIp()==='203.0.113.3','forged leftmost address ignored');
check(method_exists(Http::class,'password'),'password validation exists');
check(Http::password(['password'=>' leading and trailing '])===' leading and trailing ','password whitespace preserved');
