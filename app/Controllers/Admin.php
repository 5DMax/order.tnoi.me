<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{DB,Http,Security};
use App\Services\{Orders,Money,Uploads,EasySlip};
final class Admin {
 public static function login(array $a):array{
  Security::rate('login',10,900);$name=Http::text($a,'username',1,80);$pw=Http::password($a);$u=DB::one('SELECT * FROM users WHERE username=?',[$name]);
  $hash=$u['password_hash']??'$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$MRvWKQ2reWPZp4HyKZdq+H0ds3XcS5sEa7uD7fCz82I';
  if(!password_verify($pw,$hash)||!$u||!$u['active']){DB::audit('login_failed','login');Http::json(['error'=>'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],401);}
  session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['sv']=$u['session_version'];$_SESSION['started']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));DB::audit('login',(string)$u['id']);return ['ok'=>true];
 }
 public static function get(string $module):array {
  $reserved=in_array($module,['users','roles','bank'],true);Security::require($module.'.view',$reserved);
  if($module==='products')return Orders::catalog();
  if($module==='contact')return DB::settings();
  if($module==='bank')return ['bank'=>DB::one('SELECT * FROM bank_settings WHERE id=1'),'key_status'=>getenv('EASYSLIP_API_KEY')?'•••••••• (ตั้งค่าแล้ว)':'ยังไม่ได้ตั้งค่า'];
  if($module==='users')return DB::all('SELECT id,username,role_id,active FROM users ORDER BY id');
  if($module==='roles')return ['permissions'=>DB::all('SELECT * FROM permissions'),'grants'=>DB::all('SELECT * FROM role_permissions'),'roles'=>DB::all('SELECT * FROM roles')];
  $page=Http::integer($_GET['page']??1,1,100000);$offset=($page-1)*30;
  if($module==='audit')return DB::all("SELECT a.*,u.username FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 30 OFFSET $offset");
  if($module==='orders' && isset($_GET['id'])){$o=DB::one('SELECT * FROM orders WHERE id=?',[Http::integer($_GET['id'])]);if(!$o)throw new \InvalidArgumentException('ไม่พบคำสั่งซื้อ');$d=Orders::detail($o);$d['approvals']=DB::all('SELECT a.reason,a.created_at,u.username FROM payment_approvals a JOIN users u ON u.id=a.actor_id WHERE a.order_id=?',[$o['id']]);$d['notes']=DB::all('SELECT n.*,u.username FROM order_notes n JOIN users u ON u.id=n.actor_id WHERE order_id=? ORDER BY n.id DESC',[$o['id']]);return $d;}
  if($module==='payments'){$args=[];$where='';if(!empty($_GET['status'])){$where=' WHERE p.status=?';$args[]=(string)$_GET['status'];}return DB::all("SELECT p.*,o.reference,o.total_satang FROM payment_attempts p JOIN orders o ON o.id=p.order_id $where ORDER BY p.id DESC LIMIT 30 OFFSET $offset",$args);}
  $where=['1=1'];$args=[];
  foreach(['from'=>'>=','to'=>'<='] as $key=>$op)if(!empty($_GET[$key])){$date=(string)$_GET[$key];if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new \InvalidArgumentException('วันที่ไม่ถูกต้อง');$where[]="o.created_at $op ?";$args[]=$date.($key==='from'?' 00:00:00':' 23:59:59');}
  foreach(['zone_id','province_id','status'] as $key)if(!empty($_GET[$key])){$where[]="o.$key=?";$args[]=(string)$_GET[$key];}
  if(!empty($_GET['q'])){$where[]='(o.reference LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)';for($i=0;$i<3;$i++)$args[]='%'.mb_substr((string)$_GET['q'],0,100).'%';}
  $sql=implode(' AND ',$where);
  if($module==='dashboard')return DB::all("SELECT status,COUNT(*) count,COALESCE(SUM(total_satang),0) total FROM orders o WHERE $sql GROUP BY status",$args);
  if($module==='orders')return DB::all("SELECT o.id,o.reference,o.customer_name,o.phone,o.total_satang,o.quantity,o.status,o.created_at,z.name zone,p.name province FROM orders o JOIN zones z ON z.id=o.zone_id JOIN provinces p ON p.id=o.province_id WHERE $sql ORDER BY o.id DESC LIMIT 30 OFFSET $offset",$args);
  throw new \InvalidArgumentException('ไม่พบเมนู');
 }
 public static function update(string $module,array $a):array {
  $reserved=in_array($module,['users','roles','bank'],true);$action=$module==='payments'?'approve':($module==='products'&&($a['action']??'')==='variant'&&empty($a['id'])?'create':'update');$u=Security::require($module.'.'.$action,$reserved);
  if($module==='bank'&&in_array($a['action']??'',['banks','register','sync'],true))return self::bankApi($a);
  DB::tx(function()use($module,$a,$u){
   switch($module){
    case 'products':
     if(($a['action']??'')==='variant'){$id=(int)($a['id']??0);$type=Http::integer($a['product_type_id']??0,1,3);$size=Http::text($a,'size',1,30);$price=Money::parse($a['price']??'');if($price<1)throw new \InvalidArgumentException('ราคาต้องมากกว่า 0');$active=Http::integer($a['active']??0,0,1);if($id&&!DB::one('SELECT id FROM product_variants WHERE id=? AND product_type_id=?',[$id,$type]))throw new \InvalidArgumentException('ไม่พบไซซ์ในประเภทนี้ ไม่สามารถย้ายประเภทสินค้า');if($id)DB::query('UPDATE product_variants SET size=?,price_satang=?,active=? WHERE id=? AND product_type_id=?',[$size,$price,$active,$id,$type]);else DB::query('INSERT INTO product_variants(product_type_id,size,price_satang,active) VALUES(?,?,?,?)',[$type,$size,$price,$active]);}
     elseif(($a['action']??'')==='type')DB::query('UPDATE product_types SET active=? WHERE id=?',[Http::integer($a['active']??0,0,1),Http::integer($a['id']??0,1,3)]);
     elseif(($a['action']??'')==='image'){$f=Uploads::save($_FILES['image']??[]);DB::query('UPDATE zone_product_images SET filename=? WHERE zone_id=? AND product_type_id=?',[$f['filename'],Http::integer($a['zone_id']??0,1,5),Http::integer($a['product_type_id']??0,1,3)]);}else throw new \InvalidArgumentException('คำสั่งไม่ถูกต้อง');break;
    case 'contact':
     $phone=Http::text($a,'contact_phone',9,30);DB::query("UPDATE app_settings SET value=? WHERE name='contact_phone'",[$phone]);if(!empty($_FILES['image']['name'])){$f=Uploads::save($_FILES['image']);DB::query("UPDATE app_settings SET value=? WHERE name='line_qr'",[$f['filename']]);}break;
    case 'bank':
     if(($a['confirm']??'')!=='ยืนยันเปลี่ยนบัญชี')throw new \InvalidArgumentException('กรุณาพิมพ์ ยืนยันเปลี่ยนบัญชี');
     DB::query('SELECT id FROM bank_settings WHERE id=1 FOR UPDATE');
     $type=Http::text($a,'account_type',1,20);if(!in_array($type,['NATURAL','JURISTIC'],true))throw new \InvalidArgumentException('ประเภทบัญชีไม่ถูกต้อง');$number=Http::text($a,'bank_number',6,40);if(!preg_match('/^[0-9 -]+$/D',$number))throw new \InvalidArgumentException('เลขบัญชีไม่ถูกต้อง');
     DB::query('UPDATE bank_settings SET bank_name=?,bank_code=?,bank_number=?,name_th=?,name_en=?,account_type=?,extra_verify=?,easyslip_account_id=NULL WHERE id=1',[Http::text($a,'bank_name',1,100),Http::text($a,'bank_code',3,10),$number,Http::text($a,'name_th',1,150),Http::text($a,'name_en',1,150),$type,Http::text($a,'extra_verify',0,255)]);
     DB::query("UPDATE app_settings SET value=? WHERE name='privacy_ready'",[($a['privacy_ready']??'0')==='1'?'1':'0']);break;
    case 'users':
     DB::query('SELECT id FROM roles WHERE id=1 FOR UPDATE');$id=(int)($a['id']??0);$role=Http::integer($a['role_id']??0,1,3);$active=Http::integer($a['active']??0,0,1);$name=Http::text($a,'username',3,80);if(!preg_match('/^[a-zA-Z0-9_.@-]+$/D',$name))throw new \InvalidArgumentException('ชื่อผู้ใช้ไม่ถูกต้อง');
     $pw=Http::password($a,true);if($pw!==''&&mb_strlen($pw)<14)throw new \InvalidArgumentException('รหัสผ่านอย่างน้อย 14 ตัวอักษร');
     if($id){$old=DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]);if(!$old)throw new \InvalidArgumentException('ไม่พบผู้ใช้');if($id===$u['id']&&($role!==1||!$active))throw new \InvalidArgumentException('ห้ามเปลี่ยนสิทธิ์หรือปิดบัญชีตนเอง');if((int)$old['role_id']===1&&($role!==1||!$active)&&(int)DB::one('SELECT COUNT(*) n FROM users WHERE role_id=1 AND active=1')['n']<=1)throw new \InvalidArgumentException('ห้ามปิด Admin คนสุดท้าย');DB::query('UPDATE users SET username=?,role_id=?,active=?,session_version=session_version+1 WHERE id=?',[$name,$role,$active,$id]);if($pw!=='')DB::query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($pw,PASSWORD_ARGON2ID),$id]);}
     else{if($pw==='')throw new \InvalidArgumentException('กรุณากำหนดรหัสผ่าน');DB::query('INSERT INTO users(username,password_hash,role_id,active) VALUES(?,?,?,?)',[$name,password_hash($pw,PASSWORD_ARGON2ID),$role,$active]);}break;
    case 'roles':
     $role=Http::integer($a['role_id']??0,2,3);$ids=$a['permissions']??[];if(!is_array($ids))throw new \InvalidArgumentException('สิทธิ์ไม่ถูกต้อง');DB::query('SELECT id FROM roles WHERE id=? FOR UPDATE',[$role]);DB::query('DELETE FROM role_permissions WHERE role_id=?',[$role]);foreach(array_unique($ids) as $id)DB::query('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)',[$role,Http::integer($id)]);break;
    case 'orders':
     $id=Http::integer($a['id']??0);$o=DB::one('SELECT status FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$o)throw new \InvalidArgumentException('ไม่พบคำสั่งซื้อ');$note=Http::text($a,'note',1,2000);DB::query('INSERT INTO order_notes(order_id,actor_id,note) VALUES(?,?,?)',[$id,$u['id'],$note]);$status=$a['status']??'';
     if($status!==''){$valid=($o['status']==='paid'&&$status==='fulfilled')||($o['status']==='pending_payment'&&$status==='cancelled');if(!$valid||DB::one("SELECT id FROM payment_attempts WHERE order_id=? AND status='processing'",[$id]))throw new \InvalidArgumentException('เปลี่ยนสถานะไม่ได้');DB::query('UPDATE orders SET status=? WHERE id=?',[$status,$id]);}break;
    case 'payments':
     $id=Http::integer($a['order_id']??0);$reason=Http::text($a,'reason',10,2000);$attempt=Http::integer($a['attempt_id']??0);$ref=Http::text($a,'transaction_reference',1,128);if(!preg_match('/^[A-Za-z0-9_-]+$/D',$ref))throw new \InvalidArgumentException('เลขอ้างอิงไม่ถูกต้อง');
     $o=DB::one('SELECT status FROM orders WHERE id=? FOR UPDATE',[$id]);$p=DB::one('SELECT * FROM payment_attempts WHERE id=? AND order_id=? FOR UPDATE',[$attempt,$id]);if(!$o||!$p||!in_array($o['status'],['pending_payment','underpaid','overpaid','review'],true)||$p['status']==='processing'||DB::one("SELECT id FROM payment_attempts WHERE order_id=? AND status='processing'",[$id]))throw new \InvalidArgumentException('อนุมัติรายการไม่ได้');
     if($p['transaction_reference'] && $p['transaction_reference']!==$ref)throw new \InvalidArgumentException('เลขอ้างอิงขัดแย้งกับผลตรวจ');
     $claim=DB::one('SELECT * FROM payment_reference_claims WHERE transaction_reference=? FOR UPDATE',[$ref]);if($claim&&(int)$claim['order_id']!==$id)throw new \InvalidArgumentException('เลขอ้างอิงถูกใช้แล้ว');if(!$claim)DB::query('INSERT INTO payment_reference_claims(transaction_reference,order_id,attempt_id) VALUES(?,?,?)',[$ref,$id,$attempt]);
     DB::query('INSERT INTO payment_approvals(order_id,attempt_id,actor_id,reason) VALUES(?,?,?,?)',[$id,$attempt,$u['id'],$reason]);DB::query("UPDATE orders SET status='paid',paid_at=NOW() WHERE id=?",[$id]);break;
    default:throw new \InvalidArgumentException('ไม่พบเมนู');
   }
   DB::audit($module.'.'.($module==='payments'?'approve':'update'),(string)($a['id']??$a['order_id']??$a['role_id']??$module));
  });return ['ok'=>true];
 }
 private static function bankApi(array $a):array{
  Security::rate('bank_api',10,300);$api=new EasySlip();$action=$a['action'];if($action==='banks')return $api->request('GET','/banks');
  $b=DB::one('SELECT * FROM bank_settings WHERE id=1');
  if($action==='register'){$data=['bankCode'=>$b['bank_code'],'bankNumber'=>$b['bank_number'],'nameTh'=>$b['name_th'],'nameEn'=>$b['name_en'],'type'=>$b['account_type']];if($b['extra_verify'])$data['extraVerify']=$b['extra_verify'];$r=$api->request('POST','/bank-accounts',$data);$accounts=isset($r['data']['id'])?[$r['data']]:[];}
  else{$r=$api->request('GET','/bank-accounts?limit=100&bankCode='.rawurlencode($b['bank_code']));$accounts=$r['data']['items']??[];}
  $found=null;if(($r['success']??false)===true)foreach($accounts as $account)if(($account['bankCode']??'')===$b['bank_code']&&preg_replace('/[\s-]/','',$account['bankNumber']??'')===preg_replace('/[\s-]/','',$b['bank_number'])){$found=(string)$account['id'];break;}
  DB::tx(function()use($b,$found){$now=DB::one('SELECT * FROM bank_settings WHERE id=1 FOR UPDATE');if($now!==$b)throw new \InvalidArgumentException('ข้อมูลบัญชีเปลี่ยน กรุณาลองใหม่');DB::query('UPDATE bank_settings SET easyslip_account_id=? WHERE id=1',[$found]);DB::audit('bank.connection',$found??'unlinked');});
  return ['ok'=>$found!==null,'account_id'=>$found,'error'=>$found?null:($r['error']['code']??'ไม่พบบัญชีใน 100 รายการแรก กรุณาตรวจสอบใน EasySlip')];
 }
}
