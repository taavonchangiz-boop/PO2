/* ==========================================================================
   فایل جاوااسکریپت پنل مدیریت ارشد (Admin Panel JavaScript)
   سامانه مدیریت کانال‌ها و انتشار خودکار پُست‌یار
   ========================================================================== */

/* ===== دراور منوی بیشتر (موبایل) ===== */
function toggleMobileMoreMenu() {
    var overlay = document.getElementById('mobileMoreOverlay');
    var drawer = document.getElementById('mobileMoreDrawer');
    var isOpen = drawer && drawer.classList.contains('open');
    if (isOpen) {
        if (overlay) overlay.classList.remove('active');
        if (drawer) drawer.classList.remove('open');
        document.body.style.overflow = '';
    } else {
        if (overlay) overlay.classList.add('active');
        if (drawer) drawer.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

/* اطمینان از بسته بودن دراور موبایل هنگام بارگذاری */
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    var drawer = document.getElementById('mobileMoreDrawer');
    var overlay = document.getElementById('mobileMoreOverlay');
    if (drawer) drawer.classList.remove('open');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
  });
})();

/* ===== تب‌بندی بخش‌های ادمین ===== */
function switchSection(sectionId) {
    var sections = document.querySelectorAll('.tab-content');
    for (var i = 0; i < sections.length; i++) {
        sections[i].classList.remove('active');
    }
    var targetSec = document.getElementById('section-' + sectionId);
    if (targetSec) {
        targetSec.classList.add('active');
    }
    var menuItems = document.querySelectorAll('.menu-item');
    for (var j = 0; j < menuItems.length; j++) {
        menuItems[j].classList.remove('active');
    }
    var targets = document.querySelectorAll('.menu-item[data-target="' + sectionId + '"]');
    for (var k = 0; k < targets.length; k++) {
        targets[k].classList.add('active');
    }
    // بازآغازی تقویم جلالی هنگام ورود به بخش تبلیغات
    if (sectionId === 'ads' && window.jalaliDatepicker) {
        setTimeout(function() {
            try {
                var adInputs = document.querySelectorAll('#section-ads input[data-jdp]');
                for (var i = 0; i < adInputs.length; i++) {
                    (function(inp) {
                        inp.removeEventListener('focus', inp._jdpFocusHandler);
                        inp._jdpFocusHandler = function() { try { jalaliDatepicker.show(inp); } catch(e) {} };
                        inp.addEventListener('focus', inp._jdpFocusHandler);
                        inp.removeEventListener('click', inp._jdpClickHandler);
                        inp._jdpClickHandler = function() { try { jalaliDatepicker.show(inp); } catch(e) {} };
                        inp.addEventListener('click', inp._jdpClickHandler);
                    })(adInputs[i]);
                }
            } catch(e) {}
        }, 80);
    }
    if (window.SafeStorage && typeof SafeStorage.setItem === 'function') {
        SafeStorage.setItem('last_admin_tab', sectionId);
    } else {
        try { sessionStorage.setItem('last_admin_tab', sectionId); } catch (e) {}
    }
}

/* ===== راه‌اندازی اولیه پنل ادمین ===== */
function initAdminPanel() {
    var menuItems = document.querySelectorAll('.g-sidebar .menu-item');
    for (var i = 0; i < menuItems.length; i++) {
        var item = menuItems[i];
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
    if (query.indexOf('edit_plan') !== -1) {
        switchSection('plans');
        return;
    }
    var lastTab = 'dashboard';
    try {
        if (window.SafeStorage && typeof SafeStorage.getItem === 'function') {
            lastTab = SafeStorage.getItem('last_admin_tab', 'dashboard');
        } else {
            lastTab = sessionStorage.getItem('last_admin_tab') || 'dashboard';
        }
    } catch (e) {}
    if (!document.getElementById('section-' + lastTab)) lastTab = 'dashboard';
    switchSection(lastTab);
}

if (document.readyState !== 'loading') {
    initAdminPanel();
} else {
    window.addEventListener('DOMContentLoaded', initAdminPanel);
}

/* ===== مدال هدیه اشتراک ===== */
function openGiftModal(userId, userName) {
    document.getElementById('giftUserId').value = userId;
    document.getElementById('giftUserName').textContent = userName;
    document.getElementById('giftModal').style.display = 'flex';
}
function closeGiftModal() {
    document.getElementById('giftModal').style.display = 'none';
}

/* ===== مدال پروفایل ۳۶۰ درجه کاربر ===== */
var currentProfileUserId = 0;
var currentProfileUserName = "";

function openUserProfileModal(u) {
    currentProfileUserId = u.id;
    currentProfileUserName = u.name;
    document.getElementById('up-name').textContent = u.name || "کاربر پُست‌یار";
    document.getElementById('up-email').textContent = u.email || "";
    document.getElementById('up-plan').textContent = u.plan_title ? ("💎 " + u.plan_title) : "رایگان / بدون اشتراک";
    document.getElementById('up-created').textContent = toFaDigits(u.created_at_fa || u.created_at || "نامشخص");
    document.getElementById('up-end').textContent = toFaDigits(u.end_date_fa || u.end_date || "بدون انقضا");
    document.getElementById('up-biz-name').textContent = u.business_name || "ثبت نشده";
    document.getElementById('up-biz-type').textContent = u.business_type || "ثبت نشده";
    document.getElementById('up-channels').textContent = toFaDigits(u.channel_count || 0) + " کانال";
    document.getElementById('up-posts').textContent = toFaDigits(u.posts_count || 0) + " پست";
    document.getElementById('up-tickets').textContent = toFaDigits(u.tickets_count || 0) + " تیکت";
    var spent = parseInt(u.total_spent || 0);
    document.getElementById('up-payments').textContent = spent.toLocaleString('fa-IR') + " تومان";
    document.getElementById('userProfileModal').style.display = 'flex';
}

function closeUserProfileModal() {
    document.getElementById('userProfileModal').style.display = 'none';
}

function triggerGiftFromProfile() {
    closeUserProfileModal();
    openGiftModal(currentProfileUserId, currentProfileUserName);
}

/* ===== جستجوی کاربران ===== */
function filterAdminUsers(query) {
    var rows = document.querySelectorAll('#section-users tbody tr');
    var q = query.toLowerCase().trim();
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        rows[i].style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
    }
}

/* ===== مدال تیکت ادمین ===== */
function openAdminTicketModal(t) {
    document.getElementById('at-modal-subject').textContent = t.subject || "تیکت پشتیبانی";
    document.getElementById('at-modal-user').textContent = "کاربر: " + (t.user_name || "") + " (" + (t.user_email || "") + ")";
    document.getElementById('at-reply-id').value = t.id;
    document.getElementById('at-close-id').value = t.id;
    var statusSpan = document.getElementById('at-modal-status');
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
    var bodyDiv = document.getElementById('at-modal-body');
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
            var adminMatch = text.match(/^\[پیام مدیر سیستم \(([^)]+)\) در تاریخ ([^\]]+)\]:\s*([\s\S]*)$/m);
            if (adminMatch) {
                bubble.innerHTML = '<div style="font-size:0.8rem; color:#F5BC82; font-weight:900; margin-bottom:0.4rem;">👑 پیام مدیر سیستم (' + adminMatch[1] + '):</div><div style="font-size:0.7rem; color:#7A7062; margin-bottom:0.5rem;">📅 ' + adminMatch[2] + '</div>' + adminMatch[3].replace(/\n/g, "<br>");
            } else {
                bubble.innerHTML = '<div style="font-size:0.75rem; color:#E9C77E; font-weight:bold; margin-bottom:0.4rem;">👤 پیام کاربر (' + (t.user_name || "کاربر") + '):</div>' + text.replace(/\n/g, "<br>");
            }
        } else {
            bubble.style.background = "linear-gradient(135deg, rgba(214,172,99,0.15) 0%, rgba(10,15,26,0.1) 100%)";
            bubble.style.border = "1px solid #E9C77E";
            bubble.style.color = "#171310";
            var supportMatch = text.match(/^\[پاسخ پشتیبان در تاریخ ([^\]]+)\]:\s*([\s\S]*)$/m);
            var userReplyMatch = text.match(/^\[پاسخ کاربر در تاریخ ([^\]]+)\]:\s*([\s\S]*)$/m);
            var headerHtml = '';
            var bodyText = text;
            if (supportMatch) {
                headerHtml = '<div style="font-size:0.8rem; color:#55C47E; font-weight:900; margin-bottom:0.4rem;">👑 پاسخ پشتیبانی:</div><div style="font-size:0.7rem; color:#7A7062; margin-bottom:0.5rem;">📅 ' + supportMatch[1] + '</div>';
                bodyText = supportMatch[2];
            } else if (userReplyMatch) {
                headerHtml = '<div style="font-size:0.8rem; color:#E9C77E; font-weight:900; margin-bottom:0.4rem;">👤 پاسخ کاربر:</div><div style="font-size:0.7rem; color:#7A7062; margin-bottom:0.5rem;">📅 ' + userReplyMatch[1] + '</div>';
                bodyText = userReplyMatch[2];
            } else {
                headerHtml = '<div style="font-size:0.8rem; color:#55C47E; font-weight:900; margin-bottom:0.4rem;">👑 پاسخ پشتیبانی:</div>';
            }
            bubble.innerHTML = headerHtml + bodyText.replace(/\n/g, "<br>");
        }
        bodyDiv.appendChild(bubble);
    }
    document.getElementById('adminTicketModal').style.display = 'flex';
}

function closeAdminTicketModal() {
    document.getElementById('adminTicketModal').style.display = 'none';
}

/* ===== اصلاح چیدمان: انتقال تب‌های بیرون‌افتاده به داخل main ===== */
(function(){
  try{
    var wrapper = document.querySelector('.wrapper');
    var main = document.querySelector('.wrapper > main');
    if(!wrapper || !main) return;
    var ids = ['section-broadcast','section-bank','section-tickets','section-admin-ai','section-admin-responder','section-admin-woo','section-admin-gold','section-discounts'];
    ids.forEach(function(id){
      var el = document.getElementById(id);
      if(el && el.parentElement !== main){
        main.appendChild(el);
      }
    });
    var bank = document.getElementById('section-bank');
    if(bank){ bank.style.minHeight = '200px'; }
  }catch(e){ console.log('layout fix',e); }
})();

/* ===== وسط‌چین کردن پاپ‌آپ زنگوله در موبایل ===== */
(function(){
  function centerBell(){
    if(window.innerWidth > 768) return;
    var b = document.getElementById('admin-bell-popup');
    if(b && b.style.display !== 'none'){
      b.style.setProperty('position','fixed','important');
      b.style.setProperty('left','50%','important');
      b.style.setProperty('top','50%','important');
      b.style.setProperty('transform','translate(-50%,-50%)','important');
      b.style.setProperty('width','90vw','important');
      b.style.setProperty('max-width','340px','important');
    }
  }
  var btn = document.querySelector('[onclick*="admin-bell-popup"]');
  if(btn) btn.addEventListener('click', function(){ setTimeout(centerBell,10); });
  window.addEventListener('resize', centerBell);
})();

/* ===== کارت آمار تفکیکی داینامیک ===== */
document.addEventListener('DOMContentLoaded', function(){
  var grid = document.querySelector('#section-dashboard .grid-stats');
  if(grid && !document.getElementById('admin-detailed-stats')){
    var card = document.createElement('div');
    card.id = 'admin-detailed-stats';
    card.className = 'card';
    card.style.marginTop = '1.25rem';
    card.innerHTML = 
                    '<h2>📊 آمار تفکیکی انتشارها و بازخوردها</h2>' +
                    '<p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:1rem;">نمایش دقیق بازخورد هر پست به تفکیک کانال — کلیک کل، یکتا و نرخ تعامل (داده‌ها از همین دیتابیس پُست‌یار)</p>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem;">' +
                        '<div style="background:#1E1A14;border:1px solid #1E1A14;border-radius:12px;padding:1rem;text-align:center;"><div style="font-size:0.8rem;color:#DCD3C4;">کل پست‌های ارسالی</div><strong style="color:#AEC4DC;font-size:1.4rem;">' + (document.querySelectorAll('#section-users tbody tr').length || 0) + ' پست</strong></div>' +
                        '<div style="background:#1E1A14;border:1px solid #1E1A14;border-radius:12px;padding:1rem;text-align:center;"><div style="font-size:0.8rem;color:#DCD3C4;">کل کانال‌های فعال</div><strong style="color:#55C47E;font-size:1.4rem;">' + (document.querySelectorAll('#section-plans tbody tr').length || 0) + ' کانال</strong></div>' +
                        '<div style="background:#1E1A14;border:1px solid #1E1A14;border-radius:12px;padding:1rem;text-align:center;"><div style="font-size:0.8rem;color:#DCD3C4;">تیکت‌های باز</div><strong style="color:#F5BC82;font-size:1.4rem;">' + (document.querySelectorAll('#section-tickets tbody tr').length || 0) + ' تیکت</strong></div>' +
                    '</div>' +
                    '<div style="font-size:0.8rem;color:#7A7062;text-align:center;">آمار به صورت زنده از همین جداول محاسبه می‌شود — برای جزئیات هر کانال، تب «مدیریت کاربران» → پروفایل ۳۶۰ درجه را ببینید</div>';
    grid.parentNode.insertBefore(card, grid.nextSibling);
  }
});

/* ===== تنظیمات هوش مصنوعی ادمین (AI Provider/Model Fill) ===== */
(function(){
  var modelsMap = {
    'openai': [
      {v:'gpt-4o', t:'GPT-4o (پرچمدار)'},
      {v:'gpt-4o-mini', t:'GPT-4o-mini (سریع و اقتصادی)'},
      {v:'gpt-4-turbo', t:'GPT-4 Turbo'},
      {v:'gpt-3.5-turbo', t:'GPT-3.5 Turbo'},
      {v:'o1-mini', t:'o1-mini (استدلالی)'},
      {v:'o3-mini', t:'o3-mini (استدلالی پیشرفته)'}
    ],
    'deepseek': [
      {v:'deepseek-chat', t:'DeepSeek V3 (پرچمدار)'},
      {v:'deepseek-reasoner', t:'DeepSeek R1 (استدلالی)'}
    ],
    'anthropic': [
      {v:'claude-sonnet-4-20250514', t:'Claude 4 Sonnet (پرچمدار)'},
      {v:'claude-3-5-sonnet-20241022', t:'Claude 3.5 Sonnet'},
      {v:'claude-3-5-haiku-20241022', t:'Claude 3.5 Haiku (سریع)'}
    ],
    'openrouter': [
      {v:'anthropic/claude-sonnet-4-20250514', t:'Claude 4 Sonnet via OpenRouter'},
      {v:'anthropic/claude-3.5-sonnet', t:'Claude 3.5 Sonnet via OpenRouter'},
      {v:'deepseek/deepseek-chat', t:'DeepSeek V3 via OpenRouter'},
      {v:'deepseek/deepseek-reasoner', t:'DeepSeek R1 via OpenRouter'},
      {v:'meta-llama/llama-3.1-70b-instruct', t:'Llama 3.1 70B'},
      {v:'google/gemini-2.0-flash-001', t:'Gemini 2.0 Flash via OpenRouter'},
      {v:'openai/gpt-4o', t:'GPT-4o via OpenRouter'},
      {v:'google/gemini-pro', t:'Gemini Pro via OpenRouter'}
    ],
    'groq': [
      {v:'llama-3.3-70b-versatile', t:'Llama 3.3 70B Versatile'},
      {v:'llama-3.1-8b-instant', t:'Llama 3.1 8B Instant'},
      {v:'mixtral-8x7b-32768', t:'Mixtral 8x7B'},
      {v:'gemma2-9b-it', t:'Gemma2 9B'}
    ],
    'gemini': [
      {v:'gemini-2.5-pro-preview-05-06', t:'Gemini 2.5 Pro (پیشرفته)'},
      {v:'gemini-2.0-flash', t:'Gemini 2.0 Flash (پیشنهادی)'},
      {v:'gemini-1.5-pro', t:'Gemini 1.5 Pro'},
      {v:'gemini-1.5-flash', t:'Gemini 1.5 Flash'}
    ],
    'custom': []
  };
  var urlsMap = {
    'openai': 'https://api.openai.com/v1/chat/completions',
    'deepseek': 'https://api.deepseek.com/v1/chat/completions',
    'anthropic': 'https://api.anthropic.com/v1/messages',
    'openrouter': 'https://openrouter.ai/api/v1/chat/completions',
    'groq': 'https://api.groq.com/openai/v1/chat/completions',
    'gemini': 'https://generativelanguage.googleapis.com/v1beta/openAI/chat/completions',
    'custom': ''
  };
  function fillModels(provider, saved){
    var sel = document.getElementById('ai-g-model');
    var custom = document.getElementById('ai-g-model-custom');
    var urlInput = document.getElementById('ai-g-url');
    if(!sel) return;
    sel.innerHTML = '';
    if(provider === 'custom'){
      sel.style.display='none';
      if(custom){ custom.style.display='block'; custom.value = saved || ''; custom.focus(); sel.value = custom.value; custom.oninput = function(){ sel.value = this.value; }; }
      if(urlInput){ urlInput.placeholder = 'https://example.com/v1/chat/completions'; }
      return;
    }
    sel.style.display='block';
    if(custom) custom.style.display='none';
    var list = modelsMap[provider] || [];
    list.forEach(function(m){
      var opt=document.createElement('option');
      opt.value=m.v; opt.textContent=m.t;
      sel.appendChild(opt);
    });
    if(saved && list.some(function(m){return m.v===saved;})){
      sel.value = saved;
    } else if(list.length){
      sel.value = list[0].v;
    }
    if(urlInput && urlsMap[provider]) urlInput.value = urlsMap[provider];
  }
  document.addEventListener('DOMContentLoaded', function(){
    var prov = document.getElementById('ai-g-provider');
    var sel = document.getElementById('ai-g-model');
    if(!prov || !sel) return;
    var form = prov.closest('form');
    var savedProvider = form ? (form.getAttribute('data-saved-provider') || 'openai') : 'openai';
    var savedModel = form ? (form.getAttribute('data-saved-model') || '') : '';
    var savedUrl = form ? (form.getAttribute('data-saved-url') || '') : '';
    // تنظیم آدرس ذخیره‌شده
    var urlInput = document.getElementById('ai-g-url');
    if(urlInput && savedUrl) urlInput.value = savedUrl;
    prov.value = savedProvider;
    prov.addEventListener('change', function(){ fillModels(this.value, null); });
    fillModels(prov.value, savedModel);
    var custom = document.getElementById('ai-g-model-custom');
    if(custom){ custom.addEventListener('input', function(){ sel.value = this.value; }); }
    if(form){ form.addEventListener('submit', function(){ if(prov.value==='custom' && custom){ sel.value = custom.value; } }); }
  });
})();

/* ===== رفع برون‌رفت پاپ‌آپ زنگوله در موبایل ===== */
(function(){
  var btn = document.querySelector('[onclick*="admin-bell-popup"]');
  var popup = document.getElementById('admin-bell-popup');
  if(!btn || !popup) return;
  function showPopup(){
    if(window.innerWidth <= 768){
      if(popup.parentElement !== document.body){
        document.body.appendChild(popup);
        popup.style.setProperty('position','fixed','important');
        popup.style.setProperty('left','50%','important');
        popup.style.setProperty('top','50%','important');
        popup.style.setProperty('transform','translate(-50%,-50%)','important');
        popup.style.setProperty('width','90vw','important');
        popup.style.setProperty('max-width','340px','important');
        popup.style.setProperty('z-index','99999','important');
        var backdrop = document.getElementById('admin-bell-backdrop');
        if(!backdrop){
          backdrop = document.createElement('div');
          backdrop.id = 'admin-bell-backdrop';
          backdrop.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:99998; display:none;';
          backdrop.onclick = function(){ popup.style.display='none'; backdrop.style.display='none'; };
          document.body.appendChild(backdrop);
        }
        backdrop.style.display = 'block';
      }
      popup.style.display = 'flex';
    }
  }
  btn.setAttribute('onclick','');
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    if(popup.style.display==='flex' && popup.parentElement===document.body){
      popup.style.display='none';
      var bd=document.getElementById('admin-bell-backdrop');
      if(bd) bd.style.display='none';
      if(window.innerWidth > 768){
        var orig = document.querySelector('header div[style*="position:relative"]');
        if(orig) orig.appendChild(popup);
      }
    } else {
      if(window.innerWidth <= 768) showPopup();
      else {
        popup.style.display = (popup.style.display==='flex'?'none':'flex');
      }
    }
  });
})();

/* ===== راه‌اندازی تقویم شمسی جلالی (ادمین) ===== */
/* توجه: startWatch فقط یک بار در انتهای admin.php فراخوانی می‌شود تا از تداخل گزینه‌ها جلوگیری شود */

/* ===== فیلتر تیکت‌ها بر اساس وضعیت ===== */
function filterTickets(status, btn) {
    var rows = document.querySelectorAll('.ticket-card-row');
    for (var i = 0; i < rows.length; i++) {
        if (status === 'all' || rows[i].getAttribute('data-status') === status) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
    var btns = document.querySelectorAll('.ticket-filter-btn');
    for (var j = 0; j < btns.length; j++) {
        btns[j].classList.remove('active');
    }
    if (btn) btn.classList.add('active');
}

/* ===== تبدیل خودکار اعداد به فارسی ===== */
if(typeof autoConvertToPersianDigits === 'function'){
    if(document.readyState === 'complete' || document.readyState === 'interactive'){
        autoConvertToPersianDigits();
    } else {
        window.addEventListener('DOMContentLoaded', function(){ autoConvertToPersianDigits(); });
    }
}
