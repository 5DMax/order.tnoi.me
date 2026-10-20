<?php
declare(strict_types=1);
namespace App\Services;
final class Uploads {
 public static function save(array $file):array {
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new \InvalidArgumentException('กรุณาอัปโหลดภาพ');
  $path=$file['tmp_name'];if(filesize($path)>4194304||filesize($path)<10)throw new \InvalidArgumentException('ภาพต้องไม่เกิน 4 MB');
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);$types=['image/jpeg'=>IMAGETYPE_JPEG,'image/png'=>IMAGETYPE_PNG,'image/gif'=>IMAGETYPE_GIF,'image/webp'=>IMAGETYPE_WEBP];
  $size=@getimagesize($path);if(!isset($types[$mime])||!$size||$size[2]!==$types[$mime]||$size[0]*$size[1]>12000000||max($size[0],$size[1])>8000)throw new \InvalidArgumentException('ชนิดหรือขนาดภาพไม่ถูกต้อง');
  $hash=hash_file('sha256',$path);$im=@imagecreatefromstring(file_get_contents($path));if(!$im)throw new \InvalidArgumentException('อ่านรูปไม่ได้');
  $name=bin2hex(random_bytes(24)).'.jpg';$dest=ROOT.'/storage/uploads/'.$name;
  $canvas=imagecreatetruecolor($size[0],$size[1]);imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));imagecopy($canvas,$im,0,0,0,0,$size[0],$size[1]);$ok=imagejpeg($canvas,$dest,92);imagedestroy($im);imagedestroy($canvas);
  if(!$ok)throw new \RuntimeException('Upload write failed');chmod($dest,0640);if(filesize($dest)>4194304){unlink($dest);throw new \InvalidArgumentException('ภาพหลังประมวลผลเกิน 4 MB');}return ['filename'=>$name,'hash'=>$hash];
 }
 public static function serve(string $name):never{if(!preg_match('/^[a-f0-9]{48}\.jpg$/D',$name)||!is_file(ROOT.'/storage/uploads/'.$name)){http_response_code(404);exit; }header('Content-Type: image/jpeg');header('Content-Disposition: inline; filename="image.jpg"');readfile(ROOT.'/storage/uploads/'.$name);exit;}
}
