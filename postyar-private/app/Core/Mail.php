<?php
namespace WHCM\Core;

/**
 * ارسال ایمیل از طریق SMTP یا تابع mail() داخلی PHP
 *
 * @package WHCM\Core
 */
class Mail {
    /**
     * ارسال ایمیل
     *
     * @param string $to آدرس گیرنده
     * @param string $subject موضوع
     * @param string $body بدنه HTML
     * @return bool
     */
    public static function send(string $to, string $subject, string $body): bool {
        $mail_config = Bootstrap::getConfig('mail', []);
        // Admin DB settings override config-file defaults. Passwords are decrypted when stored securely.
        try {
            $db = Bootstrap::getDB();
            $stmt = $db->query("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND key_name LIKE 'smtp_%'");
            foreach ($stmt->fetchAll() as $row) {
                $key = substr($row['key_name'], 5);
                $mail_config[$key] = SecretStore::decrypt((string)$row['key_value']);
            }
        } catch (\Throwable $e) {}
        $enabled = ($mail_config['enabled'] ?? false) === true || ($mail_config['enabled'] ?? '') === '1';

        if ($enabled && !empty($mail_config['host'])) {
            return self::sendSmtp($to, $subject, $body, $mail_config);
        }

        // Fallback: تابع mail() داخلی PHP
        return self::sendNative($to, $subject, $body, $mail_config);
    }

    /**
     * ارسال از طریق SMTP
     */
    private static function sendSmtp(string $to, string $subject, string $body, array $config): bool {
        try {
            $from = $config['from_address'] ?? 'noreply@localhost';
            $from_name = $config['from_name'] ?? 'پُست‌یار';
            $encryption = $config['encryption'] ?? 'tls';
            $port = (int)($config['port'] ?? 587);

            // استفاده از PHPMailer در صورت وجود
            if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $config['host'];
                $mail->Port       = $port;
                $mail->SMTPAuth   = (($config['auth'] ?? '1') === '1');
                $mail->Username   = $config['username'];
                $mail->Password   = $config['password'];
                $mail->SMTPSecure = $encryption;
                $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = max(5, min(60, (int)($config['timeout'] ?? 15)));
            if (!empty($config['reply_to']) && filter_var($config['reply_to'], FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($config['reply_to'], $config['reply_name'] ?? '');
            }

                $mail->setFrom($from, $from_name);
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->isHTML(true);

                return $mail->send();
            }

            // Fallback: ارسال SMTP دستی با stream_context
            return self::sendNative($to, $subject, $body, $config);

        } catch (\Throwable $e) {
            error_log('[Postyar Mail] SMTP Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * ارسال با تابع داخلی mail() PHP
     */
    private static function sendNative(string $to, string $subject, string $body, array $config): bool {
        $from = $config['from_address'] ?? 'noreply@localhost';
        $from_name = $config['from_name'] ?? 'پُست‌یار';

        $headers  = "From: " . $from_name . " <" . $from . ">\r\n";
        $headers .= "Reply-To: " . $from . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: Postyar-SaaS/1.0\r\n";

        return \mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }

    /**
     * ساخت قالب ایمیل بازیابی رمز عبور
     */
    public static function buildPasswordResetTemplate(string $name, string $reset_link): string {
        $app_name = Bootstrap::getConfig('app.name', 'پُست‌یار');

        return "<!DOCTYPE html><html dir='rtl' lang='fa'><head><meta charset='UTF-8'>"
            . "<style>body{font-family:Tahoma,Arial,sans-serif;background:#f1f5f9;margin:0;padding:2rem}"
            . ".container{max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08)}"
            . ".header{background:#4f46e5;color:#fff;padding:2rem;text-align:center}"
            . ".body{padding:2rem;line-height:1.8;color:#334155}"
            . ".button{display:inline-block;background:#4f46e5;color:#fff!important;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:bold;margin:1rem 0}"
            . ".footer{background:#f8fafc;padding:1rem 2rem;text-align:center;color:#94a3b8;font-size:0.85rem}</style></head>"
            . "<body><div class='container'><div class='header'><h1>{$app_name}</h1></div>"
            . "<div class='body'><p>سلام {$name} عزیز،</p>"
            . "<p>درخواست بازنشانی کلمه عبور شما دریافت شد. برای تنظیم رمز جدید روی دکمه زیر کلیک کنید:</p>"
            . "<p style='text-align:center'><a href='{$reset_link}' class='button'>بازنشانی کلمه عبور</a></p>"
            . "<p style='color:#94a3b8;font-size:0.85rem'>این لینک فقط ۱ ساعت اعتبار دارد. اگر شما درخواست نکرده‌اید، این پیام را نادیده بگیرید.</p></div>"
            . "<div class='footer'>این ایمیل توسط {$app_name} ارسال شده است.</div></div></body></html>";
    }
}
