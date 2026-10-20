<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
spl_autoload_register(static function(string $class):void { if(str_starts_with($class,'App\\')) { $file=ROOT.'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file)) require $file; }});
if(is_file(ROOT.'/.env')) foreach(file(ROOT.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){ if(str_starts_with(trim($line),'#')||!str_contains($line,'='))continue; [$k,$v]=explode('=',$line,2);if(getenv(trim($k))===false)putenv(trim($k).'='.trim(trim($v),'"\'')); }
date_default_timezone_set(getenv('APP_TIMEZONE')?:'Asia/Bangkok');
function e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
