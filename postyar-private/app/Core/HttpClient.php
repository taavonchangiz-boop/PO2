<?php
namespace WHCM\Core;

/**
 * کلاس کمکی ارسال درخواست‌های HTTP (cURL با لایه پشتیبان Stream Context)
 *
 * @package WHCM\Core
 */
class HttpClient {

    /**
     * ارسال درخواست GET
     */
    public static function get(string $url, array $headers = [], int $timeout = 15): array {
        return self::request('GET', $url, [], $headers, $timeout);
    }

    /**
     * ارسال درخواست POST
     */
    public static function post(string $url, $body = [], array $headers = [], int $timeout = 15): array {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    /**
     * متد اصلی مدیریت و ارسال درخواست
     */
    public static function request(string $method, string $url, $body = [], array $headers = [], int $timeout = 15): array {
        $method = strtoupper($method);

        // آماده‌سازی بدنه درخواست در صورتی که آرایه باشد
        if (is_array($body)) {
            if ($method === 'GET') {
                if (!empty($body)) {
                    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($body);
                }
                $body_str = '';
            } else {
                // اگر نوع محتوا مشخص نشده باشد، فرم پیش‌فرض یا JSON در نظر می‌گیریم
                $has_json_header = false;
                foreach ($headers as $h) {
                    if (stripos($h, 'application/json') !== false) {
                        $has_json_header = true;
                        break;
                    }
                }
                if ($has_json_header) {
                    $body_str = json_encode($body, JSON_UNESCAPED_UNICODE);
                } else {
                    $body_str = http_build_query($body);
                }
            }
        } else {
            $body_str = (string)$body;
        }

        // اولویت اول: استفاده از cURL در صورت فعال بودن
        if (function_exists('curl_init')) {
            return self::runCurl($method, $url, $body_str, $headers, $timeout);
        }

        // اولویت دوم: استفاده از stream_context_create
        return self::runStream($method, $url, $body_str, $headers, $timeout);
    }

    /**
     * ارسال با متد cURL
     */
    private static function runCurl(string $method, string $url, string $body, array $headers, int $timeout): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        // اعتبارسنجی SSL برای جلوگیری از حملات MITM
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method !== 'GET' && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'code' => 0,
                'body' => '',
                'error' => $err ?: 'خطای ناشناخته در cURL'
            ];
        }

        return [
            'success' => ($code >= 200 && $code < 300),
            'code' => $code,
            'body' => $response,
            'error' => ''
        ];
    }

    /**
     * ارسال با متد Stream Context به عنوان لایه پشتیبان (Fallback)
     */
    private static function runStream(string $method, string $url, string $body, array $headers, int $timeout): array {
        $opts = [
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true, // دریافت بدنه حتی در صورت بروز خطای HTTP مانند ۵۰۰ یا ۴۰۰
                'follow_location' => false,
                'max_redirects' => 0,
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ]
            ]
        ];

        if ($method !== 'GET' && !empty($body)) {
            $opts['http']['content'] = $body;
            // در صورتی که Content-Type ثبت نشده باشد
            $has_ct = false;
            foreach ($headers as $h) {
                if (stripos($h, 'Content-Type') !== false) {
                    $has_ct = true;
                    break;
                }
            }
            if (!$has_ct) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        if (!empty($headers)) {
            $opts['http']['header'] = implode("\r\n", $headers);
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [
                'success' => false,
                'code' => 0,
                'body' => '',
                'error' => 'ارتباط با سرور برقرار نشد (غیرفعال بودن cURL و عدم پاسخگویی file_get_contents)'
            ];
        }

        // استخراج کد وضعیت پاسخ از هدرهای سورس
        $code = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $match);
            $code = isset($match[1]) ? (int)$match[1] : 200;
        }

        return [
            'success' => ($code >= 200 && $code < 300),
            'code' => $code,
            'body' => $response,
            'error' => ''
        ];
    }
}
