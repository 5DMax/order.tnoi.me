<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{DB, Http};

final class ProductBatch
{
    public static function variants(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 50) {
            throw new \InvalidArgumentException('กรุณาเพิ่มไซซ์ 1–50 รายการต่อครั้ง');
        }
        $result = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('ข้อมูลแถวที่ '.($i + 1).' ไม่ถูกต้อง');
            $type = Http::integer($row['product_type_id'] ?? 0, 1, 4);
            $size = Http::text($row, 'size', 1, 40);
            $price = Money::parse($row['price'] ?? '');
            if ($price < 1) throw new \InvalidArgumentException('ราคาของไซซ์ '.$size.' ต้องมากกว่า 0');
            $key = $type.':'.mb_strtolower($size);
            if (isset($seen[$key])) throw new \InvalidArgumentException('ไซซ์ '.$size.' ของประเภทนี้ซ้ำในรายการ');
            $seen[$key] = true;
            $result[] = ['product_type_id' => $type, 'size' => $size, 'price_satang' => $price, 'active' => Http::integer($row['active'] ?? 1, 0, 1)];
        }
        return $result;
    }

    public static function addVariants(mixed $rows): void
    {
        $rows = self::variants($rows);
        // Called inside the controller transaction. Stable locks serialize bulk inserts.
        DB::all('SELECT id FROM product_types ORDER BY id FOR UPDATE');
        foreach ($rows as $row) {
            if (DB::one('SELECT id FROM product_variants WHERE product_type_id=? AND size=?', [$row['product_type_id'], $row['size']])) {
                throw new \InvalidArgumentException('ไซซ์ '.$row['size'].' ของประเภทนี้มีอยู่แล้ว ไม่มีรายการถูกเพิ่ม');
            }
        }
        try {
            foreach ($rows as $row) DB::query('INSERT INTO product_variants(product_type_id,size,price_satang,active) VALUES(?,?,?,?)', array_values($row));
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) throw new \InvalidArgumentException('มีไซซ์ซ้ำ กรุณาตรวจสอบรายการ ไม่มีรายการถูกเพิ่ม');
            throw $e;
        }
    }

    public static function images(array $input, array $files): array
    {
        $result = [];
        $legacy = isset($input['product_type_id']) ? Http::integer($input['product_type_id'], 1, 4) : null;
        foreach (range(1, 5) as $zone) {
            foreach ($legacy ? [$legacy] : range(1, 4) as $type) {
                $key = $legacy ? 'zone_'.$zone : 'image_'.$zone.'_'.$type;
                if (!isset($files[$key])) continue;
                $file = $files[$key];
                if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) throw new \InvalidArgumentException('ข้อมูลไฟล์ไม่ถูกต้อง');
                if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
                if ($file['error'] !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('อัปโหลดภาพโซน '.$zone.' ไม่สำเร็จ กรุณาตรวจขนาดไฟล์ (ไม่เกิน 4 MB ต่อภาพ)');
                $result[] = ['zone_id' => $zone, 'product_type_id' => $type, 'file' => $file];
            }
        }
        if (!$result) throw new \InvalidArgumentException('กรุณาเลือกภาพอย่างน้อย 1 ภาพ');
        return $result;
    }

    public static function saveImages(array $input, array $files): void
    {
        $selected = self::images($input, $files);
        $saved = [];
        try {
            // Decode/re-encode all files before acquiring database locks.
            foreach ($selected as $image) {
                $image['saved'] = Uploads::save($image['file']);
                unset($image['file']);
                $saved[] = $image;
            }
            DB::tx(function () use ($saved): void {
                foreach ($saved as $image) {
                    $args = [$image['zone_id'], $image['product_type_id']];
                    if (!DB::one('SELECT zone_id FROM zone_product_images WHERE zone_id=? AND product_type_id=? FOR UPDATE', $args)) {
                        throw new \InvalidArgumentException('ไม่พบกลุ่มหรือประเภทสินค้า กรุณาตรวจสอบ master data');
                    }
                    DB::query('UPDATE zone_product_images SET filename=? WHERE zone_id=? AND product_type_id=?', [$image['saved']['filename'], ...$args]);
                }
                DB::audit('products.images_bulk', 'images:'.count($saved));
            });
        } catch (\Throwable $e) {
            foreach ($saved as $image) @unlink(ROOT.'/storage/uploads/'.$image['saved']['filename']);
            throw $e;
        }
    }
}
