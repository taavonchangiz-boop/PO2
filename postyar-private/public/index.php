<?php
/**
 * فایل ورودی اصلی سامانه مستقل مدیریت کانال‌ها (SaaS)
 *
 * @package WHCM_SaaS
 */

try {
    require_once __DIR__ . '/../app/Core/Bootstrap.php';
} catch (\Throwable $e) {
    error_log('[Postyar FATAL] Bootstrap load: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<h1>خطای سیستمی</h1><p>لطفاً چند لحظه بعد دوباره تلاش کنید.</p>';
    exit;
}

use WHCM\Core\Bootstrap;
use WHCM\Core\Router;

try {
    Bootstrap::run();

    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (strpos($requestUri, '/api/v1/') === 0) {
        require_once __DIR__ . '/../app/Api/MobileApiResponse.php';
        require_once __DIR__ . '/../app/Api/MobileApiAuth.php';
        require_once __DIR__ . '/../app/Api/MobileApiRouter.php';
        require_once __DIR__ . '/../app/Api/MobileApiController.php';
        $apiControllerFiles = glob(__DIR__ . '/../app/Api/Controllers/*.php');
        foreach ($apiControllerFiles as $apiCtrlFile) require_once $apiCtrlFile;
        require_once __DIR__ . '/../app/Api/Routes/api.php';
        \WHCM\Api\MobileApiRouter::dispatch($_SERVER['REQUEST_METHOD'], $requestUri);
        exit;
    }

    if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');

    $__moduleLoader = __DIR__ . '/../app/Modules/ModuleLoader.php';
    if (file_exists($__moduleLoader)) {
        require_once $__moduleLoader;
        if (class_exists('\\WHCM\\Modules\\ModuleLoader')) \WHCM\Modules\ModuleLoader::load();
    }

    Router::get('/healthz', 'HealthController@live');
    Router::get('/readyz', 'HealthController@ready');
    Router::get('/metrics', 'HealthController@metrics');

    Router::get('/', 'MainController@index');
    Router::post('/login', 'MainController@handleLogin');
    Router::post('/phone-login', 'MainController@handlePhoneLoginRequest');
    Router::get('/phone-login-verify', 'MainController@showPhoneLoginVerify');
    Router::post('/phone-login-verify', 'MainController@handlePhoneLoginVerify');
    Router::post('/register', 'MainController@handleRegister');
    Router::post('/logout', 'MainController@logout');
    Router::get('/dashboard', 'MainController@dashboard');
    Router::post('/dashboard/add-post', 'MainController@handleCreatePost');
    Router::post('/dashboard/cancel-post', 'MainController@handleCancelPost');
    Router::post('/dashboard/add-channel', 'MainController@handleAddChannel');
    Router::post('/dashboard/edit-channel', 'MainController@handleEditChannel');
    Router::post('/dashboard/delete-channel', 'MainController@handleDeleteChannel');
    Router::post('/dashboard/submit-payment', 'MainController@handlePaymentSubmit');
    Router::post('/dashboard/update-profile', 'MainController@handleUpdateProfile');
    Router::post('/dashboard/change-password', 'MainController@handleChangePassword');
    Router::post('/dashboard/save-gold-settings', 'MainController@handleSaveGoldSettings');
    Router::post('/dashboard/save-advanced-settings', 'MainController@handleSaveAdvancedSettings');
    Router::post('/dashboard/trigger-gold-publish', 'MainController@handleTriggerGoldPublish');
    Router::post('/dashboard/add-auto-reply', 'MainController@handleAddAutoReply');
    Router::post('/dashboard/delete-auto-reply', 'MainController@handleDeleteAutoReply');
    Router::post('/dashboard/toggle-responder', 'MainController@handleToggleResponder');
    Router::post('/dashboard/mark-announcement-read', 'MainController@handleMarkAnnouncementRead');
    Router::post('/dashboard/mark-notification-read', 'MainController@handleMarkNotificationRead');
    Router::post('/dashboard/mark-all-notifications-read', 'MainController@handleMarkAllNotificationsRead');
    Router::post('/dashboard/add-ticket', 'MainController@handleCreateTicket');
    Router::post('/reset-password', 'MainController@handleResetPassword');
    Router::get('/reset-password', 'MainController@showResetPasswordForm');
    Router::post('/reset-password/confirm', 'MainController@handleResetPasswordConfirm');

    Router::get('/hnnh', 'MainController@admin');
    Router::post('/hnnh/reply-ticket', 'MainController@handleReplyTicket');
    Router::post('/hnnh/delete-plan', 'MainController@handleDeletePlan');
    Router::post('/hnnh/edit-plan', 'MainController@handleEditPlan');
    Router::post('/hnnh/approve-payment', 'MainController@handleApprovePayment');
    Router::post('/hnnh/create-plan', 'MainController@handleCreatePlan');
    Router::post('/hnnh/delete-user', 'MainController@handleDeleteUser');
    Router::post('/hnnh/suspend-user', 'MainController@handleSuspendUser');
    Router::post('/hnnh/activate-user', 'MainController@handleActivateUser');
    Router::post('/hnnh/wipe-test-data', 'MainController@handleWipeTestData');
    Router::post('/hnnh/broadcast-announcement', 'MainController@handleBroadcastAnnouncement');
    Router::post('/hnnh/save-bank-settings', 'MainController@handleSaveBankSettings');
    Router::post('/hnnh/add-user-manual', 'MainController@handleAddUserManual');
    Router::post('/hnnh/grant-subscription-manual', 'MainController@handleGrantSubscriptionManual');

    Router::get('/dashboard/referral', 'MainController@referralSection');
    Router::get('/dashboard/wallet', 'MainController@walletSection');
    Router::post('/dashboard/convert-points', 'MainController@handleConvertPoints');
    Router::get('/hnnh/referral-settings', 'MainController@adminReferralSettings');
    Router::post('/hnnh/save-referral-settings', 'MainController@handleSaveReferralSettings');
    Router::get('/hnnh/wallet-stats', 'MainController@adminWalletStats');

    Router::get('/hnnh/sms-settings', 'MainController@adminSmsSettings');
    Router::get('/hnnh/provider-settings', 'MainController@adminProviderSettings');
    Router::post('/hnnh/save-provider-settings', 'MainController@handleSaveProviderSettings');
    Router::post('/hnnh/test-payment-connection', 'MainController@handleTestPaymentConnection');
    Router::post('/hnnh/save-sms-config', 'MainController@handleSaveSmsConfig');
    Router::post('/hnnh/save-sms-template', 'MainController@handleSaveSmsTemplate');
    Router::post('/hnnh/delete-sms-template', 'MainController@handleDeleteSmsTemplate');
    Router::post('/hnnh/test-sms', 'MainController@handleTestSms');
    Router::post('/hnnh/send-bulk-sms', 'MainController@handleSendBulkSms');
    Router::get('/hnnh/email-settings', 'MainController@adminEmailSettings');
    Router::post('/hnnh/save-email-config', 'MainController@handleSaveEmailConfig');
    Router::post('/hnnh/save-email-template', 'MainController@handleSaveEmailTemplate');
    Router::post('/hnnh/delete-email-template', 'MainController@handleDeleteEmailTemplate');
    Router::post('/hnnh/test-email', 'MainController@handleTestEmail');
    Router::post('/hnnh/send-bulk-email', 'MainController@handleSendBulkEmail');
    Router::post('/hnnh/preview-email-template', 'MainController@handlePreviewEmailTemplate');

    Router::get('/go/{code}', 'MainController@handleLinkRedirect');
    Router::get('/dashboard/link-stats', 'MainController@linkStatsSection');
    Router::get('/help', 'MainController@helpPage');
    Router::get('/privacy', 'MainController@privacyPage');

    Router::post('/ads/impression', 'MainController@recordAdImpression');
    Router::get('/ads/click/{id}', 'MainController@handleAdClick');
    Router::post('/dashboard/ads/create', 'MainController@handleCreateAd');
    Router::post('/dashboard/ads/order', 'MainController@handleCreateAdOrder');
    Router::post('/dashboard/ads/payment', 'MainController@handleAdCardPayment');
    Router::post('/hnnh/ads/manual-create', 'MainController@handleAdminCreateAd');
    Router::post('/hnnh/ads/quote', 'MainController@handleAdQuote');
    Router::post('/hnnh/ads/payment-approve', 'MainController@handleAdPaymentApprove');
    Router::post('/hnnh/ads/status', 'MainController@handleAdStatus');
    Router::post('/hnnh/ads/delete', 'MainController@handleAdDelete');
    Router::get('/hnnh/ads/export', 'MainController@exportAdReport');
    Router::post('/reset-password-sms', 'MainController@handleResetPasswordSms');
    Router::get('/sms-verify', 'MainController@showSmsVerifyForm');
    Router::post('/verify-sms-code', 'MainController@handleVerifySmsCode');
    Router::get('/click', 'MainController@handleClick');
    Router::post('/api/webhook', 'MainController@handleApiWebhook');

    Router::post('/hnnh/save-gold-settings-admin', 'MainController@handleSaveGoldSettingsAdmin');
    Router::post('/hnnh/save-ai-settings-admin', 'MainController@handleSaveAiSettingsAdmin');
    Router::post('/hnnh/delete-discount', 'MainController@handleDeleteDiscount');
    Router::post('/hnnh/add-discount', 'MainController@handleAddDiscount');
    Router::post('/hnnh/save-responder-settings-admin', 'MainController@handleSaveResponderSettingsAdmin');
    Router::post('/hnnh/save-woo-settings-admin', 'MainController@handleSaveWooSettingsAdmin');
    Router::post('/hnnh/reopen-ticket', 'MainController@handleReopenTicketAdmin');
    Router::post('/hnnh/delete-ticket', 'MainController@handleDeleteTicketAdmin');
    Router::post('/hnnh/close-ticket', 'MainController@handleCloseTicketAdmin');
    Router::post('/hnnh/create-ticket', 'MainController@handleCreateTicketAdmin');

    Router::get('/api/push/vapid-key', 'MainController@getVapidPublicKey');
    Router::post('/api/push/subscribe', 'MainController@handlePushSubscribe');
    Router::post('/api/push/unsubscribe', 'MainController@handlePushUnsubscribe');
    Router::get('/api/push/status', 'MainController@getPushStatus');
    Router::post('/api/process-post-queue', 'MainController@processPostQueue');
    Router::post('/api/heartbeat', 'MainController@handleHeartbeat');
    Router::post('/hnnh/save-ticket-categories', 'MainController@handleSaveTicketCategories');

    // Single web security boundary: CSRF, channel edit verification/encryption, secret handling and headers.
    \WHCM\Core\RequestGuard::enforce();

    Router::dispatch();

} catch (\Throwable $e) {
    error_log('[Postyar FATAL] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . ' | Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo '<h1>خطای داخلی سرور</h1><p>خطا لاگ شد. لطفاً فایل error_log هاست را بررسی کنید یا دوباره تلاش کنید.</p>';
    exit;
}
