/* Postyar landing UI controller v4 */
(function(){
  'use strict';
  var byId=function(id){return document.getElementById(id)};
  var qs=function(s){return document.querySelector(s)};
  function bodyLock(on){document.documentElement.classList.toggle('ui-overlay-open',on);document.body.classList.toggle('ui-overlay-open',on)}
  function closeAll(){document.querySelectorAll('.modal.show').forEach(function(m){m.classList.remove('show');m.setAttribute('aria-hidden','true')});bodyLock(false)}
  window.closeModal=function(id){var m=byId('modal-'+id);if(m){m.classList.remove('show');m.setAttribute('aria-hidden','true')}bodyLock(!!qs('.modal.show'))};
  window.openModal=function(id){var m=byId('modal-'+id);if(!m){return false}document.querySelectorAll('.modal.show').forEach(function(x){x.classList.remove('show');x.setAttribute('aria-hidden','true')});m.classList.add('show');m.setAttribute('aria-hidden','false');bodyLock(true);var first=m.querySelector('input,select,textarea,button');if(first)setTimeout(function(){try{first.focus({preventScroll:true})}catch(e){}},30);return false};
  function mobileMenu(open){var m=byId('mobileMenu'),b=byId('mobileToggle');if(!m)return;m.classList.toggle('hidden',!open);m.classList.toggle('is-open',open);m.setAttribute('aria-hidden',open?'false':'true');if(b)b.setAttribute('aria-expanded',open?'true':'false');bodyLock(open||!!qs('.modal.show'))}
  window.closeMobileMenu=function(){mobileMenu(false)};
  window.toggleMobileMenu=function(){var m=byId('mobileMenu');mobileMenu(m?!m.classList.contains('is-open'):true)};
  function init(){
    var b=byId('mobileToggle'),c=byId('mobileClose'),m=byId('mobileMenu');
    if(b)b.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();window.toggleMobileMenu()});
    if(c)c.addEventListener('click',function(e){e.preventDefault();mobileMenu(false)});
    if(m)m.addEventListener('click',function(e){if(e.target===m)mobileMenu(false)});
    document.querySelectorAll('.modal').forEach(function(x){x.setAttribute('aria-hidden',x.classList.contains('show')?'false':'true');x.addEventListener('click',function(e){if(e.target===x)window.closeModal(x.id.replace('modal-',''))})});
    document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('[data-auth-action]');if(a){e.preventDefault();window.openModal(a.getAttribute('data-auth-action'));return}var inline=e.target.closest&&e.target.closest('[onclick*="openModal"]');if(inline){var oc=inline.getAttribute('onclick')||'';var id=oc.indexOf("register")>=0?'register':'login';if(byId('modal-'+id)){e.preventDefault();window.openModal(id)}}});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){var mo=qs('.modal.show');if(mo)window.closeModal(mo.id.replace('modal-',''));else mobileMenu(false)}});
    document.querySelectorAll('.faq-toggle').forEach(function(btn){btn.addEventListener('click',function(){var item=btn.closest('.faq-item');if(!item)return;var was=item.classList.contains('open');document.querySelectorAll('.faq-item.open').forEach(function(x){x.classList.remove('open')});if(!was)item.classList.add('open')})});
    var reveal=document.querySelectorAll('.reveal');if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting)x.target.classList.add('active')})},{threshold:.08});reveal.forEach(function(x){io.observe(x)})}else reveal.forEach(function(x){x.classList.add('active')});
    mobileMenu(false);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
