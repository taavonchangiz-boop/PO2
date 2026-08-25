<?php
namespace WHCM\Modules\Billing\Controllers;

use WHCM\Core\Csrf;
use WHCM\Domain\Notification;
use WHCM\Domain\PaymentSettlement;
use WHCM\Controllers\BaseController;

/**
 * کنترلر ماژول Billing — تأیید پرداخت‌ها
 * تمامی مسیرهای تأیید پرداخت از PaymentSettlement واحد استفاده می‌کنند.
 */
class PaymentController extends BaseController
{
    public function approve()
    {
        $this->checkSuperAdmin();
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            $this->setFlashMessage('خطای امنیتی! توکن نامعتبر است.');
            $this->redirect('/hnnh');
        }

        $id = (int)($_POST['payment_id'] ?? 0);
        try {
            $result = PaymentSettlement::approve($id);
            $userId = (int)$result['user_id'];
            $planTitle = (string)$result['plan_title'];
            try {
                \WHCM\Controllers\MainController::sendPushToUser($userId, '✅ اشتراک شما فعال شد!', 'پلن «' . $planTitle . '» با موفقیت فعال گردید. ✔', '/dashboard');
            } catch (\Throwable $e) {}
            try {
                Notification::create($userId, '✅ اشتراک شما فعال شد', 'پلن «' . $planTitle . '» با موفقیت فعال گردید و از همین لحظه قابل استفاده است.', 'subscription', 'upgrade');
            } catch (\Throwable $e) {}
            $this->setFlashMessage('پرداخت با موفقیت تایید و اشتراک کاربر بلافاصله فعال گردید. ✔');
        } catch (\Throwable $e) {
            $this->setFlashMessage('بروز خطا در پردازش تایید تراکنش: ' . $e->getMessage());
        }
        $this->redirect('/hnnh');
    }
}
