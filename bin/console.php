<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Core\DB;
$cmd=$argv[1]??'';
try{
 if($cmd==='migrate'){
  DB::query('CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
  if(!DB::one("SELECT GET_LOCK('shirt_migrations',30) AS acquired")['acquired'])throw new RuntimeException('Migration lock unavailable');
  try{foreach(glob(ROOT.'/database/migrations/*.sql') as $file){$v=basename($file);if(DB::one('SELECT 1 FROM schema_migrations WHERE version=?',[$v]))continue;foreach(explode(';',file_get_contents($file)) as $sql)if(trim($sql)!=='')DB::query($sql);DB::query('INSERT INTO schema_migrations(version) VALUES(?)',[$v]);echo "$v applied\n";}}finally{DB::query("SELECT RELEASE_LOCK('shirt_migrations')");}
 }elseif($cmd==='seed'){
  DB::tx(function(){ $z=0;$p=0;foreach(require ROOT.'/database/seeders/master.php' as $zone=>$provinces){$z++;DB::query('INSERT IGNORE INTO zones(id,name) VALUES(?,?)',[$z,$zone]);foreach($provinces as $province)DB::query('INSERT IGNORE INTO provinces(id,zone_id,name) VALUES(?,?,?)',[++$p,$z,$province]);}
   foreach([1=>'Polo',2=>'เสื้อคอกลม',3=>'Jacket',4=>'เสื้อนักกีฬา'] as $id=>$name){DB::query('INSERT IGNORE INTO product_types(id,name) VALUES(?,?)',[$id,$name]);for($i=1;$i<=5;$i++)DB::query('INSERT IGNORE INTO zone_product_images(zone_id,product_type_id) VALUES(?,?)',[$i,$id]);}
   foreach([1=>'Admin',2=>'Sales',3=>'Support'] as $id=>$name)DB::query('INSERT IGNORE INTO roles(id,name) VALUES(?,?)',[$id,$name]);
   foreach(['dashboard.view','orders.view','orders.update','products.view','products.create','products.update','payments.view','payments.approve','contact.view','contact.update','audit.view'] as $p)DB::query('INSERT IGNORE INTO permissions(name) VALUES(?)',[$p]);
   DB::query('INSERT IGNORE INTO bank_settings(id) VALUES(1)');foreach(['contact_phone'=>'','line_qr'=>'','privacy_ready'=>'0'] as $k=>$v)DB::query('INSERT IGNORE INTO app_settings(name,value) VALUES(?,?)',[$k,$v]);
  });echo "Seed complete; no passwords or prices created\n";
 }elseif($cmd==='admin:create'){
  if(!stream_isatty(STDIN))throw new RuntimeException('Use an interactive terminal');
  echo 'Username: ';$name=trim(fgets(STDIN));if(!preg_match('/^[a-zA-Z0-9_.@-]{3,80}$/D',$name))throw new RuntimeException('Invalid username');
  echo 'Password (minimum 14 characters): ';system('stty -echo');try{$pw=rtrim(fgets(STDIN),"\r\n");}finally{system('stty echo');echo "\n";}
  if(mb_strlen($pw)<14||mb_strlen($pw)>200)throw new RuntimeException('Password must contain 14–200 characters');
  DB::tx(function()use($name,$pw){DB::query('SELECT id FROM roles WHERE id=1 FOR UPDATE');if(DB::one('SELECT id FROM users WHERE role_id=1'))throw new RuntimeException('First admin already exists; use admin management');DB::query('INSERT INTO users(username,password_hash,role_id) VALUES(?,?,1)',[$name,password_hash($pw,PASSWORD_ARGON2ID)]);DB::audit('first_admin_created',(string)DB::connection()->lastInsertId());});echo "Admin created\n";
 }elseif($cmd==='maintenance') {DB::query('DELETE FROM rate_limits WHERE expires_at<?',[time()]);DB::query("UPDATE payment_attempts SET status='review',reason='การตรวจสอบขาดการเชื่อมต่อ ติดต่อเจ้าหน้าที่',completed_at=NOW() WHERE status='processing' AND created_at < DATE_SUB(NOW(),INTERVAL 5 MINUTE)");echo "Maintenance complete\n";
 }else{echo "Commands: migrate, seed, admin:create, maintenance\n";exit(1);}
}catch(Throwable $e){fwrite(STDERR,"Command failed: ".$e->getMessage()."\n");exit(1);}
