/* ============================================================
   پُست‌یار × Gentelella v4 — رفتارهای پوسته
   ۱) دکمه لبه‌ای سایدبار: جمع/باز (rail) در دسکتاپ + ذخیره وضعیت
   ۲) برگر سه‌بعدی: فقط موبایل/تبلت — باز/بسته نرم شیت منو + مورف آیکون
   ۳) نوار تب پایین: اتصال عمومی
   صرفاً لایه نمایشی؛ هیچ منطق برنامه‌ای تغییر نمی‌کند.
   ============================================================ */
(function () {
  'use strict';
  var RAIL_KEY = 'postyar:sidebar-rail';
  var body = document.body;
  var sidebar = document.querySelector('.g-sidebar');
  var railToggle = document.querySelector('.g-rail-toggle');
  var burger = document.querySelector('.gt-burger');

  function isDesktop() {
    return window.matchMedia('(min-width: 993px)').matches;
  }

  /* ---------- آیکون برگر: همگام‌سازی با وضعیت واقعی شیت‌ها ---------- */
  function syncBurger() {
    if (!burger) return;
    var open = !!(document.querySelector('#mobileMoreDrawer.open') || document.querySelector('.drawer-menu.show'));
    burger.classList.toggle('is-open', open);
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? 'بستن منو' : 'باز کردن منو');
  }

  /* ---------- باز/بسته کردن شیت منوی پنل ---------- */
  function toggleMenuSheet() {
    if (typeof window.toggleMobileMoreMenu === 'function') {
      window.toggleMobileMoreMenu();          // داشبورد کاربر
    } else {
      var hb = document.querySelector('.hamburger-btn');  // ادمین
      if (hb) hb.click();
    }
    setTimeout(syncBurger, 30);
  }

  /* ---------- Rail (دسکتاپ) ---------- */
  function applyRailLabels() {
    if (!sidebar) return;
    sidebar.querySelectorAll('.menu-item').forEach(function (item) {
      var text = item.querySelector('.nav-text');
      if (text && text.textContent.trim()) {
        item.setAttribute('data-rail-label', text.textContent.trim());
      }
    });
  }
  function setRail(on) {
    body.classList.toggle('sidebar-rail', on);
    if (railToggle) railToggle.setAttribute('aria-label', on ? 'باز کردن منو' : 'جمع کردن منو');
    if (on) applyRailLabels();
  }

  if (railToggle) {
    railToggle.addEventListener('click', function () {
      if (!isDesktop()) return;
      var on = !body.classList.contains('sidebar-rail');
      setRail(on);
      try { sessionStorage.setItem(RAIL_KEY, on ? '1' : '0'); } catch (e) { /* ignore */ }
    });
  }

  /* ---------- برگر (موبایل/تبلت فقط؛ در دسکتاپ مخفی است) ---------- */
  if (burger) {
    burger.addEventListener('click', function () {
      if (isDesktop()) return;
      toggleMenuSheet();
    });
  }

  /* همگام‌سازی زنده آیکون برگر با شیت‌ها (حتی از طریق دکمه «بیشتر») */
  ['mobileMoreDrawer', 'drawer-menu'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    try {
      new MutationObserver(syncBurger).observe(el, { attributes: true, attributeFilter: ['class'] });
    } catch (e) { /* ignore */ }
  });
  document.addEventListener('click', function (e) {
    if (e.target.closest && (e.target.closest('.mobile-more-overlay') || e.target.closest('.drawer-overlay'))) {
      setTimeout(syncBurger, 30);
    }
  }, true);

  /* ---------- بازیابی وضعیت rail (دسکتاپ) ---------- */
  var saved = '0';
  try { saved = sessionStorage.getItem(RAIL_KEY) || '0'; } catch (e) { /* ignore */ }
  if (saved === '1' && isDesktop()) setRail(true);

  /* ---------- نوار تب پایین: اتصال عمومی (ادمین binder اختصاصی ندارد) ---------- */
  document.addEventListener('click', function (e) {
    var item = e.target.closest ? e.target.closest('.mobile-nav-item[data-target]') : null;
    if (!item) return;
    var id = item.getAttribute('data-target');
    if (window.switchSection) { try { window.switchSection(id); } catch (err) {} }
    var items = document.querySelectorAll('.mobile-nav-item');
    for (var i = 0; i < items.length; i++) items[i].classList.remove('active');
    item.classList.add('active');
  });

  /* ---------- breadcrumb با کلیک آیتم‌های سایدبار (delegation) ---------- */
  var crumb = document.getElementById('g-crumb') || document.querySelector('.breadcrumb .current');
  if (crumb && sidebar) {
    sidebar.addEventListener('click', function (e) {
      var item = e.target.closest ? e.target.closest('.menu-item') : null;
      if (!item) return;
      var t = item.querySelector('.nav-text');
      if (t) setTimeout(function () { crumb.textContent = t.textContent.trim(); }, 0);
    });
  }
})();
