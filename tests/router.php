<?php
// PHP built-in server router for local development/tests only.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/__test/upload' && getenv('APP_ENV')==='testing'){
 require __DIR__.'/../app/bootstrap.php';
 try{$saved=App\Services\Uploads::save($_FILES['image']??[]);$file=ROOT.'/storage/uploads/'.$saved['filename'];$info=getimagesize($file);unlink($file);App\Core\Http::json(['filename'=>$saved['filename'],'mime'=>$info['mime']]);}
 catch(InvalidArgumentException $e){App\Core\Http::json(['error'=>$e->getMessage()],422);}
}
if($path==='/__test/upload-batch' && getenv('APP_ENV')==='testing'){
 require __DIR__.'/../app/bootstrap.php';
 $before=glob(ROOT.'/storage/uploads/*.jpg');
 try{App\Services\ProductBatch::saveImages([], $_FILES);App\Core\Http::json(['unexpected'=>true]);}
 catch(InvalidArgumentException $e){App\Core\Http::json(['cleaned'=>count(array_diff(glob(ROOT.'/storage/uploads/*.jpg'),$before))===0],422);}
}
$file=realpath(__DIR__.'/../public'.$path);
if($file && str_starts_with($file,realpath(__DIR__.'/../public').DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file,PATHINFO_EXTENSION)!=='php')return false;
require __DIR__.'/../public/index.php';
