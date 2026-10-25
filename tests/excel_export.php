<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Services\ExcelExport;
function checkExport(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$base=['reference'=>'ORD1','created_at'=>'2026-10-25 12:00:00','customer_name'=>'=1+1 & <test>','phone'=>'0812345678','zone'=>'กลุ่ม 1','province'=>'จังหวัด A','status'=>'paid','address'=>"address\x01"];
$rows=[['id'=>1]+$base,['id'=>2,'reference'=>'ORD2']+$base,['id'=>3,'zone'=>'กลุ่ม 2']+$base,['id'=>4,'status'=>'cancelled']+$base];
$item=['product_name'=>'Polo','size'=>'M','quantity'=>2,'unit_satang'=>10001];
$items=[1=>[$item,['size'=>'L','quantity'=>3]+$item],2=>[$item],3=>[$item],4=>[$item]];
$export=new ExcelExport();$xml=$export->buildXml($rows,$items,['paid'=>'ชำระแล้ว','cancelled'=>'ยกเลิก']);
$doc=new DOMDocument();checkExport($doc->loadXML($xml),'valid XML including escaped user data');
$xp=new DOMXPath($doc);$xp->registerNamespace('s','urn:schemas-microsoft-com:office:spreadsheet');
checkExport($xp->query('//s:Worksheet')->length===3,'one tab per zone plus summary');
checkExport($xp->evaluate('string((//s:Worksheet)[last()]/@s:Name)')==='สรุป','summary is last');
checkExport($xp->query('//s:Worksheet[@s:Name="กลุ่ม 1"]/s:Table/s:Row')->length===5,'each product size is a separate row');
checkExport($xp->evaluate('string((//s:Worksheet[1]/s:Table/s:Row)[2]/s:Cell[4]/s:Data)')==='0812345678','phone leading zero retained');
checkExport($xp->query('//@s:Formula')->length===0,'customer text never becomes a formula');
checkExport(str_contains($xml,'200.02'),'line amount uses exact satang');
$sum=[];foreach($xp->query('//s:Worksheet[@s:Name="สรุป"]/s:Table/s:Row[position()>1]') as $row){$v=[];foreach($xp->query('s:Cell/s:Data',$row) as $cell)$v[]=$cell->textContent;$sum[]=$v;}
checkExport(count($sum)===4,'summary separates product size zone and status');
checkExport(in_array(['กลุ่ม 1','จังหวัด A','Polo','M','ชำระแล้ว','4'],$sum,true),'same zone province shirt size status aggregate');
checkExport(array_sum(array_column($sum,5))===11,'summary reconciles to item quantities');
checkExport($doc->loadXML($export->buildXml([],[],[])),'empty export is valid');
$weird=[['id'=>1,'zone'=>'A/B']+$base,['id'=>2,'zone'=>'A?B']+$base];
checkExport($doc->loadXML($export->buildXml($weird,$items,[])),'sanitized duplicate tab names valid');
$names=[];foreach($doc->getElementsByTagName('Worksheet') as $sheet)$names[]=$sheet->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet','Name');
checkExport(count($names)===count(array_unique($names)),'tab names unique after sanitizing');
