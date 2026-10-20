<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class DB {
 private static ?PDO $pdo=null;
 public static function connection():PDO {
  if(!self::$pdo){self::$pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'localhost').';port='.(getenv('DB_PORT')?:'3306').';dbname='.getenv('DB_DATABASE').';charset=utf8mb4',getenv('DB_USERNAME')?:'',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);self::$pdo->exec("SET time_zone = '+07:00'");}
  return self::$pdo;
 }
 public static function query(string $sql,array $args=[]):\PDOStatement {$s=self::connection()->prepare($sql);$s->execute($args);return $s;}
 public static function one(string $sql,array $args=[]):?array{return self::query($sql,$args)->fetch()?:null;}
 public static function all(string $sql,array $args=[]):array{return self::query($sql,$args)->fetchAll();}
 public static function tx(callable $fn):mixed{$p=self::connection();$p->beginTransaction();try{$r=$fn();$p->commit();return $r;}catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}}
 public static function audit(string $action,string $target):void{self::query('INSERT INTO audit_logs(actor_id,action,target) VALUES(?,?,?)',[$_SESSION['uid']??null,$action,$target]);}
 public static function settings():array{return array_column(self::all('SELECT name,value FROM app_settings'),'value','name');}
}
