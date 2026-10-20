<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\{DB,Http,Security};
use App\Services\{Orders,Payments,Uploads};
use App\Controllers\Admin;
set_exception_handler(static function(Throwable $e):void{
 $expected=$e instanceof InvalidArgumentException||$e instanceof JsonException;
 if(!$expected)error_log('Application error: '.get_class($e).' code='.$e->getCode());
 Http::json(['error'=>$expected?$e->getMessage():'ระบบไม่พร้อมใช้งาน กรุณาลองใหม่หรือติดต่อเจ้าหน้าที่'],$expected?422:503);
});
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
if($path==='/health'){try{DB::query('SELECT 1');if(!is_writable(ROOT.'/storage/uploads'))throw new RuntimeException();Http::json(['status'=>'ok']);}catch(Throwable){Http::json(['status'=>'unavailable'],503);}}
Security::boot();if($method==='POST')Security::csrf();
if($method==='GET'&&$path==='/') {Http::view('shop');exit;}
if($method==='GET'&&$path==='/privacy'){Http::view('privacy');exit;}
if($method==='GET'&&$path==='/admin'){Http::view(Security::user()?'admin':'login');exit;}
if($method==='GET'&&$path==='/api/catalog')Http::json(Orders::catalog());
if($method==='POST'&&$path==='/api/orders'){Security::rate('order',10,600);Http::json(Orders::create(Http::input()),201);}
if($method==='POST'&&in_array($path,['/api/order','/api/slip'],true)){
 Security::rate('order_access',60,300);$a=Http::input();$o=Orders::access(Http::text($a,'reference',1,40),Http::text($a,'token',64,64));
 if($path==='/api/order')Http::json(Orders::detail($o));Security::rate('slip',10,600);Http::json(Payments::verify($o,$_FILES['image']??[],Http::text($a,'idempotency_key',64,64)));
}
if($method==='POST'&&$path==='/api/login')Http::json(Admin::login(Http::input()));
if($method==='POST'&&$path==='/api/logout'){$_SESSION=[];session_regenerate_id(true);Http::json(['ok'=>true]);}
if(preg_match('#^/api/admin/(dashboard|orders|products|payments|bank|contact|users|roles|audit)$#D',$path,$m)){
 if($method==='GET')Http::json(Admin::get($m[1]));if($method==='POST')Http::json(Admin::update($m[1],Http::input()));
}
if($method==='GET'&&preg_match('#^/media/product/(\d+)/(\d+)$#D',$path,$m)){$f=DB::one('SELECT filename FROM zone_product_images WHERE zone_id=? AND product_type_id=?',[$m[1],$m[2]]);Uploads::serve($f['filename']??'');}
if($method==='GET'&&$path==='/media/line'){Uploads::serve(DB::settings()['line_qr']??'');}
if($method==='GET'&&preg_match('#^/admin/slip/(\d+)$#D',$path,$m)){Security::require('payments.view');$p=DB::one('SELECT filename FROM payment_attempts WHERE id=?',[$m[1]]);Uploads::serve($p['filename']??'');}
Http::json(['error'=>'ไม่พบหน้า'],404);
