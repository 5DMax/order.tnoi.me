<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(!preg_match('/^[a-zA-Z0-9_]+_test$/D',getenv('DB_DATABASE')?:''))exit(77);
[$order,$attempt,$response]=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
$result=App\Services\Payments::complete($order,$attempt,$response);
echo $result['status'];
