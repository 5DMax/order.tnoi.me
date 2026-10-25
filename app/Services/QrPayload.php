<?php
declare(strict_types=1);
namespace App\Services;

final class QrPayload
{
    public static function fromOutput(string $output): string
    {
        $payload = rtrim($output, "\r\n");
        if (!preg_match('/^[\x20-\x7E]{1,128}$/D', $payload)) {
            throw new \InvalidArgumentException('อ่าน QR ไม่ได้ พบหลาย QR หรือ payload ไม่ถูกต้อง');
        }
        return $payload;
    }

    public static function read(string $file): string
    {
        if (!is_executable('/usr/bin/zbarimg') || !is_executable('/usr/bin/timeout')) {
            throw new \RuntimeException('QR_DECODER_UNAVAILABLE');
        }
        $pipes = [];
        // Fixed executable and argument array: uploaded filenames never enter a shell.
        $process = proc_open(['/usr/bin/timeout', '--kill-after=1s', '10s', '/usr/bin/zbarimg', '--quiet', '--raw', '--set', 'disable', '--set', 'qrcode.enable', $file],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('QR_DECODER_UNAVAILABLE');
        $output = stream_get_contents($pipes[1], 4096);
        fclose($pipes[1]);
        $code = proc_close($process);
        if ($code !== 0 || !is_string($output)) throw new \InvalidArgumentException('อ่าน QR บนสลิปไม่สำเร็จ');
        return self::fromOutput($output);
    }
}
