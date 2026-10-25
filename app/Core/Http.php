<?php
declare(strict_types=1);

namespace App\Core;
final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function input(): array
    {
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) throw new \InvalidArgumentException('ข้อมูลมากเกินไป');
            $a = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($a)) throw new \InvalidArgumentException('ข้อมูลไม่ถูกต้อง');
            return $a;
        }
        return $_POST;
    }

    public static function text(array $a, string $key, int $min = 1, int $max = 150): string
    {
        $v = $a[$key] ?? null;
        if (!is_string($v) || mb_strlen(trim($v)) < $min || mb_strlen(trim($v)) > $max) throw new \InvalidArgumentException('กรุณาตรวจสอบ ' . $key);
        return trim($v);
    }

    public static function password(array $a, bool $allowEmpty = false): string
    {
        $p = $a['password'] ?? null;
        if (!is_string($p) || mb_strlen($p) > 200 || (!$allowEmpty && $p === '')) throw new \InvalidArgumentException('กรุณาตรวจสอบรหัสผ่าน');
        return $p;
    }

    public static function integer(mixed $v, int $min = 1, int $max = 2147483647): int
    {
        if (is_bool($v) || !preg_match('/^[0-9]+$/D', (string)$v) || filter_var($v, FILTER_VALIDATE_INT) === false || (int)$v < $min || (int)$v > $max) throw new \InvalidArgumentException('จำนวนไม่ถูกต้อง');
        return (int)$v;
    }

    public static function view(string $name, array $vars = []): void
    {
        extract($vars, EXTR_SKIP);
        require ROOT . '/app/Views/layout.php';
    }
}
