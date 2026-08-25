/* ==========================================================================
   فایل توابع مشترک و قابل استفاده مجدد (Shared Utility Functions)
   سامانه مدیریت کانال‌ها و انتشار خودکار پُست‌یار
   ========================================================================== */

/* کنترلر ذخیره‌سازی ایمن جهت ممانعت از کرش در مرورگرهای قدیمی و پرایوت */
var SafeStorage = {
    getItem: function(key, defaultValue) {
        try {
            return sessionStorage.getItem(key) || defaultValue;
        } catch (e) {
            return defaultValue;
        }
    },
    setItem: function(key, value) {
        try {
            sessionStorage.setItem(key, value);
        } catch (e) {
            // نادیده گرفتن خطای پرایوت مرورگر
        }
    }
};

/* جابه‌جایی امن بین بخش‌های پنل؛ مستقل از فایل‌های صفحه */
if (typeof window.switchSection !== 'function') {
    window.switchSection = function(sectionId) {
        if (!sectionId) return false;
        try {
            var target = document.getElementById('section-' + sectionId);
            if (!target) return false;
            var sections = document.querySelectorAll('.tab-content');
            for (var i = 0; i < sections.length; i++) sections[i].classList.remove('active');
            target.classList.add('active');
            var items = document.querySelectorAll('.menu-item, .mobile-nav-item, .mobile-more-item');
            for (var j = 0; j < items.length; j++) items[j].classList.remove('active');
            if (window.SafeStorage) window.SafeStorage.setItem('last_tab', sectionId);
            return true;
        } catch (e) {
            console.error('[Postyar] switchSection:', e);
            return false;
        }
    };
}

/* تبدیل اعداد لاتین به فارسی */
function toFaDigits(num) {
    var str = num.toString();
    var fa = ['\u06F0','\u06F1','\u06F2','\u06F3','\u06F4','\u06F5','\u06F6','\u06F7','\u06F8','\u06F9'];
    return str.replace(/[0-9]/g, function(w){ return fa[+w]; });
}

/* استخراج بخش تاریخ از رشته کامل datetime */
function toPersianDateStr(dtStr) {
    if (!dtStr || dtStr.indexOf('2099') !== -1) return "\u0628\u062F\u0648\u0646 \u0627\u0646\u0642\u0636\u0627 / \u062F\u0627\u0626\u0645\u06CC";
    return dtStr.split(' ')[0];
}

/* حذف خودکار اعلان (Alert Toast Auto-Dismiss) */
function autoDismissAlert(elementId, timeoutMs) {
    timeoutMs = timeoutMs || 5000;
    setTimeout(function() {
        var toast = document.getElementById(elementId);
        if (toast) {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.6s ease';
            setTimeout(function() { toast.style.display = 'none'; }, 600);
        }
    }, timeoutMs);
}

/* تبدیل خودکار تمام اعداد لاتین نمایشی به فارسی */
function autoConvertToPersianDigits() {
    var walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
    var node;
    var skips = ['SCRIPT', 'STYLE', 'INPUT', 'TEXTAREA', 'CODE', 'PRE'];
    while (node = walk.nextNode()) {
        if (skips.indexOf(node.parentElement.tagName) !== -1) continue;
        if (skips.indexOf(node.parentNode.tagName) !== -1) continue;
        var original = node.nodeValue;
        if (/[0-9]/.test(original)) {
            node.nodeValue = toFaDigits(original);
        }
    }
}
