/* ==========================================================================
   فایل جاوااسکریپت صفحه اصلی لندینگ (Home Page JavaScript)
   سامانه مدیریت کانال‌ها و انتشار خودکار پُست‌یار
   ========================================================================== */

/* ===== مدال‌های احراز هویت ===== */
function openModal(id) {
    document.querySelectorAll('.modal').forEach(function(m) { m.classList.remove('show'); });
    var target = document.getElementById('modal-' + id);
    if (target) target.classList.add('show');
}
function closeModal(id) {
    var target = document.getElementById('modal-' + id);
    if (target) target.classList.remove('show');
}
window.addEventListener('click', function(e) {
    document.querySelectorAll('.modal').forEach(function(m) {
        if (e.target === m) m.classList.remove('show');
    });
});

/* ===== منوی موبایل ===== */
var mobileToggle = document.getElementById('mobileToggle');
var mobileClose = document.getElementById('mobileClose');
var mobileMenu = document.getElementById('mobileMenu');

if (mobileToggle && mobileMenu) {
    mobileToggle.addEventListener('click', function() {
        mobileMenu.classList.remove('hidden');
        mobileMenu.classList.add('flex');
    });
}
if (mobileClose && mobileMenu) {
    mobileClose.addEventListener('click', function() {
        mobileMenu.classList.add('hidden');
        mobileMenu.classList.remove('flex');
    });
}
function closeMobileMenu() {
    if (mobileMenu) {
        mobileMenu.classList.add('hidden');
        mobileMenu.classList.remove('flex');
    }
}

/* ===== آکاردئون سوالات متداول ===== */
document.querySelectorAll('.faq-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var item = btn.parentElement;
        var isOpen = item.classList.contains('open');
        document.querySelectorAll('.faq-item').forEach(function(el) { el.classList.remove('open'); });
        if (!isOpen) item.classList.add('open');
    });
});

/* ===== Scroll Reveal ===== */
var revealElements = document.querySelectorAll('.reveal');
var revealObserver = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
        if (entry.isIntersecting) {
            entry.target.classList.add('active');
        }
    });
}, { threshold: 0.1 });
revealElements.forEach(function(el) { revealObserver.observe(el); });
