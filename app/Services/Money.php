<?php
declare(strict_types=1);
namespace App\Services;
final class Money {
 public static function parse(mixed $value):int {
  if(!is_string($value)&&!is_int($value))throw new \InvalidArgumentException('จำนวนเงินต้องเป็นทศนิยมที่ถูกต้อง');
  if(!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D',(string)$value,$m))throw new \InvalidArgumentException('จำนวนเงินไม่ถูกต้อง');
  return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
 }
 public static function format(int $satang):string{return intdiv($satang,100).'.'.str_pad((string)($satang%100),2,'0',STR_PAD_LEFT);}
}
