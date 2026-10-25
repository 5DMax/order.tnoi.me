<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$_SESSION=['csrf'=>'test'];
$name='login';
ob_start();require ROOT.'/app/Views/layout.php';$html=ob_get_clean();
foreach(['common.js','login.js','app.css','vendor/bootstrap.min.css'] as $file){
 $expected='/assets/'.$file.'?v='.substr(hash_file('sha256',ROOT.'/public/assets/'.$file),0,16);
 if(!str_contains($html,$expected))throw new RuntimeException('Missing content version: '.$file);
 echo "PASS content version $file\n";
}
if(preg_match('~(?:src|href)="/assets/[^"?]+"~',$html))throw new RuntimeException('Unversioned asset remains');
echo "PASS all layout assets versioned\n";
