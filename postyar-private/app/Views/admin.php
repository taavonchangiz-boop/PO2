<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?php echo htmlspecialchars($title); ?> | پنل مدیریت ارشد پُست‌یار</title>

    <?php $baseUrl = rtrim(str_replace(['/assets', '/public/assets'], '', \WHCM\Core\Bootstrap::getAssetsUrl()), '/'); ?>

    <!-- PWA Meta Tags -->
    <link rel="manifest" href="<?php echo $baseUrl; ?>/manifest.json">
    <meta name="theme-color" content="#1C1C28">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="پُست‌یار">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $baseUrl; ?>/assets/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $baseUrl; ?>/assets/icons/favicon-16x16.png">
    <!-- iOS PWA Support -->
    <link rel="apple-touch-icon" href="<?php echo $baseUrl; ?>/assets/icons/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo $baseUrl; ?>/assets/icons/icon-152x152.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="پُست‌یار">
    <meta name="format-detection" content="telephone=no">
    <link rel="stylesheet" href="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/css/gentelella.css?v=3">
    <link rel="stylesheet" href="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/css/components.css?v=18">
    <link rel="stylesheet" href="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/css/admin.css?v=18">
    <link rel="stylesheet" href="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/css/components.css">
    <link rel="stylesheet" href="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/css/jalalidatepicker.min.css">
</head>
<body>

    <!-- هدر Gentelella ادمین -->
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="gt-burger sidebar-toggle" id="g-sidebar-toggle" aria-label="باز کردن منو" aria-expanded="false">
                <span class="gt-burger-box"><span class="gt-burger-layer"></span><span class="gt-burger-layer"></span><span class="gt-burger-layer"></span></span>
            </button>
            <div class="breadcrumb"><span class="current"><?php echo $is_support ? 'پنل پشتیبانی' : 'مرکز فرماندهی مدیریت'; ?></span></div>
        </div>
        <div class="topbar-right">
            <?php 
                $pending_p_count = 0;
                foreach ($payments as $pay) { if($pay['status'] === 'pending') $pending_p_count++; }
                $open_t_count = 0;
                foreach ($tickets as $tick) { if($tick['status'] === 'open') $open_t_count++; }
                $total_notifs = $pending_p_count + $open_t_count;
            ?>
            <!-- زنگوله اعلان‌های سیستمی مدیر -->
            <div style="position:relative;">
                <button type="button" onclick="var p=document.getElementById('admin-bell-popup'); p.style.display=(p.style.display==='flex'?'none':'flex');" class="tb-btn" style="background:#16161F;border:1px solid #2A2A38;border-radius:8px;" aria-label="اعلان‌های مدیر">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    <?php if ($total_notifs > 0): ?>
                        <span class="dot" style="position:absolute;top:4px;right:4px;width:9px;height:9px;background:#F87171;border-radius:50%;border:2px solid #fff;"></span>
                    <?php endif; ?>
                </button>
                <div id="admin-bell-popup" style="display:none;position:absolute;left:0;top:44px;width:290px;background:#16161F;border:1px solid #2A2A38;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.4),0 0 0 1px rgba(99,102,241,.08);z-index:9999;flex-direction:column;padding:14px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #2A2A38;padding-bottom:10px;margin-bottom:10px;">
                        <strong style="color:#F1F0F4;font-size:13px;">🔔 اعلان‌های سیستمی مدیر</strong>
                        <span style="font-size:11.5px;color:#A5B4FC;font-weight:700;"><?php echo \WHCM\Domain\TextFormat::fa_digits($total_notifs); ?> مورد</span>
                    </div>
                    <?php if ($total_notifs === 0): ?>
                        <div style="color:#8888A4;font-size:12.5px;text-align:center;padding:.5rem 0;">همه موارد بررسی شده است ✔</div>
                    <?php else: ?>
                        <?php if ($pending_p_count > 0): ?>
                            <div style="padding:8px 10px;background:#1C1C28;border:1px solid #2A2A38;border-radius:6px;margin-bottom:6px;font-size:12.5px;color:#B4B2BE;cursor:pointer;" onclick="switchSection('payments'); document.getElementById('admin-bell-popup').style.display='none';">
                                💳 <?php echo \WHCM\Domain\TextFormat::fa_digits($pending_p_count); ?> فیش واریزی در انتظار تأیید
                            </div>
                        <?php endif; ?>
                        <?php if ($open_t_count > 0): ?>
                            <div style="padding:8px 10px;background:#1C1C28;border:1px solid #2A2A38;border-radius:6px;font-size:12.5px;color:#B4B2BE;cursor:pointer;" onclick="switchSection('tickets'); document.getElementById('admin-bell-popup').style.display='none';">
                                🎫 <?php echo \WHCM\Domain\TextFormat::fa_digits($open_t_count); ?> تیکت پشتیبانی باز
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/dashboard'); ?>" class="admin-badge" style="text-decoration:none;">🏠 داشبورد کاربری</a>
            <div class="admin-badge"><?php echo $is_support ? '🎧 پشتیبان' : '👑 مدیر ارشد'; ?></div>
        </div>
    </header>

    <!-- نوار تب پایین موبایل -->
    <nav class="mobile-nav">
        <div class="mobile-nav-item active" data-target="dashboard">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
            <span>وضعیت</span>
        </div>
        <div class="mobile-nav-item" data-target="users">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span>کاربران</span>
        </div>
        <div class="mobile-nav-item" data-target="payments">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h.01M11 15h.01M3 4h18a2 2 0 012 2v12a2 2 0 01-2 2H3a2 2 0 01-2-2V6a2 2 0 012-2z"></path></svg>
            <span>فیش‌ها</span>
        </div>
        <div class="mobile-nav-item" data-target="tickets">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg>
            <span>تیکت‌ها</span>
        </div>
        <div class="mobile-nav-item" onclick="toggleMobileMoreMenu()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            <span>بیشتر</span>
        </div>
    </nav>

    <!-- دراور منوی بیشتر (موبایل) - مشابه داشبورد کاربر -->
    <div class="mobile-more-overlay" id="mobileMoreOverlay" onclick="toggleMobileMoreMenu()"></div>
    <div class="mobile-more-drawer" id="mobileMoreDrawer">
        <div class="mobile-more-handle"></div>
        <div class="mobile-more-title"><?php echo $is_support ? 'پنل پشتیبانی' : 'منوی مدیریت'; ?></div>
        <div class="mobile-more-grid">
            <?php if (!$is_support): ?>
            <div class="mobile-more-item" data-target="dashboard" onclick="switchSection('dashboard'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">📊</span>
                <span>وضعیت کلی</span>
            </div>
            <div class="mobile-more-item" data-target="users" onclick="switchSection('users'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">👥</span>
                <span>کاربران</span>
            </div>
            <div class="mobile-more-item" data-target="payments" onclick="switchSection('payments'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">💳</span>
                <span>تایید فیش</span>
            </div>
            <div class="mobile-more-item" data-target="subscriptions" onclick="switchSection('subscriptions'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🎫</span>
                <span>اشتراک‌ها</span>
            </div>
            <div class="mobile-more-item" data-target="plans" onclick="switchSection('plans'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">💎</span>
                <span>پلن‌ها</span>
            </div>
            <div class="mobile-more-item" data-target="ads" onclick="switchSection('ads'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">📣</span>
                <span>تبلیغات</span>
            </div>
            <div class="mobile-more-item" data-target="admin-gold" onclick="switchSection('admin-gold'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🪙</span>
                <span>ربات طلا</span>
            </div>
            <div class="mobile-more-item" data-target="admin-ai" onclick="switchSection('admin-ai'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🧠</span>
                <span>هوش مصنوعی</span>
            </div>
            <div class="mobile-more-item" data-target="discounts" onclick="switchSection('discounts'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🎁</span>
                <span>تخفیف‌ها</span>
            </div>
            <div class="mobile-more-item" data-target="admin-responder" onclick="switchSection('admin-responder'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🤖</span>
                <span>پاسخگوی هوشمند</span>
            </div>
            <div class="mobile-more-item" data-target="admin-woo" onclick="switchSection('admin-woo'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🛍</span>
                <span>ووکامرس</span>
            </div>
            <div class="mobile-more-item" data-target="broadcast" onclick="switchSection('broadcast'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">📢</span>
                <span>اعلان همگانی</span>
            </div>
            <div class="mobile-more-item" data-target="bank" onclick="switchSection('bank'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🏦</span>
                <span>کارت بانکی</span>
            </div>
            <div class="mobile-more-item" data-target="provider-settings" onclick="switchSection('provider-settings'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🔌</span>
                <span>درگاه پرداخت</span>
            </div>
            <div class="mobile-more-item" data-target="referral-settings" onclick="switchSection('referral-settings'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🎯</span>
                <span>زیرمجموعه</span>
            </div>
            <div class="mobile-more-item" data-target="sms-settings" onclick="switchSection('sms-settings'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">📱</span>
                <span>پیامک</span>
            </div>
            <div class="mobile-more-item" data-target="email-settings" onclick="switchSection('email-settings'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">📧</span>
                <span>ایمیل</span>
            </div>
            <?php endif; ?>
            <div class="mobile-more-item" data-target="tickets" onclick="switchSection('tickets'); toggleMobileMoreMenu();">
                <span class="mobile-more-icon">🎫</span>
                <span>تیکت‌ها</span>
            </div>
            <?php if (!$is_support): ?>
            <a href="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/dashboard'); ?>" class="mobile-more-item mobile-more-admin">
                <span class="mobile-more-icon">🏠</span>
                <span>داشبورد کاربری</span>
            </a>
            <?php endif; ?>
            <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/logout'); ?>" class="mobile-more-item mobile-more-logout" onsubmit="return confirm('آیا می‌خواهید از حساب خارج شوید؟');">
                <?php echo \WHCM\Core\Csrf::field(); ?>
                <button type="submit" style="all:unset; display:flex; align-items:center; gap:.5rem; width:100%; cursor:pointer;">
                    <span class="mobile-more-icon">🚪</span>
                    <span>خروج از حساب</span>
                </button>
            </form>
        </div>
    </div>

    <!-- کانتینر اصلی محتوا -->
    <div class="wrapper g-main">
        
        <!-- سایدبار Gentelella مدیریت -->
        <aside class="g-sidebar" id="g-sidebar">
            <div class="sidebar-brand">
                <div class="brand-icon">
                    <img src="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/images/logo-white-bg.webp" alt="نشان پُست‌یار">
                </div>
                <div class="brand-name">پُست‌یار <small>مدیریت ارشد</small></div>
            </div>
            <button type="button" class="g-rail-toggle" aria-label="جمع کردن منو" title="جمع/باز کردن منو">
                    <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"></path></svg>
                </button>
            <nav class="sidebar-nav">
                <div class="nav-group">
                    <div class="nav-label">وضعیت و کاربران</div>
                    <div class="menu-item active" data-target="dashboard"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg><span class="nav-text">وضعیت کلی و آمارگیری</span></div>
                    <div class="menu-item" data-target="users"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg><span class="nav-text">مدیریت کاربران</span></div>
                    <div class="menu-item" data-target="payments"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h.01M11 15h.01M3 4h18a2 2 0 012 2v12a2 2 0 01-2 2H3a2 2 0 01-2-2V6a2 2 0 012-2z"></path></svg><span class="nav-text">تأیید فیش‌های واریزی</span></div>
                    <div class="menu-item" data-target="subscriptions"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg><span class="nav-text">اشتراک‌های فعال</span></div>
                    <div class="menu-item" data-target="plans"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h12l4 5-10 11L2 9l4-5z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 9h20M6 4l6 16L18 4"></path></svg><span class="nav-text">پلن‌های اشتراکی</span></div>
                </div>
                <div class="nav-group">
                    <div class="nav-label">درآمد و تبلیغات</div>
                    <div class="menu-item" data-target="ads"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882v9.412a2 2 0 11-4 0v-2.39l-3.26-.943a.5.5 0 01-.24-.847l1.5-1.4"></path><circle cx="16" cy="8" r="3" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></circle></svg><span class="nav-text">مدیریت تبلیغات</span></div>
                </div>
                <div class="nav-group">
                    <div class="nav-label">تنظیمات هوشمند</div>
                    <div class="menu-item" data-target="admin-gold"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m9-5a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg><span class="nav-text">ربات طلا و سکه</span></div>
                    <div class="menu-item" data-target="admin-ai"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg><span class="nav-text">هوش مصنوعی</span></div>
                    <div class="menu-item" data-target="discounts"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"></path></svg><span class="nav-text">کدهای تخفیف</span></div>
                    <div class="menu-item" data-target="admin-responder"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8l-3.6 1a1 1 0 01-1.3-1.3l1-2.5C2.8 14.8 2 13.5 2 12c0-4.4 4-8 9-8s9 3.6 9 8z"></path></svg><span class="nav-text">پاسخگوی هوشمند</span></div>
                    <div class="menu-item" data-target="admin-woo"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg><span class="nav-text">اتصال ووکامرس</span></div>
                </div>
                <div class="nav-group">
                    <div class="nav-label">ارتباطات</div>
                    <div class="menu-item" data-target="broadcast"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882L19.591 3.7a.5.5 0 01.656.585l-2.4 10a.5.5 0 01-.736.31l-3.06-1.767M11 5.882v9.412a2 2 0 11-4 0v-2.39l-3.26-.943a.5.5 0 01-.24-.847l1.5-1.4"></path></svg><span class="nav-text">اعلان همگانی</span></div>
                    <div class="menu-item" data-target="tickets"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg><span class="nav-text">تیکت‌های پشتیبانی</span></div>
                </div>
                <div class="nav-group">
                    <div class="nav-label">مالی و سیستمی</div>
                    <div class="menu-item" data-target="bank"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h.01M11 15h.01M3 4h18a2 2 0 012 2v12a2 2 0 01-2 2H3a2 2 0 01-2-2V6a2 2 0 012-2z"></path></svg><span class="nav-text">کارت بانکی</span></div>
                    <div class="menu-item" data-target="provider-settings"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg><span class="nav-text">درگاه‌های پرداخت</span></div>
                    <div class="menu-item" data-target="referral-settings"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12m9 0a9 9 0 11-18 0 9 9 0 0118 0zm0 0a5 5 0 11-10 0 5 5 0 0110 0zm0 0a1 1 0 11-2 0 1 1 0 012 0z"></path></svg><span class="nav-text">زیرمجموعه‌گیری</span></div>
                    <div class="menu-item" data-target="sms-settings"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg><span class="nav-text">پنل‌های پیامک</span></div>
                    <div class="menu-item" data-target="email-settings"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg><span class="nav-text">ایمیل</span></div>
                </div>
                <div class="nav-group">
                    <div class="nav-label">حساب</div>
                    <a href="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/dashboard'); ?>" class="menu-item" style="color:#5ED4BE;"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg><span class="nav-text">رفتن به پیشخوان کاربری</span></a>
                    <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/logout'); ?>" class="logout-form"
      onsubmit="return confirm('آیا می‌خواهید از حساب خارج شوید؟');">
    <?php echo \WHCM\Core\Csrf::field(); ?>
    <button type="submit" class="menu-item logout-btn"><svg class="g-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg><span class="nav-text">خروج از حساب</span></button>
</form>
                </div>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="avatar"><?php echo $is_support ? '🎧' : '👑'; ?><span class="online"></span></div>
                    <div class="sidebar-user-info">
                        <div class="name">مدیریت پُست‌یار</div>
                        <div class="role"><?php echo $is_support ? 'کارشناس پشتیبانی' : 'مدیر ارشد پلتفرم'; ?></div>
                    </div>
                </div>
            </div>
        </aside>
        <div class="sidebar-backdrop" id="g-sidebar-backdrop"></div>

        <!-- محتوای اصلی -->
        <main class="page-wrapper">
            
            <!-- نمایش سریع گزارش وضعیت سیستم (اعلان بالا صفحه) -->
            <?php 
                $pending_p_count = 0;
                foreach ($payments as $pay) { if($pay['status'] === 'pending') $pending_p_count++; }
                $open_t_count = 0;
                foreach ($tickets as $tick) { if($tick['status'] === 'open') $open_t_count++; }
            ?>
            <div class="admin-announcement-bar">
                <span>📢</span>
                <span>گزارش سریع وضعیت سیستم: تعداد <?php echo \WHCM\Domain\TextFormat::fa_digits($pending_p_count); ?> فیش پرداخت در انتظار تأیید و <?php echo \WHCM\Domain\TextFormat::fa_digits($open_t_count); ?> تیکت پشتیبانی پاسخ‌نداده فعال وجود دارد.</span>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert" id="system-alert-toast" style="position:relative; display:flex; justify-content:space-between; align-items:center;">
                    <span><?php echo htmlspecialchars($message); ?></span>
                    <button type="button" onclick="document.getElementById('system-alert-toast').style.display='none'" style="background:none; border:none; color:white; font-size:1.1rem; cursor:pointer; margin-right:1rem;">✖</button>
                </div>
                <script>autoDismissAlert('system-alert-toast', 5000);</script>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- ۱. بخش وضعیت کلی سیستم -->
            <!-- ========================================== -->
            <!-- Wave R — مدیریت تبلیغات، تایید، آرشیو و آمار -->
            <div id="section-ads" class="tab-content">
                <div class="admin-announcement-bar"><span>📣</span><span>مرکز مدیریت تبلیغات: فقط آگهی‌های تأییدشده و داخل بازه زمانی فعال در پیشخوان کاربران نمایش داده می‌شوند.</span></div>
                <div class="card ad-admin-card">
                    <h3>📊 گزارش کلی تبلیغات</h3>
                    <form method="get" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh'); ?>" class="form-grid" style="margin-bottom:1rem;">
                        <input type="hidden" name="section" value="ads">
                        <?php
                            $adFromStored = \WHCM\Domain\TextFormat::normalize_ad_date($_GET['ad_from'] ?? null);
                            $adToStored = \WHCM\Domain\TextFormat::normalize_ad_date($_GET['ad_to'] ?? null);
                            $adFromJ = ''; $adToJ = '';
                            if ($adFromStored) { [$jy,$jm,$jd] = \WHCM\Domain\TextFormat::g2j((int)substr($adFromStored,0,4),(int)substr($adFromStored,5,2),(int)substr($adFromStored,8,2)); $adFromJ=\WHCM\Domain\TextFormat::fa_digits(sprintf('%04d/%02d/%02d',$jy,$jm,$jd)); }
                            if ($adToStored) { [$jy,$jm,$jd] = \WHCM\Domain\TextFormat::g2j((int)substr($adToStored,0,4),(int)substr($adToStored,5,2),(int)substr($adToStored,8,2)); $adToJ=\WHCM\Domain\TextFormat::fa_digits(sprintf('%04d/%02d/%02d',$jy,$jm,$jd)); }
                        ?>
                        <label>از تاریخ<input type="text" data-jdp data-ad-date="from" id="ad_from_jalali" inputmode="numeric" autocomplete="off" readonly value="<?php echo htmlspecialchars($adFromJ); ?>" placeholder="۱۴۰۵/۰۱/۰۱"><input type="hidden" name="ad_from" id="ad_from" value="<?php echo htmlspecialchars($adFromStored ?? ''); ?>"></label>
                        <label>تا تاریخ<input type="text" data-jdp data-ad-date="to" id="ad_to_jalali" inputmode="numeric" autocomplete="off" readonly value="<?php echo htmlspecialchars($adToJ); ?>" placeholder="۱۴۰۵/۰۱/۰۱"><input type="hidden" name="ad_to" id="ad_to" value="<?php echo htmlspecialchars($adToStored ?? ''); ?>"></label>
                        <div style="display:flex;gap:.5rem;align-items:end;"><button class="primary-btn" type="submit">فیلتر گزارش</button><a class="primary-btn" href="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/ads/export'); ?><?php echo (!empty($_GET['ad_from'])||!empty($_GET['ad_to'])) ? '?' . http_build_query(['from'=>$_GET['ad_from']??'', 'to'=>$_GET['ad_to']??'']) : ''; ?>">خروجی CSV</a></div>
                    </form>
                    <?php $adTotImp=$adTotUniImp=$adTotClicks=$adTotUniClicks=0; foreach (($ad_stats ?? []) as $as){$adTotImp+=(int)$as['impressions'];$adTotUniImp+=(int)$as['unique_impressions'];$adTotClicks+=(int)$as['clicks'];$adTotUniClicks+=(int)$as['unique_clicks'];} ?>
                    <div class="grid-stats">
                        <div class="card-stat"><div class="card-stat-icon">👁</div><div class="card-stat-info"><span class="title">نمایش</span><span class="value"><?php echo $adTotImp; ?></span></div></div>
                        <div class="card-stat"><div class="card-stat-icon">👥</div><div class="card-stat-info"><span class="title">نمایش یکتا</span><span class="value"><?php echo $adTotUniImp; ?></span></div></div>
                        <div class="card-stat"><div class="card-stat-icon">🖱</div><div class="card-stat-info"><span class="title">کلیک</span><span class="value"><?php echo $adTotClicks; ?></span></div></div>
                        <div class="card-stat"><div class="card-stat-icon">🎯</div><div class="card-stat-info"><span class="title">کلیک یکتا</span><span class="value"><?php echo $adTotUniClicks; ?></span></div></div>
                    </div>
                </div>
                <?php
$ad_admin_date = static function($value): string {
    $value=trim((string)$value);
    if($value==='') return '—';
    $value=strtr($value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2})(?::\d{2})?)?$/',$value,$m)) return htmlspecialchars($value);
    [$jy,$jm,$jd]=\WHCM\Domain\TextFormat::g2j((int)$m[1],(int)$m[2],(int)$m[3]);
    $date=sprintf('%04d/%02d/%02d',$jy,$jm,$jd);
    $time=isset($m[4])?'ساعت '.str_pad($m[4],2,'0',STR_PAD_LEFT).':'.str_pad($m[5],2,'0',STR_PAD_LEFT):'';
    return \WHCM\Domain\TextFormat::fa_digits($date).($time?' · '.\WHCM\Domain\TextFormat::fa_digits($time):'');
};
$ad_admin_status = static function($status): string {
    return \WHCM\Domain\AdSales::statusLabel((string)$status);
};
?>
<div class="card ad-admin-card ad-manual-create" style="margin-top:1rem;">
                    <h3>➕ ثبت مستقیم تبلیغ توسط مدیر</h3>
                    <p>این بخش برای ثبت و فعال‌سازی مستقیم تبلیغ توسط مدیر است. تاریخ‌ها را به شمسی وارد کنید.</p>
                    <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/ads/manual-create'); ?>" enctype="multipart/form-data" class="ad-manual-form-modern">
                        <?php echo $csrf_field; ?>
                        <input type="hidden" name="placements[]" value="global_top">
                        <div class="ad-form-field">
                            <label>عنوان تبلیغ</label>
                            <input required name="title" maxlength="180" placeholder="مثلاً: فروش ویژه عید">
                        </div>
                        <div class="ad-form-field">
                            <label>نشانی مقصد (URL)</label>
                            <input required name="destination_url" maxlength="2048" placeholder="https://example.com" dir="ltr">
                        </div>
                        <div class="ad-form-field">
                            <label>تاریخ و ساعت شروع</label>
                            <input required type="text" id="manual_ad_starts_at" name="starts_at" data-jdp data-ad-admin-date="start" inputmode="numeric" autocomplete="off" readonly placeholder="انتخاب تاریخ و ساعت شروع">
                        </div>
                        <div class="ad-form-field">
                            <label>تاریخ و ساعت پایان</label>
                            <input required type="text" id="manual_ad_ends_at" name="ends_at" data-jdp data-ad-admin-date="end" inputmode="numeric" autocomplete="off" readonly placeholder="انتخاب تاریخ و ساعت پایان">
                        </div>
                        <div class="ad-form-field ad-form-full">
                            <label>تصویر تبلیغ (JPG, PNG, WebP, GIF)</label>
                            <input required type="file" name="manual_ad_image" accept="image/jpeg,image/png,image/webp,image/gif">
                        </div>
                        <div class="ad-form-full" style="padding-top:.25rem;">
                            <button class="primary-btn" type="submit" style="width:100%;justify-content:center;padding:.75rem;font-size:14px;font-weight:700;">✨ ثبت و فعال‌سازی تبلیغ</button>
                        </div>
                    </form>
                </div>

                <div class="card ad-admin-card" style="margin-top:1rem;">
                    <h3>💳 درخواست‌های تبلیغات و صف پرداخت</h3>
                    <p style="color:#B4B2BE;font-size:.82rem;line-height:1.7;">مدیر ابتدا مبلغ نهایی را تعیین می‌کند. فقط پس از تایید رسید/تراکنش، کمپین به وضعیت قابل‌نمایش منتقل می‌شود.</p>
                    <table class="data-table"><thead><tr><th>درخواست</th><th>پیش‌نمایش</th><th>کاربر</th><th>بازه</th><th>وضعیت</th><th>مبلغ</th><th>قیمت‌گذاری</th><th>تایید پرداخت</th></tr></thead><tbody>
                    <?php foreach (($ad_orders ?? []) as $order): ?>
                    <tr>
                        <td>#<?php echo (int)$order['id']; ?></td>
                        <td><?php $orderPreviewItems=$ad_order_creatives[(int)$order['id']]??[]; $firstPreview=$orderPreviewItems[0]??null; ?><?php if($firstPreview && !empty($firstPreview['image_url'])): ?><button type="button" class="ad-admin-preview-btn" onclick="openAdOrderPreview(<?php echo (int)$order['id']; ?>)"><img src="<?php echo htmlspecialchars($firstPreview['image_url']); ?>" alt="پیش‌نمایش آگهی" loading="lazy"><span><?php echo \WHCM\Domain\TextFormat::fa_digits(count($orderPreviewItems)); ?> اسلاید</span></button><?php else: ?><button type="button" class="ad-admin-preview-btn ad-admin-preview-empty" onclick="openAdOrderPreview(<?php echo (int)$order['id']; ?>)"><span>مشاهده پیش‌نمایش</span></button><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($order['owner_name'] ?? ''); ?><br><small><?php echo htmlspecialchars($order['owner_email'] ?? ''); ?></small></td>
                        <td><?php echo htmlspecialchars($ad_admin_date($order['requested_starts_at']??'')); ?><br><small>تا</small><br><?php echo htmlspecialchars($ad_admin_date($order['requested_ends_at']??'')); ?></td>
                        <td><?php echo htmlspecialchars(\WHCM\Domain\AdSales::statusLabel((string)$order['status']).' / '.\WHCM\Domain\AdSales::paymentStatusLabel((string)$order['payment_status'])); ?></td>
                        <td><?php echo $order['quoted_amount']!==null ? htmlspecialchars(number_format((int)round(((float)$order['quoted_amount'])/10))).' تومان' : '—'; ?></td>
                        <td>
                            <?php if (($order['status']??'') === 'submitted'): ?>
                            <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/ads/quote'); ?>" style="display:grid;gap:.35rem;min-width:210px;">
                                <?php echo $csrf_field; ?><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><input required min="1" step="1" type="number" name="quoted_amount" placeholder="مبلغ نهایی (تومان)"><input name="admin_notes" maxlength="1000" placeholder="یادداشت مدیر"><button class="primary-btn" type="submit">تایید مبلغ و ارسال به کاربر</button>
                            </form>
                            <?php else: ?><span style="color:#B4B2BE;">قیمت‌گذاری انجام شده</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (($order['status']??'') === 'payment_submitted' && ($order['payment_status']??'') === 'pending_verification'): ?>
                            <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/ads/payment-approve'); ?>" onsubmit="return confirm('پس از تایید، کمپین واجد شرایط نمایش می‌شود. آیا رسید را بررسی کرده‌اید؟');">
                                <?php echo $csrf_field; ?><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><button class="primary-btn" type="submit">تایید پرداخت و فعال‌سازی</button>
                            </form>
                            <?php else: ?><span style="color:#B4B2BE;">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ad_orders)): ?><tr><td colspan="8">درخواستی وجود ندارد.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
                <div id="adOrderPreviewModal" class="ad-admin-preview-modal" aria-hidden="true">
                    <div class="ad-admin-preview-dialog">
                        <button type="button" class="ad-admin-preview-close" onclick="closeAdOrderPreview()" aria-label="بستن">×</button>
                        <div class="ad-admin-preview-title"><span>پیش‌نمایش درخواست تبلیغ</span><strong id="adOrderPreviewHeading">پیش‌نمایش</strong></div>
                        <div id="adOrderPreviewBody" class="ad-admin-preview-body"></div>
                    </div>
                </div>
                <script>
                window.adOrderPreviewData=<?php echo json_encode($ad_order_creatives??[],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE); ?>;
                function closeAdOrderPreview(){var m=document.getElementById('adOrderPreviewModal');if(m){m.classList.remove('is-open');m.setAttribute('aria-hidden','true');}}
                function openAdOrderPreview(id){var m=document.getElementById('adOrderPreviewModal'),body=document.getElementById('adOrderPreviewBody'),heading=document.getElementById('adOrderPreviewHeading'),items=(window.adOrderPreviewData||{})[String(id)]||(window.adOrderPreviewData||{})[id]||[];if(!m||!body)return;heading.textContent='درخواست '+id;body.innerHTML='';if(!items.length){body.innerHTML='<div class="ad-admin-preview-no-image">برای این درخواست تصویر قابل پیش‌نمایش ثبت نشده است.</div>';}else{items.forEach(function(item,index){var card=document.createElement('article');card.className='ad-admin-preview-card';var image=item.image_url?'<img src="'+String(item.image_url).replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'" alt="پیش‌نمایش اسلاید '+(index+1)+'" loading="lazy">':'<div class="ad-admin-preview-placeholder">بدون تصویر</div>';card.innerHTML='<div class="ad-admin-preview-media">'+image+'</div><div class="ad-admin-preview-copy"><span>اسلاید '+(index+1)+'</span><h4>'+String(item.title||'بدون عنوان').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];})+'</h4><p>'+String(item.body_text||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];})+'</p><small>'+String(item.destination_url||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'</small></div>';body.appendChild(card);});}m.classList.add('is-open');m.setAttribute('aria-hidden','false');}
                document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAdOrderPreview();});
document.addEventListener('click',function(e){var m=document.getElementById('adOrderPreviewModal');if(m&&e.target===m)closeAdOrderPreview();});
                </script>
                <script>
(function(){
    function initManualAdCalendar(){
        if(!window.jalaliDatepicker) return;
        try{
            document.querySelectorAll('input[data-ad-admin-date]').forEach(function(input){
                input.addEventListener('click',function(){ try{ jalaliDatepicker.show(input); }catch(e){} });
                input.addEventListener('focus',function(){ try{ jalaliDatepicker.show(input); }catch(e){} });
            });
        }catch(e){ console.error('خطا در راه‌اندازی تقویم تبلیغ',e); }
    }
    document.addEventListener('DOMContentLoaded',initManualAdCalendar);
})();
</script>
<div class="card ad-admin-card" style="margin-top:1rem;">
                    <h3>🗂 کمپین‌ها و آرشیو</h3>
                    <table class="data-table"><thead><tr><th>ID</th><th>آگهی</th><th>صاحب</th><th>وضعیت</th><th>نمایش</th><th>کلیک</th><th>بازه</th><th>عملیات</th></tr></thead><tbody>
                    <?php foreach (($ad_campaigns ?? []) as $ad): ?>
                        <tr>
                            <td><?php echo (int)$ad['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($ad['title']); ?></strong><br><small><?php echo htmlspecialchars($ad['destination_url']); ?></small></td>
                            <td><?php echo htmlspecialchars($ad['owner_name']); ?><br><small><?php echo htmlspecialchars($ad['owner_email']); ?></small></td>
                            <td><?php echo htmlspecialchars($ad_admin_status($ad['status'])); ?></td>
                            <?php $rowStat=null; foreach (($ad_stats ?? []) as $st){if((int)$st['id']===(int)$ad['id']){$rowStat=$st;break;}} ?>
                            <td><?php echo (int)($rowStat['impressions']??0); ?> / <?php echo (int)($rowStat['unique_impressions']??0); ?></td>
                            <td><?php echo (int)($rowStat['clicks']??0); ?> / <?php echo (int)($rowStat['unique_clicks']??0); ?></td>
                            <td><div class="ad-date-range"><span><b>شروع:</b> <?php echo htmlspecialchars($ad_admin_date($ad['starts_at'])); ?></span><span><b>پایان:</b> <?php echo htmlspecialchars($ad_admin_date($ad['ends_at'])); ?></span></div></td>
                            <td>
                                <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/ads/status'); ?>" class="ad-status-form">
                                    <?php echo $csrf_field; ?><input type="hidden" name="ad_id" value="<?php echo (int)$ad['id']; ?>">
                                    <select name="status"><option value="approved" <?php echo $ad['status']==='approved'?'selected':''; ?>>تأیید</option><option value="rejected" <?php echo $ad['status']==='rejected'?'selected':''; ?>>رد</option><option value="paused" <?php echo $ad['status']==='paused'?'selected':''; ?>>توقف</option><option value="archived" <?php echo $ad['status']==='archived'?'selected':''; ?>>آرشیو</option></select>
                                    <button type="submit" class="primary-btn">تأیید تغییر</button>
                                </form>
                                <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/ads/delete'); ?>" class="ad-delete-form" onsubmit="return confirm('این تبلیغ و اطلاعات وابسته به آن حذف شود؟');">
                                    <?php echo $csrf_field; ?><input type="hidden" name="ad_id" value="<?php echo (int)$ad['id']; ?>">
                                    <button type="submit" class="danger-btn">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ad_campaigns)): ?><tr><td colspan="8">هیچ کمپین تبلیغاتی ثبت نشده است.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </div>

            <div id="section-dashboard" class="tab-content active">
                <div class="grid-stats">
                    <a href="javascript:switchSection('users')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(99,102,241,0.2) 0%, rgba(99,102,241,0.05) 100%); color:#A5B4FC;">👥</div>
                            <div class="card-stat-info">
                                <span class="title">کل کاربران ثبت‌نام شده</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_digits($total_users); ?> نفر</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('users')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(52,211,153,0.2) 0%, rgba(52,211,153,0.05) 100%); color:#34D399;">✅</div>
                            <div class="card-stat-info">
                                <span class="title">کاربران فعال</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_digits($active_users_count); ?> نفر</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('payments')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(251,191,36,0.2) 0%, rgba(251,191,36,0.05) 100%); color:#FDE68A;">💳</div>
                            <div class="card-stat-info">
                                <span class="title">پرداخت‌های در انتظار تایید</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_digits($pending_p_count); ?> فیش</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('tickets')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(248,113,113,0.2) 0%, rgba(248,113,113,0.05) 100%); color:#F87171;">🎟</div>
                            <div class="card-stat-info">
                                <span class="title">تیکت‌های باز منتظر پاسخ</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_digits($open_t_count); ?> تیکت</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('plans')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(174,62,201,0.2) 0%, rgba(174,62,201,0.05) 100%); color:#C4B5FD;">💎</div>
                            <div class="card-stat-info">
                                <span class="title">پلن‌های اشتراک</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_digits(count($plans)); ?> پلن</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('subscriptions')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(159,180,206,0.2) 0%, rgba(159,180,206,0.05) 100%); color:#AEC4DC;">🎯</div>
                            <div class="card-stat-info">
                                <span class="title">اشتراک‌های فعال</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_digits($active_subs_count); ?> اشتراک</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('users')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(159,180,206,0.2) 0%, rgba(159,180,206,0.05) 100%); color:#AEC4DC;">📱</div>
                            <div class="card-stat-info">
                                <span class="title">کل کانال‌های ثبت شده</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_digits($total_channels); ?> کانال</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('payments')" style="text-decoration:none;">
                        <div class="card-stat" style="cursor:pointer;">
                            <div class="card-stat-icon" style="background:linear-gradient(135deg, rgba(251,191,36,0.2) 0%, rgba(251,191,36,0.05) 100%); color:#FDE68A;">💰</div>
                            <div class="card-stat-info">
                                <span class="title">کل درآمد تایید شده</span>
                                <span class="value"><?php echo \WHCM\Domain\TextFormat::fa_num($total_revenue); ?> تومان</span>
                            </div>
                        </div>
                    </a>
                    <a href="javascript:switchSection('ads')" class="admin-ad-stat-link">
                        <div class="card-stat admin-ad-stat" style="cursor:pointer;">
                            <div class="card-stat-icon">📣</div>
                            <div class="card-stat-info">
                                <span class="title">تبلیغات</span>
                                <span class="value">در انتظار بررسی: <?php echo \WHCM\Domain\TextFormat::fa_digits($ad_submitted_count ?? 0); ?> · تأیید پرداخت: <?php echo \WHCM\Domain\TextFormat::fa_digits($ad_payment_pending_count ?? 0); ?></span>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ۲. بخش مدیریت کاربران -->
            <!-- ========================================== -->
            <div id="section-users" class="tab-content">
                <div class="card">
                    <h2>👥 لیست کامل کاربران فعال سیستم</h2>
                    <?php if (empty($users)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 2rem 0;">کاربری یافت نشد.</p>
                    <?php else: ?>
                        <div style="margin-bottom:0.75rem; display:flex; gap:0.5rem;"><input type="text" id="admin-user-search" placeholder="جستجوی نام یا ایمیل یا کسب‌وکار..." oninput="filterAdminUsers(this.value)" style="flex:1; padding:0.6rem 0.85rem; border-radius:10px; border:1px solid #2A2A38; background:#1C1C28; color:white;"></div>
                        <div class="table-responsive" style="max-height:520px; overflow:auto; border:1px solid #1C1C28; border-radius:12px;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>نام کاربر</th>
                                        <th>مشخصات کسب و کار</th>
                                        <th>اشتراک فعلی و اعتبار</th>
                                        <th>تاریخ عضویت</th>
                                        <th>کانال‌ها</th>
                                        <th>وضعیت حساب</th>
                                        <th>اقدامات مدیریتی</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td data-label="نام کاربر">
                                                <strong><?php echo htmlspecialchars($u['name']); ?></strong><br>
                                                <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($u['email']); ?></span>
                                            </td>
                                            <td data-label="مشخصات کسب و کار">
                                                <?php if (!empty($u['business_name'])): ?>
                                                    <strong><?php echo htmlspecialchars($u['business_name']); ?></strong><br>
                                                    <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($u['business_type']); ?></span>
                                                <?php else: ?>
                                                    <span style="font-size:0.8rem; color:var(--text-muted);">ثبت نشده</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="اشتراک فعلی و اعتبار">
                                                <?php if (!empty($u['plan_title'])): ?>
                                                    <span class="badge badge-success" style="font-size:0.75rem; margin-bottom:0.25rem; display:inline-block;">💎 <?php echo htmlspecialchars($u['plan_title']); ?></span><br>
                                                    <span style="font-size:0.7rem; color: #A5B4FC;">اعتبار تا: <?php echo \WHCM\Domain\TextFormat::mysql_to_jalali($u['end_date'], false); ?></span>
                                                <?php else: ?>
                                                    <span class="badge" style="background:rgba(15,15,20,0.06); color:#B4B2BE; font-size:0.75rem;">رایگان / بدون اشتراک</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="تاریخ عضویت">
                                                <span style="font-size:0.8rem; color:#B4B2BE;"><?php echo \WHCM\Domain\TextFormat::mysql_to_jalali($u['created_at']); ?></span>
                                            </td>
                                            <td data-label="کانال‌ها"><strong><?php echo \WHCM\Domain\TextFormat::fa_digits($u['channel_count']); ?> کانال</strong></td>
                                            <td data-label="وضعیت حساب">
                                                <span style="color: <?php echo $u['status'] === 'active' ? '#34D399' : '#F87171'; ?>; font-weight: bold;">
                                                    <?php echo $u['status'] === 'active' ? 'فعال' : 'مسدود'; ?>
                                                </span>
                                            </td>
                                            <td data-label="اقدامات مدیریتی">
                                                <div style="display:flex; gap:0.25rem; flex-wrap:wrap;">
                                                    <button type="button" class="btn btn-sm" style="padding:0.35rem 0.6rem; background:linear-gradient(135deg, #A5B4FC 0%, #A5B4FC 100%); color:white; border:none; font-weight:bold;" onclick='openUserProfileModal(<?php echo json_encode($u, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE); ?>)'>👁 پروفایل ۳۶۰ درجه</button>
                                                    <button type="button" class="btn btn-success btn-sm" style="padding:0.35rem 0.6rem; background:#34D399; color:white; border:none;" onclick="openGiftModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['name'])); ?>')">🎁 هدیه اشتراک</button>
                                                    <?php if ($u['status'] === 'active'): ?>
                                                        <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/suspend-user'); ?>" style="display:inline">
    <?php echo \WHCM\Core\Csrf::field(); ?>
    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
    <button type="submit" class="btn btn-danger btn-sm" style="background:#FDE68A; padding:0.35rem 0.6rem;">تعلیق 🚫</button>
</form>
                                                    <?php else: ?>
                                                        <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/activate-user'); ?>" style="display:inline">
    <?php echo \WHCM\Core\Csrf::field(); ?>
    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
    <button type="submit" class="btn btn-success btn-sm" style="padding:0.35rem 0.6rem;">فعال ✔</button>
</form>
                                                    <?php endif; ?>
                                                    <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/delete-user'); ?>" style="display:inline" onsubmit="return confirm('آیا از حذف کامل این مستأجر اطمینان دارید؟ تمامی داده‌های او پاک خواهد شد.');">
    <?php echo \WHCM\Core\Csrf::field(); ?>
    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
    <button type="submit" class="btn btn-danger btn-sm" style="padding:0.35rem 0.6rem;">حذف 🗑</button>
</form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- بخش عملیات پیشرفته دستی کاربران -->
                <div class="grid-content" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem;">
                    
                    <!-- کارت افزودن دستی کاربر -->
                    <div class="card">
                        <h2>👤 افزودن کاربر جدید به صورت دستی</h2>
                        <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/add-user-manual'); ?>" method="POST">
                            <?php echo $csrf_field; ?>
                            <div class="form-group">
                                <label for="man-name">نام و نام خانوادگی:</label>
                                <input type="text" name="name" id="man-name" required placeholder="مثال: رضا محمدی">
                            </div>
                            <div class="form-group">
                                <label for="man-email">نشانی ایمیل:</label>
                                <input type="email" name="email" id="man-email" required placeholder="name@example.com">
                            </div>
                            <div class="form-group">
                                <label for="man-phone">شماره موبایل:</label>
                                <input type="tel" name="phone" id="man-phone" required inputmode="tel" pattern="09[0-9]{9}" placeholder="09123456789">
                            </div>
                            <div class="form-group">
                                <label for="man-pass">رمز عبور اختصاصی:</label>
                                <input type="password" name="password" id="man-pass" required placeholder="حداقل ۸ کاراکتر">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="man-biz">نام کسب و کار:</label>
                                    <input type="text" name="business_name" id="man-biz" placeholder="مثال: گالری آسوین">
                                </div>
                                <div class="form-group">
                                    <label for="man-role">نقش کاربر:</label>
                                    <select name="role" id="man-role" style="border-radius:12px;">
                                        <option value="user">کاربر عادی (مستأجر)</option>
                                        <option value="support_agent">پشتیبان (فقط تیکت‌ها)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="man-biz-type">نوع کسب و کار:</label>
                                <input type="text" name="business_type" id="man-biz-type" placeholder="مثال: طلا و جواهرات">
                            </div>
                            <button type="submit" class="btn" style="width:100%;">ثبت و ایجاد دستی کاربر 👤</button>
                        </form>
                    </div>

                    <!-- کارت اعطای دستی اشتراک -->
                    <div class="card">
                        <h2>🎫 اعطای مستقیم و دستی اشتراک به کاربر</h2>
                        <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/grant-subscription-manual'); ?>" method="POST">
                            <?php echo $csrf_field; ?>
                            <div class="form-group">
                                <label for="man-user">انتخاب مستأجر (کاربر):</label>
                                <select name="user_id" id="man-user" required style="border-radius:12px;">
                                    <option value="">-- یک کاربر را انتخاب کنید --</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['email']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="man-plan">انتخاب پلن اشتراک هدف:</label>
                                <select name="plan_id" id="man-plan" required style="border-radius:12px;">
                                    <option value="">-- پلن مورد نظر را انتخاب کنید --</option>
                                    <?php foreach ($plans as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?> (قیمت: <?php echo \WHCM\Domain\TextFormat::fa_num($p['price']); ?> تومان)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success" style="width:100%; margin-top:2.5rem; background: linear-gradient(135deg, #34D399 0%, #6EE7B7 100%); border:none;">فعال‌سازی آنی اشتراک برای کاربر 🎫</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ۳. بخش تایید فیش‌های واریزی -->
            <!-- ========================================== -->
            <div id="section-payments" class="tab-content">
                <div class="card">
                    <h2>درخواست‌های فعال تایید واریز کارت به کارت (بلو بانک) 💳</h2>
                    <?php if (empty($payments)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 2rem 0;">هیچ فیش بانکی منتظر تاییدی یافت نشد.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>کاربر فرستنده</th>
                                        <th>پلن درخواستی</th>
                                        <th>مبلغ واریزی</th>
                                        <th>کد رهگیری تراکنش</th>
                                        <th>تصویر رسید</th>
                                        <th>وضعیت مالی</th>
                                        <th>تأیید</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td data-label="کاربر فرستنده">
                                                <strong><?php echo htmlspecialchars($p['user_name']); ?></strong><br>
                                                <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($p['user_email']); ?></span>
                                            </td>
                                            <td data-label="پلن درخواستی"><strong><?php echo htmlspecialchars($p['plan_title']); ?></strong></td>
                                            <td data-label="مبلغ واریزی"><strong style="color:#A5B4FC;"><?php echo \WHCM\Domain\TextFormat::fa_num($p['amount']); ?> تومان</strong></td>
                                            <td data-label="کد رهگیری تراکنش"><code><?php echo \WHCM\Domain\TextFormat::fa_digits($p['reference_num']); ?></code></td>
                                            <td data-label="تصویر رسید">
                                                <?php if (!empty($p['receipt_photo'])): ?>
                                                    <a href="<?php echo htmlspecialchars($p['receipt_photo']); ?>" target="_blank" class="btn btn-sm" style="background:#C4B5FD; padding:0.35rem 0.6rem; font-size:0.75rem;">🔎 مشاهده فیش</a>
                                                <?php else: ?>
                                                    <span style="font-size:0.8rem; color:var(--text-muted);">فاقد تصویر</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="وضعیت مالی">
                                                <span class="badge badge-<?php echo $p['status']; ?>">
                                                    <?php echo $p['status'] === 'pending' ? 'در انتظار تایید ⏳' : 'تایید شده ✔'; ?>
                                                </span>
                                            </td>
                                            <td data-label="تأیید">
                                                <?php if ($p['status'] === 'pending'): ?>
                                                    <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/approve-payment'); ?>" style="display:inline">
    <?php echo \WHCM\Core\Csrf::field(); ?>
    <input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>">
    <button type="submit" class="btn btn-success btn-sm">تایید و فعال‌سازی</button>
</form>
                                                <?php else: ?>
                                                    <span style="font-size: 0.85rem; color: var(--text-muted);">پردازش شده</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ۴. بخش لیست کامل اشتراک‌های فعال کاربران -->
            <!-- ========================================== -->
            <div id="section-subscriptions" class="tab-content">
                <div class="card">
                    <h2>🎫 لیست اشتراک‌های تهیه شده توسط کاربران</h2>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>کاربر فرستنده</th>
                                    <th>کسب و کار</th>
                                    <th>پلن اشتراک</th>
                                    <th>شروع اشتراک</th>
                                    <th>اتمام اشتراک</th>
                                    <th>وضعیت نهایی</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                    // دریافت مستقیم اشتراک‌های فعال از دیتابیس
                                    $stmt = \WHCM\Core\Bootstrap::getDB()->query("
                                        SELECT s.*, u.name as user_name, u.email as user_email, u.business_name, p.title as plan_title 
                                        FROM subscriptions s 
                                        JOIN users u ON s.user_id = u.id 
                                        JOIN plans p ON s.plan_id = p.id 
                                        ORDER BY s.id DESC
                                    ");
                                    $subs = $stmt->fetchAll();
                                    if (empty($subs)):
                                ?>
                                    <tr><td colspan="6" style="text-align:center; color:var(--text-muted);">هیچ اشتراک فعالی ثبت نشده است.</td></tr>
                                <?php else: foreach ($subs as $s): ?>
                                    <tr>
                                        <td data-label="کاربر فرستنده">
                                            <strong><?php echo htmlspecialchars($s['user_name']); ?></strong><br>
                                            <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($s['user_email']); ?></span>
                                        </td>
                                        <td data-label="کسب و کار"><strong><?php echo htmlspecialchars($s['business_name'] ?: 'ثبت نشده'); ?></strong></td>
                                        <td data-label="پلن اشتراک"><span class="badge badge-approved"><?php echo htmlspecialchars($s['plan_title']); ?></span></td>
                                        <td data-label="شروع اشتراک"><span style="font-size:0.8rem;"><?php echo \WHCM\Domain\TextFormat::mysql_to_jalali($s['start_date'], false); ?></span></td>
                                        <td data-label="اتمام اشتراک"><span style="font-size:0.8rem; color:#F87171;"><?php echo \WHCM\Domain\TextFormat::mysql_to_jalali($s['end_date'], false); ?></span></td>
                                        <td data-label="وضعیت نهایی">
                                            <span style="color: <?php echo $s['status'] === 'active' ? '#34D399' : '#F87171'; ?>; font-weight:bold;">
                                                <?php echo $s['status'] === 'active' ? 'فعال' : 'منقضی'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ۵. بخش مدیریت پلن‌های اشتراکی مستقل -->
            <!-- ========================================== -->
            <div id="section-plans" class="tab-content">
                <div class="grid-content" style="grid-template-columns: 1fr;">
                    
                    <!-- فرم ایجاد/ویرایش پلن -->
                    <div class="card" style="border-color: <?php echo $edit_plan ? 'var(--warning)' : 'var(--border)'; ?>;">
                        <?php if ($edit_plan): ?>
                            <h2>⚙ ویرایش پلن اشتراک: «<?php echo htmlspecialchars($edit_plan['title']); ?>»</h2>
                            <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/edit-plan'); ?>" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="plan_id" value="<?php echo $edit_plan['id']; ?>">
                        <?php else: ?>
                            <h2>💎 ساخت پلن اشتراک جدید</h2>
                            <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/create-plan'); ?>" method="POST" enctype="multipart/form-data">
                        <?php endif; ?>

                            <?php echo $csrf_field; ?>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="title">نام پلن اشتراکی:</label>
                                    <input type="text" name="title" id="title" value="<?php echo $edit_plan ? htmlspecialchars($edit_plan['title']) : ''; ?>" required placeholder="مثلا: پلن طلایی ویژه">
                                </div>
                                <div class="form-group">
                                    <label for="price">قیمت پلن (به تومان):</label>
                                    <input type="number" name="price" id="price" value="<?php echo $edit_plan ? (int)$edit_plan['price'] : ''; ?>" required placeholder="قیمت دوره">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="duration_days">مدت اعتبار به روز:</label>
                                    <input type="number" name="duration_days" id="duration_days" value="<?php echo $edit_plan ? (int)$edit_plan['duration_days'] : '30'; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="max_channels">حداکثر سهمیه کانال:</label>
                                    <input type="number" name="max_channels" id="max_channels" value="<?php echo $edit_plan ? (int)$edit_plan['max_channels'] : '3'; ?>" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="max_posts">حداکثر پست ماهانه (۰ = نامحدود):</label>
                                    <input type="number" name="max_posts" id="max_posts" value="<?php echo $edit_plan ? (int)$edit_plan['max_posts'] : '0'; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="early_renewal_discount">درصد تخفیف تمدید پیش از موعد (درصد):</label>
                                    <input type="number" name="early_renewal_discount" id="early_renewal_discount" value="<?php echo $edit_plan ? (int)$edit_plan['early_renewal_discount'] : '0'; ?>" min="0" max="100" required placeholder="مثلاً: ۱۰">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="general_discount">درصد تخفیف عمومی/مناسبتی کل پلن (درصد):</label>
                                    <input type="number" name="general_discount" id="general_discount" value="<?php echo $edit_plan ? (int)$edit_plan['general_discount'] : '0'; ?>" min="0" max="100" required placeholder="مثلاً: ۱۵">
                                </div>
                                <div class="form-group">
                                    <label for="discount_badge_text">متن برچسب تخفیف روی تصویر (مثال: آفر ویژه عید):</label>
                                    <input type="text" name="discount_badge_text" id="discount_badge_text" value="<?php echo $edit_plan ? htmlspecialchars($edit_plan['discount_badge_text'] ?? '') : ''; ?>" placeholder="خالی بگذارید تا نشان داده نشود">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="plan-desc">توضیحات اختصاصی پلن (مزیت‌های این اشتراک):</label>
                                <textarea name="description" id="plan-desc" rows="4" placeholder="توضیحات را بنویسید..."><?php echo $edit_plan ? htmlspecialchars($edit_plan['description'] ?? '') : ''; ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="plan-img">بارگذاری و آپلود تصویر پلن (فرمت وب‌پی خودکار):</label>
                                    <input type="file" name="plan_image" id="plan-img" accept="image/*" style="padding:0.5rem 1rem;">
                                </div>
                                <div class="form-group">
                                    <label for="payment_url">لینک پرداخت مستقیم اختصاصی (بلو لینک):</label>
                                    <input type="url" name="payment_url" id="payment_url" value="<?php echo $edit_plan ? htmlspecialchars($edit_plan['payment_url'] ?? '') : ''; ?>" placeholder="https://blubank.com/pay/...">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label style="margin-bottom: 0.75rem;">دسترسی‌های مجاز پلن:</label>
                                <?php 
                                    $feats = [];
                                    if ($edit_plan) {
                                        $feats = json_decode($edit_plan['features'] ?? '{}', true);
                                    }
                                    $feat_gold = !empty($feats['gold_ticker']);
                                    $feat_reply = !empty($feats['auto_responder']);
                                    $feat_woo = !empty($feats['woocommerce']);
                                    $feat_ai = !empty($feats['ai_caption']);
                                ?>
                                <div style="display:flex; gap:1.5rem; flex-wrap:wrap;">
                                    <div class="form-group-checkbox" style="margin:0;">
                                        <input type="checkbox" name="feat_gold" id="feat_gold" value="1" <?php echo $feat_gold ? 'checked' : ''; ?>>
                                        <label for="feat_gold" style="margin: 0; cursor: pointer;">📈 ربات قیمت خودکار طلا</label>
                                    </div>
                                    <div class="form-group-checkbox" style="margin:0;">
                                        <input type="checkbox" name="feat_reply" id="feat_reply" value="1" <?php echo $feat_reply ? 'checked' : ''; ?>>
                                        <label for="feat_reply" style="margin: 0; cursor: pointer;">🤖 پاسخگوی کلمات کلیدی</label>
                                    </div>
                                    <div class="form-group-checkbox" style="margin:0;">
                                        <input type="checkbox" name="feat_woo" id="feat_woo" value="1" <?php echo $feat_woo ? 'checked' : ''; ?>>
                                        <label for="feat_woo" style="margin: 0; cursor: pointer;">🛍 اتصال ووکامرس</label>
                                    </div>
                                    <div class="form-group-checkbox" style="margin:0;">
                                        <input type="checkbox" name="feat_ai" id="feat_ai" value="1" <?php echo $feat_ai ? 'checked' : ''; ?>>
                                        <label for="feat_ai" style="margin: 0; cursor: pointer;">🧠 کپشن‌ساز هوش مصنوعی</label>
                                    </div>
                                    <div class="form-group-checkbox" style="margin:0; border-color:var(--warning);">
                                        <input type="checkbox" name="is_featured" id="is_featured" value="1" <?php echo ($edit_plan && !empty($edit_plan['is_featured'])) ? 'checked' : ''; ?>>
                                        <label for="is_featured" style="margin: 0; cursor: pointer; color:var(--primary); font-weight:bold;">⭐ پلن پیشنهادی ویژه (محبوب‌ترین)</label>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($edit_plan): ?>
                                <div style="display:flex; gap:1rem;">
                                    <button type="submit" class="btn btn-success btn-block" style="flex:1;">بروزرسانی نهایی پلن ⚙</button>
                                    <a href="?" class="btn btn-danger" style="background:#4A4857; color:white;">انصراف</a>
                                </div>
                            <?php else: ?>
                                <button type="submit" class="btn btn-block">ثبت و ایجاد پلن جدید 🚀</button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- لیست پلن‌های فعال -->
                    <div class="card">
                        <h2>📋 لیست کامل پلن‌های اشتراک تعریف شده در پُست‌یار</h2>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>نام پلن</th>
                                        <th>قیمت</th>
                                        <th>مدت</th>
                                        <th>سهمیه کانال</th>
                                        <th>سهمیه پست</th>
                                        <th>عملیات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plans as $p): ?>
                                        <tr>
                                            <td data-label="نام پلن"><strong><?php echo htmlspecialchars($p['title']); ?></strong></td>
                                            <td data-label="قیمت"><strong style="color:#34D399;"><?php echo \WHCM\Domain\TextFormat::fa_num($p['price']); ?> تومان</strong></td>
                                            <td data-label="مدت"><?php echo \WHCM\Domain\TextFormat::fa_digits($p['duration_days']); ?> روز</td>
                                            <td data-label="سهمیه کانال"><?php echo \WHCM\Domain\TextFormat::fa_digits($p['max_channels']); ?> کانال</td>
                                            <td data-label="سهمیه پست"><?php echo $p['max_posts'] === 0 ? 'نامحدود' : \WHCM\Domain\TextFormat::fa_digits($p['max_posts']) . ' پست'; ?></td>
                                            <td data-label="عملیات">
                                                <div style="display:flex; gap:0.5rem;">
                                                    <a href="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh'); ?>&edit_plan=<?php echo $p['id']; ?>" class="btn btn-sm" style="background:#AEC4DC;">⚙ ویرایش</a>
                                                    <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/delete-plan'); ?>" style="display:inline" onsubmit="return confirm('آیا از حذف این پلن اشتراک اطمینان دارید؟');">
    <?php echo \WHCM\Core\Csrf::field(); ?>
    <input type="hidden" name="plan_id" value="<?php echo (int)$p['id']; ?>">
    <button type="submit" class="btn btn-danger btn-sm">حذف</button>
</form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ========================================== -->
            <!-- تنظیمات ربات طلا و سکه (Gold Ticker Admin) -->
            <!-- ========================================== -->
            <div id="section-admin-gold" class="tab-content">
                <div class="card">
                    <h2>🪙 تنظیمات کلان ربات لحظه‌ای طلا، سکه و ارز</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">پیکربندی سورس‌های رسمی دریافت نرخ و متون پیش‌فرض برای کاربران سامانه پُست‌یار</p>
                    
                    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-gold-settings-admin'); ?>" method="POST">
                        <?php echo $csrf_field; ?>
                        <div class="form-row" style="margin-bottom: 1.5rem;">
                            <div class="form-group">
                                <label for="gold-source">سورس رسمی دریافت زنده نرخ‌ها (API):</label>
                                <select name="gold_api_source" id="gold-source" style="border-radius:10px;">
                                    <option value="tgju" <?php echo (($admin_settings['gold_api_source'] ?? 'tgju') === 'tgju') ? 'selected' : ''; ?>>سامانه شبکه اطلاع‌رسانی طلا، سکه و ارز (TGJU API)</option>
                                    <option value="tala_ir" <?php echo (($admin_settings['gold_api_source'] ?? '') === 'tala_ir') ? 'selected' : ''; ?>>اتحادیه طلا و جواهر ایران (Tala.ir)</option>
                                    <option value="cbi" <?php echo (($admin_settings['gold_api_source'] ?? '') === 'cbi') ? 'selected' : ''; ?>>بانک مرکزی جمهوری اسلامی ایران</option>
                                    <option value="custom" <?php echo (($admin_settings['gold_api_source'] ?? '') === 'custom') ? 'selected' : ''; ?>>سورس اختصاصی و وب‌هوک دستی</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="gold-interval">دوره تناوب استعلام نرخ زنده (Interval):</label>
                                <select name="gold_interval" id="gold-interval" style="border-radius:10px;">
                                    <option value="60" <?php echo (($admin_settings['gold_interval'] ?? '') === '60') ? 'selected' : ''; ?>>هر ۶۰ ثانیه یک‌بار (پرفشار)</option>
                                    <option value="180" <?php echo (($admin_settings['gold_interval'] ?? '180') === '180') ? 'selected' : ''; ?>>هر ۳ دقیقه یک‌بار (پیشنهادی)</option>
                                    <option value="300" <?php echo (($admin_settings['gold_interval'] ?? '') === '300') ? 'selected' : ''; ?>>هر ۵ دقیقه یک‌بار</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label for="gold-custom-url">🔑 آدرس API دستی / کلید اختصاصی (برای سورس custom یا جایگزینی):
                            <span style="display:block; color:var(--text-muted); font-size:0.78rem; margin-top:0.25rem;">این آدرس به کاربرانی که پلن طلا دارند به عنوان API پیش‌فرض تخصیص داده می‌شود. اگر خالی بگذارید، آدرس پیش‌فرض TGJU استفاده خواهد شد.</span></label>
                            <input type="url" name="gold_custom_api_url" id="gold-custom-url" value="<?php echo htmlspecialchars($admin_settings['gold_custom_api_url'] ?? ''); ?>" placeholder="https://api.example.com/gold/prices" class="dir-ltr" style="border-radius:10px; font-size:0.85rem;">
                        </div>

                        <div class="form-group">
                            <label for="gold-default-template">الگوی پیش‌فرض ارسال قیمت طلا برای کاربران جدید:</label>
                            <textarea name="gold_default_template" id="gold-default-template" rows="6" style="font-family: monospace, Vazirmatn; line-height: 1.8;"><?php echo htmlspecialchars($admin_settings['gold_default_template'] ?? '🔸 نرخ لحظه‌ای طلا و سکه در بازار:

🥇 طلا ۱۸ عیار: {gold_18k} تومان
🪙 سکه امامی: {coin_emami} تومان
🪙 سکه بهار آزادی: {coin_bahar} تومان
🌕 انس جهانی طلا: {gold_ounce} دلار

📌 به‌روزرسانی خودکار توسط پُست‌یار'); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-success" style="width: 100%; padding: 0.9rem; font-size: 1rem;">
                            💾 ذخیره و اعمال تنظیمات کلان ربات طلا و سکه
                        </button>
                    </form>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- تنظیمات سراسری هوش مصنوعی (AI Admin) -->
            <!-- ========================================== -->
            <div id="section-admin-ai" class="tab-content">
                <div class="card">
                    <h2>🧠 تنظیمات سراسری هوش مصنوعی و مدل‌های مولد</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">تعریف ارائه‌دهندگان هوش مصنوعی (OpenAI، OpenRouter، Groq و مدل‌های دلخواه دستی) برای کاربران پُست‌یار</p>
                    
                    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-ai-settings-admin'); ?>" method="POST" data-saved-provider="<?php echo htmlspecialchars($admin_settings['ai_global_provider'] ?? 'openai'); ?>" data-saved-model="<?php echo htmlspecialchars($admin_settings['ai_global_model'] ?? ''); ?>" data-saved-url="<?php echo htmlspecialchars($admin_settings['ai_global_url'] ?? ''); ?>">
                        <?php echo $csrf_field; ?>
                        
                        <div class="form-row" style="margin-bottom: 1.5rem;">
                            <div class="form-group">
                                <label for="ai-g-provider">ارائه‌دهنده پیش‌فرض هوش مصنوعی در پلتفرم:</label>
                                <select name="ai_global_provider" id="ai-g-provider" style="border-radius:10px;">
                                    <option value="openai" <?php echo (($admin_settings['ai_global_provider'] ?? '') === 'openai') ? 'selected' : ''; ?>>OpenAI (GPT-4o / o3-mini / GPT-3.5-turbo)</option>
                                    <option value="deepseek" <?php echo (($admin_settings['ai_global_provider'] ?? '') === 'deepseek') ? 'selected' : ''; ?>>DeepSeek (V3 / R1 استدلالی)</option>
                                    <option value="anthropic" <?php echo (($admin_settings['ai_global_provider'] ?? '') === 'anthropic') ? 'selected' : ''; ?>>Anthropic Claude (Claude 4 Sonnet / 3.5)</option>
                                    <option value="openrouter" <?php echo (($admin_settings['ai_global_provider'] ?? '') === 'openrouter') ? 'selected' : ''; ?>>OpenRouter AI (تمام مدل‌ها با یک کلید)</option>
                                    <option value="groq" <?php echo (($admin_settings['ai_global_provider'] ?? '') === 'groq') ? 'selected' : ''; ?>>Groq (Llama-3.3-70b / Mixtral)</option>
                                    <option value="gemini" <?php echo (($admin_settings['ai_global_provider'] ?? '') === 'gemini') ? 'selected' : ''; ?>>Google Gemini (2.5 Pro / 2.0 Flash)</option>
                                    <option value="custom" <?php echo (($admin_settings['ai_global_provider'] ?? '') === 'custom') ? 'selected' : ''; ?>>مدل دلخواه دستی (Custom AI Model / Custom URL)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="ai-g-model">نام مدل پیش‌فرض (Model Name):</label>
                                <select name="ai_global_model" id="ai-g-model" style="border-radius:10px;" class="dir-ltr"></select>
                                <input type="text" id="ai-g-model-custom" placeholder="نام مدل دلخواه را وارد کنید" style="display:none; margin-top:0.5rem; border-radius:10px;" class="dir-ltr" value="<?php echo htmlspecialchars($admin_settings['ai_global_model'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row" style="margin-bottom: 1.5rem;">
                            <div class="form-group">
                                <label for="ai-g-key">کلید اصلی دسترسی به API (Global API Key):</label>
                                <input type="password" name="ai_global_key" id="ai-g-key" placeholder="sk-..." class="dir-ltr" value="<?php echo htmlspecialchars($admin_settings['ai_global_key'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="ai-g-url">آدرس اختصاصی endpoint (برای مدل دستی / Custom URL):</label>
                                <input type="url" name="ai_global_url" id="ai-g-url" placeholder="https://api.openai.com/v1/chat/completions" class="dir-ltr" value="<?php echo htmlspecialchars($admin_settings['ai_global_url'] ?? ''); ?>">
                            </div>
                        </div>

                        <div style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.3); padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem;">
                            <label style="display:flex; align-items:center; gap:0.5rem; color:white; cursor:pointer; margin:0;">
                                <input type="checkbox" name="ai_active_by_default" value="1" <?php echo (empty($admin_settings['ai_active_by_default']) || $admin_settings['ai_active_by_default'] === '1') ? 'checked' : ''; ?> style="width:18px; height:18px;">
                                <span>فعال‌سازی دستیار نگارش هوشمند (AI Writer) برای کاربران دارای اشتراک مجاز</span>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.9rem; font-size: 1rem; background-color: #C4B5FD;">
                            💾 ذخیره پیکربندی و مدل‌های سراسری هوش مصنوعی
                        </button>
                    </form>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- کدهای تخفیف (Discounts - تقویم شمسی) -->
            <!-- ========================================== -->
            <div id="section-discounts" class="tab-content">
                <div class="card">
                    <h2>🎁 مدیریت کدهای تخفیف و جشنواره‌ها</h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">کدهای تخفیفی که کاربران می‌توانند هنگام خرید اشتراک از آن‌ها استفاده کنند (با تقویم شمسی و اعداد فارسی).</p>
                    
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>کد تخفیف</th>
                                    <th>درصد تخفیف</th>
                                    <th>حداکثر استفاده</th>
                                    <th>تعداد استفاده‌شده</th>
                                    <th>تاریخ انقضا (شمسی)</th>
                                    <th>وضعیت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($discounts)): ?>
                                    <?php foreach ($discounts as $d): ?>
                                        <tr>
                                            <td><strong style="color: var(--warning); font-family: monospace; font-size: 1.1rem;"><?php echo htmlspecialchars($d['code']); ?></strong></td>
                                            <td>٪<?php echo \WHCM\Domain\TextFormat::fa_digits($d['percentage']); ?></td>
                                            <td><?php echo $d['max_uses'] == 0 ? 'نامحدود' : \WHCM\Domain\TextFormat::fa_digits($d['max_uses']); ?></td>
                                            <td><?php echo \WHCM\Domain\TextFormat::fa_digits($d['used_count']); ?></td>
                                            <td><?php echo htmlspecialchars($d['expires_at'] ?? 'بدون انقضا'); ?></td>
                                            <td>
                                                <?php if ($d['status'] === 'active'): ?>
                                                    <span class="badge badge-success">فعال</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">غیرفعال</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/delete-discount'); ?>" method="POST" style="display:inline-block;" onsubmit="return confirm('آیا از حذف این کد تخفیف اطمینان دارید؟');">
                                                    <?php echo $csrf_field; ?>
                                                    <input type="hidden" name="discount_id" value="<?php echo $d['id']; ?>">
                                                    <button type="submit" class="btn btn-danger" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;">🗑 حذف</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-muted);">هیچ کد تخفیفی تعریف نشده است.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>➕ ایجاد کد تخفیف جدید</h3>
                    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/add-discount'); ?>" method="POST">
                        <?php echo $csrf_field; ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label>کد تخفیف (حروف انگلیسی یا عدد):</label>
                                <input type="text" name="code" required placeholder="مثلاً: NOWRUZ1405" style="text-transform: uppercase;">
                            </div>
                            <div class="form-group">
                                <label>درصد تخفیف (۱ تا ۱۰۰):</label>
                                <input type="number" name="percentage" required min="1" max="100" placeholder="مثلاً: 25">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>حداکثر تعداد دفعات مجاز استفاده (۰ = نامحدود):</label>
                                <input type="number" name="max_uses" value="0" required placeholder="مثلاً: 100">
                            </div>
                            <div class="form-group">
                                <label>تاریخ انقضا (انتخاب از تقویم شمسی - خالی برای بدون انقضا):</label>
                                <input type="text" name="expires_at" id="admin-discount-date" data-jdp placeholder="مثلاً: 1405/01/15 23:59" class="dir-ltr" readonly style="cursor: pointer; background: var(--bg-dark); color: #34D399; font-weight: bold; border: 2px solid #34D399;">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success" style="width: 100%;">ثبت و فعال‌سازی کد تخفیف 🎁</button>
                    </form>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ۶. بخش ارسال اعلان همگانی -->
            <!-- ========================================== -->
            
            <!-- ========================================== -->
            <!-- تنظیمات پاسخگوی هوشمند (مدیریت) -->
            <!-- ========================================== -->
            <div id="section-admin-responder" class="tab-content">
                <div class="card">
                    <h2>🤖 تنظیمات کلان پاسخگوی هوشمند کلمات کلیدی</h2>
                    <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:1.25rem;">پیکربندی پیش‌فرض، محدودیت‌ها و پیام‌های سیستمی پاسخگوی خودکار برای تمام کاربران (کاربر فقط در صورت داشتن پلن مجاز به این بخش دسترسی دارد)</p>
                    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-responder-settings-admin'); ?>" method="POST">
                        <?php echo $csrf_field; ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label>حداکثر کلمات کلیدی مجاز برای هر کاربر</label>
                                <input type="number" name="responder_max_keywords" value="<?php echo htmlspecialchars($global_responder['responder_max_keywords'] ?? '20'); ?>" min="1" max="200">
                            </div>
                            <div class="form-group">
                                <label>تأخیر پاسخ (ثانیه)</label>
                                <select name="responder_delay">
                                    <option value="0">بدون تأخیر</option>
                                    <option value="2">۲ ثانیه</option>
                                    <option value="5">۵ ثانیه</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>پیام پیش‌فرض در صورت عدم تطابق کلمه</label>
                            <textarea name="responder_fallback" rows="3" placeholder="پیامی که اگر کلمه یافت نشد ارسال شود (خالی = بدون پاسخ)"><?php echo htmlspecialchars($global_responder['responder_fallback'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">ذخیره تنظیمات پاسخگو 🤖</button>
                    </form>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- تنظیمات اتصال ووکامرس (مدیریت) -->
            <!-- ========================================== -->
            <div id="section-admin-woo" class="tab-content">
                <div class="card">
                    <h2>🛍 تنظیمات کلان اتصال ووکامرس</h2>
                    <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:1.25rem;">راهنما و محدودیت‌های اتصال فروشگاه ووکامرس — کاربران فقط در صورت داشتن پلن دارای ووکامرس به تنظیمات اتصال دسترسی دارند</p>
                    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-woo-settings-admin'); ?>" method="POST">
                        <?php echo $csrf_field; ?>
                        <div class="form-group">
                            <label>متن راهنمای اتصال برای کاربران</label>
                            <textarea name="woo_help_text" rows="4"><?php echo htmlspecialchars($global_woo['woo_help_text'] ?? 'برای اتصال، از پیشخوان وردپرس → ووکامرس → تنظیمات → پیشرفته → REST API یک کلید با دسترسی خواندن/نوشتن بسازید.'); ?></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>حداکثر فروشگاه مجاز برای هر کاربر</label>
                                <input type="number" name="woo_max_stores" value="<?php echo htmlspecialchars($global_woo['woo_max_stores'] ?? '1'); ?>" min="1" max="5">
                            </div>
                            <div class="form-group">
                                <label style="display:flex; align-items:center; gap:0.5rem; margin-top:1.5rem;"><input type="checkbox" name="woo_require_ssl" value="1" checked> الزام اتصال امن (HTTPS)</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">ذخیره تنظیمات ووکامرس 🛍</button>
                    </form>
                </div>
            </div>

            <div id="section-broadcast" class="tab-content">
                <div class="card" style="border: 1px solid rgba(99,102,241,0.25); background: linear-gradient(135deg, rgba(99,102,241,0.05) 0%, rgba(15,15,20,0.084) 100%);">
                    <h2>📢 ارسال اعلان همگانی درون‌برنامه‌ای</h2>
                    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/broadcast-announcement'); ?>" method="POST">
                        <?php echo $csrf_field; ?>
                        <div class="form-group">
                            <label for="ann-title">عنوان اعلان:</label>
                            <input type="text" name="title" id="ann-title" required placeholder="مثال: بروزرسانی جدید هسته پُست‌یار">
                        </div>
                        <div class="form-group">
                            <label>انتخاب جامعه هدف (پلن‌های اشتراکی):</label>
                            <div style="display:flex; gap:1rem; flex-wrap:wrap; background:rgba(15,15,20,0.084); padding:1rem; border-radius:12px; border:1px solid #2A2A38;">
                                <label style="display:flex; align-items:center; gap:0.4rem; color:white; cursor:pointer;">
                                    <input type="radio" name="target_plans" value="all" checked style="width:16px; height:16px;">
                                    <span>همه کاربران پلتفرم</span>
                                </label>
                                <?php foreach ($plans as $p_opt): ?>
                                    <label style="display:flex; align-items:center; gap:0.4rem; color:#A5B4FC; cursor:pointer;">
                                        <input type="radio" name="target_plans" value="<?php echo $p_opt['id']; ?>" style="width:16px; height:16px;">
                                        <span>کاربران پلن «<?php echo htmlspecialchars($p_opt['title']); ?>»</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="ann-msg">متن پیام اعلان:</label>
                            <textarea name="message" id="ann-msg" rows="5" required placeholder="متن پیام شما برای نمایش در بالای پیشخوان کاربران منتخب..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%; background:linear-gradient(135deg, #A5B4FC 0%, #A5B4FC 100%);">ارسال اعلان به کاربران هدف 📢</button>
                    </form>
                </div>
                <div class="card" style="margin-top:2rem;">
                    <h2>📋 آمار و تاریخچه اعلان‌های ارسالی</h2>
                    <?php if (empty($announcements_list)): ?>
                        <p style="color:var(--text-muted); text-align:center; padding:1.5rem 0;">هیچ اعلانی تا کنون ارسال نشده است.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>عنوان اعلان</th>
                                        <th>جامعه هدف</th>
                                        <th>تاریخ ارسال</th>
                                        <th>متن پیام</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($announcements_list as $ann_item): ?>
                                        <tr>
                                            <td><strong style="color:white;"><?php echo htmlspecialchars($ann_item['title']); ?></strong></td>
                                            <td>
                                                <span class="badge badge-success">
                                                    <?php 
                                                        if ($ann_item['target_plans'] === 'all' || empty($ann_item['target_plans'])) {
                                                            echo 'همه کاربران';
                                                        } else {
                                                            echo 'پلن اختصاصی شناسه ' . \WHCM\Domain\TextFormat::fa_digits($ann_item['target_plans']);
                                                        }
                                                    ?>
                                                </span>
                                            </td>
                                            <td><span style="font-size:0.8rem; color:#A5B4FC;"><?php echo htmlspecialchars($ann_item['created_at']); ?></span></td>
                                            <td><span style="font-size:0.8rem; color:#B4B2BE;"><?php echo htmlspecialchars(mb_substr($ann_item['message'], 0, 60)) . '...'; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ۷. بخش تنظیمات کارت بانکی پُست‌یار (Bank Settings) -->
            <!-- ========================================== -->
            <div id="section-bank" class="tab-content">
                <div class="card">
                    <h2>💳 تنظیمات و شماره کارت بانکی عمومی سامانه</h2>
                    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1.5rem;">
                        شماره کارت و مشخصات حساب بانکی خود را از این بخش ویرایش کنید. این کارت گرافیکی فین‌تک در تب تمدید اشتراک تمام کاربران پلتفرم به صورت اتوماتیک تغییر خواهد کرد!
                    </p>

                    <?php 
                        // لود فیلدهای کارت بانکی و پشتیبانی ادمین
                        $stmt = \WHCM\Core\Bootstrap::getDB()->prepare("SELECT key_name, key_value FROM settings WHERE tenant_id = 0 AND key_name IN ('admin_card_number', 'admin_card_holder', 'admin_bank_name', 'support_telegram_url', 'support_bale_url', 'support_email', 'occasion_discount_text')");
                        $stmt->execute();
                        $global_bank_rows = $stmt->fetchAll();
                        $global_bank = [];
                        foreach ($global_bank_rows as $row) {
                            $global_bank[$row['key_name']] = $row['key_value'];
                        }
                        $saved_card = $global_bank['admin_card_number'] ?? '۶۲۱۹-۸۶۱۰-xxxx-xxxx';
                        $saved_holder = $global_bank['admin_card_holder'] ?? 'هومن نقشی';
                        $saved_bank = $global_bank['admin_bank_name'] ?? 'بانک سامان';
                        $saved_tele = $global_bank['support_telegram_url'] ?? 'https://t.me/asovin_support';
                        $saved_bale = $global_bank['support_bale_url'] ?? 'https://ble.ir/asovin_support';
                        $saved_email = $global_bank['support_email'] ?? 'support@asovin.ir';
                        $saved_occ = $global_bank['occasion_discount_text'] ?? 'تخفیف مناسبتی';
                    ?>

                    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-bank-settings'); ?>" method="POST">
                        <?php echo $csrf_field; ?>
                        
                        <div class="form-group">
                            <label for="bk-card">شماره کارت ۱۶ رقمی (فرمت خط تیره خودکار اعمال می‌شود):</label>
                            <input type="text" name="card_number" id="bk-card" value="<?php echo htmlspecialchars($saved_card); ?>" required placeholder="62198610xxxxxxxx">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="bk-holder">نام و نام خانوادگی صاحب حساب:</label>
                                <input type="text" name="card_holder" id="bk-holder" value="<?php echo htmlspecialchars($saved_holder); ?>" required placeholder="هومن نقشی">
                            </div>
                            <div class="form-group">
                                <label for="bk-name">نام بانک صادرکننده:</label>
                                <input type="text" name="bank_name" id="bk-name" value="<?php echo htmlspecialchars($saved_bank); ?>" required placeholder="بانک سامان">
                            </div>
                        </div>

                        <!-- تنظیمات پویای سایر روش‌های تماس با پشتیبانی -->
                        <h3 style="font-size: 0.95rem; margin-top: 1.5rem; margin-bottom: 0.75rem; border-bottom: 1px dashed var(--border); padding-bottom: 0.4rem; color:#A5B4FC;">📞 تنظیمات راه‌های ارتباطی فرعی پشتیبانی کاربران</h3>
                        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1rem; margin-bottom: 1.5rem;">
                            <div class="form-group">
                                <label for="sup-tele">آدرس پشتیبانی تلگرام:</label>
                                <input type="url" name="support_telegram_url" id="sup-tele" value="<?php echo htmlspecialchars($saved_tele); ?>" placeholder="https://t.me/...">
                            </div>
                            <div class="form-group">
                                <label for="sup-bale">آدرس پشتیبانی بله:</label>
                                <input type="url" name="support_bale_url" id="sup-bale" value="<?php echo htmlspecialchars($saved_bale); ?>" placeholder="https://ble.ir/...">
                            </div>
                            <div class="form-group">
                                <label for="sup-mail">نشانی ایمیل پشتیبانی:</label>
                                <input type="email" name="support_email" id="sup-mail" value="<?php echo htmlspecialchars($saved_email); ?>" placeholder="support@example.com">
                            </div>
                        </div>



                        <button type="submit" class="btn btn-success" style="width:100%;">ذخیره تنظیمات بانک، پشتیبانی و تخفیف‌های پُست‌یار 💳✔</button>
                    </form>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ۹. بخش تنظیمات زیرمجموعه‌گیری -->
            <!-- ========================================== -->
            <div id="section-referral-settings" class="tab-content">
                <?php
                    $admin_ref_settings = \WHCM\Domain\Referral::getAdminSettings();
                    $settings = $admin_ref_settings;
                ?>
                <?php include __DIR__ . '/partials/admin-referral-settings.php'; ?>
            </div>

            <!-- ========================================== -->
            <!-- ۱۰. بخش تنظیمات پنل‌های پیامک -->
            <!-- ========================================== -->
            <div id="section-sms-settings" class="tab-content">
                <?php
                    $sms_settings = [];
                    $db = \WHCM\Core\Bootstrap::getDB();
                    $sms_keys = ['sms_enabled', 'sms_api_key', 'sms_line_number'];
                    foreach ($sms_keys as $sk) {
                        $sstmt = $db->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = ? LIMIT 1");
                        $sstmt->execute([$sk]);
                        $srow = $sstmt->fetch();
                        $sms_settings[$sk] = $srow !== false ? \WHCM\Core\SecretStore::decrypt((string)$srow['key_value']) : '';
                        if ($sk === 'sms_api_key' && $sms_settings[$sk] !== '') { $sms_settings[$sk] = ''; }
                    }
                    $templates = $db->query("SELECT * FROM sms_templates ORDER BY id ASC")->fetchAll();
                    $logs = $db->query("SELECT sl.*, st.template_name, st.event_key FROM sms_log sl LEFT JOIN sms_templates st ON sl.template_id = st.template_id ORDER BY sl.id DESC LIMIT 50")->fetchAll();
                    $active_users = $db->query("SELECT id, name, phone FROM users WHERE status = 'active' AND role != 'superadmin' ORDER BY id DESC")->fetchAll();
                    $filter_status = $_GET['filter_status'] ?? '';
                    $filter_phone = trim($_GET['filter_phone'] ?? '');
                ?>
                <?php include __DIR__ . '/partials/admin-sms-settings.php'; ?>
            </div>

            <!-- ========================================== -->
            <!-- ۱۱. بخش تنظیمات ایمیل -->
            <!-- ========================================== -->
            <div id="section-provider-settings" class="tab-content">
<?php include __DIR__ . '/partials/admin-provider-settings.php'; ?>
</div>

<div id="section-email-settings" class="tab-content">
                <?php
                    $email_settings = [];
                    $edb = \WHCM\Core\Bootstrap::getDB();
                    $smtp_keys = ['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_address', 'smtp_from_name', 'smtp_timeout', 'smtp_auth', 'smtp_reply_to', 'smtp_reply_name'];
                    foreach ($smtp_keys as $ek) {
                        $estmt = $edb->prepare("SELECT key_value FROM settings WHERE tenant_id = 0 AND key_name = ? LIMIT 1");
                        $estmt->execute([$ek]);
                        $erow = $estmt->fetch();
                        $email_settings[$ek] = $erow !== false ? \WHCM\Core\SecretStore::decrypt((string)$erow['key_value']) : '';
                        if ($ek === 'smtp_password' && $email_settings[$ek] !== '') { $email_settings[$ek] = ''; }
                    }
                    $email_templates = \WHCM\Core\EmailTemplate::getAllTemplates();
                    $email_logs = \WHCM\Core\EmailTemplate::getLog(50, 0, !empty($_GET['filter_status']) ? $_GET['filter_status'] : null);
                    $email_stats = \WHCM\Core\EmailTemplate::getAdminEmailStats();
                    $active_users = $edb->query("SELECT id, name, email FROM users WHERE status = 'active' AND role != 'superadmin' ORDER BY id DESC")->fetchAll();
                    $all_users = $edb->query("SELECT id, name, email FROM users WHERE role != 'superadmin' ORDER BY id DESC")->fetchAll();
                    $filter_status = $_GET['filter_status'] ?? '';
                ?>
                <?php include __DIR__ . '/partials/admin-email-settings.php'; ?>
            </div>

            <!-- ========================================== -->
            <!-- ۸. بخش تیکت‌های پشتیبانی — سیستم حرفه‌ای -->
            <!-- ========================================== -->
            <div id="section-tickets" class="tab-content">
                <!-- مدیریت دسته‌بندی‌ها و پشتیبان‌ها -->
                <?php if (!$is_support): ?>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                    <div class="card">
                        <h2 style="font-size:1rem; margin-bottom:1rem;">🏷️ مدیریت دسته‌بندی تیکت‌ها</h2>
                        <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:1rem;">دسته‌بندی‌ها در فرم ثبت تیکت کاربران نمایش داده می‌شوند. می‌توانید هر دسته را به یک پشتیبان اختصاص دهید.</p>
                        <div id="cat-editor-area"></div>
                        <div style="display:flex; gap:0.5rem; margin-top:0.75rem;">
                            <button type="button" class="btn btn-sm" style="background:rgba(99,102,241,0.2); color:#A5B4FC; border:1px solid rgba(99,102,241,0.3);" onclick="addCategoryRow()">➕ افزودن دسته‌بندی</button>
                            <button type="button" class="btn btn-sm" style="background:rgba(52,211,153,0.2); color:#34D399; border:1px solid rgba(52,211,153,0.3);" onclick="saveCategories()">💾 ذخیره تغییرات</button>
                        </div>
                    </div>
                    <div class="card">
                        <h2 style="font-size:1rem; margin-bottom:1rem;">🎧 لیست کاربران پشتیبان</h2>
                        <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom:1rem;">کاربران با نقش «پشتیبان» فقط به بخش تیکت‌ها دسترسی دارند.</p>
                        <?php if (empty($support_agents)): ?>
                            <p style="color:#B4B2BE; font-size:0.85rem; text-align:center; padding:1rem;">هیچ پشتیبانی ثبت نشده است. از بخش «مدیریت کاربران» با نقش «پشتیبان» بسازید.</p>
                        <?php else: ?>
                            <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                <?php foreach ($support_agents as $agent): ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; background:#1C1C28; border:1px solid #2A2A38; border-radius:10px; padding:0.75rem 1rem;">
                                        <div>
                                            <strong style="color:white; font-size:0.9rem;"><?php echo htmlspecialchars($agent['name']); ?></strong>
                                            <div style="color:#B4B2BE; font-size:0.75rem;"><?php echo htmlspecialchars($agent['email']); ?></div>
                                        </div>
                                        <span style="background:rgba(99,102,241,0.2); color:#A5B4FC; padding:0.25rem 0.75rem; border-radius:8px; font-size:0.75rem; font-weight:700;">🎧 پشتیبان</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- کارت آمار تیکت‌ها -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
                    <div style="background:linear-gradient(135deg, rgba(99,102,241,0.15) 0%, rgba(15,15,20,0.1) 100%); border:1px solid rgba(99,102,241,0.3); border-radius:16px; padding:1.25rem; text-align:center;">
                        <div style="font-size:2rem;">🎟</div>
                        <div style="font-size:0.8rem; color:#B4B2BE; margin:0.3rem 0;">کل تیکت‌ها</div>
                        <strong style="color:#A5B4FC; font-size:1.4rem;"><?php echo \WHCM\Domain\TextFormat::fa_digits(count($tickets)); ?></strong>
                    </div>
                    <div style="background:linear-gradient(135deg, rgba(251,191,36,0.15) 0%, rgba(15,15,20,0.1) 100%); border:1px solid rgba(251,191,36,0.3); border-radius:16px; padding:1.25rem; text-align:center;">
                        <div style="font-size:2rem;">⏳</div>
                        <div style="font-size:0.8rem; color:#B4B2BE; margin:0.3rem 0;">در انتظار پاسخ</div>
                        <strong style="color:#FDE68A; font-size:1.4rem;"><?php echo \WHCM\Domain\TextFormat::fa_digits($open_t_count); ?></strong>
                    </div>
                    <div style="background:linear-gradient(135deg, rgba(52,211,153,0.15) 0%, rgba(15,15,20,0.1) 100%); border:1px solid rgba(52,211,153,0.3); border-radius:16px; padding:1.25rem; text-align:center;">
                        <div style="font-size:2rem;">✔</div>
                        <div style="font-size:0.8rem; color:#B4B2BE; margin:0.3rem 0;">پاسخ داده شده</div>
                        <strong style="color:#34D399; font-size:1.4rem;"><?php
                            $replied_count = 0;
                            foreach ($tickets as $tc) { if($tc['status'] === 'replied') $replied_count++; }
                            echo \WHCM\Domain\TextFormat::fa_digits($replied_count);
                        ?></strong>
                    </div>
                    <div style="background:linear-gradient(135deg, rgba(248,113,113,0.12) 0%, rgba(15,15,20,0.1) 100%); border:1px solid rgba(248,113,113,0.2); border-radius:16px; padding:1.25rem; text-align:center;">
                        <div style="font-size:2rem;">🔒</div>
                        <div style="font-size:0.8rem; color:#B4B2BE; margin:0.3rem 0;">بسته شده</div>
                        <strong style="color:#F87171; font-size:1.4rem;"><?php
                            $closed_count = 0;
                            foreach ($tickets as $tc) { if($tc['status'] === 'closed') $closed_count++; }
                            echo \WHCM\Domain\TextFormat::fa_digits($closed_count);
                        ?></strong>
                    </div>
                </div>

                <!-- دکمه ارسال پیام جدید + فیلتر -->
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; border-bottom:1px solid var(--border); padding-bottom:1rem;">
                        <h2 style="margin:0; border:none; padding:0;">🎫 مرکز تیکت‌ها و پیام‌رسانی</h2>
                        <button type="button" class="btn" style="background:linear-gradient(135deg, #A5B4FC 0%, #A5B4FC 100%); font-size:0.85rem; padding:0.7rem 1.2rem;" onclick="document.getElementById('newTicketModal').style.display='flex'">✉️ ارسال پیام جدید به کاربر</button>
                    </div>

                    <!-- فیلتر وضعیت -->
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.25rem;">
                        <button type="button" class="ticket-filter-btn active" onclick="filterTickets('all', this)">همه</button>
                        <button type="button" class="ticket-filter-btn" onclick="filterTickets('open', this)">⏳ باز</button>
                        <button type="button" class="ticket-filter-btn" onclick="filterTickets('replied', this)">✔ پاسخ‌داده‌شده</button>
                        <button type="button" class="ticket-filter-btn" onclick="filterTickets('closed', this)">🔒 بسته‌شده</button>
                    </div>

                    <?php if (empty($tickets)): ?>
                        <div style="text-align:center; padding:3rem 1rem;">
                            <div style="font-size:4rem; margin-bottom:1rem;">📭</div>
                            <p style="color:var(--text-muted); font-size:1.05rem; margin-bottom:0.75rem; font-weight:bold;">هنوز هیچ تیکتی ثبت نشده است</p>
                            <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.8; margin-bottom:1.5rem;">کاربران از بخش «پشتیبانی» در پیشخوان خود تیکت ثبت می‌کنند.<br>همچنین می‌توانید با دکمه بالا پیام مستقیم به هر کاربر ارسال کنید.</p>
                            <button type="button" class="btn" style="background:linear-gradient(135deg, #A5B4FC 0%, #A5B4FC 100%);" onclick="document.getElementById('newTicketModal').style.display='flex'">✉️ ارسال پیام به کاربر</button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="border:none; background:transparent; border-radius:0;">
                            <div id="tickets-list-container">
                                <?php foreach ($tickets as $t): ?>
                                <div class="ticket-card-row" data-status="<?php echo $t['status']; ?>">
                                    <div class="ticket-card-header">
                                        <div class="ticket-card-user">
                                            <div class="ticket-avatar"><?php echo mb_substr(htmlspecialchars($t['user_name'] ?? '؟'), 0, 1); ?></div>
                                            <div>
                                                <strong style="color:#fff; font-size:0.9rem;"><?php echo htmlspecialchars($t['user_name'] ?? 'نامشخص'); ?></strong><br>
                                                <span style="font-size:0.75rem; color:#B4B2BE;"><?php echo htmlspecialchars($t['user_email'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:0.5rem;">
                                            <?php if (!empty($t['created_by_admin'])): ?>
                                                <span style="font-size:0.7rem; background:rgba(99,102,241,0.2); color:#A5B4FC; padding:0.2rem 0.6rem; border-radius:6px;">📤 ارسال توسط ادمین</span>
                                            <?php endif; ?>
                                            <span class="badge badge-<?php echo $t['status'] === 'open' ? 'pending' : ($t['status'] === 'replied' ? 'approved' : 'danger'); ?>">
                                                <?php echo $t['status'] === 'open' ? '⏳ باز' : ($t['status'] === 'replied' ? '✔ پاسخ‌داده‌شده' : '🔒 بسته‌شده'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="ticket-card-subject"><?php echo htmlspecialchars($t['subject']); ?></div>
                                    <div class="ticket-card-preview"><?php echo mb_substr(strip_tags($t['message']), 0, 120); ?>...</div>
                                    <div class="ticket-card-actions">
                                        <button type="button" class="btn btn-sm" style="background:linear-gradient(135deg, #A5B4FC 0%, #A5B4FC 100%) !important; color:#fff !important; font-weight:800; border:none;" onclick='openAdminTicketModal(<?php echo json_encode($t, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE); ?>)'>👁 مشاهده و پاسخ</button>
                                        <?php if ($t['status'] === 'closed'): ?>
                                            <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/reopen-ticket'); ?>" method="POST" style="display:inline;">
                                                <?php echo $csrf_field; ?>
                                                <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline" title="باز کردن مجدد">🔄</button>
                                            </form>
                                        <?php endif; ?>
                                        <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/delete-ticket'); ?>" method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این تیکت اطمینان دارید؟')">
                                            <?php echo $csrf_field; ?>
                                            <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-sm" style="background:rgba(248,113,113,0.15); color:#F87171; border:1px solid rgba(248,113,113,0.3);" title="حذف تیکت">🗑</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
        <footer class="g-footer">
            <span>© پُست‌یار — پنل مدیریت ارشد</span>
        </footer>
    </div>

    <script src="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/js/gentelella.js?v=3"></script>
    <script src="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/js/utils.js"></script>
    <script src="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/js/admin.js"></script>
    <script src="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/js/jalalidatepicker.min.js"></script>
    <!-- مدال هدیه دادن اشتراک به کاربر -->
    <div id="giftModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:1000; align-items:center; justify-content:center; padding:1rem;">
        <div class="card" style="width:100%; max-width:480px; margin:0; position:relative; background:#1C1C28; border:1px solid #2A2A38;">
            <button onclick="closeGiftModal()" style="position:absolute; top:15px; left:15px; background:none; border:none; color:#B4B2BE; font-size:1.2rem; cursor:pointer;">✖</button>
            <h3 style="color:#34D399; margin-bottom:1.25rem;">🎁 هدیه دادن اشتراک به کاربر</h3>
            <p style="color:#B4B2BE; font-size:0.85rem; margin-bottom:1.5rem;">با انتخاب پلن زیر، اشتراک کاربر <strong id="giftUserName" style="color:white;"></strong> به صورت رایگان و فوری فعال/تمدید خواهد شد.</p>
            
            <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/grant-subscription-manual'); ?>" method="POST">
                <?php echo $csrf_field; ?>
                <input type="hidden" name="user_id" id="giftUserId">
                <div class="form-group" style="margin-bottom:1.5rem;">
                    <label for="giftPlanSelect" style="display:block; color:#B4B2BE; margin-bottom:0.5rem;">انتخاب پلن اشتراک هدیه:</label>
                    <select name="plan_id" id="giftPlanSelect" required style="width:100%; padding:0.75rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                        <?php foreach ($plans as $pl): ?>
                            <option value="<?php echo $pl['id']; ?>"><?php echo htmlspecialchars($pl['title']) . ' (' . \WHCM\Domain\TextFormat::fa_digits($pl['duration_days']) . ' روزه)'; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success" style="width:100%; padding:0.85rem; background:#34D399; color:white; font-weight:bold; border-radius:12px;">🎁 فعال‌سازی فوری اشتراک هدیه برای کاربر</button>
            </form>
        </div>
    </div>

    <!-- مدال پروفایل ۳۶۰ درجه و سوابق فعالیت کاربر -->
    <div id="userProfileModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1100; align-items:center; justify-content:center; padding:1rem; overflow-y:auto;">
        <div class="card" style="width:100%; max-width:640px; margin:auto; position:relative; background:#1C1C28; border:1px solid #A5B4FC; border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,0.7);">
            <button onclick="closeUserProfileModal()" style="position:absolute; top:15px; left:15px; background:none; border:none; color:#B4B2BE; font-size:1.4rem; cursor:pointer;">✖</button>
            
            <!-- هدر کارت پروفایل -->
            <div style="display:flex; align-items:center; gap:1rem; border-bottom:1px dashed #2A2A38; padding-bottom:1.25rem; margin-bottom:1.25rem;">
                <div style="width:60px; height:60px; border-radius:50%; background:linear-gradient(135deg, #A5B4FC 0%, #A5B4FC 100%); display:flex; align-items:center; justify-content:center; font-size:1.8rem; color:white; font-weight:900;">
                    👤
                </div>
                <div>
                    <h3 id="up-name" style="color:white; margin:0; font-size:1.25rem; font-weight:900;"></h3>
                    <span id="up-email" style="color:#B4B2BE; font-size:0.85rem;"></span>
                </div>
            </div>

            <!-- وضعیت اشتراک فعلی و اعتبار -->
            <div style="background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.3); border-radius:12px; padding:1rem; margin-bottom:1.25rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                    <span style="color:#A5B4FC; font-weight:bold; font-size:0.9rem;">💎 وضعیت اشتراک فعال:</span>
                    <span id="up-plan" class="badge badge-success" style="font-size:0.85rem;"></span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.8rem; color:#B4B2BE;">
                    <span>تاریخ عضویت در سایت: <strong id="up-created" style="color:white;"></strong></span>
                    <span>اعتبار اشتراک تا: <strong id="up-end" style="color:#34D399;"></strong></span>
                </div>
            </div>

            <!-- مشخصات کسب و کار -->
            <div style="background:rgba(15,15,20,0.098); border:1px solid #1C1C28; border-radius:12px; padding:1rem; margin-bottom:1.25rem; display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div>
                    <span style="font-size:0.75rem; color:#B4B2BE;">نام کسب و کار:</span>
                    <div id="up-biz-name" style="color:white; font-weight:bold; font-size:0.9rem;"></div>
                </div>
                <div>
                    <span style="font-size:0.75rem; color:#B4B2BE;">حوزه فعالیت / صنف:</span>
                    <div id="up-biz-type" style="color:white; font-weight:bold; font-size:0.9rem;"></div>
                </div>
            </div>

            <!-- ۴ کارت آمار ۳۶۰ درجه عملکرد کاربر -->
            <h4 style="color:#A5B4FC; font-size:0.9rem; margin-bottom:0.75rem;">📊 آمار جامع و تفکیکی عملکرد ۳۶۰ درجه</h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1.5rem;">
                <div style="background:#1C1C28; border-radius:10px; padding:0.85rem; text-align:center; border:1px solid #2A2A38;">
                    <div style="font-size:0.75rem; color:#B4B2BE; margin-bottom:0.25rem;">📻 کانال‌های متصل شده</div>
                    <strong id="up-channels" style="color:#4299E1; font-size:1.2rem;">۰</strong>
                </div>
                <div style="background:#1C1C28; border-radius:10px; padding:0.85rem; text-align:center; border:1px solid #2A2A38;">
                    <div style="font-size:0.75rem; color:#B4B2BE; margin-bottom:0.25rem;">📝 پست‌های ارسالی</div>
                    <strong id="up-posts" style="color:#34D399; font-size:1.2rem;">۰</strong>
                </div>
                <div style="background:#1C1C28; border-radius:10px; padding:0.85rem; text-align:center; border:1px solid #2A2A38;">
                    <div style="font-size:0.75rem; color:#B4B2BE; margin-bottom:0.25rem;">🎫 تیکت‌های پشتیبانی</div>
                    <strong id="up-tickets" style="color:#FDE68A; font-size:1.2rem;">۰</strong>
                </div>
                <div style="background:#1C1C28; border-radius:10px; padding:0.85rem; text-align:center; border:1px solid #2A2A38;">
                    <div style="font-size:0.75rem; color:#B4B2BE; margin-bottom:0.25rem;">💳 کل واریزی‌های تایید شده</div>
                    <strong id="up-payments" style="color:#C4B5FD; font-size:1.2rem;">۰ تومان</strong>
                </div>
            </div>

            <!-- اقدامات سریع مدیریتی روی کاربر -->
            <div style="display:flex; justify-content:space-between; gap:0.75rem; border-top:1px dashed #2A2A38; padding-top:1rem;">
                <button type="button" class="btn btn-success" style="flex:1; background:#34D399; border:none;" onclick="triggerGiftFromProfile()">🎁 هدیه اشتراک</button>
                <button type="button" class="btn" style="flex:1; background:rgba(15,15,20,0.06); color:white; border:1px solid #2A2A38;" onclick="closeUserProfileModal()">بستن پنجره</button>
            </div>
        </div>
    </div>
    <!-- مدال گفتگو و مدیریت حرفه‌ای تیکت توسط ادمین ارشد -->
    <div id="adminTicketModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1200; align-items:center; justify-content:center; padding:1rem; overflow-y:auto;">
        <div class="card" style="width:100%; max-width:620px; margin:auto; position:relative; background:#1C1C28; border:1px solid #A5B4FC; border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,0.8);">
            <button onclick="closeAdminTicketModal()" style="position:absolute; top:15px; left:15px; background:none; border:none; color:#B4B2BE; font-size:1.4rem; cursor:pointer;">✖</button>
            
            <div style="border-bottom:1px dashed #2A2A38; padding-bottom:1rem; margin-bottom:1.25rem;">
                <span id="at-modal-status" class="badge" style="float:left; margin-top:2px;"></span>
                <h3 id="at-modal-subject" style="color:white; margin:0; font-size:1.15rem; font-weight:900;"></h3>
                <span id="at-modal-user" style="font-size:0.8rem; color:#B4B2BE; display:block; margin-top:0.3rem;"></span>
            </div>

            <div id="at-modal-body" style="display:flex; flex-direction:column; gap:1rem; max-height:380px; overflow-y:auto; padding-right:0.5rem; margin-bottom:1.5rem;">
                <!-- گفتگوها در اینجا رندر می‌شوند -->
            </div>

            <!-- فرم ارسال پاسخ به تیکت — با پیوست، ارجاع و بستن همزمان -->
            <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/reply-ticket'); ?>" method="POST" enctype="multipart/form-data" style="margin-bottom:1rem;">
                <?php echo $csrf_field; ?>
                <input type="hidden" name="ticket_id" id="at-reply-id">
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <textarea name="reply" rows="3" required placeholder="پاسخ کارشناس پشتیبانی را بنویسید..." style="width:100%; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; padding:0.75rem;"></textarea>
                </div>
                <div class="form-group">
                    <label style="font-size:0.8rem; color:#B4B2BE;">پیوست تصویر (اختیاری):</label>
                    <input type="file" name="attachment" accept="image/*,.pdf" style="padding:0.4rem; font-size:0.8rem;">
                </div>
                <div class="form-group">
                    <label style="font-size:0.8rem; color:#B4B2BE;">ارجاع به پشتیبان دیگر (اختیاری):</label>
                    <select name="assigned_to" style="width:100%; padding:0.5rem; border-radius:8px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                        <option value="0">— بدون ارجاع —</option>
                        <?php foreach ($users as $au): if(($au['role'] ?? '')==='superadmin' || ($au['role'] ?? '')==='support_agent'): ?>
                        <option value="<?php echo $au['id']; ?>"><?php echo htmlspecialchars($au['name']); ?> (<?php echo htmlspecialchars($au['email']); ?>)<?php echo ($au['role'] ?? '')==='support_agent' ? ' 🎧' : ' 👑'; ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; color:#FDE68A; margin-bottom:0.75rem; cursor:pointer;"><input type="checkbox" name="close_after_reply" value="1"> ارسال و بستن همزمان تیکت</label>
                <button type="submit" class="btn btn-success" style="width:100%; padding:0.75rem;">ارسال پاسخ پشتیبانی به کاربر ✔</button>
            </form>

            <!-- دکمه بستن جداگانه بدون پاسخ -->
            <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/close-ticket'); ?>" method="POST" style="margin:0;">
                <?php echo $csrf_field; ?>
                <input type="hidden" name="ticket_id" id="at-close-id">
                <button type="submit" class="btn btn-danger" style="width:100%; padding:0.6rem; font-size:0.85rem; background:rgba(248,113,113,0.2); border:1px solid #F87171; color:#F87171;">بستن این تیکت بدون پاسخ</button>
            </form>
        </div>
    </div>

    <!-- مدال ایجاد تیکت جدید (ارسال پیام ادمین به کاربر) -->
    <div id="newTicketModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1300; align-items:center; justify-content:center; padding:1rem; overflow-y:auto;">
        <div class="card" style="width:100%; max-width:540px; margin:auto; position:relative; background:#1C1C28; border:1px solid #A5B4FC; border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,0.8);">
            <button onclick="document.getElementById('newTicketModal').style.display='none'" style="position:absolute; top:15px; left:15px; background:none; border:none; color:#B4B2BE; font-size:1.4rem; cursor:pointer;">✖</button>
            <h3 style="color:#A5B4FC; margin-bottom:1.5rem;">✉️ ارسال پیام جدید به کاربر</h3>
            <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/create-ticket'); ?>" method="POST" enctype="multipart/form-data">
                <?php echo $csrf_field; ?>
                <div class="form-group">
                    <label>انتخاب کاربر مقصد <span style="color:#F87171;">*</span></label>
                    <select name="target_user_id" required style="width:100%; padding:0.75rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                        <option value="">— کاربر را انتخاب کنید —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['email']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>موضوع پیام <span style="color:#F87171;">*</span></label>
                    <input type="text" name="subject" required placeholder="موضوع پیام یا تیکت..." style="width:100%; padding:0.75rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group">
                        <label>دسته‌بندی</label>
                        <select name="category" style="width:100%; padding:0.75rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                            <option value="general">🔍 عمومی</option>
                            <option value="technical">💻 فنی</option>
                            <option value="billing">💳 مالی</option>
                            <option value="feature">🚀 پیشنهاد ویژگی</option>
                            <option value="bug">🐛 گزارش باگ</option>
                            <option value="other">📋 سایر</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>اولویت</label>
                        <select name="priority" style="width:100%; padding:0.75rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                            <option value="low">🟢 پایین</option>
                            <option value="normal" selected>🟡 عادی</option>
                            <option value="high">🟠 بالا</option>
                            <option value="critical">🔴 بحرانی</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>متن پیام <span style="color:#F87171;">*</span></label>
                    <textarea name="message" rows="5" required placeholder="متن پیام خود را بنویسید..." style="width:100%; padding:0.75rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; line-height:1.8;"></textarea>
                </div>
                <div class="form-group">
                    <label style="font-size:0.8rem; color:#B4B2BE;">پیوست فایل (اختیاری):</label>
                    <input type="file" name="attachment" accept="image/*,.pdf" style="padding:0.4rem; font-size:0.8rem;">
                </div>
                <button type="submit" class="btn btn-success" style="width:100%; padding:0.85rem;">📤 ارسال پیام به کاربر</button>
            </form>
        </div>
    </div>


    <!-- مدیریت دسته‌بندی تیکت‌ها -->
    <?php if (!$is_support): ?>
    <script>
    var __ticketCategories = <?php echo json_encode($ticket_categories ?? [], JSON_UNESCAPED_UNICODE); ?>;
    var __supportAgents = <?php echo json_encode($support_agents ?? [], JSON_UNESCAPED_UNICODE); ?>;
    var __adminCsrf = '<?php echo \WHCM\Core\Csrf::getToken(); ?>';

    function renderCategoryRows() {
        var area = document.getElementById('cat-editor-area');
        if (!area) return;
        area.innerHTML = '';
        var rows = area.querySelectorAll('.cat-edit-row');
        // If no rows exist yet, create from saved data
        if (rows.length === 0 && __ticketCategories.length > 0) {
            for (var i = 0; i < __ticketCategories.length; i++) {
                createCatRow(__ticketCategories[i].slug, __ticketCategories[i].title, __ticketCategories[i].icon, __ticketCategories[i].assigned_agent_id || '');
            }
        }
    }

    function createCatRow(slug, title, icon, agentId) {
        var area = document.getElementById('cat-editor-area');
        var row = document.createElement('div');
        row.className = 'cat-edit-row';
        row.style.cssText = 'display:grid; grid-template-columns:1fr 2fr auto auto 40px; gap:0.5rem; align-items:center; margin-bottom:0.5rem;';

        var agentOptions = '<option value="">بدون تخصیص</option>';
        for (var a = 0; a < __supportAgents.length; a++) {
            var sel = (__supportAgents[a].id == agentId) ? ' selected' : '';
            agentOptions += '<option value="' + __supportAgents[a].id + '"' + sel + '>' + __supportAgents[a].name + '</option>';
        }

        row.innerHTML =
            '<input type="text" placeholder="slug" value="' + (slug || '') + '" style="width:100%;background:#1C1C28;color:#B4B2BE;border:1px solid #2A2A38;border-radius:8px;padding:0.5rem;font-size:0.8rem;direction:ltr;" class="cat-slug">' +
            '<input type="text" placeholder="عنوان" value="' + (title || '') + '" style="width:100%;background:#1C1C28;color:white;border:1px solid #2A2A38;border-radius:8px;padding:0.5rem;" class="cat-title">' +
            '<input type="text" placeholder="ایموجی" value="' + (icon || '🌐') + '" style="width:50px;text-align:center;background:#1C1C28;color:white;border:1px solid #2A2A38;border-radius:8px;padding:0.5rem;font-size:1.1rem;" class="cat-icon">' +
            '<select style="background:#1C1C28;color:white;border:1px solid #2A2A38;border-radius:8px;padding:0.5rem;font-size:0.8rem;" class="cat-agent">' + agentOptions + '</select>' +
            '<button type="button" onclick="this.closest(\'.cat-edit-row\').remove()" style="background:rgba(248,113,113,0.15);color:#F87171;border:1px solid rgba(248,113,113,0.3);border-radius:8px;padding:0.5rem 0.6rem;cursor:pointer;font-size:0.9rem;">✖</button>';
        area.appendChild(row);
    }

    function addCategoryRow() {
        createCatRow('', '', '🌐', '');
    }

    function saveCategories() {
        var rows = document.querySelectorAll('.cat-edit-row');
        var cats = [];
        for (var i = 0; i < rows.length; i++) {
            var slug = rows[i].querySelector('.cat-slug').value.trim();
            var title = rows[i].querySelector('.cat-title').value.trim();
            var icon = rows[i].querySelector('.cat-icon').value.trim() || '🌐';
            var agent = rows[i].querySelector('.cat-agent').value;
            if (slug && title) {
                cats.push({slug: slug, title: title, icon: icon, assigned_agent: agent});
            }
        }
        var formData = 'csrf_token=' + encodeURIComponent(__adminCsrf) + '&categories=' + encodeURIComponent(JSON.stringify(cats));
        var xhr = new XMLHttpRequest();
        var baseUrl = window.location.pathname;
        xhr.open('POST', baseUrl + '?route=' + encodeURIComponent('/hnnh/save-ticket-categories'), true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        alert(res.message);
                        __ticketCategories = cats;
                    } else {
                        alert(res.message || 'خطا در ذخیره‌سازی');
                    }
                } catch(e) {
                    alert('خطای سیستمی');
                }
            }
        };
        xhr.send(formData);
    }

    // مقداردهی اولیه
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderCategoryRows);
    } else {
        renderCategoryRows();
    }
    </script>
    <?php endif; ?>

    <!-- پشتیبان: فقط بخش تیکت -->
    <?php if ($is_support): ?>
    <script>
    (function(){
        var sections = document.querySelectorAll('.tab-content');
        for (var i = 0; i < sections.length; i++) {
            if (sections[i].id !== 'section-tickets') {
                sections[i].style.display = 'none';
            }
        }
        var items = document.querySelectorAll('.sidebar-desktop .menu-item');
        for (var j = 0; j < items.length; j++) {
            var target = items[j].getAttribute('data-target');
            if (target && target !== 'tickets') {
                items[j].style.display = 'none';
            }
        }
    }());
    </script>
    <?php endif; ?>

    <script>
    (function(){
        function latin(v){return String(v||'').replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);});}
        function fa(v){return String(v||'').replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];});}
        function g2j(gy,gm,gd){
            var gdm=[0,31,59,90,120,151,181,212,243,273,304,334], jy=(gy<=1600?0:979), y=gy-(gy<=1600?621:1600), y2=(gm>2?y+1:y);
            var days=365*y+Math.floor((y2+3)/4)-Math.floor((y2+99)/100)+Math.floor((y2+399)/400)-80+gd+gdm[gm-1];
            jy+=33*Math.floor(days/12053); days%=12053; jy+=4*Math.floor(days/1461); days%=1461;
            if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;}
            var jm,jd;if(days<186){jm=1+Math.floor(days/31);jd=1+(days%31);}else{jm=7+Math.floor((days-186)/30);jd=1+((days-186)%30);} return [jy,jm,jd];
        }
        function j2g(jy,jm,jd){
            jy-=979; jm-=1; jd-=1; var jdn=365*jy+Math.floor(jy/33)*8+Math.floor(((jy%33)+3)/4);
            for(var i=0;i<jm;i++)jdn+=(i<6?31:30); jdn+=jd; var gdn=jdn+79, gy=1600+400*Math.floor(gdn/146097); gdn%=146097; var leap=true;
            if(gdn>=36525){gdn--;gy+=100*Math.floor(gdn/36524);gdn%=36524;if(gdn>=365)gdn++;else leap=false;}
            gy+=4*Math.floor(gdn/1461);gdn%=1461;if(gdn>=366){leap=false;gdn--;gy+=Math.floor(gdn/365);gdn%=365;}
            var md=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31],gm=1;while(gm<=12&&gdn>=md[gm-1]){gdn-=md[gm-1];gm++;}return gy+'-'+String(gm).padStart(2,'0')+'-'+String(gdn+1).padStart(2,'0');
        }
        function sync(input){
            var id=input.getAttribute('data-ad-date'), target=document.getElementById(id==='from'?'ad_from':'ad_to'); if(!target)return;
            var v=latin(input.value).replace(/[.\-]/g,'/').split('/');
            if(v.length===3&&/^\d{4}$/.test(v[0])) target.value=j2g(+v[0],+v[1],+v[2]); else target.value='';
            input.value=fa(input.value);
        }
        function persianCalendarDigits(){
            var root=document.querySelector('.jdp-container')||document.body;
            var nodes=root.querySelectorAll ? root.querySelectorAll('.jdp-day,.jdp-day-name,.jdp-year,.jdp-month,.jdp-btn-today,.jdp-btn-empty,select option') : [];
            for(var i=0;i<nodes.length;i++){if(nodes[i].children.length===0){var converted=fa(nodes[i].textContent);if(converted!==nodes[i].textContent)nodes[i].textContent=converted;}}
        }
        document.addEventListener('DOMContentLoaded',function(){
            /* راه‌اندازی نهایی تقویم جلالی — تنها یک بار startWatch فراخوانی می‌شود */
            if(window.jalaliDatepicker){
                jalaliDatepicker.startWatch({
                    separatorChar:'/',
                    showTodayBtn:true,
                    showEmptyBtn:true,
                    autoShow:true,
                    autoHide:true
                });
            }
            /* همگام‌سازی تاریخ‌های گزارش تبلیغات */
            var inputs=document.querySelectorAll('input[data-ad-date]');
            for(var i=0;i<inputs.length;i++){
                (function(input){
                    input.addEventListener('change',function(){sync(input);});
                    input.addEventListener('input',function(){input.value=fa(input.value);});
                    /* اطمینان از باز شدن تقویم با کلیک روی فیلدهای گزارش */
                    input.addEventListener('click',function(){try{jalaliDatepicker.show(input);}catch(e){}});
                    input.addEventListener('focus',function(){try{jalaliDatepicker.show(input);}catch(e){}});
                })(inputs[i]);
            }
            persianCalendarDigits();
            for(var ri=0;ri<inputs.length;ri++)(function(input){input.addEventListener('focus',function(){[0,60,180].forEach(function(ms){setTimeout(persianCalendarDigits,ms);});});})(inputs[ri]);
            var form=document.querySelector('#section-ads form');if(form)form.addEventListener('submit',function(){for(var i=0;i<inputs.length;i++)sync(inputs[i]);});
        });
    })();
    </script>

    <!-- PWA: ثبت سرویس ورکر و بنر نصب (فقط موبایل/تبلت) -->
    <script>
    (function(){
        var baseUrl = '<?php echo $baseUrl; ?>';
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(baseUrl + '/service-worker.js', { scope: baseUrl + '/' })
                .catch(function() {});
        }
    }());
    </script>
    <script src="<?php echo \WHCM\Core\Bootstrap::getAssetsUrl(); ?>/js/pwa-install.js"></script>
</body>
</html>