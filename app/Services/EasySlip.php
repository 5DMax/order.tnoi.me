<?php
declare(strict_types=1);
namespace App\Services;
class EasySlip {
 public function request(string $method,string $path,?array $data=null,bool $multipart=false):array{
  $key=getenv('EASYSLIP_API_KEY');if(!$key)return ['success'=>false,'error'=>['code'=>'MISSING_API_KEY']];
  $c=curl_init('https://api.easyslip.com/v2'.$path);$headers=['Authorization: Bearer '.$key,'Accept: application/json'];if(!$multipart)$headers[]='Content-Type: application/json';
  curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true]);if($data!==null)curl_setopt($c,CURLOPT_POSTFIELDS,$multipart?$data:json_encode($data,JSON_THROW_ON_ERROR));
  $raw=curl_exec($c);$http=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
  if($raw===false)return ['success'=>false,'error'=>['code'=>'TIMEOUT_OR_NETWORK']];
  if(strlen($raw)>1048576)return ['success'=>false,'error'=>['code'=>'INVALID_RESPONSE']];
  try{$r=self::decode($raw);}catch(\JsonException){return ['success'=>false,'error'=>['code'=>'INVALID_RESPONSE']];}
  if(!is_array($r)||$http<200||$http>=300)return ['success'=>false,'error'=>['code'=>is_array($r)?($r['error']['code']??'HTTP_ERROR'):'INVALID_RESPONSE']];return $r;
 }
 public static function decode(string $raw):array {
  // Quote numeric JSON lexemes before decoding; strings and booleans are unchanged.
  $raw=preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|(?<=[\[:,])\s*(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(?=\s*[,}\]])/',static fn($m)=>'"'.trim($m[1]).'"',$raw);
  $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($value))throw new \JsonException('Invalid response');
  return $value;
 }
 public function verify(string $file,int $satang,string $reference):array{return $this->request('POST','/verify/bank',['image'=>new \CURLFile($file,'image/jpeg','slip.jpg'),'matchAccount'=>'true','matchAmount'=>Money::format($satang),'checkDuplicate'=>'true','remark'=>$reference],true);}
 public function verifyPayload(string $payload,int $satang,string $reference):array{return $this->request('POST','/verify/bank',['payload'=>$payload,'matchAmount'=>Money::format($satang),'matchAccount'=>'true','checkDuplicate'=>'true','remark'=>$reference]);}
}
