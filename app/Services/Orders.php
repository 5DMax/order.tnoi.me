<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Http,Security};
final class Orders {
 public static function catalog():array{$bank=DB::one('SELECT * FROM bank_settings WHERE id=1');$settings=DB::settings();return ['ready'=>(bool)($bank&&$bank['bank_number']&&$bank['bank_code']&&$bank['name_th']&&($settings['privacy_ready']??'0')==='1'),'zones'=>DB::all('SELECT * FROM zones ORDER BY id'),'provinces'=>DB::all('SELECT * FROM provinces ORDER BY id'),'types'=>DB::all('SELECT * FROM product_types ORDER BY id'),'variants'=>DB::all('SELECT * FROM product_variants ORDER BY id'),'images'=>DB::all('SELECT zone_id,product_type_id,filename FROM zone_product_images')];}
 public static function create(array $a):array {
  $key=Http::text($a,'idempotency_key',64,64);if(!ctype_xdigit($key))throw new \InvalidArgumentException('รหัสคำขอไม่ถูกต้อง');
  $name=Http::text($a,'name',2,150);$phone=Http::text($a,'phone',9,20);if(!preg_match('/^\+?[0-9]{9,15}$/D',$phone))throw new \InvalidArgumentException('เบอร์โทรไม่ถูกต้อง');$address=Http::text($a,'address',10,2000);
  $zone=Http::integer($a['zone_id']??0,1,5);$province=Http::integer($a['province_id']??0,1,20);
  if(($a['privacy']??false)!==true)throw new \InvalidArgumentException('กรุณารับทราบประกาศความเป็นส่วนตัว');
  $items=$a['items']??[];if(!is_array($items)||!count($items)||count($items)>100)throw new \InvalidArgumentException('กรุณาเลือกสินค้า');
  $normalized=[];foreach($items as $item){if(!is_array($item))throw new \InvalidArgumentException('สินค้าไม่ถูกต้อง');$id=Http::integer($item['variant_id']??0);$qty=Http::integer($item['quantity']??0,1,999);if(isset($normalized[$id]))throw new \InvalidArgumentException('รายการไซซ์ซ้ำ');$normalized[$id]=$qty;}ksort($normalized);
  $hash=hash('sha256',json_encode([$name,$phone,$address,$zone,$province,$normalized]));
  return DB::tx(function()use($key,$name,$phone,$address,$zone,$province,$normalized,$hash){
   // Serialize checkout setup and idempotency; external calls never run here.
   $bank=DB::one('SELECT * FROM bank_settings WHERE id=1 FOR UPDATE');
   if($old=DB::one('SELECT reference,request_hash FROM orders WHERE idempotency_key=?',[$key])){if(!hash_equals($old['request_hash'],$hash))throw new \InvalidArgumentException('รหัสคำขอนี้ใช้กับข้อมูลอื่นแล้ว');return ['reference'=>$old['reference'],'token'=>Security::token($key)];}
   $settings=DB::settings();if(!$bank||!$bank['bank_number']||!$bank['bank_code']||!$bank['name_th']||($settings['privacy_ready']??'0')!=='1')throw new \InvalidArgumentException('ระบบยังไม่พร้อมรับคำสั่งซื้อ กรุณาติดต่อเจ้าหน้าที่');
   if(!DB::one('SELECT id FROM provinces WHERE id=? AND zone_id=?',[$province,$zone]))throw new \InvalidArgumentException('จังหวัดไม่ตรงกับกลุ่ม');
   $rows=[];$total=0;$count=0;
   foreach($normalized as $id=>$qty){$v=DB::one('SELECT v.*,p.name,p.active AS product_active FROM product_variants v JOIN product_types p ON p.id=v.product_type_id WHERE v.id=? FOR SHARE',[$id]);if(!$v||!$v['active']||!$v['product_active']||!$v['price_satang'])throw new \InvalidArgumentException('สินค้าไม่มีราคาหรือปิดขาย');$total+=(int)$v['price_satang']*$qty;$count+=$qty;$rows[]=[$v,$qty];}
   if($total>9999999999||$count>9999)throw new \InvalidArgumentException('จำนวนสินค้ามากเกินไป');
   $ref='SS'.date('ymd').strtoupper(bin2hex(random_bytes(8)));$token=Security::token($key);
   DB::query('INSERT INTO orders(reference,token_hash,idempotency_key,request_hash,zone_id,province_id,customer_name,phone,address,total_satang,quantity,bank_snapshot) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$ref,hash('sha256',$token),$key,$hash,$zone,$province,$name,$phone,$address,$total,$count,json_encode($bank)]);$id=(int)DB::connection()->lastInsertId();
   foreach($rows as [$v,$qty])DB::query('INSERT INTO order_items(order_id,variant_id,product_name,size,quantity,unit_satang) VALUES(?,?,?,?,?,?)',[$id,$v['id'],$v['name'],$v['size'],$qty,$v['price_satang']]);
   DB::query('INSERT INTO privacy_acknowledgements(order_id,version) VALUES(?,?)',[$id,'2026-10-20-v1']);
   return ['reference'=>$ref,'token'=>$token];
  });
 }
 public static function access(string $ref,string $token):array {$o=DB::one('SELECT * FROM orders WHERE reference=?',[$ref]);if(!$o||!hash_equals($o['token_hash'],hash('sha256',$token)))Http::json(['error'=>'ไม่พบคำสั่งซื้อหรือรหัสเข้าถึงไม่ถูกต้อง'],404);return $o;}
 public static function detail(array $o):array {unset($o['token_hash'],$o['idempotency_key'],$o['request_hash']);$o['items']=DB::all('SELECT product_name,size,quantity,unit_satang FROM order_items WHERE order_id=?',[$o['id']]);$o['attempts']=DB::all('SELECT id,status,reason,amount_satang,created_at FROM payment_attempts WHERE order_id=? ORDER BY id DESC',[$o['id']]);$o['bank']=json_decode($o['bank_snapshot'],true);unset($o['bank_snapshot'],$o['bank']['extra_verify']);$o['contact']=DB::settings();return $o;}
}
