<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class ExcelExport {
 public function orders(array $filters):void {
  $where=['1=1'];$args=[];
  foreach(['from'=>'>=','to'=>'<='] as $key=>$op){
   if(!empty($filters[$key])){
    $date=(string)$filters[$key];
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new \InvalidArgumentException('วันที่ไม่ถูกต้อง');
    $where[]="o.created_at $op ?";$args[]=$date.($key==='from'?' 00:00:00':' 23:59:59');
   }
  }
  foreach(['zone_id','province_id','status'] as $key){
   if(!empty($filters[$key])){$where[]="o.$key=?";$args[]=(string)$filters[$key];}
  }
  if(!empty($filters['q'])){
   $where[]='(o.reference LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)';
   for($i=0;$i<3;$i++)$args[]='%'.mb_substr((string)$filters['q'],0,100).'%';
  }
  $sql=implode(' AND ',$where);
  $rows=DB::all("SELECT o.id,o.reference,o.customer_name,o.phone,o.address,o.total_satang,o.quantity,o.status,o.created_at,o.paid_at,z.name zone,p.name province FROM orders o JOIN zones z ON z.id=o.zone_id JOIN provinces p ON p.id=o.province_id WHERE $sql ORDER BY o.id DESC LIMIT 5001",$args);
  if(count($rows)>5000)throw new \InvalidArgumentException('ข้อมูลเกิน 5,000 คำสั่งซื้อ กรุณากรองช่วงวันที่หรือกลุ่มให้แคบลง');
  $orderIds=array_column($rows,'id');$items=[];
  if($orderIds){
   $placeholders=implode(',',array_fill(0,count($orderIds),'?'));
   foreach(DB::all("SELECT order_id,product_name,size,quantity,unit_satang FROM order_items WHERE order_id IN ($placeholders) ORDER BY order_id,id",$orderIds) as $item){$items[$item['order_id']][]=$item;}
  }
  $statusLabels=['pending_payment'=>'รอชำระเงิน','paid'=>'ชำระแล้ว','underpaid'=>'ยอดขาด','overpaid'=>'ยอดเกิน','review'=>'รอตรวจสอบ','cancelled'=>'ยกเลิก','fulfilled'=>'จัดส่งแล้ว'];
  $xml=$this->buildXml($rows,$items,$statusLabels);
  $filename='orders_'.date('Ymd_His').'.xls';
  header('Content-Type: application/vnd.ms-excel');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Cache-Control: max-age=0');
  echo $xml;
 }
 public function buildXml(array $rows,array $items,array $statusLabels):string {
  $xml='<?xml version="1.0" encoding="UTF-8"?>';
  $xml.='<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:x="urn:schemas-microsoft-com:office:excel"><Styles><Style ss:ID="header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1E3A5F" ss:Pattern="Solid"/></Style><Style ss:ID="money"><NumberFormat ss:Format="#,##0.00"/></Style><Style ss:ID="wrap"><Alignment ss:WrapText="1" ss:Vertical="Top"/></Style></Styles>';
  $groups=[];$summary=[];
  foreach($rows as $row){
   $zone=(string)$row['zone'];
   $groups[$zone]??=[];
   foreach($items[$row['id']]??[] as $item){
    $qty=(int)$item['quantity'];$unit=(int)$item['unit_satang'];
    $status=$statusLabels[$row['status']]??$row['status'];
    $groups[$zone][]=[(string)$row['reference'],substr($row['created_at'],0,16),(string)$row['customer_name'],(string)$row['phone'],$zone,(string)$row['province'],$status,(string)$item['product_name'],(string)$item['size'],$qty,['money'=>Money::format($unit)],['money'=>Money::format($unit*$qty)],(string)$row['address']];
    $key=json_encode([$zone,$row['province'],$item['product_name'],$item['size'],$row['status']],JSON_THROW_ON_ERROR);
    $summary[$key]??=[$zone,(string)$row['province'],(string)$item['product_name'],(string)$item['size'],$status,0];
    $summary[$key][5]+=$qty;
   }
  }
  ksort($groups,SORT_NATURAL);
  $headers=['เลขคำสั่งซื้อ','วันที่สั่ง','ผู้สั่ง','เบอร์โทร','กลุ่ม','จังหวัด','สถานะ','ประเภทเสื้อ','ไซซ์','จำนวน (ตัว)','ราคาต่อตัว (บาท)','ยอดรายการ (บาท)','ที่อยู่จัดส่ง'];
  $used=['สรุป'=>true];
  if(!$groups)$groups['รายการ']=[];
  foreach($groups as $zone=>$details){
   $base=trim(preg_replace('/[\\\\\/?:*\[\]\x00-\x1F]/u',' ',(string)$zone)," '");
   $base=mb_substr($base!==''?$base:'กลุ่ม',0,31);$name=$base;$suffix=1;
   while(isset($used[mb_strtolower($name)])){$tail=' ('.++$suffix.')';$name=mb_substr($base,0,31-mb_strlen($tail)).$tail;}
   $used[mb_strtolower($name)]=true;
   $xml.=$this->sheet($name,$headers,$details,[130,110,150,100,100,110,100,110,65,85,110,110,250]);
  }
  $summary=array_values($summary);
  usort($summary,static fn($a,$b)=>array_slice($a,0,5)<=>array_slice($b,0,5));
  $xml.=$this->sheet('สรุป',['กลุ่ม','จังหวัด','ประเภทเสื้อ','ไซซ์','สถานะ','จำนวน (ตัว)'],$summary,[110,130,120,75,110,100]);
  return $xml.'</Workbook>';
 }
 private function sheet(string $name,array $headers,array $rows,array $widths):string {
  $xml='<Worksheet ss:Name="'.$this->escape($name).'"><Table>';
  foreach($widths as $width)$xml.='<Column ss:Width="'.$width.'"/>';
  $xml.='<Row ss:Height="28">';
  foreach($headers as $header)$xml.='<Cell ss:StyleID="header"><Data ss:Type="String">'.$this->escape($header).'</Data></Cell>';
  $xml.='</Row>';
  foreach($rows as $row){
   $xml.='<Row>';
   foreach($row as $value){
    $money=is_array($value);$numeric=$money||is_int($value);
    $xml.='<Cell ss:StyleID="'.($money?'money':'wrap').'"><Data ss:Type="'.($numeric?'Number':'String').'">'.$this->escape((string)($money?$value['money']:$value)).'</Data></Cell>';
   }
   $xml.='</Row>';
  }
  return $xml.'</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions></Worksheet>';
 }
 private function escape(string $value):string {
  $value=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
  return htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 }
}
