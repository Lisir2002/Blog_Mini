/* PenBlog Markdown 编辑器(11 §3):工具栏 / 快捷键 / 服务端权威预览 / localStorage 草稿。
 * 渐进增强:核心保存走表单提交,本脚本只做编辑体验增强。 */
(function () {
  'use strict';
  var page = document.querySelector('.editor-page');
  if (!page) return;

  var form = document.getElementById('editor-form');
  var ta = document.getElementById('md-body');
  var main = document.querySelector('.editor-main');
  var preview = document.getElementById('md-preview');
  var toggleBtn = document.getElementById('preview-toggle');
  var countEl = document.getElementById('md-count');
  var draftStatus = document.getElementById('draft-status');
  var banner = document.getElementById('draft-banner');
  var previewUrl = page.getAttribute('data-preview-url');
  var csrf = page.getAttribute('data-csrf') || '';
  var draftKey = 'pb-draft:' + (page.getAttribute('data-draft-key') || 'new');
  var serverBody = ta.value;

  /* ---------- 工具栏:包裹选区 / 行首前缀 ---------- */
  function wrap(before, after, placeholder) {
    var s = ta.selectionStart, e = ta.selectionEnd;
    var sel = ta.value.slice(s, e) || placeholder || '';
    ta.setRangeText(before + sel + after, s, e, 'end');
    ta.setSelectionRange(s + before.length, s + before.length + sel.length);
    ta.focus(); onInput();
  }
  function prefixLine(prefix) {
    var s = ta.selectionStart;
    var lineStart = ta.value.lastIndexOf('\n', s - 1) + 1;
    ta.setRangeText(prefix, lineStart, lineStart, 'end');
    ta.focus(); onInput();
  }
  function insertText(text) {
    var s = ta.selectionStart;
    ta.setRangeText(text, s, ta.selectionEnd, 'end');
    ta.focus(); onInput();
  }
  /* 标题循环:无 → H1 → H2 → H3 → 无(11 §3.1) */
  function cycleHeading() {
    var s = ta.selectionStart;
    var lineStart = ta.value.lastIndexOf('\n', s - 1) + 1;
    var lineEnd = ta.value.indexOf('\n', s);
    if (lineEnd === -1) lineEnd = ta.value.length;
    var line = ta.value.slice(lineStart, lineEnd);
    var bare = line.replace(/^#{1,3} /, '');
    var m = line.match(/^(#{1,3}) /);
    var prefix = !m ? '# ' : m[1].length >= 3 ? '' : m[1] + '# ';
    ta.setRangeText(prefix + bare, lineStart, lineEnd, 'end');
    ta.focus(); onInput();
  }
  var actions = {
    bold: function () { wrap('**', '**', '粗体'); },
    italic: function () { wrap('*', '*', '斜体'); },
    strike: function () { wrap('~~', '~~', '删除'); },
    code: function () { wrap('`', '`', 'code'); },
    link: function () {
      var s = ta.selectionStart, e = ta.selectionEnd;
      var sel = ta.value.slice(s, e) || '链接文字';
      ta.setRangeText('[' + sel + '](https://)', s, e, 'end');
      ta.setSelectionRange(s + sel.length + 3, s + sel.length + 11);
      ta.focus(); onInput();
    },
    image: function () {
      var s = ta.selectionStart, e = ta.selectionEnd;
      var sel = ta.value.slice(s, e) || '图片描述';
      ta.setRangeText("![" + sel + "](https://)", s, e, 'end');
      ta.setSelectionRange(s + sel.length + 4, s + sel.length + 12);
      ta.focus(); onInput();
    },
    block: function () { wrap('\n```\n', '\n```\n', '代码块'); },
    quote: function () { prefixLine('> '); },
    heading: cycleHeading,
    list: function () { prefixLine('- '); },
    olist: function () { prefixLine('1. '); },
    task: function () { prefixLine('- [ ] '); },
    hr: function () { insertText('\n---\n'); },
    footnote: function () {
      var s = ta.selectionStart, e = ta.selectionEnd;
      var id = ta.value.slice(s, e) || '1';
      ta.setRangeText('[^' + id + ']', s, e, 'end');
      if (ta.value.indexOf('[^' + id + ']:') === -1) {
        ta.value += '\n\n[^' + id + ']: 脚注内容';
      }
      ta.focus(); onInput();
    },
    break: function () { insertText('\n<!--break-->\n'); },
    table: function () {
      wrap('\n| 列1 | 列2 | 列3 |\n| --- | :---: | ---: |\n| 内容 | 内容 | 内容 |\n', '', '');
    }
  };
  page.querySelectorAll('[data-md]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var fn = actions[btn.getAttribute('data-md')];
      if (fn) fn();
    });
  });

  /* ---------- 快捷键(11 §3.2) ---------- */
  ta.addEventListener('keydown', function (ev) {
    if (!(ev.ctrlKey || ev.metaKey)) return;
    var k = ev.key.toLowerCase();
    if (ev.shiftKey && k === 'p') { ev.preventDefault(); togglePreview(); return; }
    if (ev.shiftKey) return;
    var map = { b: 'bold', i: 'italic', k: 'link', d: 'strike' };
    if (map[k]) { ev.preventDefault(); actions[map[k]](); return; }
    if (k === 's') { ev.preventDefault(); form.requestSubmit(document.getElementById('save-btn')); }
  });

  /* ---------- 字数统计:中文字符 + 英文词(11 §3.4) ---------- */
  function countWords(text) {
    var zh = (text.match(/[\u4e00-\u9fff]/g) || []).length;
    var en = (text.replace(/[\u4e00-\u9fff\u3000-\u303f\uff00-\uffef]/g, ' ').match(/\b\w+\b/g) || []).length;
    return { zh: zh, en: en };
  }
  function onInput() {
    var c = countWords(ta.value);
    countEl.textContent = '中文 ' + c.zh + ' 字 · 英文 ' + c.en + ' 词';
    markDirty();
    if (previewVisible()) schedulePreview();
  }
  ta.addEventListener('input', onInput);
  onInput();

  /* ---------- 分屏预览:防抖 300ms,服务端权威渲染(11 §3.3) ---------- */
  var previewTimer = null;
  function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(renderPreview, 300);
  }
  function renderPreview() {
    fetch(previewUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ bodyMd: ta.value })
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    }).then(function (html) {
      preview.innerHTML = html; /* 服务端 Markdown 渲染器已整体转义(11 §2) */
    }).catch(function () {
      preview.innerHTML = '<p class="meta">预览服务暂不可用,请稍后重试。</p>';
    });
  }
  /* 分屏/整页切换纯 CSS(媒体查询 + 类),JS 只读样式结果、不读窗口宽度(08 §3.4) */
  function previewVisible() {
    return getComputedStyle(preview).display !== 'none';
  }
  function togglePreview() {
    var on = previewVisible();
    main.classList.remove(on ? 'preview-on' : 'preview-off');
    main.classList.add(on ? 'preview-off' : 'preview-on');
    toggleBtn.setAttribute('aria-pressed', String(!on));
    if (!on) renderPreview();
  }
  toggleBtn.addEventListener('click', togglePreview);

  /* ---------- 本地草稿:停输 5s 写入,刷新可恢复(11 §3.4) ---------- */
  var draftTimer = null;
  function collect() {
    var out = { bodyMd: ta.value, savedAt: Date.now() };
    out.title = document.getElementById('f-title').value;
    out.excerpt = document.getElementById('f-excerpt').value;
    out.categories = document.getElementById('f-categories').value;
    out.tags = document.getElementById('f-tags').value;
    out.status = document.getElementById('f-status').value;
    return out;
  }
  function markDirty() {
    clearTimeout(draftTimer);
    draftStatus.textContent = '有未保存的修改…';
    draftTimer = setTimeout(saveDraft, 5000);
  }
  function saveDraft() {
    if (ta.value === serverBody) { draftStatus.textContent = ''; return; }
    try {
      localStorage.setItem(draftKey, JSON.stringify(collect()));
      draftStatus.textContent = '草稿已存本地 ' + new Date().toLocaleTimeString();
    } catch (e) { /* 隐私模式等场景静默降级 */ }
  }
  /* 提交前落一份本地副本(冲突回显时"本地副本已保存"为真,11 §3.4);
   * 保存成功回跳(saved=1 渲染 alert-success)时才清除副本。 */
  form.addEventListener('submit', function () {
    clearTimeout(draftTimer);
    saveDraft();
  });
  if (document.querySelector('.editor-page .alert-success')) {
    try { localStorage.removeItem(draftKey); } catch (e) {}
  }
  ['f-title', 'f-excerpt', 'f-categories', 'f-tags'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', markDirty);
  });
  document.getElementById('f-status').addEventListener('change', markDirty);

  function loadDraft() {
    var raw = null;
    try { raw = localStorage.getItem(draftKey); } catch (e) { return; }
    if (!raw) return;
    var d;
    try { d = JSON.parse(raw); } catch (e) { return; }
    if (!d || d.bodyMd === serverBody) return;
    banner.querySelector('.draft-time').textContent = new Date(d.savedAt || 0).toLocaleString();
    banner.hidden = false;
    document.getElementById('draft-restore').addEventListener('click', function () {
      ta.value = d.bodyMd || '';
      document.getElementById('f-title').value = d.title || '';
      document.getElementById('f-excerpt').value = d.excerpt || '';
      document.getElementById('f-categories').value = d.categories || '';
      document.getElementById('f-tags').value = d.tags || '';
      document.getElementById('f-status').value = d.status || 'draft';
      banner.hidden = true;
      onInput();
    });
    document.getElementById('draft-discard').addEventListener('click', function () {
      try { localStorage.removeItem(draftKey); } catch (e) {}
      banner.hidden = true;
    });
  }
  loadDraft();
})();
