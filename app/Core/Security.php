<?php
declare(strict_types=1);
namespace App\Core;
final class Security {
 public static function boot():void {
  $prod=(getenv('APP_ENV')?:'production')==='production';
  if($prod && (!str_starts_with(getenv('APP_URL')?:'','https://')||strlen(getenv('APP_KEY')?:'')<32))throw new \RuntimeException('Invalid production configuration');
  if($prod && (!self::trusted($_SERVER['REMOTE_ADDR']??'')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')!=='https') && ($_SERVER['HTTPS']??'')!=='on'){http_response_code(400);exit('HTTPS required');}
  header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
  header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');if($prod)header('Strict-Transport-Security: max-age=31536000');
  ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_name('shirt_session');session_set_cookie_params(['httponly'=>true,'secure'=>$prod,'samesite'=>'Lax','path'=>'/']);session_start();
  if(isset($_SESSION['uid']) && (time()-($_SESSION['last']??0)>1800||time()-($_SESSION['started']??0)>28800)){$_SESSION=[];session_regenerate_id(true);}
  $_SESSION['last']=time();$_SESSION['csrf']??=bin2hex(random_bytes(32));
 }
 public static function trusted(string $ip):bool {
  $address=@inet_pton($ip);if($address===false)return false;
  foreach(explode(',',getenv('APP_TRUSTED_PROXIES')?:'') as $range){
   $parts=explode('/',trim($range));$network=@inet_pton($parts[0]);if($network===false||strlen($network)!==strlen($address))continue;
   $bits=isset($parts[1])?filter_var($parts[1],FILTER_VALIDATE_INT):strlen($network)*8;
   if($bits===false||$bits<0||$bits>strlen($network)*8)continue;
   $bytes=intdiv($bits,8);$rest=$bits%8;
   if(substr($address,0,$bytes)!==substr($network,0,$bytes))continue;
   if($rest && ((ord($address[$bytes]) ^ ord($network[$bytes])) & (255 << (8-$rest))))continue;
   return true;
  }
  return false;
 }
 public static function clientIp():string {
  $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';
  if(!self::trusted($ip))return $ip;
  $chain=explode(',',$_SERVER['HTTP_X_FORWARDED_FOR']??'');
  foreach(array_reverse($chain) as $candidate){if(!self::trusted($ip))break;$candidate=trim($candidate);if(!filter_var($candidate,FILTER_VALIDATE_IP))break;$ip=$candidate;}
  return $ip;
 }
 public static function csrf():void{$v=$_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['_csrf']??'';if(!is_string($v)||!hash_equals($_SESSION['csrf']??'!', $v))Http::json(['error'=>'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่'],403);}
 public static function rate(string $scope,int $limit,int $seconds):void{
  $bucket=hash_hmac('sha256',$scope.'|'.self::clientIp().'|'.intdiv(time(),$seconds),getenv('APP_KEY')?:'local');
  DB::query('INSERT INTO rate_limits(bucket,hits,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1',[$bucket,time()+$seconds]);
  if((DB::one('SELECT hits FROM rate_limits WHERE bucket=?',[$bucket])['hits']??0)>$limit)Http::json(['error'=>'ทำรายการบ่อยเกินไป กรุณาลองใหม่ภายหลัง'],429);
 }
 public static function user():?array {
  if(!isset($_SESSION['uid']))return null;
  $u=DB::one('SELECT id,username,role_id,session_version FROM users WHERE id=? AND active=1',[$_SESSION['uid']]);
  if(!$u||$u['session_version']!==($_SESSION['sv']??null)){unset($_SESSION['uid']);return null;}return $u;
 }
 public static function can(string $permission):bool{$u=self::user();return $u && ((int)$u['role_id']===1 || (bool)DB::one('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.name=?',[$u['role_id'],$permission]));}
 public static function require(string $permission,bool $admin=false):array{$u=self::user();if(!$u||($admin?(int)$u['role_id']!==1:!self::can($permission)))Http::json(['error'=>'ไม่มีสิทธิ์ดำเนินการ'],403);return $u;}
 public static function token(string $key):string{return hash_hmac('sha256','order-access|'.$key,getenv('APP_KEY')?:'');}
}
