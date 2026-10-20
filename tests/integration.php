<?php
/** Run only against a NEW dedicated database ending in _test. Never uses production data. */
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\{DB,Security};
use App\Services\Orders;
$database=getenv('TEST_DB_DATABASE');
if(!$database||!preg_match('/^[a-zA-Z0-9_]+_test$/D',$database)){fwrite(STDERR,"SKIP: TEST_DB_DATABASE must name a dedicated *_test database\n");exit(77);}
putenv('DB_DATABASE='.$database);
$n=0;
function test(bool $ok,string $name):void{global $n;$n++;if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function reject(callable $fn,string $name):void{try{$fn();}catch(InvalidArgumentException){test(true,$name);return;}test(false,$name);}
try{
 foreach(['migrate','seed','migrate','seed'] as $command){passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(ROOT.'/bin/console.php').' '.$command,$status);if($status!==0)throw new RuntimeException('Migration/seed failed');}
 if(DB::one('SELECT id FROM orders LIMIT 1')||DB::one('SELECT id FROM product_variants LIMIT 1')||DB::one('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Requires a newly created empty test database; existing data will not be modified');
 test(count(DB::all('SELECT * FROM zones'))===5,'seed 5 zones');test(count(DB::all('SELECT * FROM provinces'))===20,'seed 20 provinces');test(count(DB::all('SELECT * FROM zone_product_images'))===15,'15 zone image slots');test(DB::one('SELECT COUNT(*) n FROM product_variants')['n']===0,'no invented prices');
 $base=['name'=>'ผู้ทดสอบ ระบบ','phone'=>'0812345678','address'=>'ที่อยู่สำหรับการทดสอบระบบเท่านั้น','zone_id'=>1,'province_id'=>1,'privacy'=>true,'idempotency_key'=>bin2hex(random_bytes(32)),'items'=>[['variant_id'=>1,'quantity'=>2]]];
 reject(fn()=>Orders::create($base),'unconfigured bank blocks checkout');
 // Synthetic fixture ONLY in explicitly selected empty *_test database.
 DB::query("UPDATE bank_settings SET bank_name='TEST ONLY',bank_code='004',bank_number='1234567890',name_th='TEST ONLY',easyslip_account_id='test' WHERE id=1");DB::query("UPDATE app_settings SET value='1' WHERE name='privacy_ready'");
 DB::query('UPDATE product_types SET active=1');DB::query("INSERT INTO product_variants(id,product_type_id,size,price_satang,active) VALUES(1,1,'TEST-S',10001,1),(2,1,'TEST-M',20002,1),(3,2,'TEST-OFF',30000,0),(4,3,'TEST-NOPRICE',NULL,1)");
 $base['items'][]=['variant_id'=>2,'quantity'=>3];$result=Orders::create($base);$order=Orders::access($result['reference'],$result['token']);
 test($order['status']==='pending_payment','new order pending payment');test((int)$order['total_satang']===80008,'multiple sizes exact total, zero shipping');test((int)$order['quantity']===5,'total garment quantity');test(count(DB::all('SELECT * FROM order_items WHERE order_id=?',[$order['id']]))===2,'skip other product types');
 test(Orders::create($base)===$result,'idempotent order submission');test((int)DB::one('SELECT COUNT(*) n FROM orders')['n']===1,'retry creates one order');
 $other=$base;$other['name']='ข้อมูลเปลี่ยน';reject(fn()=>Orders::create($other),'idempotency payload conflict');
 DB::query('UPDATE product_variants SET price_satang=99999 WHERE id=1');test((int)DB::one('SELECT unit_satang FROM order_items WHERE order_id=? AND variant_id=1',[$order['id']])['unit_satang']===10001,'price snapshot immutable');
 foreach([3,4] as $variant){$a=$base;$a['idempotency_key']=bin2hex(random_bytes(32));$a['items']=[['variant_id'=>$variant,'quantity'=>1]];reject(fn()=>Orders::create($a),'disabled or unpriced variant blocked');}
 $a=$base;$a['idempotency_key']=bin2hex(random_bytes(32));$a['province_id']=6;reject(fn()=>Orders::create($a),'province-zone mismatch blocked');$a['province_id']=1;$a['privacy']=false;reject(fn()=>Orders::create($a),'privacy acknowledgement mandatory');
 test(DB::one('SELECT version FROM privacy_acknowledgements WHERE order_id=?',[$order['id']])['version']==='2026-10-20-v1','privacy version recorded');
 $_SESSION=[];test(!Security::can('orders.view'),'anonymous has no privileges');
 DB::query("INSERT INTO users(username,password_hash,role_id) VALUES('test-support',?,3)",[password_hash(bin2hex(random_bytes(16)),PASSWORD_ARGON2ID)]);$uid=(int)DB::connection()->lastInsertId();$_SESSION=['uid'=>$uid,'sv'=>1];test(!Security::can('orders.view'),'support denied without grant');DB::query("INSERT INTO role_permissions(role_id,permission_id) SELECT 3,id FROM permissions WHERE name='orders.view'");test(Security::can('orders.view'),'explicit role grant works');test(!Security::can('users.update'),'reserved privilege unavailable');DB::query('UPDATE users SET session_version=2 WHERE id=?',[$uid]);test(Security::user()===null,'password reset revokes session');
 require __DIR__.'/payments_mysql.php';
 echo "$n integration assertions passed. Test fixtures remain in dedicated test database.\n";
}catch(Throwable $e){fwrite(STDERR,"FAIL ".$e->getMessage()."\n");exit(1);}
