<?php
namespace WHCM\Core;

/**
 * سیستم مدیریت قالب‌های ایمیل
 *
 * ارسال ایمیل بر اساس رویداد، مدیریت قالب‌ها، لاگ ارسال و آمار.
 * تنظیمات SMTP از جدول settings (tenant_id=0) خوانده می‌شود و در صورت عدم وجود
 * از config/config.php به عنوان فال‌بک استفاده می‌شود.
 *
 * @package WHCM\Core
 */
class EmailTemplate {

    /**
     * دریافت تنظیمات SMTP از دیتابیس با فال‌بک به config.php
     */
    private static function getSmtpConfig(): array {
        $db = Bootstrap::getDB();
        $keys = ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_address', 'smtp_from_name'];
        $settings = [];
        foreach ($keys as $key) {
            $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            $settings[$key] = $row !== false ? $row['key_value'] : '';
        }

        // بررسی اینکه آیا تنظیمات SMTP در دیتابیس تنظیم شده یا خیر
        $hasDbConfig = !empty($settings['smtp_host']);

        if ($hasDbConfig) {
            return [
                'enabled'       => ($settings['smtp_enabled'] ?? '') === '1',
                'host'          => $settings['smtp_host'],
                'port'          => (int)($settings['smtp_port'] ?: 587),
                'username'      => $settings['smtp_username'],
                'password'      => $settings['smtp_password'],
                'encryption'    => $settings['smtp_encryption'] ?: 'tls',
                'from_address'  => $settings['smtp_from_address'] ?: 'noreply@localhost',
                'from_name'     => $settings['smtp_from_name'] ?: Bootstrap::getConfig('app.name', 'پُست‌یار'),
            ];
        }

        // فال‌بک به config.php
        return Bootstrap::getConfig('mail', []);
    }

    /**
     * ارسال ایمیل با استفاده از تنظیمات SMTP ذخیره‌شده در دیتابیس
     * (بدون وابستگی به Mail::send و بدون تغییر پیکربندی سراسری)
     */
    private static function sendWithDbConfig(string $to, string $subject, string $body): bool {
        $config = self::getSmtpConfig();
        $from = $config['from_address'] ?? 'noreply@localhost';
        $fromName = $config['from_name'] ?? Bootstrap::getConfig('app.name', 'پُست‌یار');

        $hasSmtp = !empty($config['host']) && (($config['auth'] ?? '1') !== '1' || !empty($config['username']));

        if ($hasSmtp && class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $config['host'];
                $mail->Port       = (int)($config['port'] ?? 587);
                $mail->SMTPAuth   = (($config['auth'] ?? '1') === '1');
                $mail->Username   = $config['username'];
                $mail->Password   = $config['password'] ?? '';
                $mail->SMTPSecure = $config['encryption'] ?? 'tls';
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom($from, $fromName);
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->isHTML(true);
                return $mail->send();
            } catch (\Exception $e) {
                error_log('[Postyar EmailTemplate] SMTP Error: ' . $e->getMessage());
            }
        }

        // فال‌بک: تابع mail() داخلی PHP
        $headers  = "From: " . $fromName . " <" . $from . ">\r\n";
        $headers .= "Reply-To: " . $from . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: Postyar-EmailTemplate/1.0\r\n";

        return \mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }

    /**
     * ارسال ایمیل بر اساس کلید رویداد به یک کاربر
     *
     * @param string $eventKey کلید رویداد (مثلاً welcome, payment_confirm)
     * @param int $userId شناسه کاربر
     * @param array $variables متغیرهای جایگزینی (مثلاً ['name' => 'علی', 'amount' => '۵۰۰۰۰'])
     * @return bool
     */
    public static function sendByEvent(string $eventKey, int $userId, array $variables = []): bool {
        $template = self::getTemplate($eventKey);
        if (!$template) {
            return false;
        }

        if (!($template['is_active'] ?? 1)) {
            return false;
        }

        $db = Bootstrap::getDB();

        // دریافت ایمیل کاربر
        $stmt = $db->prepare("SELECT email, name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return false;
        }

        $toEmail = $user['email'];

        // افزودن متغیرهای پیش‌فرض
        $variables['app_name'] = Bootstrap::getConfig('app.name', 'پُست‌یار');
        $variables['app_url'] = Bootstrap::getConfig('app.url', '');
        if (empty($variables['name'])) {
            $variables['name'] = $user['name'] ?? '';
        }

        // رندر قالب
        $renderedSubject = self::renderTemplate($template['subject'], $variables);
        $renderedBody = self::renderTemplate($template['body_html'], $variables);

        // ارسال
        $status = 'sent';
        $errorMsg = null;
        try {
            $sent = self::sendWithDbConfig($toEmail, $renderedSubject, $renderedBody);
            if (!$sent) {
                $status = 'failed';
                $errorMsg = 'خطا در ارسال ایمیل (send نادرست برگرداند)';
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $errorMsg = $e->getMessage();
        }

        // ثبت لاگ
        try {
            $stmt = $db->prepare("INSERT INTO email_log (template_id, to_address, user_id, subject, status, error_message) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$template['id'], $toEmail, $userId, $renderedSubject, $status, $errorMsg]);
        } catch (\Exception $e) {}

        return $status === 'sent';
    }

    /**
     * ارسال ایمیل انبوه به چندین کاربر
     *
     * @param string $eventKey کلید رویداد
     * @param array $userIds آرایه شناسه کاربران
     * @param array $variablesPerUser آرایه associative: userId => [variables]
     * @return array ['sent' => int, 'failed' => int, 'errors' => []]
     */
    /** آزمون واقعی ارسال یک ایمیل آزمایشی با گزارش علت خطا. */
    public static function testConnection(int $userId): array {
        $template = self::getTemplate('welcome');
        if (!$template || !($template['is_active'] ?? 1)) {
            return ['success'=>false,'error'=>'قالب خوش‌آمدگویی فعال پیدا نشد.'];
        }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT email,name FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email'])) return ['success'=>false,'error'=>'نشانی ایمیل مدیر پیدا نشد.'];
        $variables=['name'=>$user['name'] ?? 'مدیر سیستم','app_name'=>Bootstrap::getConfig('app.name','پُست‌یار'),'app_url'=>Bootstrap::getConfig('app.url','')];
        $subject=self::renderTemplate($template['subject'],$variables);
        $body=self::renderTemplate($template['body_html'],$variables);
        try {
            $sent=self::sendWithDbConfig($user['email'],$subject,$body);
            if (!$sent) return ['success'=>false,'error'=>'سرور ایمیل پیام ارسال را نپذیرفت. میزبان، درگاه، نام کاربری، رمز عبور و رمزنگاری را بررسی کنید.'];
            return ['success'=>true,'error'=>null];
        } catch (\Throwable $e) {
            return ['success'=>false,'error'=>$e->getMessage() ?: 'خطای ناشناخته در ارتباط با سرور ایمیل.'];
        }
    }

    public static function sendBulk(string $eventKey, array $userIds, array $variablesPerUser = []): array {
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];

        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            $vars = $variablesPerUser[$uid] ?? [];
            $ok = self::sendByEvent($eventKey, $uid, $vars);
            if ($ok) {
                $result['sent']++;
            } else {
                $result['failed']++;
                $result['errors'][] = "کاربر #{$uid}";
            }
        }

        return $result;
    }

    /**
     * جایگزینی {{key}} با مقادیر در متن HTML
     *
     * @param string $html متن قالب
     * @param array $variables آرایه کلید => مقدار
     * @return string
     */
    public static function renderTemplate(string $html, array $variables): string {
        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', (string)$value, $html);
        }
        return $html;
    }

    /**
     * دریافت یک قالب بر اساس کلید رویداد
     *
     * @param string $eventKey
     * @return array|null
     */
    public static function getTemplate(string $eventKey): ?array {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT * FROM email_templates WHERE event_key = ? LIMIT 1");
        $stmt->execute([$eventKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * دریافت همه قالب‌ها
     *
     * @return array
     */
    public static function getAllTemplates(): array {
        $db = Bootstrap::getDB();
        return $db->query("SELECT * FROM email_templates ORDER BY id ASC")->fetchAll();
    }

    /**
     * ذخیره قالب (ایجاد یا به‌روزرسانی)
     *
     * @param string $eventKey
     * @param string $name
     * @param string $subject
     * @param string $bodyHtml
     * @param array $variables
     * @param bool $active
     */
    public static function saveTemplate(string $eventKey, string $name, string $subject, string $bodyHtml, array $variables, bool $active): void {
        $db = Bootstrap::getDB();
        $isActive = $active ? 1 : 0;
        $varsJson = json_encode($variables, JSON_UNESCAPED_UNICODE);

        // بررسی وجود
        $stmt = $db->prepare("SELECT id FROM email_templates WHERE event_key = ? LIMIT 1");
        $stmt->execute([$eventKey]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE email_templates SET template_name = ?, subject = ?, body_html = ?, variables = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $subject, $bodyHtml, $varsJson, $isActive, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO email_templates (event_key, template_name, subject, body_html, variables, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$eventKey, $name, $subject, $bodyHtml, $varsJson, $isActive]);
        }
    }

    /**
     * حذف قالب
     *
     * @param int $id
     */
    public static function deleteTemplate(int $id): void {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("DELETE FROM email_templates WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * دریافت لاگ ارسال‌ها با قابلیت فیلتر
     *
     * @param int $limit
     * @param int $offset
     * @param string|null $statusFilter
     * @return array
     */
    public static function getLog(int $limit = 50, int $offset = 0, ?string $statusFilter = null): array {
        $db = Bootstrap::getDB();
        $sql = "SELECT el.*, u.name as user_name FROM email_log el LEFT JOIN users u ON el.user_id = u.id WHERE 1=1";
        $params = [];

        if ($statusFilter !== null && in_array($statusFilter, ['sent', 'failed'], true)) {
            $sql .= " AND el.status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY el.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * دریافت آمار ایمیل برای پنل مدیریت
     *
     * @return array
     */
    public static function getAdminEmailStats(): array {
        $db = Bootstrap::getDB();

        $totalSent = 0;
        $totalFailed = 0;
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM email_log WHERE status = 'sent'");
            $totalSent = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {}

        try {
            $stmt = $db->query("SELECT COUNT(*) FROM email_log WHERE status = 'failed'");
            $totalFailed = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {}

        // آمار بر اساس نوع قالب
        $byTemplate = [];
        try {
            $rows = $db->query("
                SELECT et.event_key, et.template_name,
                       SUM(CASE WHEN el.status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                       SUM(CASE WHEN el.status = 'failed' THEN 1 ELSE 0 END) as failed_count
                FROM email_log el
                LEFT JOIN email_templates et ON el.template_id = et.id
                GROUP BY el.template_id
                ORDER BY sent_count DESC
            ")->fetchAll();
            foreach ($rows as $row) {
                $byTemplate[] = $row;
            }
        } catch (\Exception $e) {}

        return [
            'total_sent' => $totalSent,
            'total_success' => $totalSent,
            'total_attempts' => $totalSent + $totalFailed,
            'total_failed' => $totalFailed,
            'by_template' => $byTemplate,
        ];
    }
}
