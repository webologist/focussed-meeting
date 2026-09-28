<?php
declare(strict_types=1);

namespace App\Services;

final class Http
{
    /** @return array{status:int, body:array|string} */
    public static function request(string $method, string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        $headers = $opts['headers'] ?? [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if (isset($opts['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
        } elseif (isset($opts['form'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if (!empty($opts['bearer'])) $headers[] = 'Authorization: Bearer ' . $opts['bearer'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new \RuntimeException('Network error: ' . $err);
        $decoded = json_decode((string)$raw, true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : (string)$raw];
    }
}
