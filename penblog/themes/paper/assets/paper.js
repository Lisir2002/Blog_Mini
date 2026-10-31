/* Paper 主题渐进增强(08 §5/§6):暗色开关、下拉菜单、模态确认。
   禁止用 innerWidth 做布局分支(§3.4);布局全部交给 CSS 流式 + 容器查询。 */
(function () {
  'use strict';

  /* ---------- 暗色开关(class 策略,theme.json darkMode.strategy=class) ---------- */
  function currentTheme() {
    var t = document.documentElement.dataset.theme;
    if (t === 'dark' || t === 'light') return t;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function reflect() {
    var dark = currentTheme() === 'dark';
    document.querySelectorAll('.theme-toggle').forEach(function (b) {
      b.setAttribute('aria-pressed', String(dark));
    });
  }
  document.querySelectorAll('.theme-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      try { localStorage.setItem('pb-theme', next); } catch (e) { /* 隐私模式 */ }
      reflect();
    });
  });
  reflect();

  /* ---------- 下拉菜单:Esc/外点关闭、↑↓ 键遍历、右对齐弹出防溢出 ---------- */
  document.querySelectorAll('[data-menu]').forEach(function (wrap) {
    var btn = wrap.querySelector('.menu-btn');
    var panel = wrap.querySelector('.menu-panel');
    if (!btn || !panel) return;
    function open() { wrap.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); }
    function close(refocus) {
      wrap.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      if (refocus) btn.focus();
    }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      wrap.classList.contains('open') ? close(true) : open();
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && wrap.classList.contains('open')) close(true);
    });
    panel.addEventListener('keydown', function (e) {
      var items = Array.prototype.slice.call(panel.querySelectorAll('a[href], button:not([disabled])'));
      if (!items.length) return;
      var i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1 + items.length) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    });
  });

  /* ---------- 模态确认:危险操作必须显示对象名;无 JS 时表单直接提交 ---------- */
  var dialog = document.getElementById('confirm-modal');
  if (dialog && typeof dialog.showModal === 'function') {
    var pending = null;
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        pending = form;
        var slot = dialog.querySelector('.modal-target');
        if (slot) slot.textContent = form.getAttribute('data-confirm') || '';
        dialog.showModal();
        var cancel = dialog.querySelector('.modal-cancel');
        if (cancel) cancel.focus();
      });
    });
    var confirmBtn = dialog.querySelector('.modal-confirm');
    if (confirmBtn) confirmBtn.addEventListener('click', function () {
      dialog.close();
      if (pending) pending.submit(); /* submit() 不再触发 submit 事件,避免循环 */
    });
    var cancelBtn = dialog.querySelector('.modal-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', function () { dialog.close(); pending = null; });
    dialog.addEventListener('cancel', function () { pending = null; });
  }
})();
