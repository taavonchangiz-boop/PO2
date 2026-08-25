<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Domain\Wallet;
use WHCM\Domain\Referral;
use WHCM\Domain\Idempotency;

/**
 * کنترلر API کیف پول و سیستم زیرمجموعه‌گیری
 *
 * شامل: مشاهده کیف پول، تبدیل امتیاز، مشاهده زیرمجموعه‌ها
 *
 * @package WHCM\Api\Controllers
 */
class WalletReferralApiController extends \WHCM\Api\MobileApiController {

    /**
     * دریافت اطلاعات کیف پول و تراکنش‌ها
     * GET /api/v1/wallet (auth)
     */
    public function getWallet(): void {
        $userId = $this->userId();
        $db     = $this->db();

        // دریافت موجودی کیف پول
        $balance = Wallet::getBalance($userId);

        // دریافت تراکنش‌ها
        $stmt = $db->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50");
        $stmt->execute([$userId]);
        $transactions = $stmt->fetchAll();

        MobileApiResponse::success([
            'balance'      => $balance,
            'transactions' => $transactions,
        ]);
    }

    /**
     * تبدیل امتیاز به موجودی کیف پول
     * POST /api/v1/wallet/convert-points (auth)
     *
     * Input: points (required, must be > 0)
     */
    public function convertPoints(): void {
        $userId = $this->userId();
        $input  = $this->input();

        $errors = $this->validate([
            'points' => 'required',
            'idempotency_key' => 'required',
        ], $input);

        if (!empty($errors)) {
            MobileApiResponse::validationError($errors);
        }

        $points = (float)$input['points'];
        $idempotencyKey = Idempotency::normalizeKey($input['idempotency_key'] ?? null);
        if ($idempotencyKey === null) {
            MobileApiResponse::validationError(['idempotency_key' => 'کلید idempotency معتبر و الزامی است.']);
        }
        if ($points <= 0) {
            MobileApiResponse::validationError(['points' => 'مقدار امتیاز باید بزرگتر از صفر باشد.']);
        }

        // دریافت نرخ تبدیل از تنظیمات سیستم
        $db = $this->db();
        $stmt = $db->prepare("SELECT setting_value FROM referral_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute(['register_reward_type']);
        $rewardType = $stmt->fetchColumn();

        // نرخ تبدیل پیش‌فرض: هر امتیاز = ۱ تومان
        $rate = 1.0;
        try {
            $stmt = $db->prepare("SELECT setting_value FROM referral_settings WHERE setting_key = 'points_wallet_rate' LIMIT 1");
            $stmt->execute();
            $rateRow = $stmt->fetch();
            if ($rateRow && !empty($rateRow['setting_value'])) {
                $rate = (float)$rateRow['setting_value'];
            }
        } catch (\Exception $e) {}

        $existingKey = Idempotency::existing($db, $userId, 'points_convert', $idempotencyKey);
        if ($existingKey) {
            if ($existingKey['status'] === 'completed') {
                $stored = json_decode($existingKey['response_json'] ?? '', true);
                if (is_array($stored)) {
                    MobileApiResponse::success($stored, 'نتیجه درخواست قبلی بازیابی شد.');
                }
            }
            MobileApiResponse::error('این درخواست در حال پردازش است؛ درخواست تکراری ارسال نکنید.', 409);
        }
        if (!Idempotency::reserve($db, $userId, 'points_convert', $idempotencyKey)) {
            MobileApiResponse::error('درخواست تکراری شناسایی شد.', 409);
        }

        $success = Wallet::convertPointsToWallet($userId, $points, $rate);

        if (!$success) {
            Idempotency::fail($db, $userId, 'points_convert', $idempotencyKey);
            MobileApiResponse::error('امتیاز کافی ندارید یا خطایی در تبدیل رخ داد.', 400);
        }

        $newBalance = Wallet::getBalance($userId);
        $responsePayload = [
            'new_balance'  => $newBalance,
            'converted'    => $points,
            'wallet_amount' => round($points * $rate, 2),
        ];
        Idempotency::complete($db, $userId, 'points_convert', $idempotencyKey, null, $responsePayload);

        MobileApiResponse::success($responsePayload, 'امتیاز با موفقیت به موجودی کیف پول تبدیل شد.');
    }

    /**
     * دریافت اطلاعات زیرمجموعه‌گیری
     * GET /api/v1/referral (auth)
     */
    public function getReferral(): void {
        $userId = $this->userId();
        $db     = $this->db();

        // دریافت کد معرف
        $referralCode = Referral::getUserReferralCode($userId);

        // دریافت آمار
        $stats = Referral::getReferralStats($userId);

        // دریافت لیست زیرمجموعه‌ها
        $stmt = $db->prepare("
            SELECT r.*, u.name as referred_name, u.email as referred_email
            FROM referrals r
            LEFT JOIN users u ON r.referred_id = u.id
            WHERE r.referrer_id = ?
            ORDER BY r.id DESC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $referrals = $stmt->fetchAll();

        // دریافت لینک زیرمجموعه‌گیری
        $referralLink = Referral::getReferralLink($userId);

        MobileApiResponse::success([
            'code'          => $referralCode,
            'link'          => $referralLink,
            'stats'         => $stats,
            'referrals'     => $referrals,
            'referral_points' => (float)(($this->user())['referral_points'] ?? 0),
        ]);
    }
}
