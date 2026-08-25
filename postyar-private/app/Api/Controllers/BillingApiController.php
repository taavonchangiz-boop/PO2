<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Core\Bootstrap;
use WHCM\Domain\Idempotency;
use WHCM\Domain\PaymentPricing;

/**
 * کنترلر API صورتحساب و پرداخت
 *
 * شامل: لیست پلن‌ها، ثبت پرداخت، لیست پرداخت‌ها، اعتبارسنجی کد تخفیف
 *
 * @package WHCM\Api\Controllers
 */
class BillingApiController extends \WHCM\Api\MobileApiController {


    /**
     * دریافت لیست پلن‌ها
     * GET /api/v1/plans
     */
    public function getPlans(): void {

        $db = $this->db();

        $stmt = $db->query(
            "SELECT * FROM plans ORDER BY price ASC"
        );

        $plans = $stmt->fetchAll();


        foreach ($plans as &$plan) {

            $plan['features'] =
                json_decode(
                    $plan['features'] ?? '[]',
                    true
                ) ?: [];
        }

        unset($plan);


        MobileApiResponse::success($plans);
    }



    /**
     * ثبت پرداخت جدید
     * POST /api/v1/payments
     */
    public function submitPayment(): void {


        $userId = $this->userId();

        $db = $this->db();

        $input = $this->input();



        $errors = $this->validate(
            [
                'plan_id'       => 'required',
                'amount'        => 'required',
                'reference_num' => 'required',
                'idempotency_key' => 'required',
            ],
            $input
        );


        if (!empty($errors)) {

            MobileApiResponse::validationError($errors);
        }



        $planId =
            (int)$input['plan_id'];

        $clientAmount = (float)$input['amount'];
        $referenceNum = trim((string)($input['reference_num'] ?? ''));

        if ($planId <= 0) {

            MobileApiResponse::validationError([
                'plan_id' => 'شناسه پلن نامعتبر است.'
            ]);
        }



        if ($referenceNum === '' || strlen($referenceNum) > 100) {
            MobileApiResponse::validationError(['reference_num' => 'شماره پیگیری نامعتبر است.']);
        }

        if ($clientAmount <= 0) {
            MobileApiResponse::validationError(['amount' => 'مبلغ اعلامی نامعتبر است.']);
        }

        try {
            $quote = PaymentPricing::quote($userId, $planId);
            $amount = (float)$quote['amount'];
            $plan = $quote['plan'];
        } catch (\Throwable $e) {
            MobileApiResponse::notFound('پلن مورد نظر یافت نشد یا قیمت آن قابل محاسبه نیست.');
        }
        if ($amount <= 0) {
            MobileApiResponse::validationError(['amount' => 'این پلن مبلغ قابل پرداخت معتبری ندارد.']);
        }
        if (abs($clientAmount - $amount) > 0.01) {
            // Never trust the client value; the server amount below is persisted.
            error_log('[Postyar] API payment amount mismatch for user #' . $userId . ', plan #' . $planId . '; server amount used');
        }

        $idempotencyKey = Idempotency::normalizeKey($input['idempotency_key'] ?? null);
        if ($idempotencyKey === null) {
            MobileApiResponse::validationError(['idempotency_key' => 'کلید idempotency معتبر و الزامی است.']);
        }
        $existingKey = Idempotency::existing($db, $userId, 'payment_submit', $idempotencyKey);
        if ($existingKey) {
            if ($existingKey['status'] === 'completed' && !empty($existingKey['resource_id'])) {
                $stmt = $db->prepare("SELECT pay.*, p.title AS plan_title FROM payments pay LEFT JOIN plans p ON pay.plan_id = p.id WHERE pay.id = ? LIMIT 1");
                $stmt->execute([(int)$existingKey['resource_id']]);
                $existingPayment = $stmt->fetch();
                if ($existingPayment) MobileApiResponse::success($existingPayment, 'درخواست قبلی بازیابی شد.');
            }
            MobileApiResponse::error('درخواست مشابه در حال پردازش است؛ لطفاً درخواست تکراری ارسال نکنید.', 409);
        }
        if (!Idempotency::reserve($db, $userId, 'payment_submit', $idempotencyKey)) {
            MobileApiResponse::error('درخواست مشابه همزمان ثبت شد؛ لطفاً کمی بعد تلاش کنید.', 409);
        }

        try {
            $receiptPhoto = $this->uploadImage('receipt_photo', 'receipts');

            // شماره پیگیری نباید برای یک کاربر دوباره مصرف شود.
            $dupStmt = $db->prepare("SELECT id FROM payments WHERE user_id = ? AND reference_num = ? LIMIT 1");
            $dupStmt->execute([$userId, $referenceNum]);
            if ($dupStmt->fetch()) {
                Idempotency::fail($db, $userId, 'payment_submit', $idempotencyKey);
                MobileApiResponse::error('این شماره پیگیری قبلاً ثبت شده است.', 409);
            }

            $stmt = $db->prepare(
                "INSERT INTO payments (user_id, plan_id, amount, quoted_amount, payment_method, receipt_photo, reference_num, status)
                 VALUES (?, ?, ?, ?, 'card_to_card', ?, ?, 'pending')"
            );
            $stmt->execute([$userId, $planId, $amount, $amount, $receiptPhoto, $referenceNum]);
            $paymentId = (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            Idempotency::fail($db, $userId, 'payment_submit', $idempotencyKey);
            MobileApiResponse::error('خطا در ثبت پرداخت. لطفاً دوباره با یک درخواست جدید تلاش کنید.', 500);
        }

        Idempotency::complete($db, $userId, 'payment_submit', $idempotencyKey, $paymentId, ['payment_id' => $paymentId]);

        $stmt = $db->prepare(
            "
            SELECT pay.*, p.title AS plan_title
            FROM payments pay
            LEFT JOIN plans p
            ON pay.plan_id = p.id
            WHERE pay.id = ?
            "
        );


        $stmt->execute([$paymentId]);


        $payment = $stmt->fetch();



        MobileApiResponse::success(
            $payment,
            'پرداخت با موفقیت ثبت شد و پس از بررسی نتیجه اعلام خواهد شد.'
        );
    }




    /**
     * ایجاد سفارش پرداخت آنلاین با مبلغ و پلن محاسبه‌شده سمت سرور.
     * Provider adapter فقط پس از ایجاد immutable order اجازه redirect دارد.
     */
    public function createOnlineOrder(): void {
        $userId = $this->userId();
        $input = $this->input();
        $errors = $this->validate([
            'plan_id' => 'required',
            'idempotency_key' => 'required',
        ], $input);
        if (!empty($errors)) MobileApiResponse::validationError($errors);

        try {
            $activeProvider = \WHCM\Payments\PaymentProviderRegistry::activeId();
            if ($activeProvider === 'manual') {
                throw new \RuntimeException('پرداخت آنلاین فعلاً فعال نیست؛ روش کارت‌به‌کارت در پنل مدیریت فعال است.');
            }
            $requestedProvider = strtolower(trim((string)($input['provider'] ?? '')));
            if ($requestedProvider !== '' && $requestedProvider !== $activeProvider) {
                throw new \RuntimeException('درگاه پرداخت انتخاب‌شده با درگاه فعال سامانه مطابقت ندارد.');
            }
            if (!\WHCM\Payments\PaymentProviderRegistry::isEnabledAndVerified($activeProvider)) {
                throw new \RuntimeException('درگاه انتخاب‌شده هنوز توسط مدیر فعال و تأیید نشده است.');
            }
            $order = \WHCM\Domain\PaymentOrder::createSubscription(
                $userId,
                (int)$input['plan_id'],
                $activeProvider,
                (string)$input['idempotency_key']
            );
            $adapter = \WHCM\Payments\PaymentProviderRegistry::adapter((string)$order['provider']);
            $returnUrl = rtrim((string)\WHCM\Core\Bootstrap::getConfig('app.url', ''), '/') . '/payment/callback?order=' . rawurlencode((string)$order['public_id']);
            // The adapter must validate/construct the provider request; the client never supplies amount.
            $created = $adapter->createPayment((int)$order['id'], $userId, (float)$order['amount'], $returnUrl);
            $reference = \WHCM\Domain\PaymentOrder::normalizeReference((string)($created['provider_reference'] ?? ''));
            if ($reference === null) throw new \RuntimeException('درگاه شناسه تراکنش معتبر برنگرداند.');
            if (!\WHCM\Domain\PaymentOrder::markRedirected((int)$order['id'], $reference)) throw new \RuntimeException('ثبت وضعیت redirect پرداخت ناموفق بود.');
            $order = \WHCM\Domain\PaymentOrder::find((int)$order['id']);
            MobileApiResponse::success(['order'=>$order,'payment_url'=>(string)($created['payment_url'] ?? '')], 'سفارش پرداخت ایجاد شد.');
        } catch (\Throwable $e) {
            MobileApiResponse::error($e->getMessage(), 422);
        }
    }


    /**
     * دریافت لیست پرداخت‌ها
     */
    public function getPayments(): void {


        $userId = $this->userId();

        $db = $this->db();



        $stmt = $db->prepare(
            "
            SELECT pay.*, p.title AS plan_title
            FROM payments pay
            LEFT JOIN plans p
            ON pay.plan_id = p.id
            WHERE pay.user_id = ?
            ORDER BY pay.id DESC
            "
        );


        $stmt->execute([$userId]);


        $payments = $stmt->fetchAll();



        MobileApiResponse::success($payments);
    }





    /**
     * اعتبارسنجی کد تخفیف
     * POST /api/v1/coupons/validate
     */
    public function validateCoupon(): void {


        $userId = $this->userId();

        $db = $this->db();

        $input = $this->input();



        $errors = $this->validate(
            [
                'code' => 'required',
                'plan_id' => 'required',
            ],
            $input
        );



        if (!empty($errors)) {

            MobileApiResponse::validationError($errors);
        }



        $code =
            trim($input['code']);

        $planId =
            (int)$input['plan_id'];



        /*
         * MySQL/MariaDB compatible timestamp
         * جایگزین datetime('now')
         */
        $now = date('Y-m-d H:i:s');



        $stmt = $db->prepare(
            "
            SELECT *
            FROM discount_codes
            WHERE code = ?
              AND active = 1
              AND (
                    expires_at IS NULL
                    OR expires_at > ?
                  )
              AND (
                    max_uses = 0
                    OR used < max_uses
                  )
            LIMIT 1
            "
        );



        $stmt->execute([
            $code,
            $now
        ]);



        $coupon = $stmt->fetch();



        if (!$coupon) {

            MobileApiResponse::error(
                'کد تخفیف نامعتبر، منقضی شده یا استفاده شده است.',
                404
            );
        }



        MobileApiResponse::success(
            [
                'id' =>
                    (int)$coupon['id'],

                'code' =>
                    $coupon['code'],

                'type' =>
                    $coupon['type'],

                'amount' =>
                    (float)$coupon['amount'],

                'max_uses' =>
                    (int)$coupon['max_uses'],

                'used' =>
                    (int)$coupon['used'],

                'expires_at' =>
                    $coupon['expires_at'],
            ],
            'کد تخفیف معتبر است.'
        );
    }
}