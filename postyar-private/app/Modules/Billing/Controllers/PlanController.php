<?php
namespace WHCM\Modules\Billing\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\Csrf;
use WHCM\Controllers\BaseController;

/**
 * کنترلر ماژول Billing — مدیریت پلن‌ها
 * قدم ۲-الف — منطق از MainController کپی شده، URL ها بدون تغییر
 */
class PlanController extends BaseController
{
    public function create()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $title = trim($_POST['title'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $duration = (int)($_POST['duration_days'] ?? 30);
        $max_channels = (int)($_POST['max_channels'] ?? 1);
        $max_posts = (int)($_POST['max_posts'] ?? 0);
        $early_renewal_discount = (int)($_POST['early_renewal_discount'] ?? 0);
        $general_discount = (int)($_POST['general_discount'] ?? 0);
        $discount_badge_text = trim($_POST['discount_badge_text'] ?? '');
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $gold = isset($_POST['feat_gold']) ? true : false;
        $reply = isset($_POST['feat_reply']) ? true : false;
        $woo = isset($_POST['feat_woo']) ? true : false;
        $ai = isset($_POST['feat_ai']) ? true : false;
        $payment_url = trim($_POST['payment_url'] ?? '');
        $image_url = $this->uploadAndConvertToWebp('plan_image', 'plans');
        $features = json_encode([
            'gold_ticker' => $gold,
            'auto_responder' => $reply,
            'woocommerce' => $woo,
            'ai_caption' => $ai,
            'stats' => true
        ], JSON_UNESCAPED_UNICODE);
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("INSERT INTO plans (title, price, duration_days, max_channels, max_posts, features, payment_url, image_url, description, early_renewal_discount, general_discount, discount_badge_text, is_featured) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $price, $duration, $max_channels, $max_posts, $features, $payment_url, $image_url, $description, $early_renewal_discount, $general_discount, $discount_badge_text, $is_featured]);
        $this->setFlashMessage('پلن جدید اشتراکی با موفقیت ایجاد گردید. 🌟');
        $this->redirect('/hnnh');
    }

    public function edit()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id = (int)($_POST['plan_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $duration = (int)($_POST['duration_days'] ?? 30);
        $max_channels = (int)($_POST['max_channels'] ?? 1);
        $max_posts = (int)($_POST['max_posts'] ?? 0);
        $early_renewal_discount = (int)($_POST['early_renewal_discount'] ?? 0);
        $general_discount = (int)($_POST['general_discount'] ?? 0);
        $discount_badge_text = trim($_POST['discount_badge_text'] ?? '');
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $gold = isset($_POST['feat_gold']) ? true : false;
        $reply = isset($_POST['feat_reply']) ? true : false;
        $woo = isset($_POST['feat_woo']) ? true : false;
        $ai = isset($_POST['feat_ai']) ? true : false;
        $payment_url = trim($_POST['payment_url'] ?? '');
        $db = Bootstrap::getDB();
        $image_url = $this->uploadAndConvertToWebp('plan_image', 'plans');
        if (empty($image_url)) {
            $stmt = $db->prepare("SELECT image_url FROM plans WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $image_url = $stmt->fetchColumn() ?: '';
        }
        $features = json_encode([
            'gold_ticker' => $gold,
            'auto_responder' => $reply,
            'woocommerce' => $woo,
            'ai_caption' => $ai,
            'stats' => true
        ], JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("UPDATE plans SET title = ?, price = ?, duration_days = ?, max_channels = ?, max_posts = ?, features = ?, payment_url = ?, image_url = ?, description = ?, early_renewal_discount = ?, general_discount = ?, discount_badge_text = ?, is_featured = ? WHERE id = ?");
        $stmt->execute([$title, $price, $duration, $max_channels, $max_posts, $features, $payment_url, $image_url, $description, $early_renewal_discount, $general_discount, $discount_badge_text, $is_featured, $id]);
        $this->setFlashMessage('پلن اشتراکی با موفقیت بروزرسانی شد. ✔');
        $this->redirect('/hnnh');
    }

    public function delete()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }
        $id = (int)($_POST['plan_id'] ?? 0);
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("DELETE FROM plans WHERE id = ?");
        $stmt->execute([$id]);
        $this->setFlashMessage('پلن اشتراکی با موفقیت حذف گردید.');
        $this->redirect('/hnnh');
    }
}
