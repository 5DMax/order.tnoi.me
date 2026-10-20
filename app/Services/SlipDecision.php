<?php
declare(strict_types=1);
namespace App\Services;
final class SlipDecision {
 public static function evaluate(array $r,array $bank,int $total):array {
  $result=['status'=>'review','reason'=>'ข้อมูลไม่ครบถ้วน รอเจ้าหน้าที่ตรวจสอบ','amount'=>null,'reference'=>null];
  if(($r['success']??null)!==true)return $result;
  $d=$r['data']??[];$a=$d['matchedAccount']??null;$s=$d['rawSlip']??[];
  if(is_string($s['transRef']??null)&&preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$s['transRef']))$result['reference']=$s['transRef'];
  try{$result['amount']=Money::parse($d['amountInSlip']??null);}catch(\InvalidArgumentException){}
  if(($d['isDuplicate']??null)===true)return array_replace($result,['status'=>'duplicate','reason'=>'สลิปซ้ำ']);
  if(($d['isDuplicate']??null)!==false || !is_array($a))return $result;
  $number=static fn($v)=>preg_replace('/[\s-]/','',(string)$v);
  if($number($a['bankNumber']??'')!==$number($bank['bank_number'])||($a['bank']['code']??null)!==$bank['bank_code'])return array_replace($result,['status'=>'rejected','reason'=>'บัญชีหรือธนาคารผู้รับไม่ตรง']);
  if(isset($s['receiver']['bank']['id']) && $s['receiver']['bank']['id']!==$bank['bank_code'])return array_replace($result,['status'=>'rejected','reason'=>'ธนาคารในสลิปขัดแย้ง']);
  if(($s['amount']['local']['currency']??null)!=='THB'||!is_string($s['transRef']??null)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$s['transRef']))return $result;
  try{$amount=Money::parse($d['amountInSlip']??null);if($amount!==Money::parse($s['amount']['amount']??null)||$amount!==Money::parse($s['amount']['local']['amount']??null))return $result;}catch(\InvalidArgumentException){return $result;}
  return ['status'=>$amount===$total?'verified':($amount<$total?'underpaid':'overpaid'),'reason'=>$amount===$total?'ตรวจสอบสำเร็จ':'ยอดไม่ตรง กรุณาติดต่อเจ้าหน้าที่','amount'=>$amount,'reference'=>$s['transRef']];
 }
}
