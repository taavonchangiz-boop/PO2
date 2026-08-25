<?php
namespace WHCM\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\SecretStore;
use WHCM\Core\Auth;
use WHCM\Core\Csrf;
use WHCM\Core\RateLimit;
use WHCM\Core\Logger;
use WHCM\Core\Sms;
use WHCM\Domain\TextFormat;
use WHCM\Domain\Quota;
use WHCM\Domain\ChannelManager;
use WHCM\Domain\GoldTicker;
use WHCM\Domain\Inbox;
use WHCM\Domain\Sender;
use WHCM\Domain\LinkTracker;
use WHCM\Domain\VerificationCode;
use WHCM\Domain\Referral;
use WHCM\Domain\Wallet;
use WHCM\Domain\PaymentPricing;
use WHCM\Core\EmailTemplate;
use WHCM\Domain\ScheduledPost;
use WHCM\Domain\AntiAbuse;

use WHCM\Core\WebPush;

/**
 * کنترلر اصلی هدایت‌کننده و پردازشگر درخواست‌های وب
 *
 * تمام متدهای مشترک (redirect، render، flash، checkAuth، uploadAndConvertToWebp،
 * jalaliToGregorian) در BaseController قرار دارند.
 *
 * @package WHCM\Controllers
 */
class MainController extends BaseController {

    /**
     * صفحه اصلی یا فرم ورود/ثبت‌نام (همراه با کپچای محاسباتی پویا)
     */
    public function index() {
        // جلوگیری از کش شدن لندینگ پیج و فرم‌ها در هاست اشتراکی و مرورگر کاربر
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        if (Auth::check()) {
            if (Auth::isSuperAdmin()) {
                $this->redirect('/hnnh');
            } else {
                $this->redirect('/dashboard');
            }
        }

        // تولید کپچای ریاضی پویا (random_int — مقاوم در برابر پیش‌بینی)
        $num1 = random_int(1, 9);
        $num2 = random_int(1, 9);
        $_SESSION['captcha_answer'] = $num1 + $num2;
        $captcha_question = "حاصل جمع " . TextFormat::fa_digits($num1) . " + " . TextFormat::fa_digits($num2) . " چقدر می‌شود؟";

        // دریافت لیست پلن‌ها جهت نمایش در لندینگ پیج
        $db = Bootstrap::getDB();
        $plans = [];
        try {
            $plans = $db->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll();
        } catch (\Throwable $e) {
            $plans = [];
        }

        $this->render('home', [
            'title' => 'پُست‌یار | سامانه هوشمند مدیریت و انتشار کانال‌ها',
            'plans' => $plans,
            'captcha_question' => $captcha_question,
            'csrf_field' => Csrf::field(),
            'message' => $this->getFlashMessage()
        ]);
    }

    /**
     * عملیات ورود کاربر
     */
    public function handleLogin() {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/');
        }

        // بررسی کپچای ریاضی ضد ربات
        $captcha = (int)($_POST['captcha'] ?? 0);
        $saved_captcha = isset($_SESSION['captcha_answer']) ? (int)$_SESSION['captcha_answer'] : null;
        if ($saved_captcha === null || $captcha !== $saved_captcha) {
            $this->setFlashMessage('پاسخ سوال امنیتی (کپچا) نادرست است.');
            $this->redirect('/');
        }

        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';


        if (!RateLimit::consume('login_web', 5, 60, strtolower($email))) {
            $this->setFlashMessage('تعداد تلاش‌های ناموفق شما بیش از حد مجاز است. لطفاً ۱ دقیقه صبر کنید.');
            $this->redirect('/');
        }

        $res = Auth::loginWithPhoneBinding($email, $password, $phone);
        if ($res['success']) {
            RateLimit::clear('login_web', strtolower($email));
            if (Auth::isSuperAdmin()) {
                $this->redirect('/hnnh');
            } else {
                $this->redirect('/dashboard');
            }
        } else {
            $this->setFlashMessage($res['message']);
            $this->redirect('/');
        }
    }

    /**
     * درخواست OTP برای ورود با شماره موبایل.
     */
    public function handlePhoneLoginRequest() {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/'); return; }
        $phone = \WHCM\Domain\AntiAbuse::normalizePhone(trim($_POST['phone'] ?? ''));
        if (!$phone || !\WHCM\Domain\AntiAbuse::validPhone($phone)) { $this->setFlashMessage('شماره موبایل معتبر نیست.'); $this->redirect('/'); return; }
        if (!RateLimit::consume('web_phone_login_request', 3, 300, $phone) || !RateLimit::consume('web_phone_login_ip', 10, 300)) { $this->setFlashMessage('تعداد درخواست‌های ورود پیامکی بیش از حد مجاز است.'); $this->redirect('/'); return; }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE phone = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if ($user && Sms::isEnabled()) {
            $tpl = $db->query("SELECT template_id FROM sms_templates WHERE event_key = 'login_otp' AND is_active = 1 LIMIT 1")->fetch();
            if ($tpl) { $code = VerificationCode::generate((int)$user['id'], 'phone_login', 5); try { Sms::send($phone, (int)$tpl['template_id'], ['code' => $code], (int)$user['id']); } catch (\Throwable $e) { error_log('[Postyar] web phone-login SMS failed: '.$e->getMessage()); } }
        }
        $_SESSION['phone_login_phone'] = $phone;
        $this->setFlashMessage('اگر شماره در سامانه ثبت شده باشد، کد ورود ارسال خواهد شد.');
        $this->redirect('/phone-login-verify');
    }

    public function showPhoneLoginVerify() {
        $this->render('home', ['title' => 'ورود با شماره موبایل | پُست‌یار', 'csrf_field' => Csrf::field(), 'message' => $this->getFlashMessage(), 'show_phone_login_verify' => true]);
    }

    public function handlePhoneLoginVerify() {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/'); return; }
        $phone = (string)($_SESSION['phone_login_phone'] ?? '');
        $code = trim((string)($_POST['code'] ?? ''));
        if (!$phone || !preg_match('/^\d{6}$/', $code)) { $this->setFlashMessage('کد ورود نامعتبر است.'); $this->redirect('/phone-login-verify'); return; }
        if (!RateLimit::consume('web_phone_login_verify', 5, 300, $phone) || !RateLimit::consume('web_phone_login_verify_ip', 20, 300)) { $this->setFlashMessage('تعداد تلاش‌های ورود بیش از حد مجاز است.'); $this->redirect('/phone-login-verify'); return; }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE phone = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if (!$user) { $this->setFlashMessage('کد ورود نامعتبر یا منقضی شده است.'); $this->redirect('/phone-login-verify'); return; }
        $record = VerificationCode::findActive((int)$user['id'], 'phone_login', $code);
        if (!$record || !VerificationCode::consume((int)$record['id']) || !Auth::loginByUserId((int)$user['id'])) { $this->setFlashMessage('کد ورود نامعتبر یا منقضی شده است.'); $this->redirect('/phone-login-verify'); return; }
        unset($_SESSION['phone_login_phone']);
        RateLimit::clear('web_phone_login_verify', $phone);
        $this->redirect(Auth::isSuperAdmin() ? '/hnnh' : '/dashboard');
    }

    /**
     * عملیات ثبت‌نام کاربر
     */
    public function handleRegister() {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/');
        }

        // پذیرش سیاست حریم خصوصی برای ثبت‌نام الزامی است (اعتبارسنجی سمت سرور)
        if (($_POST['privacy_consent'] ?? '') !== '1') {
            $this->setFlashMessage('برای ایجاد حساب، مطالعه و تأیید سیاست حریم خصوصی الزامی است.');
            $this->redirect('/');
        }

        // بررسی کپچای ریاضی ضد ربات
        $captcha = (int)($_POST['captcha'] ?? 0);
        $saved_captcha = isset($_SESSION['captcha_answer']) ? (int)$_SESSION['captcha_answer'] : null;
        if ($saved_captcha === null || $captcha !== $saved_captcha) {
            $this->setFlashMessage('پاسخ سوال امنیتی (کپچا) نادرست است.');
            $this->redirect('/');
        }

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $business_name = trim($_POST['business_name'] ?? '');
        $business_type = trim($_POST['business_type'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($email) || empty($password) || empty($phone)) {
            $this->setFlashMessage('لطفاً تمامی فیلدها را با دقت تکمیل کنید.');
            $this->redirect('/');
        }

        if ($password !== $password_confirm) {
            $this->setFlashMessage('کلمه عبور با تکرار آن مطابقت ندارد.');
            $this->redirect('/');
        }

        $res = Auth::register($name, $email, $password, $business_name, $business_type, $phone);
        if ($res['success']) {
            // ---- ورود خودکار کاربر بلافاصله پس از ثبت‌نام موفق ----
            // استفاده از Auth::login() به جای ست مستقیم سشن — سازگاری کامل با LiteSpeed
            Auth::login($email, $password);
            RateLimit::clear('login_web', strtolower($email));

            // ---- پردازش‌های پس از ثبت‌نام (غیرمسدودکننده) ----
            if (!empty($res['user_id'])) {
                try {
                    // اولویت خواندن از POST (فرم) و سپس GET (لینک مستقیم)
                    $referralCode = trim($_POST['ref'] ?? $_GET['ref'] ?? '');
                    Referral::processRegistration((int)$res['user_id'], !empty($referralCode) ? $referralCode : null);
                    Referral::getUserReferralCode((int)$res['user_id']);
                } catch (\Throwable $e) {
                    error_log('[Postyar] Post-register referral error: ' . $e->getMessage());
                }

                try {
                    EmailTemplate::sendByEvent('welcome', (int)$res['user_id']);
                } catch (\Throwable $e) {
                    error_log('[Postyar] Welcome email failed for user #' . $res['user_id'] . ': ' . $e->getMessage());
                }
            }

            $this->setFlashMessage('ثبت‌نام شما با موفقیت انجام شد و به صورت خودکار وارد حساب شدید! ✨');
            if (Auth::isSuperAdmin()) {
                $this->redirect('/hnnh');
            } else {
                $this->redirect('/dashboard');
            }
        } else {
            $this->setFlashMessage($res['message']);
            $this->redirect('/');
        }
    }

    /**
     * خروج از سیستم
     */
    public function logout() {
        if (!\WHCM\Core\Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! درخواست خروج نامعتبر است.');
            $this->redirect('/');
            return;
        }
        Auth::logout();
        $this->setFlashMessage('شما با موفقیت از سیستم خارج شدید.');
        $this->redirect('/');
    }

    /**
     * پنل کاربری (داشبورد مستاجر)
     */
    public function dashboard() {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        $this->checkAuth();
        $tenant_id = Auth::tenantId();
        $db = Bootstrap::getDB();

        // این دو مورد برای نمایش هسته پیشخوان ضروری هستند؛ در صورت خرابی ماژول جانبی
        // نباید کل پیشخوان با خطای ۵۰۰ از دسترس خارج شود.
        try { $quota = Quota::getTenantQuota($tenant_id); }
        catch (\Throwable $e) {
            error_log('[Postyar Dashboard] quota: ' . $e->getMessage());
            $quota = ['has_active_sub'=>false,'plan_title'=>'بدون اشتراک فعال','end_date'=>null,'max_channels'=>0,'max_posts'=>0,'used_channels'=>0,'used_posts'=>0,'can_add_channel'=>false,'can_send_post'=>false,'features'=>[]];
        }
        try { $channels = ChannelManager::getTenantChannels($tenant_id); }
        catch (\Throwable $e) { error_log('[Postyar Dashboard] channels: '.$e->getMessage()); $channels=[]; }

        // اعلان‌ها در بعضی نصب‌های قدیمی وجود نداشتند؛ جدول را فقط در صورت نیاز ایجاد می‌کنیم.
        $user_notifications=[]; $unread_count=0;
        try {
            \WHCM\Domain\Notification::ensureTable();
            $user_notifications = \WHCM\Domain\Notification::getRecentUnread($tenant_id,20);
            $unread_count = \WHCM\Domain\Notification::getUnreadCount($tenant_id);
        } catch (\Throwable $e) { error_log('[Postyar Dashboard] notifications: '.$e->getMessage()); }

        $edit_channel=null;
        $edit_channel_id=(int)($_GET['edit_channel']??0);
        if($edit_channel_id>0){ try{$edit_channel=ChannelManager::getChannel($edit_channel_id,$tenant_id);}catch(\Throwable $e){error_log('[Postyar Dashboard] edit channel: '.$e->getMessage());} }

        $settings=[];
        try {
            $stmt=$db->prepare("SELECT key_name,key_value FROM settings WHERE tenant_id=?"); $stmt->execute([$tenant_id]);
            foreach($stmt->fetchAll() as $row)$settings[$row['key_name']]=$row['key_value'];
        } catch(\Throwable $e){error_log('[Postyar Dashboard] settings: '.$e->getMessage());}

        $auto_replies=[];
        try {
            $stmt=$db->prepare("SELECT ar.*, c.name as channel_name, c.platform as channel_platform FROM auto_replies ar JOIN channels c ON ar.channel_id=c.id WHERE ar.tenant_id=? ORDER BY ar.id DESC");
            $stmt->execute([$tenant_id]); $auto_replies=$stmt->fetchAll();
        }catch(\Throwable $e){error_log('[Postyar Dashboard] auto replies: '.$e->getMessage());}

        $responder_settings=[];
        try {
            $stmt=$db->prepare("SELECT key_name,key_value FROM settings WHERE tenant_id=? AND key_name LIKE 'responder_enabled_%'");$stmt->execute([$tenant_id]);
            foreach($stmt->fetchAll() as $r)$responder_settings[$r['key_name']]=$r['key_value'];
        }catch(\Throwable $e){error_log('[Postyar Dashboard] responder: '.$e->getMessage());}

        $posts=[];$post_clicks=[];
        try {
            $stmt=$db->prepare("SELECT p.* FROM posts p WHERE p.tenant_id=? ORDER BY p.id DESC LIMIT 50");$stmt->execute([$tenant_id]);$posts=$stmt->fetchAll();
            if($posts){
                $post_ids=array_column($posts,'id');$placeholders=implode(',',array_fill(0,count($post_ids),'?'));
                try {
                    $stmt_cl=$db->prepare("SELECT post_id,COUNT(*) AS clicks,COUNT(DISTINCT ip) AS unique_clicks FROM clicks_log WHERE post_id IN ($placeholders) GROUP BY post_id");
                    $stmt_cl->execute($post_ids);
                    foreach($stmt_cl->fetchAll() as $cl)$post_clicks[(int)$cl['post_id']]=['clicks'=>(int)$cl['clicks'],'unique_clicks'=>(int)$cl['unique_clicks']];
                }catch(\Throwable $e){error_log('[Postyar Dashboard] clicks: '.$e->getMessage());}
                foreach($posts as &$post){$pid=(int)$post['id'];$post['clicks']=$post_clicks[$pid]['clicks']??0;$post['unique_clicks']=$post_clicks[$pid]['unique_clicks']??0;}unset($post);
            }
        }catch(\Throwable $e){error_log('[Postyar Dashboard] posts: '.$e->getMessage());}

        $offers=[]; try{$stmt=$db->prepare("SELECT do.*,p.title AS plan_title FROM discount_offers do JOIN plans p ON do.plan_id=p.id WHERE do.user_id=? AND do.used=0");$stmt->execute([$tenant_id]);$offers=$stmt->fetchAll();}catch(\Throwable $e){error_log('[Postyar Dashboard] offers: '.$e->getMessage());}
        $inbox=[]; try{$stmt=$db->prepare("SELECT i.*,c.name AS channel_name FROM inbox i JOIN channels c ON i.channel_id=c.id WHERE i.tenant_id=? ORDER BY i.id DESC LIMIT 15");$stmt->execute([$tenant_id]);$inbox=$stmt->fetchAll();}catch(\Throwable $e){error_log('[Postyar Dashboard] inbox: '.$e->getMessage());}
        $tickets=[]; try{$stmt=$db->prepare("SELECT * FROM tickets WHERE user_id=? ORDER BY id DESC LIMIT 50");$stmt->execute([$tenant_id]);$tickets=$stmt->fetchAll();}catch(\Throwable $e){error_log('[Postyar Dashboard] tickets: '.$e->getMessage());}
        $ticket_categories=[];$category_map=[];
        try{$ticket_categories=$db->query("SELECT * FROM ticket_categories ORDER BY sort_order ASC,id ASC")->fetchAll();foreach($ticket_categories as $cat)$category_map[$cat['slug']]=$cat['title'];}catch(\Throwable $e){error_log('[Postyar Dashboard] ticket categories: '.$e->getMessage());}

        $announcement=null;$announcement_unread=false;
        try{
            $stmt=$db->prepare("SELECT key_value FROM settings WHERE tenant_id=0 AND key_name='global_announcement' LIMIT 1");$stmt->execute();$announcement_json=$stmt->fetchColumn();$announcement=$announcement_json?json_decode($announcement_json,true):null;
            if($announcement){$ann_id=$announcement['id']??($announcement['title']??'');$stmt_r=$db->prepare("SELECT key_value FROM settings WHERE tenant_id=? AND key_name='last_read_announcement_id' LIMIT 1");$stmt_r->execute([$tenant_id]);$announcement_unread=($stmt_r->fetchColumn()!==$ann_id);}
        }catch(\Throwable $e){error_log('[Postyar Dashboard] announcement: '.$e->getMessage());}

        $plans=[]; try{$plans=$db->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll();}catch(\Throwable $e){error_log('[Postyar Dashboard] plans: '.$e->getMessage());}

        $active_ads=[];$active_ads_by_placement=[];$my_ads=[];$my_ad_summary=['total'=>0,'active'=>0,'pending'=>0,'impressions'=>0,'clicks'=>0];$ad_placements=[];$ad_orders=[];
        try{$active_ads=\WHCM\Domain\Advertising::activeForPlacement('global_top',8);$active_ads_by_placement=['global_top'=>$active_ads];}catch(\Throwable $e){error_log('[Postyar Dashboard] active ads: '.$e->getMessage());}
        $ad_from=\WHCM\Domain\TextFormat::normalize_ad_date($_GET['ad_from']??null);
        $ad_to=\WHCM\Domain\TextFormat::normalize_ad_date($_GET['ad_to']??null);
        try{$my_ads=($ad_from||$ad_to)?\WHCM\Domain\Advertising::statsForOwner($tenant_id,$ad_from,$ad_to):\WHCM\Domain\Advertising::ownerCampaigns($tenant_id,50);}catch(\Throwable $e){error_log('[Postyar Dashboard] owner ads: '.$e->getMessage());}try{$my_ad_stats=\WHCM\Domain\Advertising::statsForOwner($tenant_id,$ad_from,$ad_to);foreach($my_ad_stats as $adStat){$my_ad_summary['total']++;if(($adStat['status']??'')==='approved')$my_ad_summary['active']++;if(($adStat['status']??'')==='pending')$my_ad_summary['pending']++;$my_ad_summary['impressions']+=(int)($adStat['impressions']??0);$my_ad_summary['clicks']+=(int)($adStat['clicks']??0);}}catch(\Throwable $e){error_log('[Postyar Dashboard] ad summary: '.$e->getMessage());}
        try{$ad_placements=\WHCM\Domain\AdSales::placements(true);}catch(\Throwable $e){error_log('[Postyar Dashboard] ad placements: '.$e->getMessage());}
        try{$ad_orders=\WHCM\Domain\AdSales::ownerOrders($tenant_id,50);}catch(\Throwable $e){error_log('[Postyar Dashboard] ad orders: '.$e->getMessage());}

        $subscription_history=[];try{$stmt=$db->prepare("SELECT s.*,p.title AS plan_title,p.price AS plan_price FROM subscriptions s LEFT JOIN plans p ON s.plan_id=p.id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 20");$stmt->execute([$tenant_id]);$subscription_history=$stmt->fetchAll();}catch(\Throwable $e){error_log('[Postyar Dashboard] subscriptions: '.$e->getMessage());}
        $payment_history=[];try{$stmt=$db->prepare("SELECT pay.*,p.title AS plan_title FROM payments pay LEFT JOIN plans p ON pay.plan_id=p.id WHERE pay.user_id=? ORDER BY pay.id DESC LIMIT 20");$stmt->execute([$tenant_id]);$payment_history=$stmt->fetchAll();}catch(\Throwable $e){error_log('[Postyar Dashboard] payments: '.$e->getMessage());}

        $this->render('dashboard',[
            'title'=>'داشبورد کاربری','user'=>Auth::user(),'quota'=>$quota,'channels'=>$channels,'edit_channel'=>$edit_channel,
            'settings'=>$settings,'auto_replies'=>$auto_replies,'responder_settings'=>$responder_settings,'posts'=>$posts,'offers'=>$offers,
            'inbox'=>$inbox,'tickets'=>$tickets,'ticket_categories'=>$ticket_categories,'category_map'=>$category_map,'announcement'=>$announcement,
            'announcement_unread'=>$announcement_unread,'user_notifications'=>$user_notifications,'unread_count'=>$unread_count,'plans'=>$plans,
            'active_ads'=>$active_ads,'my_ads'=>$my_ads,'ad_placements'=>$ad_placements,'ad_orders'=>$ad_orders,
            'subscription_history'=>$subscription_history,'payment_history'=>$payment_history,'csrf_field'=>Csrf::field(),'message'=>$this->getFlashMessage()
        ]);
    }

    /**
     * بروزرسانی مشخصات کاربری (نام و ایمیل)
     */
    public function handleUpdateProfile() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name) || empty($email)) {
            $this->setFlashMessage('تمامی فیلدها الزامی هستند.');
            $this->redirect('/dashboard');
        }

        $birthday = trim($_POST['birthday'] ?? '');

        $db = Bootstrap::getDB();
        // چک کردن یکتایی ایمیل برای دیگران
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $stmt->execute([$email, $tenant_id]);
        if ($stmt->fetch()) {
            $this->setFlashMessage('این نشانی ایمیل قبلاً توسط کاربر دیگری ثبت شده است.');
            $this->redirect('/dashboard');
        }

        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, birthday = ? WHERE id = ?");
        $stmt->execute([$name, $email, $birthday, $tenant_id]);

        $this->setFlashMessage('پروفایل کاربری شما با موفقیت بروزرسانی شد. ✔');
        $this->redirect('/dashboard');
    }

    /**
     * تغییر رمز عبور کاربر با تایید رمز فعلی
     */
    public function handleChangePassword() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            $this->setFlashMessage('پر کردن تمامی فیلدهای کلمه عبور الزامی است.');
            $this->redirect('/dashboard');
        }

        if ($new_pass !== $confirm_pass) {
            $this->setFlashMessage('رمز عبور جدید با تکرار آن مطابقت ندارد.');
            $this->redirect('/dashboard');
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$tenant_id]);
        $user_pass = $stmt->fetchColumn();

        if (!password_verify($current_pass, $user_pass)) {
            $this->setFlashMessage('کلمه عبور فعلی شما نادرست است.');
            $this->redirect('/dashboard');
        }

        $hashed = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $tenant_id]);
        \WHCM\Api\MobileApiAuth::revokeAllUserTokens($tenant_id);
        Session::regenerate();

        $this->setFlashMessage('کلمه عبور شما با موفقیت تغییر یافت. ✔');
        $this->redirect('/dashboard');
    }

    /**
     * ویرایش کامل کانال و لینک‌ها و دکمه‌های شیشه‌ای تعاملی
     */
    public function handleEditChannel() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        $id = (int)($_POST['channel_id'] ?? 0);

        $channel = ChannelManager::getChannel($id, $tenant_id);
        if (!$channel) {
            $this->setFlashMessage('کانال مورد نظر یافت نشد.');
            $this->redirect('/dashboard');
        }

        $name = trim($_POST['name'] ?? '');
        $platform = AntiAbuse::normalizePlatform($_POST['platform'] ?? '');
        $channel_id = AntiAbuse::normalizeChannelId($_POST['channel_id_val'] ?? '');
        $token = trim($_POST['token'] ?? '');

        if (empty($name) || empty($channel_id) || empty($token)) {
            $this->setFlashMessage('تمامی فیلدهای اصلی کانال را تکمیل کنید.');
            $this->redirect('/dashboard?edit_channel=' . $id);
        }

        // پردازش ساختار لینک‌های سه‌گانه
        $links = [
            ['name' => trim($_POST['link_name_1'] ?? ''), 'url' => trim($_POST['link_url_1'] ?? '')],
            ['name' => trim($_POST['link_name_2'] ?? ''), 'url' => trim($_POST['link_url_2'] ?? '')],
            ['name' => trim($_POST['link_name_3'] ?? ''), 'url' => trim($_POST['link_url_3'] ?? '')]
        ];

        // پردازش دکمه‌های شیشه‌ای تعاملی
        $buttons_active = isset($_POST['buttons_active']) ? true : false;
        $buttons = [
            ['text' => trim($_POST['btn_text_1'] ?? ''), 'url' => trim($_POST['btn_url_1'] ?? '')],
            ['text' => trim($_POST['btn_text_2'] ?? ''), 'url' => trim($_POST['btn_url_2'] ?? '')]
        ];

        $button_config = [
            'active' => $buttons_active,
            'buttons' => $buttons
        ];

        $db = Bootstrap::getDB();

        // Claim دائمی و رجیستری باید همراه UPDATE در یک تراکنش باشند.
        $db->beginTransaction();
        try {
            if ($channel['channel_id'] !== $channel_id || $channel['platform'] !== $platform) {
                $claimIdentity = strtolower(trim($platform)) . "\0" . trim($channel_id);
                $claimOwner = AntiAbuse::claimOwner($db, 'channel', $claimIdentity);
                if ($claimOwner !== null && $claimOwner !== $tenant_id) {
                    throw new \RuntimeException('این شناسه کانال قبلاً توسط کاربر دیگری Claim شده و قفل است.');
                }
                if ($claimOwner === null && !AntiAbuse::claimChannel($db, $tenant_id, $platform, $channel_id)) {
                    throw new \RuntimeException('این شناسه کانال همزمان توسط حساب دیگری Claim شد.');
                }

                $stmt = $db->prepare("SELECT owner_user_id FROM channel_registry WHERE platform = ? AND channel_id = ? LIMIT 1");
                $stmt->execute([$platform, $channel_id]);
                $reg = $stmt->fetch();
                if ($reg && (int)$reg['owner_user_id'] !== $tenant_id) {
                    throw new \RuntimeException('این شناسه کانال قبلاً توسط کاربر دیگری ثبت شده و قفل است.');
                }
                if (!$reg) {
                    $stmt = $db->prepare("INSERT INTO channel_registry (platform, channel_id, owner_user_id) VALUES (?, ?, ?)");
                    $stmt->execute([$platform, $channel_id, $tenant_id]);
                }
            }

            $stmt = $db->prepare("UPDATE channels SET name = ?, platform = ?, channel_id = ?, token = ?, link_config = ?, button_config = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([
                $name,
                $platform,
                $channel_id,
                $token,
                json_encode($links, JSON_UNESCAPED_UNICODE),
                json_encode($button_config, JSON_UNESCAPED_UNICODE),
                $id,
                $tenant_id
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $this->setFlashMessage($e->getMessage());
            $this->redirect('/dashboard?edit_channel=' . $id);
        }

        $this->setFlashMessage('تنظیمات کانال با موفقیت بروزرسانی شد. ✔');
        $this->redirect('/dashboard');
    }

    /**
     * عملیات افزودن کانال جدید
     */
    public function handleAddChannel() {
        $this->checkAuth();

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $name = trim($_POST['name'] ?? '');
        $platform = trim($_POST['platform'] ?? '');
        $channel_id = trim($_POST['channel_id'] ?? '');
        $token = trim($_POST['token'] ?? '');

        $res = ChannelManager::addChannel($name, $platform, $channel_id, $token);
        $this->setFlashMessage($res['message']);
        $this->redirect('/dashboard');
    }

    /**
     * عملیات حذف کانال (POST با CSRF)
     */
    public function handleDeleteChannel() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $id = (int)($_POST['channel_id'] ?? 0);

        if (ChannelManager::deleteChannel($id)) {
            $this->setFlashMessage('کانال با موفقیت حذف گردید (شناسه کانال به جهت قوانین ضدتقلب قفل باقی می‌ماند).');
        } else {
            $this->setFlashMessage('امکان حذف کانال وجود ندارد یا کانال متعلق به شما نیست.');
        }

        $this->redirect('/dashboard');
    }

    /**
     * عملیات ثبت پرداخت رسید مستقیم (کارت به کارت / بلو بانک)
     */
    public function handlePaymentSubmit() {
        $this->checkAuth();
        set_time_limit(60);

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        // Client-supplied amount is informational only; the server computes the authoritative price.
        $client_amount = (float)($_POST['amount'] ?? 0);
        $ref_num = trim($_POST['reference_num'] ?? '');

        // اعتبارسنجی وجود پلن قبل از ثبت پرداخت (جلوگیری از خطای FOREIGN KEY)
        if ($plan_id <= 0) {
            $this->setFlashMessage('خطا: پلن انتخابی نامعتبر است. لطفاً دوباره تلاش کنید.');
            $this->redirect('/dashboard');
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id, price FROM plans WHERE id = ? LIMIT 1");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch();
        if (!$plan) {
            $this->setFlashMessage('خطا: پلن انتخابی یافت نشد. ممکن است حذف شده باشد.');
            $this->redirect('/dashboard');
        }

        // قیمت نهایی فقط از دیتابیس و قوانین سمت سرور محاسبه می‌شود.
        try {
            $quote = PaymentPricing::quote($tenant_id, $plan_id);
            $amount = (float)$quote['amount'];
        } catch (\Throwable $e) {
            $this->setFlashMessage('امکان محاسبه مبلغ معتبر پلن وجود ندارد.');
            $this->redirect('/dashboard');
        }
        if ($amount <= 0) {
            $this->setFlashMessage('این پلن مبلغ قابل پرداخت معتبری ندارد.');
            $this->redirect('/dashboard');
        }
        if ($client_amount > 0 && abs($client_amount - $amount) > 0.01) {
            error_log('[Postyar] client payment amount mismatch for user #' . $tenant_id . ', plan #' . $plan_id . '; server amount used');
        }
        if ($ref_num === '' || strlen($ref_num) > 100) {
            $this->setFlashMessage('شماره پیگیری نامعتبر است.');
            $this->redirect('/dashboard');
        }

        // جلوگیری از ثبت دوباره همان شماره پیگیری برای یک کاربر.
        $dupStmt = $db->prepare("SELECT id FROM payments WHERE user_id = ? AND reference_num = ? LIMIT 1");
        $dupStmt->execute([$tenant_id, $ref_num]);
        if ($dupStmt->fetch()) {
            $this->setFlashMessage('این شماره پیگیری قبلاً ثبت شده است و از ثبت پرداخت تکراری جلوگیری شد.');
            $this->redirect('/dashboard');
        }

        // آپلود خودکار عکس رسید پرداخت به فرمت وب‌پی
        $receipt_photo = $this->uploadAndConvertToWebp('receipt_photo', 'receipts');

        // ثبت پرداخت با وضعیت در انتظار تایید به همراه عکس رسید
        try {
            $stmt = $db->prepare("INSERT INTO payments (user_id, plan_id, amount, quoted_amount, reference_num, payment_method, status, receipt_photo) VALUES (?, ?, ?, ?, ?, 'card_to_card', 'pending', ?)");
            $stmt->execute([$tenant_id, $plan_id, $amount, $amount, $ref_num, $receipt_photo]);
        } catch (\PDOException $e) {
            error_log('[Postyar] Payment insert failed for user #' . $tenant_id . ', plan #' . $plan_id . ': ' . $e->getMessage());
            $this->setFlashMessage('خطا در ثبت رسید پرداخت. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.');
            $this->redirect('/dashboard');
        }

        $this->setFlashMessage('رسید پرداخت شما با موفقیت ثبت شد و در صف تایید مدیر قرار گرفت. پس از بررسی، اشتراک شما فعال خواهد شد.');
        $this->redirect('/dashboard');
    }

    /**
     * پنل مدیریت کل (Super Admin) و پشتیبان
     */
    public function admin() {
        // جلوگیری از کش شدن پنل مدیریت در هاست اشتراکی و مرورگر کاربر
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        // پشتیبان فقط دسترسی تیکت دارد
        $is_support = Auth::isSupportAgent();
        if ($is_support) {
            $this->checkAdminOrSupport();
        } else {
            $this->checkSuperAdmin();
        }

        $db = Bootstrap::getDB();

        // سیستم Pagination — تعداد آیتم در هر صفحه
        $per_page = 20;
        $current_page = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        // ۱. لیست کاربران با JOIN بهینه + Pagination
        $admin_id = Auth::tenantId() ?: 0;
        $stmt_users = $db->prepare("
            SELECT u.id, u.name, u.email, u.role, u.status, u.created_at,
                   u.business_name, u.business_type,
                   COALESCE(c.cnt, 0) as channel_count,
                   COALESCE(pc.cnt, 0) as posts_count,
                   COALESCE(tc.cnt, 0) as tickets_count,
                   COALESCE(ps.total_spent, 0) as total_spent,
                   s.end_date,
                   p.title as plan_title
            FROM users u
            LEFT JOIN (SELECT tenant_id, COUNT(*) as cnt FROM channels GROUP BY tenant_id) c ON c.tenant_id = u.id
            LEFT JOIN (SELECT tenant_id, COUNT(*) as cnt FROM posts GROUP BY tenant_id) pc ON pc.tenant_id = u.id
            LEFT JOIN (SELECT user_id, COUNT(*) as cnt FROM tickets GROUP BY user_id) tc ON tc.user_id = u.id
            LEFT JOIN (SELECT user_id, COALESCE(SUM(amount), 0) as total_spent FROM payments WHERE status = 'approved' GROUP BY user_id) ps ON ps.user_id = u.id
            LEFT JOIN (SELECT user_id, plan_id, end_date FROM subscriptions WHERE status = 'active' GROUP BY user_id) s ON s.user_id = u.id
            LEFT JOIN plans p ON s.plan_id = p.id
            WHERE u.id != ?
            ORDER BY u.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt_users->bindValue(1, $admin_id, \PDO::PARAM_INT);
        $stmt_users->bindValue(2, $per_page, \PDO::PARAM_INT);
        $stmt_users->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt_users->execute();
        $users = $stmt_users->fetchAll();

        // اضافه کردن تاریخ شمسی برای modal پروفایل ۳۶۰ درجه
        foreach ($users as &$u) {
            $u['created_at_fa'] = TextFormat::mysql_to_jalali($u['created_at']);
            $u['end_date_fa'] = (!empty($u['end_date']) && $u['end_date'] !== '2099-12-31 23:59:59')
                ? TextFormat::mysql_to_jalali($u['end_date'], false)
                : 'بدون انقضا / دائمی';
        }
        unset($u);

        // تعداد کل کاربران برای pagination (prepared statement — جلوگیری از SQL injection)
        $stmt_count = $db->prepare("SELECT COUNT(*) FROM users WHERE id != ?");
        $stmt_count->execute([$admin_id]);
        $total_users = (int)$stmt_count->fetchColumn();
        $total_user_pages = max(1, (int)ceil($total_users / $per_page));

        // ۲. پرداخت‌ها — فقط ۵۰ رکورد آخر (با pagination مشابه)
        $stmt_payments = $db->prepare("
            SELECT p.*, u.name as user_name, u.email as user_email, pl.title as plan_title 
            FROM payments p 
            JOIN users u ON p.user_id = u.id 
            JOIN plans pl ON p.plan_id = pl.id 
            ORDER BY p.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt_payments->bindValue(1, $per_page, \PDO::PARAM_INT);
        $stmt_payments->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt_payments->execute();
        $payments = $stmt_payments->fetchAll();

        $stmt_count_payments = $db->prepare("SELECT COUNT(*) FROM payments");
        $stmt_count_payments->execute();
        $total_payments = (int)$stmt_count_payments->fetchColumn();
        $total_payment_pages = max(1, (int)ceil($total_payments / $per_page));

        // ۳. لیست پلن‌های فعال
        $plans = $db->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll();

        // ۴. بررسی وجود درخواست ویرایش یک پلن اشتراکی خاص
        $edit_plan = null;
        $edit_plan_id = (int)($_GET['edit_plan'] ?? 0);
        if ($edit_plan_id > 0) {
            $stmt = $db->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
            $stmt->execute([$edit_plan_id]);
            $edit_plan = $stmt->fetch() ?: null;
        }

        // ۵. تیکت‌ها — فقط ۵۰ رکورد آخر
        $stmt_tickets = $db->prepare("
            SELECT t.*, u.name as user_name, u.email as user_email 
            FROM tickets t 
            JOIN users u ON t.user_id = u.id 
            ORDER BY (t.status = 'open') DESC, t.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt_tickets->bindValue(1, $per_page, \PDO::PARAM_INT);
        $stmt_tickets->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt_tickets->execute();
        $tickets = $stmt_tickets->fetchAll();

        $stmt_count_tickets = $db->prepare("SELECT COUNT(*) FROM tickets");
        $stmt_count_tickets->execute();
        $total_tickets = (int)$stmt_count_tickets->fetchColumn();
        $total_ticket_pages = max(1, (int)ceil($total_tickets / $per_page));

        // ۶. آمار داشبورد مدیریت
        $active_users_count = (int)$db->query("SELECT COUNT(*) FROM users WHERE id != " . (int)$admin_id . " AND status = 'active'")->fetchColumn();
        $pending_p_count = (int)$db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
        $open_t_count = (int)$db->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
        $active_subs_count = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn();
        $total_channels = (int)$db->query("SELECT COUNT(*) FROM channels")->fetchColumn();
        $total_revenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'approved'")->fetchColumn();

        // دریافت دسته‌بندی‌های تیکت و لیست پشتیبان‌ها
        try {
            $ticket_categories = $db->query("SELECT * FROM ticket_categories ORDER BY sort_order ASC, id ASC")->fetchAll();
        } catch (\Throwable $e) { $ticket_categories = []; }
        try {
            $support_agents = $db->query("SELECT id, name, email FROM users WHERE role = 'support_agent' AND status = 'active' ORDER BY id ASC")->fetchAll();
        } catch (\Throwable $e) { $support_agents = []; }

        // ۷. دریافت تنظیمات سراسری (tenant_id=0) برای فرم‌های ادمین
        $admin_settings = [];
        $stmt_settings = $db->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = 0");
        $stmt_settings->execute([]);
        foreach ($stmt_settings->fetchAll() as $row) {
            $admin_settings[$row['key_name']] = $row['key_value'];
        }

        // ۸. دریافت کدهای تخفیف فعال
        $discounts = $db->query("SELECT * FROM discount_codes ORDER BY id DESC")->fetchAll();

        // Wave R — مدیریت کامل تبلیغات و آمار تفکیک‌شده
        $ad_from = \WHCM\Domain\TextFormat::normalize_ad_date($_GET['ad_from'] ?? null);
        $ad_to = \WHCM\Domain\TextFormat::normalize_ad_date($_GET['ad_to'] ?? null);
        try { $ad_campaigns = \WHCM\Domain\Advertising::adminCampaigns(200); } catch (\Throwable $e) { error_log('[Postyar Admin] ad campaigns: '.$e->getMessage()); $ad_campaigns=[]; }
        try { $ad_stats = \WHCM\Domain\Advertising::statsForAdmin($ad_from, $ad_to); } catch (\Throwable $e) { error_log('[Postyar Admin] ad stats: '.$e->getMessage()); $ad_stats=[]; }
        try { $ad_orders = \WHCM\Domain\AdSales::adminOrders(300); } catch (\Throwable $e) { error_log('[Postyar Admin] ad orders: '.$e->getMessage()); $ad_orders=[]; }
        try {
            $ad_order_ids=array_map(static fn($row)=>(int)($row['id']??0),$ad_orders);
            $ad_order_creatives=\WHCM\Domain\AdSales::adminOrderCreatives($ad_order_ids);
        } catch (\Throwable $e) {
            error_log('[Postyar Admin] ad order creatives: '.$e->getMessage());
            $ad_order_creatives=[];
        }

        $ad_submitted_count=0; $ad_payment_pending_count=0;
        foreach($ad_orders as $adOrder){
            if(($adOrder['status']??'')==='submitted') $ad_submitted_count++;
            if(($adOrder['payment_status']??'')==='pending_verification') $ad_payment_pending_count++;
        }

        // Wave R.3 — centralized integration/provider configuration catalog. Secrets are masked in views.
        $payment_providers = \WHCM\Payments\PaymentProviderRegistry::all();
        $sms_providers = \WHCM\Core\SmsProviderRegistry::all();
        $provider_settings = [];
        foreach ($db->query("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND (key_name LIKE 'payment_gateway_%' OR key_name LIKE 'sms_provider_%' OR key_name LIKE 'smtp_%')")->fetchAll() as $row) {
            $provider_settings[$row['key_name']] = str_contains($row['key_name'], 'password') || str_contains($row['key_name'], 'api_key') || str_contains($row['key_name'], 'secret') ? '••••••••' : $row['key_value'];
        }

        $this->render('admin', [
            'title' => $is_support ? 'پنل پشتیبانی' : 'پنل مدیریت ارشد کل',
            'is_support' => $is_support,
            'users' => $users,
            'total_user_pages' => $total_user_pages,
            'current_page' => $current_page,
            'payments' => $payments,
            'plans' => $plans,
            'edit_plan' => $edit_plan,
            'tickets' => $tickets,
            'ticket_categories' => $ticket_categories,
            'support_agents' => $support_agents,
            'admin_settings' => $admin_settings,
            'discounts' => $discounts ?? [],
            'ad_campaigns' => $ad_campaigns,
            'ad_stats' => $ad_stats,
            'ad_orders' => $ad_orders,
            'ad_order_creatives' => $ad_order_creatives,
            'ad_submitted_count' => $ad_submitted_count,
            'ad_payment_pending_count' => $ad_payment_pending_count,
            'payment_providers' => $payment_providers,
            'sms_providers' => $sms_providers,
            'provider_settings' => $provider_settings,
            'csrf_field' => Csrf::field(),
            'message' => $this->getFlashMessage(),
            'total_users' => $total_users,
            'active_users_count' => $active_users_count,
            'pending_p_count' => $pending_p_count,
            'open_t_count' => $open_t_count,
            'active_subs_count' => $active_subs_count,
            'total_channels' => $total_channels,
            'total_revenue' => $total_revenue
        ]);
    }

    public function handleApprovePayment(){ return (new \WHCM\Modules\Billing\Controllers\PaymentController)->approve(); }
    public function handleCreatePlan(){ return (new \WHCM\Modules\Billing\Controllers\PlanController)->create(); }
    /**
     * ثبت کمپین تبلیغاتی کاربر — همیشه با وضعیت pending تا مدیر بررسی کند.
     */
    /** Wave R.2: submit a paid advertising order with one or more creatives/placements. */
    public function handleCreateAdOrder(): void {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی!');
            $this->redirect('/dashboard');
        }

        $ownerId=Auth::tenantId();
        $placements=['global_top'];
        $creatives=[];
        $storedFiles=[];
        $titles=(array)($_POST['creative_title']??[]);
        $bodies=(array)($_POST['creative_body']??[]);
        $destinations=(array)($_POST['creative_destination']??[]);
        $files=$_FILES['creative_image']??[];
        $fileCount=isset($files['name'])&&is_array($files['name'])?count($files['name']):0;
        $count=max(count($titles),count($bodies),count($destinations),$fileCount);
        if($count<1) $count=1;
        if($count>10){
            $this->setFlashMessage('تعداد اسلایدهای تبلیغاتی نمی‌تواند بیشتر از ۱۰ اسلاید باشد.');
            $this->redirect('/dashboard');
        }

        try {
            for($i=0;$i<$count;$i++){
                $image='';
                $hasUpload=isset($files['name'][$i])&&trim((string)$files['name'][$i])!=='';
                if($hasUpload){
                    $uploadError=(int)($files['error'][$i]??UPLOAD_ERR_NO_FILE);
                    if($uploadError!==UPLOAD_ERR_OK){
                        throw new \InvalidArgumentException('آپلود تصویر اسلاید '.($i+1).' ناموفق بود. لطفاً تصویر را دوباره انتخاب کنید.');
                    }
                    $single=[
                        'name'=>$files['name'][$i],
                        'type'=>$files['type'][$i]??'',
                        'tmp_name'=>$files['tmp_name'][$i],
                        'error'=>$files['error'][$i],
                        'size'=>$files['size'][$i]??0,
                    ];
                    $_FILES['_ad_single']=$single;
                    $image=$this->uploadAndConvertToWebp('_ad_single','uploads/ads');
                    unset($_FILES['_ad_single']);
                    if($image===''||strtolower(pathinfo(parse_url($image,PHP_URL_PATH)??$image,PATHINFO_EXTENSION))!=='webp'){
                        throw new \InvalidArgumentException('تصویر اسلاید '.($i+1).' قابل پردازش نیست. تصویر باید به‌صورت امن به WEBP تبدیل شود.');
                    }
                    $storedFiles[]=$image;
                }
                $creatives[]=[
                    'title'=>trim((string)($titles[$i]??'')),
                    'body_text'=>trim((string)($bodies[$i]??'')),
                    'image_url'=>$image,
                    'destination_url'=>trim((string)($destinations[$i]??'')),
                ];
            }

            $orderId=\WHCM\Domain\AdSales::createRequest($ownerId,[
                'starts_at'=>$_POST['ad_starts_at']??'',
                'ends_at'=>$_POST['ad_ends_at']??'',
                'placements'=>$placements,
                'creatives'=>$creatives,
                'user_notes'=>$_POST['user_notes']??'',
            ]);
            try {
                $adminIds=Bootstrap::getDB()->query("SELECT id FROM users WHERE role IN ('superadmin','admin')")->fetchAll(\PDO::FETCH_COLUMN);
                foreach($adminIds as $adminId){ \WHCM\Domain\Notification::create((int)$adminId,'درخواست تبلیغ جدید','یک درخواست تبلیغ جدید برای بررسی و قیمت‌گذاری ثبت شده است.','advertising','ads'); }
            } catch(\Throwable $notificationError) {
                \WHCM\Core\Logger::warning('ad_order_admin_notification_failed',['order_id'=>$orderId,'reason'=>$notificationError->getMessage()]);
            }
            $this->setFlashMessage('درخواست تبلیغات ثبت شد و برای بررسی و قیمت‌گذاری مدیر ارسال گردید. پس از تأیید مبلغ، امکان پرداخت فعال می‌شود.');
        } catch(\InvalidArgumentException $e) {
            foreach($storedFiles as $file){$this->deletePublicUploadedFile($file);}
            \WHCM\Core\Logger::warning('ad_order_create_rejected',['user_id'=>$ownerId,'reason'=>$e->getMessage()]);
            $this->setFlashMessage($e->getMessage());
        } catch(\Throwable $e) {
            foreach($storedFiles as $file){$this->deletePublicUploadedFile($file);}
            \WHCM\Core\Logger::warning('ad_order_create_failed',['user_id'=>$ownerId,'reason'=>$e->getMessage()]);
            $this->setFlashMessage('ثبت درخواست تبلیغات به دلیل یک خطای فنی انجام نشد. لطفاً اطلاعات فرم را دوباره بررسی کنید.');
        }
        $this->redirect('/dashboard');
    }

    /** حذف فایل آپلودشده تبلیغ در صورت شکست تراکنش تا فایل یتیم روی هاست باقی نماند. */
    private function deletePublicUploadedFile(string $url): void {
        $path=parse_url($url,PHP_URL_PATH);
        if(!$path) return;
        $publicRoot=realpath(__DIR__.'/../../public');
        if(!$publicRoot) return;
        $candidate=realpath($publicRoot.'/'.ltrim($path,'/'));
        if($candidate && strpos($candidate,$publicRoot.DIRECTORY_SEPARATOR)===0 && is_file($candidate)) @unlink($candidate);
    }

    /** Wave R.2: card-to-card payment submission after admin quotation. */
    public function handleAdCardPayment(): void {
        $this->checkAuth();
        if(!Csrf::validate($_POST['csrf_token']??null)){$this->setFlashMessage('خطای امنیتی!');$this->redirect('/dashboard');}
        $receipt=$this->uploadAndConvertToWebp('ad_receipt','receipts');
        $ok=\WHCM\Domain\AdSales::submitCardPayment((int)($_POST['order_id']??0),Auth::tenantId(),trim((string)($_POST['payment_reference']??'')),$receipt);
        $this->setFlashMessage($ok?'رسید پرداخت تبلیغات ثبت شد و در انتظار تایید مدیر است.':'ثبت پرداخت انجام نشد؛ مبلغ باید قبلاً توسط مدیر تایید شده باشد.');
        $this->redirect('/dashboard');
    }

    /** ثبت مستقیم تبلیغ توسط مدیر. */
    public function handleAdminCreateAd(): void {
        $this->checkSuperAdmin();
        if(!Csrf::validate($_POST['csrf_token']??null)){$this->setFlashMessage('خطای امنیتی!');$this->redirect('/hnnh');}
        $image='';
        try{
            if(empty($_FILES['manual_ad_image']['name'])) throw new \InvalidArgumentException('انتخاب تصویر تبلیغ الزامی است.');
            $image=$this->uploadAndConvertToWebp('manual_ad_image','uploads/ads');
            $owner=(int)($_POST['owner_user_id']??Auth::tenantId());
            $id=\WHCM\Domain\AdSales::createManualCampaign($owner,[
                'title'=>$_POST['title']??'','image_url'=>$image,'destination_url'=>$_POST['destination_url']??'',
                'starts_at'=>$_POST['starts_at']??'','ends_at'=>$_POST['ends_at']??'','placements'=>['global_top']
            ]);
            $this->setFlashMessage('تبلیغ با موفقیت ثبت و فعال شد.');
        }catch(\Throwable $e){if($image!=='')$this->deletePublicUploadedFile($image);$this->setFlashMessage($e instanceof \InvalidArgumentException?$e->getMessage():'ثبت تبلیغ انجام نشد.');}
        $this->redirect('/hnnh');
    }

    /** Admin sets the authoritative advertising quote. */
    public function handleAdQuote(): void {
        $this->checkSuperAdmin();
        if(!Csrf::validate($_POST['csrf_token']??null)){$this->setFlashMessage('خطای امنیتی!');$this->redirect('/hnnh');}
        $ok=\WHCM\Domain\AdSales::quote((int)($_POST['order_id']??0),((float)($_POST['quoted_amount']??0))*10,Auth::tenantId(),trim((string)($_POST['admin_notes']??'')));
        $this->setFlashMessage($ok?'مبلغ تبلیغات تایید و برای پرداخت کاربر ارسال شد.':'قیمت‌گذاری انجام نشد.');
        $this->redirect('/hnnh');
    }

    /** Admin verifies a card-to-card advertising payment and activates the linked campaign atomically. */
    public function handleAdPaymentApprove(): void {
        $this->checkSuperAdmin();
        if(!Csrf::validate($_POST['csrf_token']??null)){$this->setFlashMessage('خطای امنیتی!');$this->redirect('/hnnh');}
        $ok=\WHCM\Domain\AdSales::approveCardPayment((int)($_POST['order_id']??0),Auth::tenantId());
        $this->setFlashMessage($ok?'پرداخت تایید شد و تبلیغ واجد شرایط نمایش شد.':'تایید پرداخت انجام نشد یا قبلاً پردازش شده است.');
        $this->redirect('/hnnh');
    }

    /** Legacy advertising endpoint retained for compatibility, but forced into the paid workflow. */
    public function handleCreateAd(): void {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/dashboard'); }
        $ownerId=Auth::tenantId();
        $imageUrl=$this->uploadAndConvertToWebp('ad_image','uploads/ads');
        try {
            $orderId=\WHCM\Domain\AdSales::createRequest($ownerId,[
                'starts_at'=>$_POST['ad_starts_at']??'', 'ends_at'=>$_POST['ad_ends_at']??'',
                'placements'=>['global_top'],
                'creatives'=>[[
                    'title'=>trim((string)($_POST['ad_title']??'')), 'body_text'=>'', 'image_url'=>$imageUrl,
                    'destination_url'=>trim((string)($_POST['ad_destination_url']??''))
                ]]
            ]);
            $this->setFlashMessage('درخواست تبلیغات ثبت شد و برای قیمت‌گذاری مدیر ارسال گردید.');
        } catch(\Throwable $e) {
            \WHCM\Core\Logger::warning('legacy_ad_forced_into_sales_workflow',['user_id'=>$ownerId,'reason'=>$e->getMessage()]);
            $this->setFlashMessage('ثبت درخواست تبلیغات انجام نشد.');
        }
        $this->redirect('/dashboard');
    }

    /** ثبت impression تبلیغ عمومی؛ پاسخ JSON برای beacon/fetch. */
    public function recordAdImpression(): void {
        $id=(int)($_POST['ad_id'] ?? $_GET['ad_id'] ?? 0);
        if ($id <= 0 || !\WHCM\Core\RateLimit::consume('web_ad_impression', 60, 60, (string)$id)) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'rate_limited'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok=\WHCM\Domain\Advertising::recordEvent($id, 'impression', Auth::check()?Auth::tenantId():null);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>$ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** کلیک تبلیغ: فقط مقصدی که قبلاً در کمپین معتبر شده قابل redirect است. */
    public function handleAdClick(): void {
        $id=(int)($_GET['id'] ?? 0);
        if ($id <= 0 || !\WHCM\Core\RateLimit::consume('web_ad_click', 30, 60, (string)$id)) {
            http_response_code(429);
            $this->redirect('/');
        }
        $ad=\WHCM\Domain\Advertising::findPublic($id);
        if (!$ad) { http_response_code(404); $this->redirect('/'); }
        \WHCM\Domain\Advertising::recordEvent($id, 'click', Auth::check()?Auth::tenantId():null);
        $url=$ad['destination_url'];
        if (!\WHCM\Domain\Advertising::validateDestination($url)) { $this->redirect('/'); }
        header('Cache-Control: no-store');
        header('Location: '.$url, true, 302);
        exit;
    }

    /** خروجی CSV گزارش تبلیغات — فقط خواندنی و بدون تغییر وضعیت. */
    public function exportAdReport(): void {
        $this->checkSuperAdmin();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : null;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : null;
        $csv=\WHCM\Domain\Advertising::exportCsv(null,true,$from,$to);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="postyar-ads-report.csv"');
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit;
    }

    /** حذف کامل کمپین تبلیغاتی توسط مدیر ارشد. */
    public function handleAdDelete(): void {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id=(int)($_POST['ad_id'] ?? 0);
        $ok=\WHCM\Domain\Advertising::deleteCampaign($id);
        $this->setFlashMessage($ok?'تبلیغ با موفقیت حذف شد.':'حذف تبلیغ انجام نشد.');
        $this->redirect('/hnnh');
    }

    /** تأیید/رد/توقف کمپین تبلیغاتی توسط سوپرادمین. */
    public function handleAdStatus(): void {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id=(int)($_POST['ad_id'] ?? 0);
        $status=trim((string)($_POST['status'] ?? ''));
        $ok=\WHCM\Domain\Advertising::setStatus($id,$status,Auth::tenantId());
        $this->setFlashMessage($ok?'وضعیت آگهی با موفقیت تغییر کرد.':'تغییر وضعیت آگهی انجام نشد.');
        $this->redirect('/hnnh');
    }

    /**
     * ردیابی کلیک و انتقال به لینک مقصد نهایی
     */
    public function handleClick() {
        $post_id = (int)($_GET['p'] ?? 0);
        $channel_id = (int)($_GET['c'] ?? 0);

        if ($post_id > 0 && $channel_id > 0) {
            $db = Bootstrap::getDB();

            // Wave K: یک کلیک فقط زمانی معتبر است که کانال واقعاً متعلق به همان پست باشد.
            // این مانع جعل آمار با ترکیب دلخواه post_id/channel_id و افشای تنظیمات کانال دیگران می‌شود.
            $stmt = $db->prepare("
                SELECT c.id, c.link_config
                FROM channels c
                INNER JOIN posts p ON p.tenant_id = c.tenant_id
                WHERE c.id = ? AND p.id = ?
                LIMIT 1
            ");
            $stmt->execute([$channel_id, $post_id]);
            $channel = $stmt->fetch();
            if (!$channel) {
                $this->redirect('/');
            }

            $targetStmt = $db->prepare("SELECT target_channels FROM posts WHERE id = ? LIMIT 1");
            $targetStmt->execute([$post_id]);
            $target = $targetStmt->fetchColumn();
            $targetChannels = json_decode((string)$target, true);
            if (!is_array($targetChannels) || !in_array($channel_id, array_map('intval', $targetChannels), true)) {
                $this->redirect('/');
            }

            // ثبت لاگ کلیک برای آنالیتیکس تفکیکی
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $stmt = $db->prepare("INSERT INTO clicks_log (post_id, channel_id, ip, user_agent) VALUES (?, ?, ?, ?)");
            $stmt->execute([$post_id, $channel_id, $ip, $ua]);

            // بروزرسانی آمار تجمیعی کلیک‌ها فقط برای جفت معتبر پست/کانال
            $stmt = $db->prepare("UPDATE post_channel_stats SET clicks = clicks + 1 WHERE post_id = ? AND channel_id = ?");
            $stmt->execute([$post_id, $channel_id]);

            if ($channel) {
                $links = json_decode($channel['link_config'] ?? '[]', true);
                // لینک دوم معمولاً لینک مستقیم فروشگاه یا ارجاعی اول است
                $url = !empty($links[0]['url']) ? $links[0]['url'] : '/';
                $this->redirect($url);
            }
        }

        $this->redirect('/');
    }

    /**
     * دریافت اطلاعات وبهوک‌های ربات‌ها (با اعتبارسنجی امنیتی secret_token)
     */
    public function handleApiWebhook() {
        $channel_id = (int)($_GET['channel_id'] ?? 0);
        if ($channel_id <= 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'شناسه کانال نامعتبر است.']);
            exit;
        }

        $db = Bootstrap::getDB();
        // Wave K: webhook باید علاوه بر شناسه کانال، secret اختصاصی همان کانال را هم داشته باشد.
        // این endpoint عمومی است؛ بدون این بررسی، هر شخصی می‌توانست payload جعلی به Inbox تزریق کند.
        $stmt = $db->prepare("SELECT * FROM channels WHERE id = ? LIMIT 1");
        $stmt->execute([$channel_id]);
        $channel = $stmt->fetch();

        if (!$channel) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'کانال یافت نشد.']);
            exit;
        }

        // ---- اعتبارسنجی secret_token ----
        // Telegram secret از هدر می‌آید؛ برای Bale/سایر webhookها secret در query ارسال می‌شود.
        $header_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        $query_secret = (string)($_GET['secret'] ?? '');
        $providedSecret = $channel['platform'] === 'telegram' && $header_secret !== ''
            ? $header_secret
            : $query_secret;
        $expectedSecret = (string)($channel['webhook_secret'] ?? '');
        if ($expectedSecret === '' || $providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'توکن امنیتی نامعتبر است.']);
            exit;
        }

        Inbox::handleWebhook($channel);
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * ذخیره تنظیمات ربات هوشمند طلا و سکه مستأجر
     */
    public function handleSaveGoldSettings() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            if ($this->isAjax()) { echo json_encode(['success'=>false,'message'=>'خطای امنیتی'],JSON_UNESCAPED_UNICODE); return; }
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        $db = Bootstrap::getDB();

        $schedule = trim($_POST['gold_schedule'] ?? 'manual');
        $api_url = trim($_POST['gold_api_url'] ?? '');
        $currency = trim($_POST['gold_currency'] ?? 'toman');
        $template = trim($_POST['gold_template'] ?? '');
        
        // آپلود فیزیکی عکس طلا به صورت خودکار به وب‌پی
        $image_url = $this->uploadAndConvertToWebp('gold_image', 'uploads');
        if (empty($image_url)) {
            // حفظ تصویر قبلی در صورت عدم آپلود تصویر جدید
            $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = 'gold_image_url' LIMIT 1");
            $stmt->execute([$tenant_id]);
            $image_url = $stmt->fetchColumn() ?: '';
        }

        $channel_ids = isset($_POST['gold_channels']) ? array_map('intval', $_POST['gold_channels']) : [];

        // تراکنش ایمن جهت ذخیره‌سازی گروهی تنظیمات مستأجر
        $settings_to_save = [
            'gold_schedule' => $schedule,
            'gold_api_url' => $api_url,
            'gold_currency' => $currency,
            'gold_template' => $template,
            'gold_image_url' => $image_url,
            'gold_auto_channels' => json_encode($channel_ids)
        ];

        $this->saveSettingsBatch($tenant_id, $settings_to_save);

        if ($this->isAjax()) {
            echo json_encode(['success'=>true,'message'=>'تنظیمات ربات نرخ طلا با موفقیت ذخیره گردید. 🪙'],JSON_UNESCAPED_UNICODE);
            return;
        }
        $this->setFlashMessage('تنظیمات ربات نرخ طلا با موفقیت ذخیره گردید. 🪙');
        $this->redirect('/dashboard');
    }

    /**
     * شبیه‌سازی و تست انتشار دستی و آنی نرخ طلا توسط مستأجر
     */
    public function handleTriggerGoldPublish() {
        $this->checkAuth();
        $tenant_id = Auth::tenantId();

        $db = Bootstrap::getDB();
        // دریافت آدرس API مستأجر
        $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = 'gold_api_url' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $custom_api = $stmt->fetchColumn();

        $vals = GoldTicker::fetchValues($custom_api ?: '');
        if (!$vals['success']) {
            $this->setFlashMessage('خطا در دریافت نرخ از API: ' . $vals['message']);
            $this->redirect('/dashboard');
        }

        // دریافت کانال‌های هدف
        $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = 'gold_auto_channels' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $channels_json = $stmt->fetchColumn();
        $channel_ids = $channels_json ? json_decode($channels_json, true) : [];

        if (empty($channel_ids)) {
            // ارسال به همه کانال‌های فعال در صورت عدم انتخاب اختصاصی
            $stmt = $db->prepare("SELECT id FROM channels WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            $channel_ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        if (empty($channel_ids)) {
            $this->setFlashMessage('خطا: هیچ کانالی برای ارسال خودکار طلا متصل یا انتخاب نشده است.');
            $this->redirect('/dashboard');
        }

        $title = 'اعلام نرخ لحظه‌ای بازار طلا و سکه';
        $content = GoldTicker::buildMessage($tenant_id, $vals);
        
        // دریافت عکس پیش‌فرض طلا
        $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = 'gold_image_url' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $image = $stmt->fetchColumn() ?: '';

        // Manual gold publish participates in the same quota/claim invariant as every
        // other outbound post. This prevents a user from bypassing max_posts through
        // the gold shortcut.
        $stmt = $db->prepare("INSERT INTO posts (tenant_id, title, content, media_url, status, target_channels) VALUES (?, ?, ?, ?, 'queued', ?)");
        $stmt->execute([$tenant_id, $title, $content, $image, json_encode($channel_ids, JSON_UNESCAPED_UNICODE)]);
        $post_id = (int)$db->lastInsertId();

        if (!\WHCM\Domain\Quota::reservePost($tenant_id, $post_id)) {
            $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'queued'")->execute([$post_id, $tenant_id]);
            $this->setFlashMessage('سهمیه ارسال پست شما به پایان رسیده است.');
            $this->redirect('/dashboard');
        }

        $res = Sender::sendPostToChannels($tenant_id, $channel_ids, $title, $content, $image, $post_id);

        if ($res['success']) {
            \WHCM\Domain\Quota::consumePostQuota($tenant_id, $post_id);
            $this->setFlashMessage('انتشار آنی و موفقیت‌آمیز نرخ طلا به تمام کانال‌های هدف انجام گردید! ⚡🪙');
        } else {
            $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ? AND tenant_id = ? AND status = 'sending'")->execute([$post_id, $tenant_id]);
            $this->setFlashMessage('ارسال پیام با خطا مواجه شد. جزئیات کانال‌ها را بررسی کنید.');
        }

        $this->redirect('/dashboard');
    }

    /**
     * افزودن پاسخ خودکار جدید
     */
    public function handleAddAutoReply() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        $channel_id = (int)$_POST['channel_id'];
        $keyword = trim($_POST['keyword'] ?? '');
        $reply_text = trim($_POST['reply_text'] ?? '');

        if (empty($keyword) || empty($reply_text) || $channel_id <= 0) {
            $this->setFlashMessage('تمامی فیلدها الزامی هستند.');
            $this->redirect('/dashboard');
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("INSERT INTO auto_replies (tenant_id, channel_id, keyword, reply_text, active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$tenant_id, $channel_id, $keyword, $reply_text]);

        $this->setFlashMessage('پاسخ خودکار جدید با موفقیت اضافه شد. 🤖');
        $this->redirect('/dashboard');
    }

    /**
     * حذف کلمه کلیدی پاسخگو (POST با CSRF)
     */
    public function handleDeleteAutoReply() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        $id = (int)($_POST['reply_id'] ?? 0);

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("DELETE FROM auto_replies WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);

        $this->setFlashMessage('پاسخ خودکار کلمه کلیدی با موفقیت حذف گردید.');
        $this->redirect('/dashboard');
    }

    /**
     * علامت‌گذاری اعلان همگانی به عنوان خوانده‌شده (AJAX)
     */
    public function handleMarkAnnouncementRead() {
        header('Content-Type: application/json; charset=utf-8');
        $this->checkAuth();
        $tenant_id = Auth::tenantId();
        $db = Bootstrap::getDB();

        // ذخیره شناسه اعلان خوانده‌شده در تنظیمات کاربر
        $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = ? AND key_name = 'last_read_announcement_id' LIMIT 1");
        $stmt->execute([$tenant_id]);

        // دریافت شناسه اعلان فعلی
        $stmt_a = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = 'global_announcement' LIMIT 1");
        $stmt_a->execute();
        $ann_json = $stmt_a->fetchColumn();
        $ann_id = '';
        if ($ann_json) {
            $ann = json_decode($ann_json, true);
            $ann_id = $ann['id'] ?? ($ann['title'] ?? '');
        }

        if ($stmt->fetch()) {
            $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = 'last_read_announcement_id'")->execute([$ann_id, $tenant_id]);
        } else {
            $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, 'last_read_announcement_id', ?)")->execute([$tenant_id, $ann_id]);
        }

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    }

    /**
     * علامت‌گذاری یک اعلان خاص به‌عنوان خوانده‌شده (AJAX)
     */
    public function handleMarkNotificationRead() {
        header('Content-Type: application/json; charset=utf-8');
        $this->checkAuth();
        $tenant_id = Auth::tenantId();
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        if ($notification_id <= 0) {
            echo json_encode(['success' => false], JSON_UNESCAPED_UNICODE);
            return;
        }
        $result = \WHCM\Domain\Notification::markAsRead($notification_id, $tenant_id);
        $remaining = \WHCM\Domain\Notification::getUnreadCount($tenant_id);
        echo json_encode(['success' => true, 'remaining' => $remaining], JSON_UNESCAPED_UNICODE);
    }

    /**
     * علامت‌گذاری تمام اعلان‌ها به‌عنوان خوانده‌شده (AJAX)
     */
    public function handleMarkAllNotificationsRead() {
        header('Content-Type: application/json; charset=utf-8');
        $this->checkAuth();
        $tenant_id = Auth::tenantId();
        \WHCM\Domain\Notification::markAllAsRead($tenant_id);
        echo json_encode(['success' => true, 'remaining' => 0], JSON_UNESCAPED_UNICODE);
    }

    /**
     * تغییر وضعیت روشن/خاموش پاسخگوی خودکار کانال (AJAX)
     */
    public function handleToggleResponder() {
        header('Content-Type: application/json; charset=utf-8');
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'message' => 'خطای امنیتی'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $tenant_id = Auth::tenantId();
        $channel_id = (int)($_POST['channel_id'] ?? 0);
        $enabled = (int)($_POST['enabled'] ?? 0);
        if ($channel_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'شناسه کانال نامعتبر'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $db = Bootstrap::getDB();
        $key_name = 'responder_enabled_' . $channel_id;
        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1");
        $stmt->execute([$tenant_id, $key_name]);
        if ($stmt->fetch()) {
            $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = ?")->execute([$enabled ? '1' : '0', $tenant_id, $key_name]);
        } else {
            $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, ?, ?)")->execute([$tenant_id, $key_name, $enabled ? '1' : '0']);
        }
        echo json_encode(['success' => true, 'message' => $enabled ? 'پاسخگوی خودکار فعال شد ✅' : 'پاسخگوی خودکار غیرفعال شد ⏸'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * ایجاد، ارسال آنی یا زمان‌بندی پیام‌ها به شبکه‌های اجتماعی هدف (تلگرام و بله)
     */
    public function handleCreatePost() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();
        
        // بررسی سهمیه پست باقیمانده مستأجر
        $quota = Quota::getTenantQuota($tenant_id);
        if (!$quota['can_send_post']) {
            $this->setFlashMessage('خطا: سهمیه ارسال پست شما در این دوره به اتمام رسیده است. لطفا اشتراک خود را تمدید یا ارتقا دهید.');
            $this->redirect('/dashboard');
        }

        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $send_type = trim($_POST['send_type'] ?? 'instant');
        
        // دریافت تاریخ شمسی بازشو و تبدیل به ساختار دیتابیس
        $scheduled_at = '';
        if ($send_type === 'scheduled') {
            $sched_date = trim($_POST['sched_date'] ?? '');
            if (!empty($sched_date)) {
                list($s_year, $s_month, $s_day) = explode('/', $sched_date);
                $s_year = (int)$s_year;
                $s_month = (int)$s_month;
                $s_day = (int)$s_day;
            } else {
                $s_year = 1405;
                $s_month = 1;
                $s_day = 1;
            }
            $s_hour = trim($_POST['sched_hour'] ?? '00');
            $s_minute = trim($_POST['sched_minute'] ?? '00');
            
            // ابتدا تاریخ شمسی منتخب را به میلادی تبدیل می‌کنیم تا در دیتابیس به صورت کاملاً استاندارد ذخیره شود!
            // پُست‌یار مجهز به تبدیل معکوس جلالی به میلادی فوق‌حرفه‌ای است:
            $g_date = self::jalaliToGregorian($s_year, $s_month, $s_day);
            $scheduled_at = $g_date['year'] . '-' . str_pad($g_date['month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($g_date['day'], 2, '0', STR_PAD_LEFT) . ' ' . $s_hour . ':' . $s_minute . ':00';
        }

        // آپلود خودکار و فیزیکی فایل تصویر پست و تبدیل به فرمت بهینه وب‌پی
        $media_url = $this->uploadAndConvertToWebp('media_file', 'uploads');
        
        $channel_ids = isset($_POST['post_channels']) ? array_map('intval', $_POST['post_channels']) : [];

        if (empty($title) || empty($content)) {
            $this->setFlashMessage('تمامی فیلدهای عنوان و محتوای پست الزامی هستند.');
            $this->redirect('/dashboard');
        }

        if (empty($channel_ids)) {
            $this->setFlashMessage('خطا: حداقل یک کانال هدف جهت انتشار پست انتخاب کنید.');
            $this->redirect('/dashboard');
        }

        $db = Bootstrap::getDB();

        // ثبت رکورد اولیه پست در پایگاه داده مستأجر
        $status = ($send_type === 'scheduled') ? 'scheduled' : 'draft';
        $sched_date = !empty($scheduled_at) ? $scheduled_at : null;
        $target_channels_json = json_encode($channel_ids);

        // ثبت رکورد اولیه پست — محتوا با لینک‌های ردیابی ذخیره می‌شود
        $firstChannelId = (int)($channel_ids[0] ?? 0);
        $trackedContent = $content;
        if ($firstChannelId > 0) {
            $trackedContent = LinkTracker::processContent($content, 0, $firstChannelId, $tenant_id);
        }

        $stmt = $db->prepare("INSERT INTO posts (tenant_id, title, content, media_url, status, scheduled_at, target_channels) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $title, $trackedContent, $media_url, $status, $sched_date, $target_channels_json]);
        $post_id = (int)$db->lastInsertId();

        // آپدیت post_id در لینک‌های ردیابی
        if ($firstChannelId > 0 && $trackedContent !== $content) {
            try { $db->prepare("UPDATE link_tracking SET post_id = ? WHERE post_id = 0 AND channel_id = ? AND tenant_id = ?")->execute([$post_id, $firstChannelId, $tenant_id]); } catch (\Exception $e) {}
        }

        if ($send_type === 'instant') {
            // ذخیره وضعیت «در صف ارسال» و ریدایرکت فوری
            // ارسال واقعی از طریق درخواست AJAX مجزا انجام می‌شود (پردازش صف)
            $db->prepare("UPDATE posts SET status = 'queued' WHERE id = ?")->execute([$post_id]);

            $this->setFlashMessage('پست شما با موفقیت ثبت شد و در صف ارسال قرار گرفت. ارسال به کانال‌ها به‌زودی انجام خواهد شد. ⚡');
            $this->redirect('/dashboard');
        } else {
            $this->setFlashMessage('پست شما با موفقیت برای تاریخ شمسی تعیین شده زمان‌بندی گردید. ⏰');
        }

        $this->redirect('/dashboard');
    }

    /**
     * لغو/حذف پست زمان‌بندی‌شده یا در صف ارسال
     */
    public function handleCancelPost() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'خطای امنیتی! توکن نامعتبر است.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $tenant_id = Auth::tenantId();
        $post_id = (int)($_POST['post_id'] ?? 0);

        if ($post_id <= 0) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'شناسه پست نامعتبر است.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Bootstrap::getDB();

        // بررسی وجود پست و تعلق آن به این مستأجر و وضعیت قابل لغو
        $stmt = $db->prepare("SELECT id, status, title FROM posts WHERE id = ? AND tenant_id = ? AND status IN ('scheduled', 'queued', 'draft')");
        $stmt->execute([$post_id, $tenant_id]);
        $post = $stmt->fetch();

        if (!$post) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'پست یافت نشد یا قبلاً ارسال شده و قابل لغو نیست.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db->prepare("DELETE FROM posts WHERE id = ? AND tenant_id = ?")->execute([$post_id, $tenant_id]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => 'پست «' . $post['title'] . '» با موفقیت لغو و حذف شد.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * پردازش صف پست‌های در انتظار ارسال (AJAX)
     *
     * این متد از طریق JavaScript در داشبورد فراخوانی می‌شود
     * و در هر بار فقط یک پست را پردازش می‌کند تا از تایم‌اوت جلوگیری شود.
     */
    public function processPostQueue() {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $this->checkAuth();
            $tenant_id = Auth::tenantId();

            $db = Bootstrap::getDB();
            set_time_limit(30);

            // Select a candidate, then atomically transition it to `sending` and reserve
            // one quota unit. A concurrent worker may select the same candidate, but only
            // one worker can win the conditional status transition.
            $stmt = $db->prepare("SELECT id FROM posts WHERE tenant_id = ? AND status = 'queued' ORDER BY id ASC LIMIT 1");
            $stmt->execute([$tenant_id]);
            $candidate = $stmt->fetch();

            if (!$candidate) {
                echo json_encode(['success' => true, 'message' => 'no_queued_posts'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $post_id = (int)$candidate['id'];
            if (!\WHCM\Domain\Quota::reservePost($tenant_id, $post_id)) {
                echo json_encode(['success' => false, 'message' => 'post_busy_or_quota_exhausted'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $stmt = $db->prepare("SELECT id, title, content, media_url, target_channels, tenant_id, status FROM posts WHERE id = ? AND tenant_id = ? AND status = 'sending' LIMIT 1");
            $stmt->execute([$post_id, $tenant_id]);
            $post = $stmt->fetch();
            if (!$post) {
                echo json_encode(['success' => false, 'message' => 'post_claim_failed'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $channel_ids = json_decode($post['target_channels'], true) ?: [];

            if (empty($channel_ids)) {
                $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ?")->execute([$post_id]);
                echo json_encode(['success' => false, 'message' => 'کانال هدف یافت نشد'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $res = Sender::sendPostToChannels(
                (int)$post['tenant_id'],
                $channel_ids,
                $post['title'],
                $post['content'],
                $post['media_url'] ?? '',
                $post_id
            );

            if ($res['success']) {
                \WHCM\Domain\Quota::consumePostQuota($tenant_id, $post_id);
                echo json_encode(['success' => true, 'post_id' => $post_id, 'message' => 'پست با موفقیت ارسال شد'], JSON_UNESCAPED_UNICODE);
            } else {
                $db->prepare("UPDATE posts SET status = 'failed' WHERE id = ?")->execute([$post_id]);
                $errors = [];
                foreach ($res['channels'] ?? [] as $ch) {
                    if (!$ch['success']) {
                        $errors[] = $ch['name'] . ': ' . ($ch['message'] ?? '');
                    }
                }
                error_log('[Postyar] Queue send failed for post #' . $post_id . ': ' . implode(' | ', $errors));
                echo json_encode(['success' => false, 'post_id' => $post_id, 'message' => 'خطا در ارسال به برخی کانال‌ها', 'errors' => $errors], JSON_UNESCAPED_UNICODE);
            }
        } catch (\Throwable $e) {
            error_log('[Postyar] Queue process error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'خطای سیستمی'], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * قلب تپنده — پردازش پست‌های زمان‌بندی‌شده (فقط دیتابیس، بدون HTTP)
     * ⚠️ Polling پیام‌ها و پاسخگوی خودکار فقط از طریق cron.php انجام می‌شود
     */
    public function handleHeartbeat() {
        header('Content-Type: application/json; charset=utf-8');
        $this->checkAuth();

        $sent = 0;

        try {
            set_time_limit(10);

            // فقط پردازش پست‌های زمان‌بندی‌شده (بدون HTTP — فقط دیتابیس)
            // ⚠️ Polling پیام‌ها و پاسخگوی خودکار فقط از طریق cron.php انجام می‌شود
            $sent = ScheduledPost::processAll();

        } catch (\Throwable $e) {
            error_log('[Postyar Heartbeat] Error: ' . $e->getMessage());
        }

        echo json_encode(['success' => true, 'sent' => $sent], JSON_UNESCAPED_UNICODE);
    }

    public function handleEditPlan(){ return (new \WHCM\Modules\Billing\Controllers\PlanController)->edit(); }
    public function handleDeletePlan(){ return (new \WHCM\Modules\Billing\Controllers\PlanController)->delete(); }
    public function handleCreateTicket(){ return (new \WHCM\Modules\Support\Controllers\TicketController)->create(); }
    public function handleReplyTicket(){ return (new \WHCM\Modules\Support\Controllers\TicketController)->reply(); }
    public function handleAdminCreateTicket(){ return (new \WHCM\Modules\Support\Controllers\TicketController)->adminCreate(); }
    public function handleReopenTicket(){ return (new \WHCM\Modules\Support\Controllers\TicketController)->reopenAdmin(); }
    public function handleDeleteTicket(){ return (new \WHCM\Modules\Support\Controllers\TicketController)->deleteAdmin(); }

    public function handleCloseTicketUser(){ return (new \WHCM\Modules\Support\Controllers\TicketController)->closeUser(); }

    public function handleUserReplyTicket(){ return (new \WHCM\Modules\Support\Controllers\TicketController)->userReply(); }
    public function handleAssignTicket(){ 
        $this->checkSuperAdmin();
        $tid=(int)($_POST['ticket_id'] ?? 0);
        $aid=(int)($_POST['assigned_to'] ?? 0);
        if($tid>0){
            $db=\WHCM\Core\Bootstrap::getDB();
            $db->prepare("UPDATE tickets SET assigned_to=? WHERE id=?")->execute([$aid?:null,$tid]);
            $this->setFlashMessage('تیکت با موفقیت ارجاع داده شد. ✔');
        }
        $this->redirect('/hnnh');
    }


    public function handleResetPassword() {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/');
        }

        $email = trim($_POST['email'] ?? '');
        if (empty($email)) {
            $this->setFlashMessage('نشانی ایمیل را وارد کنید.');
            $this->redirect('/');
        }

        if (!RateLimit::consume('web_email_reset', 3, 900, strtolower($email))) {
            $this->setFlashMessage('تعداد درخواست‌های بازنشانی بیش از حد مجاز است.');
            $this->redirect('/');
            return;
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user_id = $stmt->fetchColumn();

        if (!$user_id) {
            // برای جلوگیری از افشای وجود ایمیل، پیام یکسان برمی‌گردانیم
            $this->setFlashMessage('در صورت وجود حساب، دستورالعمل بازنشانی ارسال شد.');
            $this->redirect('/');
            return;
        }

        // تولید توکن یکبار مصرف با اعتبار ۱ ساعت
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        
        // ذخیره توکن در تنظیمات کاربر
        $stmt = $db->prepare("SELECT id FROM settings WHERE tenant_id = ? AND key_name = 'password_reset_token' LIMIT 1");
        $stmt->execute([$user_id]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE settings SET key_value = ? WHERE tenant_id = ? AND key_name = 'password_reset_token'");
            $stmt->execute([json_encode(['token_hash' => hash('sha256', $token), 'expires_at' => $expires], JSON_UNESCAPED_UNICODE), $user_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (?, 'password_reset_token', ?)");
            $stmt->execute([$user_id, json_encode(['token_hash' => hash('sha256', $token), 'expires_at' => $expires], JSON_UNESCAPED_UNICODE)]);
        }

        // ارسال ایمیل با لینک بازنشانی رمز عبور
        $reset_link = Bootstrap::getConfig('app.url') . '/index.php?route=/reset-password&token=' . $token;

        // دریافت نام کاربر برای قالب ایمیل
        $stmt = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user_name = $stmt->fetchColumn() ?: 'کاربر';

        // ارسال ایمیل (اگر SMTP تنظیم نشده باشد، حداقل لاگ می‌شود)
        $html_body = \WHCM\Core\Mail::buildPasswordResetTemplate($user_name, $reset_link);
        $sent = \WHCM\Core\Mail::send($email, 'بازنشانی رمز عبور — پُست‌یار', $html_body);

        if (!$sent) {
            error_log('[Postyar] Failed to send password reset email to: ' . $email);
        }

        $this->setFlashMessage('در صورت وجود حساب، دستورالعمل بازنشانی به ایمیل شما ارسال شد.');
        $this->redirect('/');
    }

    /**
     * صفحه و فرم بازنشانی رمز عبور با توکن
     */
    public function showResetPasswordForm() {
        $token = trim($_GET['token'] ?? '');
        if (empty($token)) {
            $this->setFlashMessage('لینک بازنشانی نامعتبر است.');
            $this->redirect('/');
        }

        $this->render('home', [
            'title' => 'بازنشانی رمز عبور | پُست‌یار',
            'csrf_field' => Csrf::field(),
            'message' => $this->getFlashMessage(),
            'reset_token' => $token,
            'show_reset_form' => true,
        ]);
    }

    /**
     * اعمال بازنشانی رمز عبور
     */
    public function handleResetPasswordConfirm() {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/');
        }

        $token = trim($_POST['token'] ?? '');
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($token) || empty($new_pass)) {
            $this->setFlashMessage('تمامی فیلدها الزامی هستند.');
            $this->redirect('/');
        }

        if ($new_pass !== $confirm_pass) {
            $this->setFlashMessage('کلمه عبور جدید با تکرار آن مطابقت ندارد.');
            $this->redirect('/');
        }

        if (strlen($new_pass) < 6) {
            $this->setFlashMessage('کلمه عبور باید حداقل ۶ کاراکتر باشد.');
            $this->redirect('/');
        }

        $db = Bootstrap::getDB();

        // جستجوی توکن معتبر
        $stmt = $db->prepare("SELECT tenant_id, key_value FROM settings WHERE key_name = 'password_reset_token' LIMIT 1");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $tokenData = json_decode($row['key_value'], true);
            $tokenHash = is_array($tokenData) ? (string)($tokenData['token_hash'] ?? '') : '';
            $expires = is_array($tokenData) ? (string)($tokenData['expires_at'] ?? '') : '';
            if ($tokenHash !== '' && hash_equals($tokenHash, hash('sha256', $token))) {
                if ($expires === '' || strtotime($expires) < time()) {
                    $this->setFlashMessage('لینک بازنشانی منقضی شده است. لطفاً دوباره درخواست دهید.');
                    $this->redirect('/');
                }

                // تغییر رمز عبور
                $hashed = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $user_id = (int)$row['tenant_id'];
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $user_id]);

                // حذف توکن استفاده شده
                $stmt = $db->prepare("DELETE FROM settings WHERE tenant_id = ? AND key_name = 'password_reset_token'");
                $stmt->execute([$user_id]);
                \WHCM\Api\MobileApiAuth::revokeAllUserTokens($user_id);
                Session::destroy();

                $this->setFlashMessage('کلمه عبور شما با موفقیت تغییر یافت. اکنون وارد شوید.');
                $this->redirect('/');
                return;
            }
        }

        $this->setFlashMessage('لینک بازنشانی نامعتبر یا منقضی شده است.');
        $this->redirect('/');
    }

    public function handleSuspendUser(){ return (new \WHCM\Modules\Users\Controllers\UserController)->suspend(); }
    public function handleActivateUser(){ return (new \WHCM\Modules\Users\Controllers\UserController)->activate(); }
    public function handleDeleteUser(){ return (new \WHCM\Modules\Users\Controllers\UserController)->delete(); }
    public function handleBroadcastAnnouncement(){ return (new \WHCM\Modules\Support\Controllers\BroadcastController)->announce(); }
    public function handleWipeTestData(){ return (new \WHCM\Modules\Users\Controllers\UserController)->wipeTestData(); }

    // === تنظیمات سراسری ادمین ===
    public function handleSaveGoldSettingsAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $db = Bootstrap::getDB();
        $fields = ['gold_api_source', 'gold_interval', 'gold_default_template'];
        foreach ($fields as $f) {
            $val = $_POST[$f] ?? '';
            $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (0, ?, ?) ON CONFLICT(tenant_id, key_name) DO UPDATE SET key_value = ?")->execute([$f, $val, $val]);
        }
        // ذخیره آدرس API دستی طلا (کلید اختصاصی مدیر)
        $custom_url = trim($_POST['gold_custom_api_url'] ?? '');
        if (!empty($custom_url)) {
            $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (0, 'gold_custom_api_url', ?) ON CONFLICT(tenant_id, key_name) DO UPDATE SET key_value = ?")->execute([$custom_url, $custom_url]);
        }
        $this->setFlashMessage('تنظیمات ربات طلا و سکه با موفقیت ذخیره شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleSaveAiSettingsAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $db = Bootstrap::getDB();
        $fields = ['ai_global_provider', 'ai_global_model', 'ai_global_key', 'ai_global_url', 'ai_active_by_default'];
        foreach ($fields as $f) {
            $val = $_POST[$f] ?? '';
            if ($f === 'ai_active_by_default') { $val = isset($_POST[$f]) ? '1' : '0'; }
            $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (0, ?, ?) ON CONFLICT(tenant_id, key_name) DO UPDATE SET key_value = ?")->execute([$f, $val, $val]);
        }
        $this->setFlashMessage('تنظیمات سراسری هوش مصنوعی با موفقیت ذخیره شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleDeleteDiscount(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $id = (int)($_POST['discount_id'] ?? 0);
        $db = Bootstrap::getDB();
        $db->prepare("DELETE FROM discount_codes WHERE id = ?")->execute([$id]);
        $this->setFlashMessage('کد تخفیف با موفقیت حذف شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleAddDiscount(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $percentage = (int)($_POST['percentage'] ?? 0);
        $max_uses = (int)($_POST['max_uses'] ?? 0);
        $expires_at = trim($_POST['expires_at'] ?? '');
        if (empty($code) || $percentage <= 0 || $percentage > 100) {
            $this->setFlashMessage('لطفاً کد تخفیف (معتبر) و درصد (۱ تا ۱۰۰) را وارد کنید.'); $this->redirect('/hnnh'); return;
        }
        $db = Bootstrap::getDB();
        try {
            $stmt = $db->prepare("INSERT INTO discount_codes (code, type, amount, max_uses, expires_at, active) VALUES (?, 'percent', ?, ?, ?, 1)");
            $stmt->execute([$code, $percentage, $max_uses > 0 ? $max_uses : 0, $expires_at ?: null]);
            $this->setFlashMessage('کد تخفیف جدید با موفقیت ایجاد شد! ✔');
        } catch (\Throwable $e) {
            $this->setFlashMessage('خطا در ایجاد کد تخفیف: احتمالاً کد تکراری است.');
        }
        $this->redirect('/hnnh');
    }
    public function handleSaveResponderSettingsAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $db = Bootstrap::getDB();
        $fields = ['responder_max_keywords', 'responder_delay', 'responder_fallback'];
        foreach ($fields as $f) {
            $val = $_POST[$f] ?? '';
            $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (0, ?, ?) ON CONFLICT(tenant_id, key_name) DO UPDATE SET key_value = ?")->execute([$f, $val, $val]);
        }
        $this->setFlashMessage('تنظیمات پاسخگوی هوشمند با موفقیت ذخیره شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleSaveWooSettingsAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $db = Bootstrap::getDB();
        $fields = ['woo_help_text', 'woo_max_stores', 'woo_require_ssl'];
        foreach ($fields as $f) {
            $val = $_POST[$f] ?? '';
            if ($f === 'woo_require_ssl') { $val = isset($_POST[$f]) ? '1' : '0'; }
            $db->prepare("INSERT INTO settings (tenant_id, key_name, key_value) VALUES (0, ?, ?) ON CONFLICT(tenant_id, key_name) DO UPDATE SET key_value = ?")->execute([$f, $val, $val]);
        }
        $this->setFlashMessage('تنظیمات ووکامرس با موفقیت ذخیره شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleReopenTicketAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $id = (int)($_POST['ticket_id'] ?? 0);
        $db = Bootstrap::getDB();
        $db->prepare("UPDATE tickets SET status = 'open' WHERE id = ?")->execute([$id]);
        $this->setFlashMessage('تیکت با موفقیت مجدداً باز شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleDeleteTicketAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $id = (int)($_POST['ticket_id'] ?? 0);
        $db = Bootstrap::getDB();
        $db->prepare("DELETE FROM ticket_replies WHERE ticket_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);
        $this->setFlashMessage('تیکت با موفقیت حذف شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleCloseTicketAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $id = (int)($_POST['ticket_id'] ?? 0);
        $db = Bootstrap::getDB();
        $db->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?")->execute([$id]);
        $this->setFlashMessage('تیکت با موفقیت بسته شد! ✔');
        $this->redirect('/hnnh');
    }
    public function handleCreateTicketAdmin(){
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/hnnh'); return; }
        $user_id = (int)($_POST['target_user_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $priority = trim($_POST['priority'] ?? 'normal');
        $message = trim($_POST['message'] ?? '');
        if ($user_id <= 0 || empty($subject) || empty($message)) {
            $this->setFlashMessage('لطفاً کاربر، موضوع و پیام را وارد کنید.'); $this->redirect('/hnnh'); return;
        }
        $db = Bootstrap::getDB();
        try {
            $stmt = $db->prepare("INSERT INTO tickets (user_id, subject, category, message, status, priority, created_by_admin) VALUES (?, ?, ?, ?, 'replied', ?, 1)");
            $stmt->execute([$user_id, $subject, $category, $message, $priority]);
            $this->setFlashMessage('تیکت پشتیبانی با موفقیت برای کاربر ایجاد شد! ✔');
        } catch (\Throwable $e) {
            $this->setFlashMessage('خطا در ایجاد تیکت: ' . $e->getMessage());
        }
        $this->redirect('/hnnh');
    }

    /**
     * ذخیره تنظیمات شماره کارت بانکی عمومی توسط سوپر ادمین
     */
    public function handleSaveBankSettings() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $card_number = trim($_POST['card_number'] ?? '');
        $card_holder = trim($_POST['card_holder'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        
        $support_tele = trim($_POST['support_telegram_url'] ?? '');
        $support_bale = trim($_POST['support_bale_url'] ?? '');
        $support_email = trim($_POST['support_email'] ?? '');

        if (empty($card_number) || empty($card_holder)) {
            $this->setFlashMessage('شماره کارت و نام صاحب حساب الزامی هستند.');
            $this->redirect('/hnnh');
        }

        $bank_settings = [
            'admin_card_number' => $card_number,
            'admin_card_holder' => $card_holder,
            'admin_bank_name' => $bank_name,
            'support_telegram_url' => $support_tele,
            'support_bale_url' => $support_bale,
            'support_email' => $support_email
        ];

        $this->saveSettingsBatch(0, $bank_settings);

        $this->setFlashMessage('تنظیمات کارت بانکی و راه‌های ارتباطی با موفقیت بروزرسانی شد! 💳✔');
        $this->redirect('/hnnh');
    }

    public function handleAddUserManual(){ return (new \WHCM\Modules\Users\Controllers\UserController)->addManual(); }
    public function handleGrantSubscriptionManual(){ return (new \WHCM\Modules\Users\Controllers\UserController)->grantSubscription(); }
    /**
     * ذخیره‌سازی تنظیمات اتوماسیون پیشرفته توسط کاربر (مستأجر)
     */
    public function handleSaveAdvancedSettings() {
        $this->checkAuth();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            if ($this->isAjax()) { echo json_encode(['success'=>false,'message'=>'خطای امنیتی'],JSON_UNESCAPED_UNICODE); return; }
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $tenant_id = Auth::tenantId();

        // ذخیره‌سازی فیلدهای دریافتی در جدول تنظیمات اختصاصی مستأجر
        $fields = [
            'ai_provider' => trim($_POST['ai_provider'] ?? ''),
            'ai_api_key' => trim($_POST['ai_api_key'] ?? ''),
            'ai_model' => trim($_POST['ai_model'] ?? 'gpt-4o'),
            'ai_api_url' => trim($_POST['ai_api_url'] ?? 'https://api.openai.com/v1/chat/completions'),
            'auto_publish_woo' => isset($_POST['auto_publish_woo']) ? 'yes' : 'no',
            'watermark_active' => isset($_POST['watermark_active']) ? 'yes' : 'no',
            'caption_format' => trim($_POST['caption_format'] ?? 'plain'),
            'inbound_method' => trim($_POST['inbound_method'] ?? 'polling'),
            'poll_interval' => trim($_POST['poll_interval'] ?? 'every_1_minute'),
            
            'link_1_name' => trim($_POST['link_1_name'] ?? '📢 کانال تلگرام'),
            'link_1_url' => trim($_POST['link_1_url'] ?? ''),
            'link_2_name' => trim($_POST['link_2_name'] ?? '💬 کانال بله'),
            'link_2_url' => trim($_POST['link_2_url'] ?? ''),
            'link_3_name' => trim($_POST['link_3_name'] ?? '🌐 خرید آنلاین از سایت'),
            'link_3_url' => trim($_POST['link_3_url'] ?? ''),
            
            'btn_1_text' => trim($_POST['btn_1_text'] ?? '🛒 خرید آنلاین از سایت'),
            'btn_2_text' => trim($_POST['btn_2_text'] ?? '💎 پشتیبانی VIP'),
            'btn_2_url' => trim($_POST['btn_2_url'] ?? ''),
            'btn_3_text' => trim($_POST['btn_3_text'] ?? '📢 هومن وب'),
            'btn_3_url' => trim($_POST['btn_3_url'] ?? '')
        ];

        $this->saveSettingsBatch($tenant_id, $fields);

        if ($this->isAjax()) {
            echo json_encode(['success'=>true,'message'=>'تنظیمات با موفقیت ذخیره شد! ✔🤖'],JSON_UNESCAPED_UNICODE);
            return;
        }
        $this->setFlashMessage('تنظیمات اتوماسیون و پیوند‌های اختصاصی با موفقیت بروزرسانی شد! ✔🤖');
        $this->redirect('/dashboard');
    }

    // =============================================================
    // بخش سیستم زیرمجموعه‌گیری (Referral System)
    // =============================================================

    /**
     * بخش زیرمجموعه‌گیری در داشبورد (بخش جزئی — AJAX)
     */
    public function referralSection() {
        $this->checkAuth();
        $userId = Auth::tenantId();

        $referralCode = Referral::getUserReferralCode($userId);
        $referralLink = Referral::getReferralLink($userId);
        $stats = Referral::getReferralStats($userId);
        $history = Referral::getReferralHistory($userId);
        $points = $this->getUserPoints($userId);
        $settings = Referral::getAdminSettings();
        $enabled = ($settings['enabled'] ?? '0') === '1';

        include __DIR__ . '/../Views/partials/referral-section.php';
        exit;
    }

    // =============================================================
    // بخش کیف پول (Wallet System)
    // =============================================================

    /**
     * بخش کیف پول در داشبورد (بخش جزئی — AJAX)
     */
    public function walletSection() {
        $this->checkAuth();
        $userId = Auth::tenantId();

        $balance = Wallet::getBalance($userId);
        $transactions = Wallet::getTransactions($userId, 50, 0);
        $points = $this->getUserPoints($userId);

        include __DIR__ . '/../Views/partials/wallet-section.php';
        exit;
    }

    /**
     * تبدیل امتیاز به موجودی کیف پول
     */
    public function handleConvertPoints() {
        $this->checkAuth();

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/dashboard');
        }

        $userId = Auth::tenantId();
        $points = (float)TextFormat::en_num($_POST['points'] ?? '0');
        $rate = 10; // نرخ تبدیل: هر امتیاز = ۱۰ تومان

        if ($points <= 0) {
            $this->setFlashMessage('مقدار امتیاز وارد شده نامعتبر است.');
            $this->redirect('/dashboard');
        }

        if (Wallet::convertPointsToWallet($userId, $points, $rate)) {
            $this->setFlashMessage(TextFormat::fa_num($points) . ' امتیاز با موفقیت به ' . TextFormat::fa_num($points * $rate) . ' تومان در کیف پول شما تبدیل شد! 💰');
        } else {
            $this->setFlashMessage('خطا در تبدیل امتیاز. لطفاً موجودی امتیاز خود را بررسی کنید.');
        }

        $this->redirect('/dashboard');
    }

    // =============================================================
    // بخش مدیریت ادمین — تنظیمات زیرمجموعه‌گیری
    // =============================================================

    /**
     * صفحه تنظیمات زیرمجموعه‌گیری ادمین (بخش جزئی — AJAX)
     */
    public function adminReferralSettings() {
        $this->checkSuperAdmin();
        $settings = Referral::getAdminSettings();
        include __DIR__ . '/../Views/partials/admin-referral-settings.php';
        exit;
    }

    /**
     * ذخیره تنظیمات زیرمجموعه‌گیری (POST)
     */
    public function handleSaveReferralSettings() {
        $this->checkSuperAdmin();

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $settings = [
            'enabled'                  => isset($_POST['enabled']) ? '1' : '0',
            'register_reward_type'     => trim($_POST['register_reward_type'] ?? 'points'),
            'register_reward_value'    => trim($_POST['register_reward_value'] ?? '100'),
            'first_purchase_reward_type'  => trim($_POST['first_purchase_reward_type'] ?? 'percent'),
            'first_purchase_reward_value' => trim($_POST['first_purchase_reward_value'] ?? '10'),
            'max_referrals_per_user'   => trim($_POST['max_referrals_per_user'] ?? '100'),
            'monthly_reward_cap'       => trim($_POST['monthly_reward_cap'] ?? '500000'),
        ];

        Referral::saveAdminSettings($settings);
        $this->setFlashMessage('تنظیمات سیستم زیرمجموعه‌گیری با موفقیت ذخیره شد! 🎯');
        $this->redirect('/hnnh');
    }

    /**
     * آمار کیف پول‌ها برای ادمین (JSON)
     */
    public function adminWalletStats() {
        $this->checkSuperAdmin();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(Wallet::getAdminWalletStats(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =============================================================
    // بخش مدیریت پیامک (SMS.ir)
    // =============================================================

    /**
     * نمایش صفحه تنظیمات پیامک ادمین
     */
    public function adminSmsSettings() {
        $this->checkSuperAdmin();
        $db = Bootstrap::getDB();
        $sms_settings = [];
        try {
            $rows = $db->query("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND (key_name LIKE 'sms_provider_%' OR key_name IN ('sms_enabled','sms_api_key','sms_line_number'))")->fetchAll();
            foreach ($rows as $row) {
                $key = (string)$row['key_name'];
                $value = \WHCM\Core\SecretStore::decrypt((string)$row['key_value']);
                if (str_contains($key, 'api_key') || str_contains($key, 'password')) {
                    $value = $value !== '' ? '••••••••' : '';
                }
                $sms_settings[$key] = $value;
            }
        } catch (\Throwable $e) {
            error_log('[Postyar Admin SMS] ' . $e->getMessage());
        }
        try { $templates = $db->query("SELECT * FROM sms_templates ORDER BY id ASC")->fetchAll(); } catch (\Throwable $e) { $templates=[]; }
        $filter_status = $_GET['filter_status'] ?? '';
        $filter_phone = trim($_GET['filter_phone'] ?? '');
        $logs=[];
        try {
            $sql = "SELECT sl.*, st.template_name, st.event_key FROM sms_log sl LEFT JOIN sms_templates st ON sl.template_id = st.template_id WHERE 1=1";
            $params=[];
            if ($filter_status !== '' && in_array($filter_status,['success','failed','rate_limited','pending'],true)) { $sql.=' AND sl.status=?'; $params[]=$filter_status; }
            if ($filter_phone !== '') { $sql.=' AND sl.phone LIKE ?'; $params[]='%'.$filter_phone.'%'; }
            $sql.=' ORDER BY sl.id DESC LIMIT 50';
            $stmt=$db->prepare($sql); $stmt->execute($params); $logs=$stmt->fetchAll();
        } catch (\Throwable $e) { error_log('[Postyar Admin SMS] logs: '.$e->getMessage()); }
        try { $active_users=$db->query("SELECT id,name,phone FROM users WHERE status='active' AND role!='superadmin' ORDER BY id DESC")->fetchAll(); } catch (\Throwable $e) { $active_users=[]; }
        include __DIR__ . '/../Views/partials/admin-sms-settings.php';
        exit;
    }

    /**
     * ذخیره تنظیمات پیامک (API Key و شماره خط)
     */
    public function handleSaveSmsConfig() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $provider = trim((string)($_POST['sms_provider_active'] ?? 'smsir'));
        if (!\WHCM\Core\SmsProviderRegistry::get($provider)) {
            $this->setFlashMessage('پنل پیامکی انتخاب‌شده معتبر نیست.');
            $this->redirect('/hnnh');
        }
        $settings = ['sms_provider_active' => $provider];
        foreach (array_keys(\WHCM\Core\SmsProviderRegistry::all()) as $id) {
            $settings['sms_provider_' . $id . '_enabled'] = isset($_POST['sms_enabled_' . $id]) ? '1' : '0';
            $settings['sms_provider_' . $id . '_verified'] = '0';
            foreach (['api_key','username','password','sender','line_number','base_url'] as $field) {
                $name = 'sms_' . $id . '_' . $field;
                if (!array_key_exists($name, $_POST)) continue;
                $value = trim((string)$_POST[$name]);
                if ($value === '' || $value === '••••••••') continue;
                $settings['sms_provider_' . $id . '_' . $field] = in_array($field, ['api_key','password'], true)
                    ? \WHCM\Core\SecretStore::encrypt($value) : $value;
            }
        }
        // سازگاری با موتور قدیمی ارسال پیامک برای sms.ir
        if ($provider === 'smsir') {
            $settings['sms_enabled'] = isset($_POST['sms_enabled_smsir']) ? '1' : '0';
            if (!empty($_POST['sms_smsir_api_key']) && $_POST['sms_smsir_api_key'] !== '••••••••') {
                $settings['sms_api_key'] = \WHCM\Core\SecretStore::encrypt(trim((string)$_POST['sms_smsir_api_key']));
            }
            $settings['sms_line_number'] = trim((string)($_POST['sms_smsir_line_number'] ?? ''));
        }
        $this->saveSettingsBatch(0, $settings);
        $this->setFlashMessage('تنظیمات پنل پیامکی «' . \WHCM\Core\SmsProviderRegistry::get($provider)['name'] . '» با موفقیت ذخیره شد. اکنون می‌توانید اتصال را آزمایش کنید.');
        $this->redirect('/hnnh');
    }

    /**
     * ذخیره قالب پیامک (ایجاد یا به‌روزرسانی)
     */
    public function handleSaveSmsTemplate() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $id = (int)($_POST['template_db_id'] ?? 0);
        $eventKey = trim($_POST['event_key'] ?? '');
        $templateName = trim($_POST['template_name'] ?? '');
        $templateId = (int)($_POST['template_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $parameters = trim($_POST['parameters'] ?? '[]');

        // اعتبارسنجی JSON پارامترها
        json_decode($parameters);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->setFlashMessage('فرمت پارامترها نامعتبر است. لطفاً JSON معتبر وارد کنید.');
            $this->redirect('/hnnh');
        }

        if (empty($eventKey) || empty($templateName) || $templateId <= 0) {
            $this->setFlashMessage('فیلدهای کلید رویداد، نام قالب و شناسه قالب الزامی هستند.');
            $this->redirect('/hnnh');
        }

        $db = Bootstrap::getDB();

        if ($id > 0) {
            // به‌روزرسانی
            $stmt = $db->prepare("UPDATE sms_templates SET event_key = ?, template_name = ?, template_id = ?, parameters = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$eventKey, $templateName, $templateId, $parameters, $isActive, $id]);
            $this->setFlashMessage('قالب پیامک با موفقیت بروزرسانی شد! ✏️✔');
        } else {
            // ایجاد جدید
            try {
                $stmt = $db->prepare("INSERT INTO sms_templates (event_key, template_name, template_id, parameters, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$eventKey, $templateName, $templateId, $parameters, $isActive]);
                $this->setFlashMessage('قالب پیامک جدید با موفقیت ایجاد شد! 📱✔');
            } catch (\Exception $e) {
                $this->setFlashMessage('خطا در ایجاد قالب. ممکن است کلید رویداد تکراری باشد.');
            }
        }

        $this->redirect('/hnnh');
    }

    /**
     * حذف قالب پیامک
     */
    public function handleDeleteSmsTemplate() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $id = (int)($_POST['template_db_id'] ?? 0);
        if ($id <= 0) {
            $this->setFlashMessage('شناسه قالب نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("DELETE FROM sms_templates WHERE id = ?");
        $stmt->execute([$id]);

        $this->setFlashMessage('قالب پیامک حذف شد! 🗑️');
        $this->redirect('/hnnh');
    }

    /**
     * ارسال پیامک تستی
     */
    public function handleTestSms() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $phone = trim((string)($_POST['test_phone'] ?? ''));
        $provider = trim((string)($_POST['sms_provider_active'] ?? 'smsir'));
        if (!preg_match('/^09\d{9}$/', preg_replace('/\D+/', '', $phone))) {
            $this->setFlashMessage('❌ شماره موبایل آزمایشی معتبر نیست.');
            $this->redirect('/hnnh');
        }
        try {
            if ($provider === 'smsir') {
                $result = Sms::testConnection($phone);
                $this->setFlashMessage(($result['success'] ? '' : '❌ ') . $result['message']);
            } else {
                $result = \WHCM\Core\SmsProviderRegistry::provider($provider)->test($phone);
                $this->setFlashMessage(($result['success'] ? 'اتصال با موفقیت آزمایش شد: ' : '❌ آزمون اتصال ناموفق بود: ') . ($result['success'] ? 'پاسخ معتبر از پنل دریافت شد.' : ($result['error'] ?? 'علت نامشخص')));
            }
        } catch (\Throwable $e) {
            $this->setFlashMessage('❌ آزمون اتصال پنل پیامکی ناموفق بود: ' . $e->getMessage());
        }
        $this->redirect('/hnnh');
    }

    /**
     * ارسال پیامک انبوه
     */
    public function handleSendBulkSms() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $recipientType = trim($_POST['recipient_type'] ?? '');
        $templateId = (int)($_POST['bulk_template_id'] ?? 0);
        $manualPhones = trim($_POST['manual_phones'] ?? '');
        $param1Name = trim($_POST['param1_name'] ?? '');
        $param1Value = trim($_POST['param1_value'] ?? '');
        $param2Name = trim($_POST['param2_name'] ?? '');
        $param2Value = trim($_POST['param2_value'] ?? '');

        if ($templateId <= 0) {
            $this->setFlashMessage('لطفاً یک قالب پیامک انتخاب کنید.');
            $this->redirect('/hnnh');
        }

        // آماده‌سازی پارامترها
        $params = [];
        if (!empty($param1Name)) {
            $params[$param1Name] = $param1Value;
        }
        if (!empty($param2Name)) {
            $params[$param2Name] = $param2Value;
        }

        // جمع‌آوری شماره‌ها
        $phones = [];
        $db = Bootstrap::getDB();

        if ($recipientType === 'all') {
            $rows = $db->query("SELECT phone FROM users WHERE phone IS NOT NULL AND phone != '' AND role != 'superadmin'")->fetchAll();
            foreach ($rows as $row) {
                $phones[] = $row['phone'];
            }
        } elseif ($recipientType === 'active') {
            $rows = $db->query("SELECT phone FROM users WHERE phone IS NOT NULL AND phone != '' AND status = 'active' AND role != 'superadmin'")->fetchAll();
            foreach ($rows as $row) {
                $phones[] = $row['phone'];
            }
        } elseif ($recipientType === 'manual') {
            // شکستن شماره‌ها از خطوط جدید
            $lines = preg_split('/[\r\n,;]+/', $manualPhones);
            foreach ($lines as $line) {
                $p = trim($line);
                if (!empty($p)) {
                    $phones[] = $p;
                }
            }
        } else {
            $this->setFlashMessage('نوع گیرندگان نامعتبر است.');
            $this->redirect('/hnnh');
        }

        if (empty($phones)) {
            $this->setFlashMessage('هیچ شماره موبایلی یافت نشد.');
            $this->redirect('/hnnh');
        }

        $result = Sms::sendBulk($phones, $templateId, $params);

        if ($result['success']) {
            $msg = '✅ پیامک انبوه ارسال شد! ارسال موفق: ' . TextFormat::fa_digits($result['sent_count']) . ' | ناموفق: ' . TextFormat::fa_digits($result['failed_count']);
            if (!empty($result['errors'])) {
                $msg .= ' | خطاها: ' . implode('، ', array_slice($result['errors'], 0, 3));
            }
            $this->setFlashMessage($msg);
        } else {
            $msg = '❌ خطا در ارسال پیامک انبوه. ';
            if (!empty($result['errors'])) {
                $msg .= implode('، ', array_slice($result['errors'], 0, 3));
            }
            $this->setFlashMessage($msg);
        }

        $this->redirect('/hnnh');
    }

    // =============================================================
    // متدهای کمکی داخلی
    // =============================================================

    /**
     * دریافت امتیازهای کاربر
     *
     * @param int $userId
     * @return float
     */
    private function getUserPoints(int $userId): float {
        $db = Bootstrap::getDB();
        try {
            $stmt = $db->prepare("SELECT referral_points FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return (float)($row['referral_points'] ?? 0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /* =============================================================
     * متدهای مشترک (checkAuth، checkSuperAdmin، redirect، setFlashMessage،
     * getFlashMessage، render، uploadAndConvertToWebp، jalaliToGregorian، saveSetting)
     * در BaseController قرار دارند.
     * ============================================================= */

    // =============================================================
    // Wave R.3 — payment/SMS provider administration
    // =============================================================
    public function adminProviderSettings(): void {
        $this->checkSuperAdmin();
        $db=Bootstrap::getDB();
        $payment_providers=\WHCM\Payments\PaymentProviderRegistry::all();
        $sms_providers=\WHCM\Core\SmsProviderRegistry::all();
        $provider_settings=[];
        foreach($db->query("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND (key_name LIKE 'payment_gateway_%' OR key_name LIKE 'sms_provider_%')")->fetchAll() as $row){
            $key=(string)$row['key_name'];
            $value=(string)$row['key_value'];
            if (str_ends_with($key,'_enabled') || str_ends_with($key,'_verified') || $key==='payment_gateway_active' || $key==='sms_provider_active') {
                $provider_settings[$key]=$value;
            } elseif (str_contains($key,'api_key') || str_contains($key,'password') || str_contains($key,'secret')) {
                $provider_settings[$key]=$value!==''?'••••••••':'';
            } else {
                $provider_settings[$key]=$value;
            }
        }
        include __DIR__.'/../Views/partials/admin-provider-settings.php'; exit;
    }

    public function handleSaveProviderSettings(): void {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $payment = (string)($_POST['payment_gateway_active'] ?? 'manual');
        if ($payment !== 'manual' && !\WHCM\Payments\PaymentProviderRegistry::get($payment)) {
            $this->setFlashMessage('درگاه پرداخت انتخاب‌شده معتبر نیست.');
            $this->redirect('/hnnh');
        }

        $settings = ['payment_gateway_active' => $payment];
        $ids = array_keys(\WHCM\Payments\PaymentProviderRegistry::all());
        $fields = ['merchant_id','merchant','api_key','sandbox','callback_url','gateway_url','start_url','request_url','verify_url','terminal_id','username','password','merchant_code','certificate_path','wsdl_url','service_url','pin','http_method','secret'];
        foreach ($ids as $id) {
            $settings['payment_gateway_' . $id . '_enabled'] = isset($_POST['payment_enabled_' . $id]) ? '1' : '0';
            $settings['payment_gateway_' . $id . '_verified'] = '0';
            foreach ($fields as $field) {
                $name = 'payment_' . $id . '_' . $field;
                if (!array_key_exists($name, $_POST)) continue;
                $value = trim((string)$_POST[$name]);
                if ($value === '' || $value === '••••••••') continue;
                $settings['payment_gateway_' . $id . '_' . $field] = in_array($field, ['api_key','password','secret'], true)
                    ? \WHCM\Core\SecretStore::encrypt($value)
                    : $value;
            }
        }

        $this->saveSettingsBatch(0, $settings);
        $this->setFlashMessage('تنظیمات درگاه‌های پرداخت با موفقیت ذخیره شد. برای فعال‌سازی نهایی، اتصال درگاه را آزمایش کنید.');
        $this->redirect('/hnnh');
    }

    // =============================================================
    // سیستم ایمیل — تنظیمات، قالب‌ها، ارسال انبوه، لاگ
    // =============================================================

    /**
     * بخش تنظیمات ایمیل در پنل مدیریت (بازگردانی پارشیال)
     */
    public function handleTestPaymentConnection(): void {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id = trim((string)($_POST['payment_gateway_id'] ?? 'manual'));
        if ($id === 'manual') {
            $this->setFlashMessage('اتصال کارت‌به‌کارت و پرداخت دستی به درگاه بیرونی نیاز ندارد و آماده استفاده است.');
            $this->redirect('/hnnh');
        }
        $meta = \WHCM\Payments\PaymentProviderRegistry::get($id);
        if (!$meta) {
            $this->setFlashMessage('❌ درگاه انتخاب‌شده معتبر نیست.');
            $this->redirect('/hnnh');
        }
        try {
            $db = Bootstrap::getDB();
            $prefix = 'payment_gateway_' . $id . '_';
            $stmt = $db->prepare("SELECT key_name,key_value FROM settings WHERE tenant_id=0 AND key_name LIKE ?");
            $stmt->execute([$prefix . '%']);
            $cfg = [];
            foreach ($stmt->fetchAll() as $row) $cfg[substr($row['key_name'], strlen($prefix))] = \WHCM\Core\SecretStore::decrypt((string)$row['key_value']);
            if (($cfg['enabled'] ?? '0') !== '1') throw new \RuntimeException('این درگاه فعال نشده است.');
            $required = [];
            foreach (($meta['fields'] ?? []) as $field) {
                if (in_array($field, ['callback_url','gateway_url','start_url','request_url','verify_url','sandbox'], true)) continue;
                $required[] = $field;
            }
            $missing = [];
            foreach ($required as $field) if (trim((string)($cfg[$field] ?? '')) === '') $missing[] = $field;
            if ($missing) throw new \RuntimeException('این تنظیمات وارد نشده‌اند: ' . implode('، ', $missing));
            $url = trim((string)($cfg['gateway_url'] ?? $cfg['request_url'] ?? $cfg['start_url'] ?? ''));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $probe = \WHCM\Core\HttpClient::get($url, ['Accept: */*'], 8);
                if (!$probe['success'] && ($probe['code'] ?? 0) === 0) throw new \RuntimeException('ارتباط شبکه با نشانی درگاه برقرار نشد: ' . ($probe['error'] ?: 'خطای شبکه'));
            }
            $this->setFlashMessage('اتصال و تنظیمات پایه «' . $meta['name'] . '» با موفقیت بررسی شد. هیچ تراکنش واقعی در این آزمون ایجاد نشد.');
        } catch (\Throwable $e) {
            $this->setFlashMessage('❌ آزمون اتصال «' . ($meta['name'] ?? $id) . '» ناموفق بود: ' . $e->getMessage());
        }
        $this->redirect('/hnnh');
    }

    public function adminEmailSettings() {
        $this->checkSuperAdmin();
        $db = Bootstrap::getDB();

        // دریافت تنظیمات SMTP ذخیره شده
        $smtp_keys = ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_address', 'smtp_from_name', 'smtp_timeout', 'smtp_auth', 'smtp_reply_to', 'smtp_reply_name'];
        $email_settings = [];
        foreach ($smtp_keys as $ek) {
            $stmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = ? LIMIT 1");
            $stmt->execute([$ek]);
            $erow = $stmt->fetch();
            $email_settings[$ek] = $erow !== false ? $erow['key_value'] : '';
            if ($ek === 'smtp_password' && $email_settings[$ek] !== '') {
                $email_settings[$ek] = '••••••••';
            }
        }

        // دریافت قالب‌ها
        $templates = EmailTemplate::getAllTemplates();

        // دریافت لاگ‌ها
        $filter_status = $_GET['filter_status'] ?? '';
        $logs = EmailTemplate::getLog(50, 0, !empty($filter_status) ? $filter_status : null);

        // آمار
        $email_stats = EmailTemplate::getAdminEmailStats();

        // دریافت کاربران برای ارسال انبوه
        $active_users = $db->query("SELECT id, name, email FROM users WHERE status = 'active' AND role != 'superadmin' ORDER BY id DESC")->fetchAll();
        $all_users = $db->query("SELECT id, name, email FROM users WHERE role != 'superadmin' ORDER BY id DESC")->fetchAll();

        include __DIR__ . '/../Views/partials/admin-email-settings.php';
        exit;
    }

    /**
     * ذخیره تنظیمات SMTP
     */
    public function handleSaveEmailConfig() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $enabled = isset($_POST['smtp_enabled']) ? '1' : '0';
        $host = trim($_POST['smtp_host'] ?? '');
        $port = trim($_POST['smtp_port'] ?? '587');
        $username = trim($_POST['smtp_username'] ?? '');
        $password = trim($_POST['smtp_password'] ?? '');
        $encryption = trim($_POST['smtp_encryption'] ?? 'tls');
        $fromAddress = trim($_POST['smtp_from_address'] ?? '');
        $fromName = trim($_POST['smtp_from_name'] ?? '');
        $timeout = max(5, min(60, (int)($_POST['smtp_timeout'] ?? 15)));
        $auth = isset($_POST['smtp_auth']) ? '1' : '0';
        $replyTo = trim($_POST['smtp_reply_to'] ?? '');
        $replyName = trim($_POST['smtp_reply_name'] ?? '');

        // اگر رمز عبور خالی بود و قبلاً ذخیره شده، نگه‌داشتن رمز قبلی
        if (empty($password)) {
            $stmt = Bootstrap::getDB()->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = 'smtp_password' LIMIT 1");
            $stmt->execute();
            $existing = $stmt->fetch();
            if ($existing) {
                $password = $existing['key_value'];
            }
        }

        $this->saveSettingsBatch(0, [
            'smtp_enabled'      => $enabled,
            'smtp_host'         => $host,
            'smtp_port'         => $port,
            'smtp_username'     => $username,
            'smtp_password'     => $password !== '' && !str_starts_with($password,'enc:v1:') ? \WHCM\Core\SecretStore::encrypt($password) : $password,
            'smtp_encryption'   => $encryption,
            'smtp_from_address' => $fromAddress,
            'smtp_from_name'    => $fromName,
            'smtp_timeout'      => (string)$timeout,
            'smtp_auth'         => $auth,
            'smtp_reply_to'     => $replyTo,
            'smtp_reply_name'   => $replyName,
        ]);

        $this->setFlashMessage('تنظیمات SMTP با موفقیت ذخیره شد! 📧✔');
        $this->redirect('/hnnh');
    }

    /**
     * ذخیره قالب ایمیل (ایجاد یا به‌روزرسانی)
     */
    public function handleSaveEmailTemplate() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $eventKey = trim($_POST['event_key'] ?? '');
        $name = trim($_POST['template_name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $bodyHtml = trim($_POST['body_html'] ?? '');
        $variablesStr = trim($_POST['variables'] ?? '[]');
        $isActive = isset($_POST['is_active']) ? true : false;

        if (empty($eventKey) || empty($name) || empty($subject)) {
            $this->setFlashMessage('فیلدهای کلید رویداد، نام قالب و موضوع الزامی هستند.');
            $this->redirect('/hnnh');
        }

        $variables = json_decode($variablesStr, true);
        if (!is_array($variables)) {
            $variables = [];
        }

        EmailTemplate::saveTemplate($eventKey, $name, $subject, $bodyHtml, $variables, $isActive);
        $this->setFlashMessage('قالب ایمیل با موفقیت ذخیره شد! ✏️📧✔');
        $this->redirect('/hnnh');
    }

    /**
     * حذف قالب ایمیل
     */
    public function handleDeleteEmailTemplate() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $id = (int)($_POST['template_db_id'] ?? 0);
        if ($id <= 0) {
            $this->setFlashMessage('شناسه قالب نامعتبر است.');
            $this->redirect('/hnnh');
        }

        EmailTemplate::deleteTemplate($id);
        $this->setFlashMessage('قالب ایمیل حذف شد! 🗑️📧');
        $this->redirect('/hnnh');
    }

    /**
     * ارسال ایمیل تستی
     */
    public function handleTestEmail() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $adminId = Auth::tenantId();
        $result = EmailTemplate::testConnection($adminId);
        if ($result['success']) {
            $this->setFlashMessage('ایمیل تستی با موفقیت ارسال شد! 📧✔');
        } else {
            $this->setFlashMessage('❌ آزمون اتصال ایمیل ناموفق بود: ' . ($result['error'] ?? 'علت نامشخص'));
        }
        $this->redirect('/hnnh');
    }

    /**
     * ارسال ایمیل انبوه
     */
    public function handleSendBulkEmail() {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $recipientType = trim($_POST['recipient_type'] ?? '');
        $templateId = (int)($_POST['bulk_template_id'] ?? 0);

        if ($templateId <= 0) {
            $this->setFlashMessage('لطفاً یک قالب ایمیل انتخاب کنید.');
            $this->redirect('/hnnh');
        }

        $db = Bootstrap::getDB();

        // دریافت قالب
        $stmt = $db->prepare("SELECT event_key FROM email_templates WHERE id = ? LIMIT 1");
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            $this->setFlashMessage('قالب انتخاب‌شده یافت نشد.');
            $this->redirect('/hnnh');
        }

        $eventKey = $tpl['event_key'];
        $userIds = [];

        if ($recipientType === 'all') {
            $rows = $db->query("SELECT id FROM users WHERE role != 'superadmin'")->fetchAll();
            foreach ($rows as $r) $userIds[] = (int)$r['id'];
        } elseif ($recipientType === 'active') {
            $rows = $db->query("SELECT id FROM users WHERE status = 'active' AND role != 'superadmin'")->fetchAll();
            foreach ($rows as $r) $userIds[] = (int)$r['id'];
        } elseif ($recipientType === 'subscription') {
            $rows = $db->query("SELECT DISTINCT user_id as id FROM subscriptions WHERE status = 'active'")->fetchAll();
            foreach ($rows as $r) $userIds[] = (int)$r['id'];
        } else {
            $this->setFlashMessage('نوع گیرندگان نامعتبر است.');
            $this->redirect('/hnnh');
        }

        if (empty($userIds)) {
            $this->setFlashMessage('هیچ کاربری یافت نشد.');
            $this->redirect('/hnnh');
        }

        $result = EmailTemplate::sendBulk($eventKey, $userIds);

        $msg = '✅ ایمیل انبوه ارسال شد! موفق: ' . TextFormat::fa_digits($result['sent']) . ' | ناموفق: ' . TextFormat::fa_digits($result['failed']);
        if (!empty($result['errors'])) {
            $msg .= ' | خطاها: ' . implode('، ', array_slice($result['errors'], 0, 3));
        }
        $this->setFlashMessage($msg);
        $this->redirect('/hnnh');
    }

    /**
     * پیش‌نمایش قالب ایمیل (برگرداندن HTML رندر شده)
     */
    public function handlePreviewEmailTemplate() {
        $this->checkSuperAdmin();
        header('Content-Type: text/html; charset=utf-8');

        $bodyHtml = trim($_POST['body_html'] ?? '');
        $variablesStr = trim($_POST['variables'] ?? '[]');
        $variables = json_decode($variablesStr, true) ?: [];

        // مقداردهی نمونه برای پیش‌نمایش
        $variables['app_name'] = Bootstrap::getConfig('app.name', 'پُست‌یار');
        $variables['app_url'] = Bootstrap::getConfig('app.url', '#');
        $variables['name'] = 'نام نمونه';
        $variables['plan_name'] = 'پلن حرفه‌ای';
        $variables['amount'] = TextFormat::fa_digits('500000');
        $variables['days_left'] = TextFormat::fa_digits('3');
        $variables['ticket_subject'] = 'موضوع تیکت نمونه';
        $variables['message'] = 'این یک پیام نمونه برای پیش‌نمایش اعلان است.';
        $variables['date'] = '۱۴۰۴/۰۴/۲۶';
        $variables['reset_link'] = '#';

        echo EmailTemplate::renderTemplate($bodyHtml, $variables);
        exit;
    }

    public function handleLinkRedirect(string $code) {
        $result = LinkTracker::handleClick($code);
        if ($result) {
            header('Location: ' . $result['original_url'], true, 302);
            exit;
        }
        http_response_code(404);
        exit('لینک یافت نشد.');
    }

    public function linkStatsSection() {
        $this->checkAuth();
        $tenantId = Auth::tenantId();
        $stats = LinkTracker::getUserLinkStats($tenantId);
        $dailyClicks = LinkTracker::getDailyClicks($tenantId, 30);
        echo json_encode(['stats' => $stats, 'daily' => $dailyClicks], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function handleResetPasswordSms() {
        header('Content-Type: application/json; charset=utf-8');
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { echo json_encode(['success' => false, 'message' => 'خطای امنیتی!'], JSON_UNESCAPED_UNICODE); exit; }
        $phone = \WHCM\Domain\AntiAbuse::normalizePhone(trim($_POST['phone'] ?? ''));
        if (!$phone || !\WHCM\Domain\AntiAbuse::validPhone($phone)) { echo json_encode(['success' => false, 'message' => 'شماره موبایل معتبر نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!RateLimit::consume('web_sms_reset', 3, 300, $phone)) { echo json_encode(['success' => false, 'message' => 'تعداد درخواست‌های پیامکی بیش از حد مجاز است.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!class_exists('WHCM\\Core\\Sms') || !Sms::isEnabled()) { echo json_encode(['success' => false, 'message' => 'سرویس پیامک فعال نیست.'], JSON_UNESCAPED_UNICODE); exit; }
        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if (!$user) { echo json_encode(['success' => true, 'message' => 'در صورت وجود حساب، کد تأیید ارسال شد.'], JSON_UNESCAPED_UNICODE); exit; }
        $userId = (int)$user['id'];
        $stmt = $db->prepare('SELECT template_id FROM sms_templates WHERE event_key = ? AND is_active = 1 LIMIT 1');
        $stmt->execute(['password_reset']);
        $template = $stmt->fetch();
        if (!$template) { echo json_encode(['success' => false, 'message' => 'قالب پیامک بازنشانی تنظیم نشده است.'], JSON_UNESCAPED_UNICODE); exit; }
        $code = VerificationCode::generate($userId, 'sms_reset', 5);
        $result = Sms::send($phone, (int)$template['template_id'], [['Parameter' => 'code', 'ParameterValue' => $code]], $userId);
        if (!$result['success']) { echo json_encode(['success' => false, 'message' => 'خطا در ارسال پیامک: ' . ($result['error'] ?? '')], JSON_UNESCAPED_UNICODE); exit; }
        $_SESSION['sms_reset_user_id'] = $userId;
        echo json_encode(['success' => true, 'message' => 'کد تأیید ارسال شد.'], JSON_UNESCAPED_UNICODE); exit;
    }

    public function showSmsVerifyForm() {
        $this->render('home', ['title' => 'تأیید کد پیامکی | پُست‌یار', 'csrf_field' => Csrf::field(), 'message' => $this->getFlashMessage(), 'show_sms_verify' => true]);
    }

    public function handleVerifySmsCode() {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) { $this->setFlashMessage('خطای امنیتی!'); $this->redirect('/sms-verify'); return; }
        $code = trim($_POST['code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $userId = (int)($_SESSION['sms_reset_user_id'] ?? 0);
        if ($userId > 0 && !RateLimit::consume('web_sms_verify', 5, 300, (string)$userId)) { $this->setFlashMessage('تعداد تلاش‌های تأیید بیش از حد مجاز است.'); $this->redirect('/sms-verify'); return; }
        if ($userId <= 0) { $this->setFlashMessage('نشست منقضی.'); $this->redirect('/'); return; }
        if (empty($code) || !VerificationCode::verify($userId, 'sms_reset', $code)) { $this->setFlashMessage('کد نامعتبر یا منقضی.'); $this->redirect('/sms-verify'); return; }
        if (empty($newPassword) || strlen($newPassword) < 6) { $this->setFlashMessage('رمز حداقل ۶ کاراکتر.'); $this->redirect('/sms-verify'); return; }
        if ($newPassword !== $confirmPassword) { $this->setFlashMessage('رمز با تکرار مطابقت ندارد.'); $this->redirect('/sms-verify'); return; }
        $db = Bootstrap::getDB();
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), $userId]);
        \WHCM\Api\MobileApiAuth::revokeAllUserTokens($userId);
        unset($_SESSION['sms_reset_user_id']);
        Session::regenerate();
        $this->setFlashMessage('رمز عبور با موفقیت تغییر یافت.');
        $this->redirect('/');
    }

    /**
     * صفحه آموزش و راهنمای کاربری
     */
    public function helpPage() {
        $this->render('help', ['title' => 'آموزش استفاده از پُست‌یار']);
    }

    /**
     * سیاست حریم خصوصی عمومی وب‌سایت و اپلیکیشن
     */
    public function privacyPage() {
        // انتقال آدرس پارامتری قدیمی به نشانی خوانا و ثابت
        if (isset($_GET['route'])) {
            $canonicalUrl = rtrim(Bootstrap::getConfig('app.url', 'https://asovin.ir'), '/') . '/privacy';
            header('Location: ' . $canonicalUrl, true, 301);
            exit;
        }

        header('Cache-Control: public, max-age=3600');
        header('Link: <' . rtrim(Bootstrap::getConfig('app.url', 'https://asovin.ir'), '/') . '/privacy>; rel="canonical"');
        $this->render('privacy', ['title' => 'حریم خصوصی کاربران پُست‌یار']);
    }

    // ─── Push Notification ──────────────────────────────────────

    /**
     * بازگرداندن کلید عمومی VAPID برای ثبت اعلان در مرورگر
     */
    public function getVapidPublicKey() {
        header('Content-Type: application/json; charset=utf-8');

        $vapid = Bootstrap::getConfig('vapid');
        if (empty($vapid['public_key'])) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'پوش ناتیفیکیشن فعال نیست.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(['success' => true, 'publicKey' => $vapid['public_key']]);
    }

    /**
     * ثبت اشتراک Push کاربر
     */
    public function handlePushSubscribe() {
        header('Content-Type: application/json; charset=utf-8');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'نشست کاربری معتبر نیست.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userId = (int) Auth::tenantId();

        $input = json_decode(file_get_contents('php://input'), true);
        $endpoint    = $input['endpoint'] ?? '';
        $keysP256dh  = $input['keys']['p256dh'] ?? '';
        $keysAuth    = $input['keys']['auth'] ?? '';

        if (!$endpoint || !$keysP256dh || !$keysAuth) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'پارامترهای اشتراک ناقص است.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Bootstrap::getDB();

        // حذف اشتراک قبلی این کاربر (هر کاربر فقط یک اشتراک فعال)
        $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$userId]);

        $stmt = $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, keys_p256dh, keys_auth) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $endpoint, $keysP256dh, $keysAuth]);

        echo json_encode(['success' => true, 'message' => 'اشتراک اعلان با موفقیت ثبت شد.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * لغو اشتراک Push کاربر
     */
    public function handlePushUnsubscribe() {
        header('Content-Type: application/json; charset=utf-8');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'نشست کاربری معتبر نیست.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userId = (int) Auth::tenantId();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);

        echo json_encode(['success' => true, 'message' => 'اشتراک اعلان لغو شد.'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * بررسی وضعیت اشتراک Push کاربر
     */
    public function getPushStatus() {
        header('Content-Type: application/json; charset=utf-8');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'نشست کاربری معتبر نیست.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $userId = (int) Auth::tenantId();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT id FROM push_subscriptions WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);

        echo json_encode([
            'success' => true,
            'subscribed' => ($stmt->fetch() !== false),
            'enabled' => !empty(Bootstrap::getConfig('vapid.enabled')),
        ]);
    }

    /**
     * ارسال اعلان به یک کاربر خاص (برای ادمین و سیستم)
     */
    public static function sendPushToUser(int $userId, string $title, string $body, string $url = ''): bool {
        $vapid = Bootstrap::getConfig('vapid');
        if (empty($vapid['enabled']) || empty($vapid['private_key_pem'])) {
            return false;
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare('SELECT endpoint, keys_p256dh, keys_auth FROM push_subscriptions WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $sub = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$sub) return false;

        $appUrl = Bootstrap::getConfig('app.url');
        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url ?: $appUrl . '/dashboard',
        ], JSON_UNESCAPED_UNICODE);

        try {
            $result = WebPush::send(
                $sub['endpoint'],
                $sub['keys_p256dh'],
                $sub['keys_auth'],
                $payload,
                [
                    'subject'    => $vapid['subject'],
                    'publicKey'  => $vapid['public_key'],
                    'privateKey' => $vapid['private_key_pem'],
                ]
            );

            // اگر اشتراک منقضی شده، حذف شود
            if (!$result['success'] && in_array($result['status'], [404, 410])) {
                $db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$sub['endpoint']]);
            }

            return $result['success'];
        } catch (\Throwable $e) {
            error_log('[Postyar Push] Send error for user ' . $userId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * ارسال اعلان به تمام کاربران (برداشت)
     */
    public static function sendPushBroadcast(string $title, string $body, string $url = ''): array {
        $vapid = Bootstrap::getConfig('vapid');
        if (empty($vapid['enabled']) || empty($vapid['private_key_pem'])) {
            return [];
        }

        $db = Bootstrap::getDB();
        $subs = $db->query('SELECT id, endpoint, keys_p256dh, keys_auth FROM push_subscriptions')->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($subs)) return [];

        $appUrl = Bootstrap::getConfig('app.url');
        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url ?: $appUrl . '/dashboard',
        ], JSON_UNESCAPED_UNICODE);

        $vapidConfig = [
            'subject'    => $vapid['subject'],
            'publicKey'  => $vapid['public_key'],
            'privateKey' => $vapid['private_key_pem'],
        ];

        $results = [];
        $expiredEndpoints = [];

        foreach ($subs as $sub) {
            try {
                $result = WebPush::send(
                    $sub['endpoint'],
                    $sub['keys_p256dh'],
                    $sub['keys_auth'],
                    $payload,
                    $vapidConfig
                );
                $results[] = $result;

                if (!$result['success'] && in_array($result['status'], [404, 410])) {
                    $expiredEndpoints[] = $sub['endpoint'];
                }
            } catch (\Throwable $e) {
                $results[] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // پاکسازی اشتراک‌های منقضی
        if (!empty($expiredEndpoints)) {
            $placeholders = implode(',', array_fill(0, count($expiredEndpoints), '?'));
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint IN ($placeholders)")
               ->execute($expiredEndpoints);
        }

        return $results;
    }

    /**
     * ذخیره دسته‌بندی‌های تیکت (AJAX از پنل مدیریت)
     */
    public function handleSaveTicketCategories() {
        header('Content-Type: application/json; charset=utf-8');
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            echo json_encode(['success' => false, 'message' => 'خطای امنیتی'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Bootstrap::getDB();
        $categories_raw = json_decode($_POST['categories'] ?? '[]', true);
        if (!is_array($categories_raw)) {
            echo json_encode(['success' => false, 'message' => 'داده نامعتبر'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // حذف همه دسته‌بندی‌های فعلی
            $db->exec("DELETE FROM ticket_categories");

            $sort_order = 1;
            foreach ($categories_raw as $cat) {
                $slug = trim($cat['slug'] ?? '');
                $title = trim($cat['title'] ?? '');
                $icon = trim($cat['icon'] ?? '🌐');
                $assigned_agent = (int)($cat['assigned_agent'] ?? 0);
                if (empty($slug) || empty($title)) continue;

                $stmt = $db->prepare("INSERT INTO ticket_categories (slug, title, icon, assigned_agent_id, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$slug, $title, $icon, $assigned_agent ?: null, $sort_order++]);
            }

            echo json_encode(['success' => true, 'message' => 'دسته‌بندی‌ها با موفقیت ذخیره شدند ✔'], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            error_log('[Postyar] Save ticket categories error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی'], JSON_UNESCAPED_UNICODE);
        }
    }
}
