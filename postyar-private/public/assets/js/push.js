/**
 * مدیریت پوش ناتیفیکیشن پُست‌یار
 * ثبت اشتراک، درخواست مجوز و نمایش وضعیت
 */
(function () {
    'use strict';

    var baseUrl = (window.postyarBaseUrl || '');

    /**
     * بررسی پشتیبانی مرورگر
     */
    function isSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window;
    }

    /**
     * دریافت کلید VAPID از سرور
     */
    function getVapidKey() {
        return fetch(baseUrl + '/api/push/vapid-key')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.publicKey) throw new Error('VAPID key not available');
                return data.publicKey;
            });
    }

    /**
     * تبدیل base64url به ArrayBuffer
     */
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    /**
     * ثبت اشتراک Push
     */
    function subscribe() {
        if (!isSupported()) {
            return Promise.reject(new Error('مرورگر شما از اعلان‌ها پشتیبانی نمی‌کند.'));
        }

        return Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                throw new Error('اجازه نمایش اعلان داده نشد.');
            }

            return navigator.serviceWorker.ready;
        }).then(function (registration) {
            return getVapidKey().then(function (vapidKey) {
                return registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidKey)
                });
            });
        }).then(function (subscription) {
            var subData = subscription.toJSON();
            return fetch(baseUrl + '/api/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subData),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); });
        }).then(function (result) {
            if (result.success) {
                updateUI(true);
                console.log('[Push] اشتراک ثبت شد');
            }
            return result;
        });
    }

    /**
     * لغو اشتراک Push
     */
    function unsubscribe() {
        if (!isSupported()) return Promise.resolve();

        return navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.getSubscription().then(function (sub) {
                if (sub) return sub.unsubscribe();
                return true;
            });
        }).then(function () {
            return fetch(baseUrl + '/api/push/unsubscribe', {
                method: 'POST',
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); });
        }).then(function (result) {
            if (result.success) {
                updateUI(false);
                console.log('[Push] اشتراک لغو شد');
            }
            return result;
        });
    }

    /**
     * بررسی وضعیت فعلی
     */
    function checkStatus() {
        fetch(baseUrl + '/api/push/status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    updateUI(data.subscribed);
                    window.__pushEnabled = data.enabled;
                }
            })
            .catch(function () {});
    }

    /**
     * بروزرسانی UI دکمه اعلان
     */
    function updateUI(subscribed) {
        var btn = document.getElementById('push-toggle-btn');
        var icon = document.getElementById('push-toggle-icon');
        var label = document.getElementById('push-toggle-label');
        if (!btn) return;

        if (subscribed) {
            btn.classList.add('push-active');
            if (icon) icon.textContent = '🔔';
            if (label) label.textContent = 'اعلان‌ها فعال است';
        } else {
            btn.classList.remove('push-active');
            if (icon) icon.textContent = '🔕';
            if (label) label.textContent = 'فعال‌سازی اعلان‌ها';
        }
    }

    // ─── Initialization ──────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('push-toggle-btn');
        if (!btn || !isSupported()) {
            // مخفی کردن دکمه اگر پشتیبانی نمی‌شود
            if (btn) btn.style.display = 'none';
            return;
        }

        // بارگذاری وضعیت
        checkStatus();

        // رویداد کلیک
        btn.addEventListener('click', function () {
            if (btn.classList.contains('push-active')) {
                unsubscribe();
            } else {
                subscribe().catch(function (err) {
                    alert(err.message || 'خطا در فعال‌سازی اعلان‌ها');
                });
            }
        });
    });

    // Expose for admin panel
    window.PostyarPush = {
        subscribe: subscribe,
        unsubscribe: unsubscribe,
        checkStatus: checkStatus,
        isSupported: isSupported
    };
})();
