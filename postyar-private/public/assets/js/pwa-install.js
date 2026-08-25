/* ==========================================================================
   PWA Install Handler — سامانه پُست‌یار
   تشخیص خودکار موبایل/تبلت + بررسی HTTPS + بنر زیبا
   ========================================================================== */

(function() {
    'use strict';

    // ===== تشخیص محیط =====
    function isMobileOrTablet() {
        if (typeof window === 'undefined' || typeof navigator === 'undefined') return false;
        var ua = navigator.userAgent || navigator.vendor || '';
        var hasTouchPoints = navigator.maxTouchPoints > 1;
        var mobileRegex = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|Tablet/i;
        var isMobileUA = mobileRegex.test(ua);
        var isMacWithTouch = /Macintosh/.test(ua) && hasTouchPoints;
        return isMobileUA || isMacWithTouch || hasTouchPoints;
    }

    function isIOS() {
        return /iphone|ipad|ipod/i.test((navigator.userAgent || ''));
    }

    function isStandalone() {
        return window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    }

    function isSecureContext() {
        return window.isSecureContext || location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    }

    // اگر از قبل نصب شده، خروج
    if (isStandalone()) return;

    // فقط موبایل/تبلت
    if (!isMobileOrTablet()) return;

    // اگر HTTPS نیست، لاگ کن و خروج (نمی‌توان SW ثبت کرد)
    if (!isSecureContext()) {
        console.warn('[PWA] نصب PWA نیاز به HTTPS دارد. پروتکل فعلی:', location.protocol);
        return;
    }

    var deferredPrompt = null;
    var installBanner = null;
    var ios = isIOS();

    // ===== ساخت بنر نصب =====
    function createBanner() {
        if (installBanner) return;

        var banner = document.createElement('div');
        banner.id = 'pwa-install-banner';
        banner.setAttribute('role', 'dialog');
        banner.setAttribute('aria-label', 'نصب اپلیکیشن پُست‌یار');

        var html = '<div style="display:flex;align-items:center;gap:0.75rem;padding:0.875rem 1rem;">' +
            '<div style="width:3rem;height:3rem;border-radius:0.75rem;background:linear-gradient(135deg,#A5B4FC 0%,#C0A8E8 50%,#D6336C 100%);padding:0.125rem;flex-shrink:0;">' +
                '<img src="/assets/icons/icon-192x192.png" alt="پُست‌یار" style="width:100%;height:100%;border-radius:0.625rem;object-fit:contain;">' +
            '</div>' +
            '<div style="flex:1;min-width:0;">' +
                '<div style="font-weight:800;color:#f1f5f9;font-size:0.875rem;margin-bottom:0.125rem;font-family:Vazirmatn,Tahoma,sans-serif;">پُست‌یار را نصب کنید</div>' +
                '<div id="pwa-desc" style="color:#B4B2BE;font-size:0.75rem;line-height:1.6;font-family:Vazirmatn,Tahoma,sans-serif;"></div>' +
            '</div>' +
            '<button id="pwa-action-btn" style="font-family:Vazirmatn,Tahoma,sans-serif;"></button>' +
            '<button id="pwa-dismiss" aria-label="بستن" style="background:none;border:none;color:#4A4857;font-size:1.25rem;cursor:pointer;padding:0.5rem;flex-shrink:0;line-height:1;">&#10005;</button>' +
        '</div>';

        banner.innerHTML = html;

        // استایل بنر
        banner.style.cssText =
            'position:fixed;bottom:0;left:0;right:0;z-index:99999;' +
            'background:linear-gradient(135deg,#1C1C28 0%,#1C1C28 100%);' +
            'border-top:1px solid rgba(99,102,241,0.3);' +
            'box-shadow:0 -0.25rem 1.5rem rgba(0,0,0,0.4);' +
            'font-family:Vazirmatn,Tahoma,Arial,sans-serif;' +
            'direction:rtl;' +
            'transform:translateY(100%);' +
            'transition:transform 0.4s cubic-bezier(0.16,1,0.3,1);';

        document.body.appendChild(banner);
        installBanner = banner;

        // تنظیم محتوا بر اساس پلتفرم
        var desc = document.getElementById('pwa-desc');
        var actionBtn = document.getElementById('pwa-action-btn');
        var dismissBtn = document.getElementById('pwa-dismiss');

        if (ios) {
            desc.innerHTML = 'روی دکمه <b style="color:#A5B4FC;">اشتراک‌گذاری</b> بزنید و سپس <b style="color:#A5B4FC;">افزودن به صفحه اصلی</b> را انتخاب کنید';
            actionBtn.style.cssText = 'display:none;';
        } else {
            desc.textContent = 'دسترسی سریع مثل اپلیکیشن واقعی — بدون نیاز به مرورگر';
            actionBtn.textContent = 'نصب';
            actionBtn.style.cssText =
                'background:linear-gradient(135deg,#A5B4FC 0%,#A5B4FC 100%);' +
                'color:#fff;border:none;padding:0.5rem 1.25rem;border-radius:0.625rem;' +
                'font-size:0.8125rem;font-weight:700;cursor:pointer;flex-shrink:0;' +
                'box-shadow:0 0.25rem 0.75rem rgba(99,102,241,0.3);' +
                'transition:all 0.2s;';

            actionBtn.addEventListener('click', function() {
                if (!deferredPrompt) return;
                actionBtn.disabled = true;
                actionBtn.textContent = 'در حال نصب...';
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(choice) {
                    if (choice.outcome === 'accepted') {
                        localStorage.setItem('pwa_install_dismissed', 'permanent');
                    }
                    deferredPrompt = null;
                    hideBanner();
                });
            });
        }

        // دکمه بستن
        dismissBtn.addEventListener('click', function() {
            hideBanner();
            localStorage.setItem('pwa_install_dismissed', 'permanent');
        });
    }

    // ===== نمایش / مخفی =====
    function showBanner() {
        if (localStorage.getItem('pwa_install_dismissed') === 'permanent') return;
        createBanner();
        requestAnimationFrame(function() {
            if (installBanner) installBanner.style.transform = 'translateY(0)';
        });
    }

    function hideBanner() {
        if (!installBanner) return;
        installBanner.style.transform = 'translateY(100%)';
        setTimeout(function() {
            if (installBanner && installBanner.parentNode) {
                installBanner.parentNode.removeChild(installBanner);
            }
            installBanner = null;
        }, 400);
    }

    // ===== شروع =====
    if (ios) {
        // iOS: بعد از ۳ ثانیه بنر آموزشی نمایش بده
        setTimeout(showBanner, 3000);
    } else {
        // اندروید: منتظر beforeinstallprompt
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;
            setTimeout(showBanner, 2000);
        });

        // لاگ وضعیت نصب
        window.addEventListener('appinstalled', function() {
            console.log('[PWA] اپلیکیشن با موفقیت نصب شد');
            hideBanner();
            localStorage.setItem('pwa_install_dismissed', 'permanent');
        });
    }

    // تشخیص مشکلات رایج
    if (!ios) {
        window.addEventListener('load', function() {
            setTimeout(function() {
                if (!deferredPrompt && !isStandalone()) {
                    // بررسی آیا سرویس ورکر ثبت شده
                    if ('serviceWorker' in navigator) {
                        navigator.serviceWorker.getRegistration().then(function(reg) {
                            if (!reg) {
                                console.warn('[PWA] سرویس ورکر ثبت نشده — بنر نصب نمایش داده نمی‌شود');
                            }
                        });
                    }
                }
            }, 5000);
        });
    }

})();
