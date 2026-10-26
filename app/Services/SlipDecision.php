<?php
declare(strict_types=1);
namespace App\Services;
final class SlipDecision {
 public static function evaluate(array $r,array $bank,int $total):array {
  $result=['status'=>'review','reason'=>'ข้อมูลไม่ครบถ้วน รอเจ้าหน้าที่ตรวจสอบ','amount'=>null,'reference'=>null];
  if(($r['success']??null)!==true){
   if(($r['error']['code']??null)==='QR_UNREADABLE')$result['reason']='อ่าน QR บนสลิปไม่สำเร็จ เก็บรูปไว้ให้เจ้าหน้าที่ตรวจสอบแล้ว';
   return $result;
  }
  $d=$r['data']??[];$a=$d['matchedAccount']??null;$s=$d['rawSlip']??[];
  if(is_string($s['transRef']??null)&&preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$s['transRef']))$result['reference']=$s['transRef'];
  try{$result['amount']=Money::parse($s['amount']['amount']??null);}catch(\InvalidArgumentException){}
  if(($d['isDuplicate']??null)===true)return array_replace($result,['status'=>'duplicate','reason'=>'สลิปซ้ำ']);
  if(($d['isDuplicate']??null)!==false)return $result;
  $number=static fn($v)=>preg_replace('/[\s-]/','',(string)$v);

  if(is_array($a)){
   if($number($a['bankNumber']??'')!==$number($bank['bank_number'])||($a['bank']['code']??null)!==$bank['bank_code'])return array_replace($result,['status'=>'rejected','reason'=>'บัญชีหรือธนาคารผู้รับไม่ตรง']);

  }
  $receiver=$s['receiver']['account']['bank']??[];
  $rawNumber=$number($receiver['account']??'');
  if(($receiver['type']??null)==='BANKAC'&&preg_match('/^[0-9]{6,40}$/D',$rawNumber)){
   if($rawNumber!==$number($bank['bank_number']))return array_replace($result,['status'=>'rejected','reason'=>'เลขบัญชีผู้รับไม่ตรง']);

  }
  if(isset($s['receiver']['bank']['id']) && $s['receiver']['bank']['id']!==$bank['bank_code'])return array_replace($result,['status'=>'rejected','reason'=>'ธนาคารในสลิปขัดแย้ง']);
  if(($s['receiver']['bank']['id']??null)!==($bank['bank_code']??null))return $result;
  // Empty local currency means the optional local amount is not populated.
  $currency=$s['amount']['local']['currency']??'';
  if(($currency!==''&&$currency!=='THB')||!is_string($s['transRef']??null)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$s['transRef']))return $result;
  try{$amount=Money::parse($s['amount']['amount']??null);if(isset($d['amountInSlip'])&&$amount!==Money::parse($d['amountInSlip']))return $result;if($currency==='THB'&&isset($s['amount']['local']['amount'])&&$amount!==Money::parse($s['amount']['local']['amount']))return $result;}catch(\InvalidArgumentException){return $result;}
  return ['status'=>$amount===$total?'verified':($amount<$total?'underpaid':'overpaid'),'reason'=>$amount===$total?'ตรวจสอบสำเร็จ':'ยอดไม่ตรง กรุณาติดต่อเจ้าหน้าที่','amount'=>$amount,'reference'=>$s['transRef']];
 }
}
