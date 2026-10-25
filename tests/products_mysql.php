<?php
declare(strict_types=1);
use App\Core\DB;
use App\Services\ProductBatch;
$batch=[['product_type_id'=>1,'size'=>'BATCH-S','price'=>'100.01'],['product_type_id'=>2,'size'=>'BATCH-M','price'=>'200','active'=>0]];
DB::tx(fn()=>ProductBatch::addVariants($batch));
test((int)DB::one("SELECT price_satang FROM product_variants WHERE product_type_id=1 AND size='BATCH-S'")['price_satang']===10001,'batch writes exact price');
test((int)DB::one("SELECT active FROM product_variants WHERE product_type_id=2 AND size='BATCH-M'")['active']===0,'batch writes disabled state');
reject(fn()=>DB::tx(fn()=>ProductBatch::addVariants([['product_type_id'=>1,'size'=>'BATCH-NEW','price'=>'100'],$batch[1]])),'existing duplicate rejects whole batch');
test(DB::one("SELECT id FROM product_variants WHERE size='BATCH-NEW'")===null,'no partial batch inserted');
