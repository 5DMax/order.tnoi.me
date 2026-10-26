<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Http};
final class Payments {
 public static function verify(array $o,array $file,string $key,?EasySlip $api=null):array {
  if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new \InvalidArgumentException('รหัสคำขอไม่ถูกต้อง');
  if($old=DB::one('SELECT id,status,reason FROM payment_attempts WHERE order_id=? AND idempotency_key=?',[$o['id'],$key]))return $old;
  $saved=Uploads::save($file);$used=false;
  try{$attempt=DB::tx(function()use($o,$key,$saved,&$used){
   $locked=DB::one('SELECT status FROM orders WHERE id=? FOR UPDATE',[$o['id']]);
   if($old=DB::one('SELECT id,status,reason FROM payment_attempts WHERE order_id=? AND (idempotency_key=? OR file_hash=?)',[$o['id'],$key,$saved['hash']]))return $old;
   if($locked['status']!=='pending_payment')throw new \InvalidArgumentException('คำสั่งซื้อนี้ต้องติดต่อเจ้าหน้าที่');
   if(DB::one("SELECT id FROM payment_attempts WHERE order_id=? AND status='processing'",[$o['id']]))throw new \InvalidArgumentException('กำลังตรวจสอบสลิป กรุณารอสักครู่');
   if((int)DB::one('SELECT COUNT(*) n FROM payment_attempts WHERE order_id=?',[$o['id']])['n']>=3)throw new \InvalidArgumentException('ครบจำนวนครั้ง กรุณาติดต่อเจ้าหน้าที่');
   DB::query('INSERT INTO payment_attempts(order_id,idempotency_key,filename,file_hash) VALUES(?,?,?,?)',[$o['id'],$key,$saved['filename'],$saved['hash']]);$used=true;return ['id'=>(int)DB::connection()->lastInsertId(),'new'=>true];
  });}finally{if(!$used)@unlink(ROOT.'/storage/uploads/'.$saved['filename']);}
  if(!isset($attempt['new']))return $attempt;
  // Release PHP session lock and all DB locks before remote I/O.
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  try{
   $response=($api??new EasySlip())->verify(ROOT.'/storage/uploads/'.$saved['filename'],(int)$o['total_satang'],$o['reference']);
  }catch(\Throwable){$response=['success'=>false,'error'=>['code'=>'TIMEOUT_OR_NETWORK']];}

  return self::complete($o,(int)$attempt['id'],$response);
 }
 public static function complete(array $o,int $attemptId,array $response):array {
  $attempt=['id'=>$attemptId];
  $decision=SlipDecision::evaluate($response,json_decode($o['bank_snapshot'],true),(int)$o['total_satang']);
  return DB::tx(function()use($o,$attempt,$decision,$response){
   $locked=DB::one('SELECT status FROM orders WHERE id=? FOR UPDATE',[$o['id']]);$current=DB::one('SELECT status,reason FROM payment_attempts WHERE id=? FOR UPDATE',[$attempt['id']]);if($current['status']!=='processing')return $current;
   if($locked['status']!=='pending_payment')$decision=array_replace($decision,['status'=>'review','reason'=>'สถานะคำสั่งซื้อเปลี่ยนแล้ว']);
   if(in_array($decision['status'],['verified','underpaid','overpaid'],true)){
    try{DB::query('INSERT INTO payment_reference_claims(transaction_reference,order_id,attempt_id) VALUES(?,?,?)',[$decision['reference'],$o['id'],$attempt['id']]);}catch(\PDOException $e){if(($e->errorInfo[1]??null)!==1062)throw $e;$decision=array_replace($decision,['status'=>'duplicate','reason'=>'เลขอ้างอิงถูกใช้แล้ว']);}
   }
   $safe=['success'=>$response['success']??false,'error_code'=>$response['error']['code']??null,'decision'=>$decision,'isDuplicate'=>$response['data']['isDuplicate']??null,'matched_bank_code'=>$response['data']['matchedAccount']['bank']['code']??null,'receiver_bank_id'=>$response['data']['rawSlip']['receiver']['bank']['id']??null,'currency'=>$response['data']['rawSlip']['amount']['local']['currency']??null,'receiver_account'=>$response['data']['rawSlip']['receiver']['account']['bank']['account']??null];
   DB::query('UPDATE payment_attempts SET status=?,reason=?,amount_satang=?,transaction_reference=?,result=?,completed_at=NOW() WHERE id=?',[$decision['status'],$decision['reason'],$decision['amount'],$decision['reference'],json_encode($safe),$attempt['id']]);
   $status=['verified'=>'paid','underpaid'=>'underpaid','overpaid'=>'overpaid','review'=>'review'][$decision['status']]??null;
   if($status&&$locked['status']==='pending_payment'){DB::query('UPDATE orders SET status=?,paid_at=CASE WHEN ?=\'paid\' THEN NOW() ELSE paid_at END WHERE id=?',[$status,$status,$o['id']]);DB::audit('order.payment_status',$o['reference']);}
   return $decision;
  });
 }
}
