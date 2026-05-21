<?php
declare(strict_types=1);

namespace Chat\Http;

class JsonResponse
{
    public static function error(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(array $data = []): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
