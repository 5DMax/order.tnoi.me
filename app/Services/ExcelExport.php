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
  $rows=DB::all("SELECT o.id,o.reference,o.customer_name,o.phone,o.address,o.total_satang,o.quantity,o.status,o.created_at,o.paid_at,z.name zone,p.name province FROM orders o JOIN zones z ON z.id=o.zone_id JOIN provinces p ON p.id=o.province_id WHERE $sql ORDER BY o.id DESC LIMIT 5000",$args);
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
 private function buildXml(array $rows,array $items,array $statusLabels):string {
  $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n";
  $xml.='<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:x="urn:schemas-microsoft-com:office:excel">';
  $xml.='<Styles>';
  $xml.='<Style ss:ID="header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1E3A5F" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>';
  $xml.='<Style ss:ID="total"><Font ss:Bold="1"/><NumberFormat ss:Format="#,##0.00"/></Style>';
  $xml.='<Style ss:ID="money"><NumberFormat ss:Format="#,##0.00"/></Style>';
  $xml.='<Style ss:ID="date"><NumberFormat ss:Format="yyyy-mm-dd hh:mm"/></Style>';
  $xml.='<Style ss:ID="wrap"><Alignment ss:WrapText="1"/></Style>';
  $xml.='</Styles>';
  $xml.='<Worksheet ss:Name="คำสั่งซื้อ"><Table>';
  $cols=[80,120,100,130,180,80,60,80,70,80,100,300];
  foreach($cols as $w)$xml.="<Column ss:Width=\"$w\"/>";
  $headers=['เลขคำสั่งซื้อ','วันที่สั่ง','ชำระเงินเมื่อ','ชื่อผู้สั่ง','เบอร์โทร','กลุ่ม','จังหวัด','สถานะ','จำนวน (ตัว)','ยอดรวม (บาท)','รายการสินค้า','ที่อยู่จัดส่ง'];
  $xml.='<Row ss:Height="22">';
  foreach($headers as $h)$xml.='<Cell ss:StyleID="header"><Data ss:Type="String">'.htmlspecialchars($h,ENT_XML1).'</Data></Cell>';
  $xml.='</Row>';
  foreach($rows as $row){
   $orderItems=$items[$row['id']]??[];
   $itemStr=implode(', ',array_map(fn($i)=>$i['product_name'].'/'.$i['size'].' x'.$i['quantity'],$orderItems));
   $xml.='<Row ss:Height="18">';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars($row['reference'],ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars(substr($row['created_at'],0,16),ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars(substr($row['paid_at']??'',0,16),ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars($row['customer_name'],ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars($row['phone'],ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars($row['zone'],ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars($row['province'],ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="String">'.htmlspecialchars($statusLabels[$row['status']]??$row['status'],ENT_XML1).'</Data></Cell>';
   $xml.='<Cell><Data ss:Type="Number">'.(int)$row['quantity'].'</Data></Cell>';
   $xml.='<Cell ss:StyleID="money"><Data ss:Type="Number">'.number_format((int)$row['total_satang']/100,2,'.','').'</Data></Cell>';
   $xml.='<Cell ss:StyleID="wrap"><Data ss:Type="String">'.htmlspecialchars($itemStr,ENT_XML1).'</Data></Cell>';
   $xml.='<Cell ss:StyleID="wrap"><Data ss:Type="String">'.htmlspecialchars($row['address'],ENT_XML1).'</Data></Cell>';
   $xml.='</Row>';
  }
  $totalSatang=array_sum(array_column($rows,'total_satang'));
  $totalQty=array_sum(array_column($rows,'quantity'));
  $xml.='<Row ss:Height="20">';
  $xml.='<Cell ss:StyleID="total"><Data ss:Type="String">รวมทั้งหมด '.count($rows).' คำสั่งซื้อ</Data></Cell>';
  $xml.='<Cell/><Cell/><Cell/><Cell/><Cell/><Cell/><Cell/>';
  $xml.='<Cell ss:StyleID="total"><Data ss:Type="Number">'.$totalQty.'</Data></Cell>';
  $xml.='<Cell ss:StyleID="total"><Data ss:Type="Number">'.number_format($totalSatang/100,2,'.','').'</Data></Cell>';
  $xml.='<Cell/><Cell/>';
  $xml.='</Row>';
  $xml.='</Table></Worksheet></Workbook>';
  return $xml;
 }
}
