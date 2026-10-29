<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use App\Services\ProductBatch;
function verify(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function invalid(callable $fn,string $name):void {try{$fn();}catch(InvalidArgumentException){verify(true,$name);return;}throw new RuntimeException($name);}
verify(class_exists(ProductBatch::class),'batch validator exists');
$rows=[['product_type_id'=>1,'size'=>' S ','price'=>'100.01'],['product_type_id'=>1,'size'=>'M','price'=>'150','active'=>0],['product_type_id'=>2,'size'=>'S','price'=>'200']];
$v=ProductBatch::variants($rows);
verify(count($v)===3&&$v[0]['size']==='S'&&$v[0]['price_satang']===10001,'normalize multiple sizes and exact money');
verify($v[1]['active']===0,'preserve disabled state');
invalid(fn()=>ProductBatch::variants([]),'reject empty batch');
invalid(fn()=>ProductBatch::variants(array_fill(0,51,$rows[0])),'limit batch to 50');
invalid(fn()=>ProductBatch::variants([$rows[0],['product_type_id'=>1,'size'=>'s','price'=>'1']]),'reject case insensitive duplicate sizes');
invalid(fn()=>ProductBatch::variants([$rows[0],'invalid']),'reject malformed row');
invalid(fn()=>ProductBatch::variants([['product_type_id'=>1,'size'=>'L','price'=>'0']]),'reject zero price');
$files=['image_1_1'=>['error'=>0],'image_5_3'=>['error'=>0],'image_2_1'=>['error'=>UPLOAD_ERR_NO_FILE]];
$images=ProductBatch::images([], $files);
verify(count($images)===2&&$images[1]['zone_id']===5&&$images[1]['product_type_id']===3,'map all zones and product types');
invalid(fn()=>ProductBatch::images([],[]),'reject empty image selection');
invalid(fn()=>ProductBatch::images([],['image_1_1'=>['error'=>UPLOAD_ERR_INI_SIZE]]),'reject upload error instead of silently skipping');
verify(ProductBatch::images(['product_type_id'=>2],['zone_3'=>['error'=>0]])[0]['zone_id']===3,'legacy image batch supported');

verify(ProductBatch::variants([['product_type_id'=>4,'size'=>'M','price'=>'199']])[0]['product_type_id']===4,'athlete shirt sizes supported');
$all=[];foreach(range(1,5) as $zone)foreach(range(1,4) as $type)$all['image_'.$zone.'_'.$type]=['error'=>0];
verify(count(ProductBatch::images([],$all))===20,'all 20 zone and type images accepted');
verify(ProductBatch::images(['product_type_id'=>4],['zone_5'=>['error'=>0]])[0]['product_type_id']===4,'athlete shirt legacy image upload supported');
invalid(fn()=>ProductBatch::variants([['product_type_id'=>5,'size'=>'M','price'=>'199']]),'unknown product type rejected');
