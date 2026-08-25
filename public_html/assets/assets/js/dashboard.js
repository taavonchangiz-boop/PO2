/* ==========================================================================
   فایل جاوااسکریپت داشبورد کاربری (Dashboard JavaScript)
   سامانه مدیریت کانال‌ها و انتشار خودکار پُست‌یار
   ========================================================================== */

/* ===== کپی شماره کارت ===== */
function copyCardNumber() {
    var cardNumber = (window.__dashboardSavedCard || '');
    navigator.clipboard.writeText(cardNumber).then(function() {
        var toast = document.getElementById('copy-toast');
        toast.classList.add('show');
        setTimeout(function() {
            toast.classList.remove('show');
        }, 3000);
    });
}

/* ===== پیکر اموجی و استیکر ===== */
function toggleEmojiPicker() {
    var picker = document.getElementById('emoji-popup');
    picker.style.display = (picker.style.display === 'flex') ? 'none' : 'flex';
}

function switchEmojiTab(tabName) {
    var tabs = document.querySelectorAll('.emoji-tab');
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.remove('active');
    }
    var grids = document.querySelectorAll('.emoji-grid');
    for (var j = 0; j < grids.length; j++) {
        grids[j].classList.add('hidden');
    }
    event.target.classList.add('active');
    document.getElementById('emoji-grid-' + tabName).classList.remove('hidden');
}

function insertEmoji(emoji) {
    var textarea = document.getElementById('p-content');
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var text = textarea.value;
    textarea.value = text.substring(0, start) + emoji + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
    document.getElementById('emoji-popup').style.display = 'none';
}

/* ===== نمایش/پنهان فرم زمان‌بندی ===== */
function toggleScheduleInput(val) {
    var group = document.getElementById('schedule-datetime-group');
    if (val === 'scheduled') {
        group.classList.remove('hidden');
    } else {
        group.classList.add('hidden');
    }
}

/* ===== بستن اعلان همگانی ===== */
function closeBroadcastBanner() {
    var banner = document.getElementById('broadcast-alert-banner');
    if (banner) banner.style.display = 'none';
    // علامت‌گذاری خوانده‌شده در سرور
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/mark-announcement-read'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send('csrf_token=' + encodeURIComponent(window.__csrfToken || ''));
}

/* ===== تب‌بندی بخش‌های داشبورد ===== */
function switchSection(sectionId) {
    window.scrollTo(0, 0);
    var sections = document.querySelectorAll('.tab-content');
    for (var i = 0; i < sections.length; i++) {
        sections[i].classList.remove('active');
    }
    var targetSec = document.getElementById('section-' + sectionId);
    if (targetSec) {
        targetSec.classList.add('active');
    }
    var menuItems = document.querySelectorAll('.menu-item, .mobile-nav-item');
    for (var j = 0; j < menuItems.length; j++) {
        menuItems[j].classList.remove('active');
    }
    var targets = document.querySelectorAll('.menu-item[data-target="' + sectionId + '"], .mobile-nav-item[data-target="' + sectionId + '"]');
    for (var k = 0; k < targets.length; k++) {
        targets[k].classList.add('active');
    }
    SafeStorage.setItem('last_tab', sectionId);
}

/* ===== تنظیمات هوش مصنوعی (AI Provider/Model) ===== */
var AI_PROVIDERS = {
    'openai': {
        'url': 'https://api.openai.com/v1/chat/completions',
        'models': ['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo']
    },
    'gemini': {
        'url': 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        'models': ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']
    },
    'groq': {
        'url': 'https://api.groq.com/openai/v1/chat/completions',
        'models': ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'llama3-70b-8192']
    },
    'deepseek': {
        'url': 'https://api.deepseek.com/chat/completions',
        'models': ['deepseek-chat', 'deepseek-reasoner']
    },
    'mistral': {
        'url': 'https://api.mistral.ai/v1/chat/completions',
        'models': ['mistral-large-latest', 'open-mistral-nemo']
    },
    'together': {
        'url': 'https://api.together.xyz/v1/chat/completions',
        'models': ['meta-llama/Llama-3.3-70B-Instruct-Turbo', 'Qwen/Qwen2.5-72B-Instruct-Turbo']
    },
    'ollama': {
        'url': 'http://localhost:11434/v1/chat/completions',
        'models': ['llama3.2', 'qwen2.5', 'mistral']
    }
};

function onAiProviderChange(providerKey) {
    var provider = AI_PROVIDERS[providerKey];
    var urlInput = document.getElementById('ai-url-input');
    var modelSelect = document.getElementById('ai-model-select');
    if (provider) {
        if (urlInput) urlInput.value = provider.url;
        if (modelSelect) {
            modelSelect.innerHTML = '';
            for (var i = 0; i < provider.models.length; i++) {
                var opt = document.createElement('option');
                opt.value = provider.models[i];
                opt.textContent = provider.models[i];
                modelSelect.appendChild(opt);
            }
            var customOpt = document.createElement('option');
            customOpt.value = 'custom';
            customOpt.textContent = '-- مدل دلخواه --';
            modelSelect.appendChild(customOpt);
            onAiModelChange(modelSelect.value);
        }
    }
}

function onAiModelChange(modelVal) {
    var customGroup = document.getElementById('ai-custom-model-group');
    var customInput = document.getElementById('ai-model-custom-input');
    var hiddenInput = document.getElementById('ai-model-hidden');
    if (modelVal === 'custom') {
        if (customGroup) customGroup.classList.remove('hidden');
        if (hiddenInput && customInput) hiddenInput.value = customInput.value;
    } else {
        if (customGroup) customGroup.classList.add('hidden');
        if (hiddenInput) hiddenInput.value = modelVal;
    }
}

/* ===== راه‌اندازی اولیه داشبورد ===== */
function initDashboard() {
    var clickableItems = document.querySelectorAll('.menu-item, .mobile-nav-item');
    for (var i = 0; i < clickableItems.length; i++) {
        var item = clickableItems[i];
        var target = item.getAttribute('data-target');
        if (target) {
            item.addEventListener('click', function(e) {
                var clickedItem = e.currentTarget;
                var sectionId = clickedItem.getAttribute('data-target');
                switchSection(sectionId);
            });
        }
    }
    var query = window.location.search || '';
    if (query.indexOf('edit_channel') !== -1) {
        switchSection('channels');
        return;
    }
    var lastTab = SafeStorage.getItem('last_tab', 'dashboard');
    switchSection(lastTab);
}

if (document.readyState !== 'loading') {
    initDashboard();
} else {
    window.addEventListener('DOMContentLoaded', initDashboard);
}

/* ===== دراور منوی بیشتر (موبایل) ===== */
function toggleMobileMoreMenu() {
    var overlay = document.getElementById('mobileMoreOverlay');
    var drawer = document.getElementById('mobileMoreDrawer');
    var isOpen = drawer.classList.contains('open');
    if (isOpen) {
        overlay.classList.remove('active');
        drawer.classList.remove('open');
        document.body.style.overflow = '';
    } else {
        overlay.classList.add('active');
        drawer.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

/* ===== بستن پاپ‌آپ اموجی با کلیک در خارج از کادر ===== */
window.addEventListener('click', function(event) {
    var popup = document.getElementById('emoji-popup');
    var btn = document.querySelector('.emoji-picker-btn');
    if (popup && event.target !== popup && !popup.contains(event.target) && event.target !== btn) {
        popup.style.display = 'none';
    }
});

/* ===== انتخاب پلن و نمایش فرم پرداخت ===== */
function selectPlan(id, title, price, paymentUrl) {
    // جلوگیری از انتخاب پلن فعلی
    var card = document.getElementById('plan-card-' + id);
    if (card && card.getAttribute('data-current-plan') === '1') {
        return;
    }
    // حذف حالت انتخاب از تمام کارت‌های پلن و بازگردانی دکمه‌ها
    var allCards = document.querySelectorAll('.plan-card');
    var allBtns = document.querySelectorAll('.plan-select-btn');
    for (var i = 0; i < allCards.length; i++) {
        allCards[i].style.outline = 'none';
        allCards[i].style.outlineOffset = '0';
        allCards[i].style.boxShadow = '';
        allCards[i].style.position = '';
    }
    for (var j = 0; j < allBtns.length; j++) {
        allBtns[j].textContent = 'انتخاب این پلن';
        allBtns[j].style.background = 'linear-gradient(135deg, #55C47E 0%, #82D9A2 100%)';
    }
    // قفل کردن پلن انتخاب‌شده
    var selectedCard = document.getElementById('plan-card-' + id);
    var selectedBtn = document.getElementById('plan-btn-' + id);
    if (selectedCard) {
        selectedCard.style.outline = '3px solid #55C47E';
        selectedCard.style.outlineOffset = '3px';
        selectedCard.style.boxShadow = '0 0 30px rgba(85,196,126,0.5)';
        selectedCard.style.position = 'relative';
    }
    if (selectedBtn) {
        selectedBtn.textContent = '✅ این پلن انتخاب شد (قفل شده)';
        selectedBtn.style.background = 'linear-gradient(135deg, #82D9A2 0%, #82D9A2 100%)';
    }
    document.getElementById('payment-box').classList.remove('hidden');
    document.getElementById('sel-title').textContent = title;
    document.getElementById('sel-price').textContent = price.toLocaleString('fa-IR');
    document.getElementById('form-plan-id').value = id;
    document.getElementById('form-amount').value = price;
    var onlinePayDiv = document.getElementById('online-pay-div');
    var onlinePayLink = document.getElementById('online-pay-link');
    if (paymentUrl && paymentUrl.trim() !== '') {
        onlinePayDiv.classList.remove('hidden');
        onlinePayLink.href = paymentUrl;
    } else {
        onlinePayDiv.classList.add('hidden');
        onlinePayLink.href = "#";
    }
    document.getElementById('payment-box').scrollIntoView({ behavior: 'smooth' });
}

/* ===== مدال گفتگو و مدیریت تیکت ===== */
function openTicketModal(t) {
    document.getElementById('t-modal-subject').textContent = t.subject || "تیکت پشتیبانی";
    document.getElementById('t-reply-id').value = t.id;
    document.getElementById('t-close-id').value = t.id;
    var statusSpan = document.getElementById('t-modal-status');
    if (t.status === 'open') {
        statusSpan.className = "badge badge-pending";
        statusSpan.textContent = "در انتظار پاسخ ⏳";
    } else if (t.status === 'replied') {
        statusSpan.className = "badge badge-success";
        statusSpan.textContent = "پاسخ داده شده ✔";
    } else {
        statusSpan.className = "badge badge-telegram";
        statusSpan.textContent = "بسته شده";
    }
    var bodyDiv = document.getElementById('t-modal-body');
    bodyDiv.innerHTML = "";
    var rawText = t.message || "";
    var parts = rawText.split("➖➖➖➖➖➖➖➖➖➖");
    for (var i = 0; i < parts.length; i++) {
        var text = parts[i].trim();
        if (!text) continue;
        var bubble = document.createElement('div');
        bubble.style.padding = "1rem";
        bubble.style.borderRadius = "12px";
        bubble.style.lineHeight = "1.8";
        bubble.style.fontSize = "0.9rem";
        if (i === 0) {
            bubble.style.background = "#1E1A14";
            bubble.style.border = "1px solid #2B241B";
            bubble.style.color = "#F5EFE3";
            // بررسی پیام ادمین (تیکت ایجاد شده توسط ادمین)
            var adminMatch = text.match(/^\[پیام مدیر سیستم \(([^)]+)\) در تاریخ ([^\]]+)\]:\s*([\s\S]*)$/m);
            if (adminMatch) {
                bubble.innerHTML = '<div style="font-size:0.8rem; color:#F5BC82; font-weight:900; margin-bottom:0.4rem;">👑 پیام مدیر سیستم (' + adminMatch[1] + '):</div><div style="font-size:0.7rem; color:#7A7062; margin-bottom:0.5rem;">📅 ' + adminMatch[2] + '</div>' + adminMatch[3].replace(/\n/g, "<br>");
            } else {
                bubble.innerHTML = '<div style="font-size:0.75rem; color:#E9C77E; font-weight:bold; margin-bottom:0.4rem;">👤 پیام شما:</div>' + text.replace(/\n/g, "<br>");
            }
        } else {
            bubble.style.background = "linear-gradient(135deg, rgba(214,172,99,0.15) 0%, rgba(10,15,26,0.1) 100%)";
            bubble.style.border = "1px solid #E9C77E";
            bubble.style.color = "#171310";
            // استخراج تاریخ و نوع پاسخ از براکت
            var supportMatch = text.match(/^\[پاسخ پشتیبان در تاریخ ([^\]]+)\]:\s*([\s\S]*)$/m);
            var userReplyMatch = text.match(/^\[پاسخ کاربر در تاریخ ([^\]]+)\]:\s*([\s\S]*)$/m);
            var headerHtml = '';
            var bodyText = text;
            if (supportMatch) {
                headerHtml = '<div style="font-size:0.8rem; color:#55C47E; font-weight:900; margin-bottom:0.4rem;">👑 پاسخ کارشناس پشتیبانی پُست‌یار:</div><div style="font-size:0.7rem; color:#7A7062; margin-bottom:0.5rem;">📅 ' + supportMatch[1] + '</div>';
                bodyText = supportMatch[2];
            } else if (userReplyMatch) {
                headerHtml = '<div style="font-size:0.8rem; color:#E9C77E; font-weight:900; margin-bottom:0.4rem;">👤 پاسخ شما:</div><div style="font-size:0.7rem; color:#7A7062; margin-bottom:0.5rem;">📅 ' + userReplyMatch[1] + '</div>';
                bodyText = userReplyMatch[2];
            } else {
                headerHtml = '<div style="font-size:0.8rem; color:#55C47E; font-weight:900; margin-bottom:0.4rem;">👑 پاسخ کارشناس پشتیبانی پُست‌یار:</div>';
            }
            bubble.innerHTML = headerHtml + bodyText.replace(/\n/g, "<br>");
        }
        bodyDiv.appendChild(bubble);
    }
    document.getElementById('ticketModal').style.display = 'flex';
}

function closeTicketModal() {
    document.getElementById('ticketModal').style.display = 'none';
}

/* ===== دکمه بلو بانک (Deep Link) ===== */
function openBluBank() {
    // لیست URL scheme‌های ممکن بلو بانک — به ترتیب اولویت
    var schemes = [
        'blubank://transfer',
        'blu://transfer',
        'blubank://main',
        'intent://transfer#Intent;scheme=blubank;package=ir.blubank.android;end'
    ];
    
    for (var i = 0; i < schemes.length; i++) {
        try {
            var started = window.open(schemes[i], '_self');
            // اگر window.open null برگرداند یعنی پاپ‌آپ بلاک شده
            if (!started) {
                window.location.href = schemes[i];
            }
            return;
        } catch (e) {
            continue;
        }
    }
    
    // اگر هیچ scheme‌ای کار نکرد
    alert('اپلیکیشن بلو بانک روی دستگاه شما نصب نیست. لطفاً ابتدا آن را از بازار یا گوگل‌پلی نصب کنید.');
}

/* ===== لغو/حذف پست زمان‌بندی‌شده ===== */
function cancelPost(postId, btnElement) {
    if (!confirm('آیا مطمئن هستید که می‌خواهید این پست را لغو و حذف کنید؟')) {
        return;
    }

    // غیرفعال کردن دکمه حین پردازش
    btnElement.disabled = true;
    btnElement.textContent = '⏳ در حال لغو...';

    var formData = 'post_id=' + encodeURIComponent(postId) + '&csrf_token=' + encodeURIComponent(window.__csrfToken || '');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/cancel-post'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    var row = document.getElementById('queue-row-' + postId);
                    if (row) {
                        row.style.transition = 'opacity 0.3s, transform 0.3s';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(20px)';
                        setTimeout(function() {
                            row.remove();
                            // بررسی خالی بودن کارت صف
                            var tbody = row.parentNode;
                            if (tbody && tbody.rows.length === 0) {
                                var card = tbody.closest('.card');
                                if (card) {
                                    card.style.transition = 'opacity 0.3s';
                                    card.style.opacity = '0';
                                    setTimeout(function() { card.remove(); }, 300);
                                }
                            }
                        }, 300);
                    }
                } else {
                    alert(res.message || 'خطا در لغو پست');
                    btnElement.disabled = false;
                    btnElement.textContent = '🗑 لغو و حذف';
                }
            } catch (e) {
                alert('خطای سیستمی در ارتباط با سرور');
                btnElement.disabled = false;
                btnElement.textContent = '🗑 لغو و حذف';
            }
        }
    };
    xhr.send(formData);
}

/* ===== پردازش صف پست‌ها (AJAX) ===== */
var postQueuePollTimer = null;
var postQueuePollCount = 0;
function processPostQueue() {
    if (postQueuePollCount >= 5) return; // حداکثر ۵ بار تلاش
    postQueuePollCount++;
    var formData = 'csrf_token=' + encodeURIComponent(window.__csrfToken || '');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/api/process-post-queue'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.message === 'no_queued_posts') {
                    postQueuePollCount = 5; // توقف
                } else {
                    // اگر پست دیگری در صف بود، ۲ ثانیه بعد دوباره تلاش
                    postQueuePollTimer = setTimeout(processPostQueue, 2000);
                }
            } catch (e) {
                // خطا — متوقف
            }
        }
    };
    xhr.send(formData);
}
// اجرای خودکار پردازش صف هنگام لود داشبورد
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(processPostQueue, 1500);
} else {
    window.addEventListener('DOMContentLoaded', function() { setTimeout(processPostQueue, 1500); });
}

/* ===== پاسخگوی هوشمند — AJAX ===== */
function addAutoReplyAjax() {
    var channel_id = document.getElementById('ar-channel').value;
    var keyword = document.getElementById('ar-keyword').value.trim();
    var reply_text = document.getElementById('ar-reply').value.trim();
    if (!channel_id || !keyword || !reply_text) { alert('تمامی فیلدها الزامی هستند.'); return; }

    var formData = 'channel_id=' + encodeURIComponent(channel_id) + '&keyword=' + encodeURIComponent(keyword) + '&reply_text=' + encodeURIComponent(reply_text) + '&csrf_token=' + encodeURIComponent(window.__csrfToken || '');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/add-auto-reply'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            alert('قالب پاسخگویی با موفقیت اضافه شد. 🤖');
            window.location.reload();
        }
    };
    xhr.send(formData);
}

function deleteAutoReplyAjax(id) {
    if (!confirm('آیا مطمئن هستید؟')) return;
    var row = document.getElementById('ar-row-' + id);
    if (row) row.style.opacity = '0.3';

    var formData = 'reply_id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(window.__csrfToken || '');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/delete-auto-reply'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (row) {
                row.style.transition = 'opacity 0.3s';
                setTimeout(function() { row.remove(); }, 300);
            }
        }
    };
    xhr.send(formData);
}

function toggleResponder(channelId, enabled) {
    var formData = 'channel_id=' + encodeURIComponent(channelId) + '&enabled=' + (enabled ? '1' : '0') + '&csrf_token=' + encodeURIComponent(window.__csrfToken || '');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/toggle-responder'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    var label = document.getElementById('toggle-label-' + channelId);
    var track = label ? label.querySelector('.toggle-track') : null;
    var thumb = label ? label.querySelector('.toggle-thumb') : null;
    var parentCard = label ? label.closest('div[style*="border"]') : null;
    
    // به‌روزرسانی فوری ظاهر
    if (track) track.style.background = enabled ? '#55C47E' : '#3A3025';
    if (thumb) thumb.style.left = enabled ? '25px' : '3px';
    if (parentCard) parentCard.style.borderColor = enabled ? '#55C47E' : '#2B241B';
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (!res.success) {
                    // برگرداندن به وضعیت قبلی در صورت خطا
                    if (track) track.style.background = enabled ? '#3A3025' : '#55C47E';
                    if (thumb) thumb.style.left = enabled ? '3px' : '25px';
                    if (parentCard) parentCard.style.borderColor = '#2B241B';
                    var cb = label ? label.querySelector('input[type="checkbox"]') : null;
                    if (cb) cb.checked = !enabled;
                    alert(res.message || 'خطا در تغییر وضعیت');
                }
            } catch(e) { console.log('toggle error', e); }
        }
    };
    xhr.send(formData);
}

/* ===== قلب تپنده: Polling پیام‌ها + پست زمان‌بندی ===== */
function postyarHeartbeat() {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/api/heartbeat'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send('csrf_token=' + encodeURIComponent(window.__csrfToken || ''));
}

/* ===== باز کردن اعلان زنگوله + علامت‌گذاری خوانده‌شده ===== */
function toggleBellPopup() {
    var popup = document.getElementById('user-bell-popup');
    var isOpen = popup.style.display === 'flex';
    popup.style.display = isOpen ? 'none' : 'flex';
}

// بستن پاپ‌آپ زنگوله با کلیک در خارج
window.addEventListener('click', function(event) {
    var wrapper = document.getElementById('bell-wrapper');
    var popup = document.getElementById('user-bell-popup');
    if (wrapper && popup && !wrapper.contains(event.target)) {
        popup.style.display = 'none';
    }
});

function openNotification(notifId, targetSection) {
    // علامت‌گذاری خوانده‌شده
    var formData = 'notification_id=' + encodeURIComponent(notifId) + '&csrf_token=' + encodeURIComponent(window.__csrfToken || '');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/mark-notification-read'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(formData);

    // بروزرسانی ظاهری آیتم
    var item = document.getElementById('notif-item-' + notifId);
    if (item) {
        item.style.background = 'transparent';
        item.style.borderRightColor = 'transparent';
        var dot = item.querySelector('span[style*="border-radius:50%"]');
        if (dot) dot.style.display = 'none';
        var titleDiv = item.querySelector('div > div:first-child');
        if (titleDiv) titleDiv.style.fontWeight = '400';
    }

    // بستن پاپ‌آپ
    document.getElementById('user-bell-popup').style.display = 'none';

    // بروزرسانی بج
    var badge = document.getElementById('bell-badge');
    if (badge) {
        var currentCount = parseInt(badge.textContent.replace(/[^0-9]/g, '')) || 0;
        if (currentCount <= 1) {
            badge.style.display = 'none';
        } else {
            badge.textContent = (currentCount - 1).toLocaleString('fa-IR');
        }
    }

    // ناوبری به بخش مربوطه
    if (targetSection && targetSection.trim() !== '') {
        switchSection(targetSection);
    }
}

function markAllNotificationsRead(btnElement) {
    if (btnElement) { btnElement.disabled = true; btnElement.textContent = '⏳'; }
    var formData = 'csrf_token=' + encodeURIComponent(window.__csrfToken || '');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/mark-all-notifications-read'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            // علامت‌گذاری بصری همه آیتم‌ها
            var items = document.querySelectorAll('.notif-list-item');
            for (var i = 0; i < items.length; i++) {
                items[i].style.background = 'transparent';
                items[i].style.borderRightColor = 'transparent';
            }
            // پاک کردن بج
            var badge = document.getElementById('bell-badge');
            if (badge) badge.style.display = 'none';
            // مخفی کردن دکمه
            if (btnElement) btnElement.style.display = 'none';
        }
    };
    xhr.send(formData);
}

function openAnnouncement(el) {
    document.getElementById('user-bell-popup').style.display = 'none';
    var badge = document.getElementById('bell-badge');
    if (badge) badge.style.display = 'none';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/mark-announcement-read'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send('csrf_token=' + encodeURIComponent(window.__csrfToken || ''));
}

/* ===== ذخیره تنظیمات طلا — AJAX (بدون رفرش صفحه) ===== */
function saveGoldSettingsAjax(form) {
    var btn = document.getElementById('gold-save-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ در حال ذخیره...'; }
    
    var fd = new FormData(form);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/save-gold-settings'), true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (btn) { btn.disabled = false; btn.textContent = 'ذخیره تنظیمات ربات طلا 🪙'; }
            showToast('تنظیمات ربات طلا با موفقیت ذخیره شد. 🪙✔');
        }
    };
    xhr.send(fd);
}

/* ===== ذخیره تنظیمات پیشرفته — AJAX (بدون رفرش صفحه) ===== */
function saveAdvancedSettingsAjax(form) {
    var btn = document.getElementById('adv-save-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ در حال ذخیره...'; }
    
    var fd = new FormData(form);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.postyarBaseUrl + '/index.php?route=' + encodeURIComponent('/dashboard/save-advanced-settings'), true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (btn) { btn.disabled = false; btn.textContent = 'ذخیره تنظیمات پیشرفته و اتوماسیون پُست‌یار 💾✔'; }
            showToast('تنظیمات پیشرفته با موفقیت ذخیره شد. ✔🤖');
        }
    };
    xhr.send(fd);
}

/* ===== توستر ساده ===== */
function showToast(msg) {
    var existing = document.getElementById('ajax-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.id = 'ajax-toast';
    toast.textContent = msg;
    toast.style.cssText = 'position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#55C47E,#82D9A2);color:#fff;padding:0.75rem 1.5rem;border-radius:12px;font-size:0.85rem;font-weight:700;z-index:99999;box-shadow:0 8px 25px rgba(0,0,0,0.5);opacity:0;transition:opacity 0.3s;font-family:inherit;';
    document.body.appendChild(toast);
    setTimeout(function(){ toast.style.opacity = '1'; }, 10);
    setTimeout(function(){ toast.style.opacity = '0'; setTimeout(function(){ toast.remove(); }, 300); }, 3000);
}

/* ===== تبدیل خودکار اعداد به فارسی ===== */
if(typeof autoConvertToPersianDigits === 'function'){
    if(document.readyState === 'complete' || document.readyState === 'interactive'){
        autoConvertToPersianDigits();
    } else {
        window.addEventListener('DOMContentLoaded', function(){ autoConvertToPersianDigits(); });
    }
}
