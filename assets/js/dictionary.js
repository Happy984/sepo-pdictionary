/*
 * Sepo Dictionary — search suggestions, entry tabs, the suggestion forms,
 * voting, and the editor's tools. No dependencies.
 */
(function () {
  'use strict';

  var cfg = window.SepoDictionaryConfig || {};
  var T = cfg.i18n || {};

  function qa(root, sel) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmt(s) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(s || '').replace(/%(\d\$)?[sd]/g, function (m, pos) {
      if (pos) { return String(args[parseInt(pos, 10) - 1]); }
      return String(args[i++]);
    });
  }
  function rootOf(el) { return (el && el.closest && el.closest('.sdict-scope')) || document.querySelector('.sdict-scope'); }

  // ------------------------------------------------------------ dictionaries

  /** What the visitor may do in each dictionary, keyed by its id. */
  var DICTS = {};
  (cfg.dicts || []).forEach(function (d) { DICTS[d.id] = d; });
  var DICT_LIST = cfg.dicts || [];

  /** The dictionary a part of the page is about: the entry's, else the block's; 0 on the list of dictionaries. */
  function dictOf(root, entry) {
    if (entry && entry.dictionary) { return parseInt(entry.dictionary, 10) || 0; }
    var own = root && root.getAttribute('data-sdict-dict');
    return parseInt(own || cfg.defaultDict || 0, 10) || 0;
  }
  function ctx(id) {
    var d = DICTS[id];
    return {
      id: id,
      canManage: d ? !!d.canManage : !!cfg.canManage,
      pos: d ? d.pos : cfg.pos,
      reviewUrl: d ? d.reviewUrl : cfg.reviewUrl
    };
  }
  /** The dictionary the open form works in; the field builders read it. */
  var formCtx = ctx(0);

  // ------------------------------------------------------------ dark mode

  /** A declared theme marker wins; otherwise the page background decides. */
  function isDark() {
    var nodes = [document.documentElement, document.body];
    var attrs = ['data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode'];
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (!n) { continue; }
      for (var j = 0; j < attrs.length; j++) {
        var v = (n.getAttribute(attrs[j]) || '').toLowerCase();
        if (v === 'dark') { return true; }
        if (v === 'light') { return false; }
      }
      if (/(^|\s)(dark|dark-mode|theme-dark|is-dark-theme|wp-dark-mode-active)(\s|$)/.test(n.className || '')) { return true; }
    }
    var bg = '';
    nodes.forEach(function (n) {
      if (!n) { return; }
      var c = getComputedStyle(n).backgroundColor;
      if (c && !/rgba\(\s*0,\s*0,\s*0,\s*0\s*\)|transparent/.test(c)) { bg = c; }
    });
    var m = bg.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
    return !!m && (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 < 0.4;
  }
  /** The colour actually painted behind an element: the nearest ancestor with a solid background. */
  function backdrop(el) {
    for (var n = el.parentElement; n; n = n.parentElement) {
      var c = getComputedStyle(n).backgroundColor;
      var m = c && c.match(/rgba?\(([^)]+)\)/);
      if (!m) { continue; }
      var parts = m[1].split(/[,\s/]+/).filter(Boolean);
      if (parts.length < 4 || parseFloat(parts[3]) >= 0.98) { return c; }
    }
    return '';
  }
  function paintMode() {
    var dark = isDark();
    qa(document, '.sdict-scope').forEach(function (s) {
      s.classList.toggle('sdict-dark', dark);
      var bg = backdrop(s);
      if (bg) { s.style.setProperty('--sdict-page-bg', bg); } else { s.style.removeProperty('--sdict-page-bg'); }
    });
  }

  // ------------------------------------------------------------ requests

  function request(method, path, body) {
    var headers = { 'Accept': 'application/json' };
    if (body) { headers['Content-Type'] = 'application/json'; }
    if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
    return fetch(String(cfg.api || '').replace(/\/$/, '') + path, {
      method: method, credentials: 'same-origin', headers: headers, body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        if (!data) { throw new Error(T.network || 'Request failed.'); }
        if (!res.ok || data.status === 'error' || data.code) {
          var err = new Error(data.message || T.network || 'Request failed.');
          err.data = data.data || {};
          throw err;
        }
        return data;
      });
    }, function () { throw new Error(T.network || 'Request failed.'); });
  }
  function post(path, body) { return request('POST', path, body || {}); }
  function get(path) { return request('GET', path); }

  // ------------------------------------------------------------ toast and dialogs

  var toastTimer = 0;
  function toast(root, msg, kind) {
    var el = root && root.querySelector('[data-sdict-toast]');
    if (!el) { return; }
    el.textContent = msg;
    el.className = 'sdict-toast' + (kind ? ' sdict-toast--' + kind : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 4200);
  }

  /**
   * The page's own dialog. o: title, html (body), buttons [{label, kind, value}],
   * wide. Resolves with the pressed button's value, or null when dismissed.
   * o.onOpen(panel, close) runs once the dialog is shown.
   */
  function dialog(root, o) {
    var modal = root.querySelector('[data-sdict-modal]');
    if (!modal) { return Promise.resolve(null); }
    var panel = modal.querySelector('.sdict-modal__panel');
    var body = modal.querySelector('[data-sdict-modal-body]');
    var foot = modal.querySelector('[data-sdict-modal-foot]');
    modal.querySelector('[data-sdict-modal-title]').textContent = o.title || '';
    body.innerHTML = o.html || '';
    panel.classList.toggle('sdict-modal__panel--wide', !!o.wide);
    foot.innerHTML = (o.buttons || []).map(function (b, i) {
      return '<button type="button" class="sdict-btn' + (b.kind ? ' sdict-btn--' + b.kind : '') + '" data-sdict-dlg="' + i + '">' + esc(b.label) + '</button>';
    }).join('');
    foot.hidden = !(o.buttons || []).length;
    var before = document.activeElement;
    modal.hidden = false;
    document.documentElement.classList.add('sdict-modal-open');

    return new Promise(function (resolve) {
      var done = false;
      function close(v) {
        if (done) { return; }
        done = true;
        modal.hidden = true;
        document.documentElement.classList.remove('sdict-modal-open');
        document.removeEventListener('keydown', onKey, true);
        modal.removeEventListener('click', onClick);
        if (before && before.focus) { before.focus(); }
        resolve(v === undefined ? null : v);
      }
      function onClick(e) {
        if (e.target === modal || e.target.closest('[data-sdict-modal-close]')) { close(null); return; }
        var b = e.target.closest('[data-sdict-dlg]');
        if (b) {
          var spec = o.buttons[parseInt(b.getAttribute('data-sdict-dlg'), 10)];
          if (spec && typeof spec.onClick === 'function') { spec.onClick(b, close); return; }
          close(spec ? spec.value : null);
        }
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(null); return; }
        if (e.key === 'Tab') {
          var f = qa(panel, 'button:not([disabled]), [href], input:not([type="hidden"]):not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])').filter(function (n) { return n.offsetParent !== null; });
          if (!f.length) { return; }
          var first = f[0];
          var last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }
      modal.addEventListener('click', onClick);
      document.addEventListener('keydown', onKey, true);
      var focusEl = panel.querySelector('[autofocus], input:not([type="hidden"]), textarea, select') || foot.querySelector('button');
      if (focusEl) { setTimeout(function () { focusEl.focus(); }, 20); }
      if (typeof o.onOpen === 'function') { o.onOpen(panel, close); }
    });
  }

  function confirmDialog(root, o) {
    return dialog(root, {
      title: o.title,
      html: '<p class="sdict-modal__text">' + esc(o.body || '') + '</p>',
      buttons: [{ label: o.no || T.cancel || 'Cancel', value: false }, { label: o.yes || 'OK', kind: o.danger ? 'danger' : 'primary', value: true }]
    }).then(function (v) { return v === true; });
  }

  /** Ask for a short note; resolves with the text, or null when cancelled. */
  function noteDialog(root, o) {
    var id = 'sdict-note-' + Date.now();
    return dialog(root, {
      title: o.title,
      html: (o.body ? '<p class="sdict-modal__text">' + esc(o.body) + '</p>' : '')
        + '<label class="sdict-field"><span class="sdict-field__label">' + esc(o.label) + (o.required ? '' : ' <span class="sdict-opt">' + esc(T.optional) + '</span>') + '</span>'
        + '<textarea id="' + id + '" rows="3" maxlength="255" placeholder="' + esc(o.placeholder || '') + '"></textarea>'
        + '</label>',
      buttons: [{ label: T.cancel, value: null }, {
        label: o.yes, kind: o.kind || 'primary', onClick: function (btn, close) {
          var ta = document.getElementById(id);
          var v = ta ? ta.value.trim() : '';
          if (o.required && !v) {
            fieldMessage(ta.closest('.sdict-field'), T.required);
            ta.focus();
            return;
          }
          close(v);
        }
      }]
    }).then(function (v) { return v === null ? null : String(v); });
  }

  // ------------------------------------------------------------ search suggestions

  function initSearch(form) {
    if (form.getAttribute('data-sdict-ready') === '1') { return; }
    form.setAttribute('data-sdict-ready', '1');
    var input = form.querySelector('.sdict-search__input');
    var box = form.querySelector('[data-sdict-suggest]');
    var lang = form.querySelector('.sdict-search__lang') || form.querySelector('input[name="sd_lang"]');
    var dict = dictOf(rootOf(form), null);
    if (!input || !box) { return; }
    var timer = 0, last = '', active = -1, seq = 0;

    function opts() { return qa(box, '.sdict-suggest__opt'); }
    function hide() { box.hidden = true; box.innerHTML = ''; active = -1; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
    function mark(i) {
      var o = opts();
      o.forEach(function (n, k) { n.classList.toggle('is-active', k === i); n.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
      active = i;
      if (o[i]) { input.setAttribute('aria-activedescendant', o[i].id); o[i].scrollIntoView({ block: 'nearest' }); }
    }
    function run() {
      var q = input.value.trim();
      var key = q + '|' + (lang ? lang.value : '');
      if (key === last) { return; }
      last = key;
      if (q.length < 1) { hide(); return; }
      var mine = ++seq;
      get('/search?limit=8&q=' + encodeURIComponent(q) + (lang && lang.value ? '&language=' + encodeURIComponent(lang.value) : '') + (dict ? '&dictionary=' + dict : '')).then(function (b) {
        if (mine !== seq) { return; }
        var rows = (b.data && b.data.results) || [];
        if (!rows.length) {
          box.innerHTML = '<div class="sdict-suggest__none">' + esc(fmt(T.noMatch, q)) + '</div>';
        } else {
          box.innerHTML = rows.map(function (r, i) {
            return '<a class="sdict-suggest__opt" role="option" id="' + form.querySelector('.sdict-search__input').id + '-o' + i + '" href="' + esc(r.url) + '">'
              + '<span class="sdict-suggest__head"><span class="sdict-suggest__term">' + esc(r.term) + '</span>'
              + (r.pos ? '<span class="sdict-pos">' + esc(r.pos) + '</span>' : '')
              + (r.language || (!dict && DICT_LIST.length > 1 && r.dictionary) ? '<span class="sdict-suggest__lang">' + esc([!dict && DICT_LIST.length > 1 ? r.dictionary : '', r.language].filter(Boolean).join(' · ')) + '</span>' : '') + '</span>'
              + (r.snippet ? '<span class="sdict-suggest__def">' + esc(r.snippet) + '</span>' : '') + '</a>';
          }).join('') + '<button type="submit" class="sdict-suggest__all">' + esc(fmt(T.allResults, q)) + '</button>';
        }
        box.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        active = -1;
      }).catch(hide);
    }
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 160); });
    if (lang && lang.tagName === 'SELECT') { lang.addEventListener('change', function () { last = ''; run(); }); }
    input.addEventListener('keydown', function (e) {
      var o = opts();
      if (e.key === 'ArrowDown' && o.length) { e.preventDefault(); mark(Math.min(o.length - 1, active + 1)); }
      else if (e.key === 'ArrowUp' && o.length) { e.preventDefault(); mark(Math.max(0, active - 1)); }
      else if (e.key === 'Enter' && active >= 0 && o[active]) { e.preventDefault(); window.location.assign(o[active].href); }
      else if (e.key === 'Escape') { hide(); }
    });
    input.addEventListener('focus', function () { if (input.value.trim() && box.innerHTML === '') { last = ''; run(); } });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) { hide(); } });
  }

  // ------------------------------------------------------------ forms

  /**
   * Suggestion lists are collected and written once at the end of the form,
   * outside the fields, so they never take a place in the field layout.
   */
  var pendingLists = '';
  function datalist(id, values) {
    pendingLists += '<datalist id="' + id + '">' + (values || []).map(function (v) { return '<option value="' + esc(v) + '"></option>'; }).join('') + '</datalist>';
    return '';
  }
  function takeLists() { var out = pendingLists; pendingLists = ''; return out; }
  function field(label, inner, o) {
    o = o || {};
    return '<label class="sdict-field' + (o.wide ? ' sdict-field--wide' : '') + '"' + (o.name ? ' data-sdict-field="' + o.name + '"' : '') + '><span class="sdict-field__label">' + esc(label)
      + (o.optional ? ' <span class="sdict-opt">' + esc(T.optional) + '</span>' : '') + '</span>' + inner
      + (o.hint ? '<span class="sdict-field__hint">' + esc(o.hint) + '</span>' : '') + '</label>';
  }
  function input(name, value, attrs) {
    return '<input type="text" name="' + name + '" value="' + esc(value || '') + '"' + (attrs || '') + '>';
  }
  function textarea(name, value, rows, attrs) {
    return '<textarea name="' + name + '" rows="' + (rows || 3) + '"' + (attrs || '') + '>' + esc(value || '') + '</textarea>';
  }
  /** A select from the editors' list for members; a free field with suggestions for editors. */
  function choice(name, value, values, uid) {
    if (formCtx.canManage) {
      return input(name, value, ' list="' + uid + '-' + name + '" autocomplete="off"') + datalist(uid + '-' + name, values);
    }
    var o = '<option value="">' + esc(T.none) + '</option>';
    (values || []).forEach(function (v) { o += '<option value="' + esc(v) + '"' + (v === value ? ' selected' : '') + '>' + esc(v) + '</option>'; });
    if (value && (values || []).indexOf(value) === -1) { o += '<option value="' + esc(value) + '" selected>' + esc(value) + '</option>'; }
    return '<select name="' + name + '">' + o + '</select>';
  }
  function defRow(d, n) {
    d = d || {};
    return '<div class="sdict-defrow" data-sdict-defrow' + (d.id ? ' data-id="' + esc(d.id) + '"' : '') + '>'
      + '<span class="sdict-defrow__n">' + n + '</span><div class="sdict-defrow__fields">'
      + '<label class="sdict-field"><span class="screen-reader-text">' + esc(T.meaning) + '</span><textarea name="def_text" rows="2" placeholder="' + esc(T.meaningPh) + '">' + esc(d.text || '') + '</textarea></label>'
      + '<label class="sdict-field"><span class="screen-reader-text">' + esc(T.example) + '</span><input type="text" name="def_example" value="' + esc(d.example || '') + '" placeholder="' + esc(T.examplePh) + '"></label>'
      + '</div><div class="sdict-defrow__tools">'
      + (formCtx.canManage ? '<button type="button" class="sdict-iconbtn" data-sdict-def="up" aria-label="' + esc(T.moveUp) + '" title="' + esc(T.moveUp) + '">&#8593;</button>' : '')
      + '<button type="button" class="sdict-iconbtn sdict-iconbtn--danger" data-sdict-def="del" aria-label="' + esc(T.removeMeaning) + '" title="' + esc(T.removeMeaning) + '">&times;</button>'
      + '</div></div>';
  }
  function defsBlock(defs) {
    defs = defs && defs.length ? defs : [{}];
    return '<div class="sdict-defs" data-sdict-defs>' + defs.map(function (d, i) { return defRow(d, i + 1); }).join('') + '</div>'
      + '<button type="button" class="sdict-btn sdict-btn--quiet sdict-btn--sm" data-sdict-def="add">+ ' + esc(T.addMeaning) + '</button>';
  }
  function renumber(panel) { qa(panel, '[data-sdict-defrow] .sdict-defrow__n').forEach(function (n, i) { n.textContent = i + 1; }); }
  function wireDefs(panel) {
    panel.addEventListener('click', function (e) {
      var b = e.target.closest('[data-sdict-def]');
      if (!b) { return; }
      var box = panel.querySelector('[data-sdict-defs]');
      var act = b.getAttribute('data-sdict-def');
      if (act === 'add') {
        box.insertAdjacentHTML('beforeend', defRow({}, box.children.length + 1));
        box.lastElementChild.querySelector('textarea').focus();
      } else {
        var row = b.closest('[data-sdict-defrow]');
        if (act === 'del') {
          if (box.children.length > 1) { row.remove(); } else { row.querySelector('textarea').value = ''; row.querySelector('input').value = ''; }
        } else if (act === 'up' && row.previousElementSibling) {
          box.insertBefore(row, row.previousElementSibling);
        }
      }
      renumber(panel);
    });
  }
  function readDefs(panel) {
    return qa(panel, '[data-sdict-defrow]').map(function (r) {
      return { id: r.getAttribute('data-id') || '', text: r.querySelector('[name="def_text"]').value.trim(), example: r.querySelector('[name="def_example"]').value.trim() };
    }).filter(function (d) { return d.text; });
  }
  function val(panel, name) { var el = panel.querySelector('[name="' + name + '"]'); return el ? el.value.trim() : ''; }
  /**
   * Error messages are added only when there is something to say and removed
   * when it is fixed, so no empty placeholder ever takes a place in the layout.
   */
  function fieldMessage(f, msg) {
    var e = f.querySelector(':scope > .sdict-field__err');
    if (!msg) { if (e) { e.remove(); } f.classList.remove('is-error'); return; }
    if (!e) { e = document.createElement('span'); e.className = 'sdict-field__err'; f.appendChild(e); }
    e.textContent = msg;
    f.classList.add('is-error');
  }
  function setErr(panel, name, msg) {
    var f = panel.querySelector('[data-sdict-field="' + name + '"]');
    if (!f) {
      var el = panel.querySelector('[name="' + name + '"]');
      f = el ? el.closest('.sdict-field') : null;
    }
    if (f) { fieldMessage(f, msg); }
  }
  function clearErrs(panel) {
    qa(panel, '.is-error').forEach(function (f) { fieldMessage(f, ''); });
    var top = panel.querySelector('[data-sdict-form-err]');
    if (top) { top.remove(); }
  }
  function formError(panel, err) {
    var top = panel.querySelector('[data-sdict-form-err]');
    if (!top) {
      top = document.createElement('div');
      top.className = 'sdict-form__err';
      top.setAttribute('data-sdict-form-err', '');
      top.setAttribute('role', 'alert');
      var host = panel.querySelector('.sdict-form, .sdict-rec') || panel.querySelector('[data-sdict-modal-body]');
      var intro = host.querySelector(':scope > .sdict-form__intro');
      host.insertBefore(top, intro ? intro.nextSibling : host.firstChild);
    }
    var link = err && err.data && err.data.entry_url ? ' <a href="' + esc(err.data.entry_url) + '">' + esc(T.openEntry) + '</a>' : '';
    top.innerHTML = esc(err.message || err) + link;
    top.scrollIntoView({ block: 'nearest' });
  }
  function busy(btn, on, label) {
    if (!btn) { return; }
    if (on) { btn.setAttribute('data-label', btn.textContent); btn.textContent = label || T.saving; btn.disabled = true; }
    else { btn.textContent = btn.getAttribute('data-label') || btn.textContent; btn.disabled = false; }
  }
  function entryData(root) {
    var el = root.querySelector('[data-sdict-entry]');
    if (!el) { return null; }
    try { return JSON.parse(el.getAttribute('data-sdict-entry')); } catch (e) { return null; }
  }
  function goProposals(root) {
    var el = root.querySelector('[data-sdict-entry]');
    var url = el ? el.getAttribute('data-sdict-url') : window.location.href;
    var u = new URL(url, window.location.href);
    u.searchParams.set('sd_tab', 'proposals');
    window.location.assign(u.toString());
  }

  /** After a suggestion is saved: thank the member and point to where it can be voted on. */
  function thanks(root, message, onEntry, reviewUrl) {
    return dialog(root, {
      title: T.thanksTitle,
      html: '<div class="sdict-thanks"><span class="sdict-thanks__icon">&#10003;</span><p>' + esc(message) + '</p><p class="sdict-muted">' + esc(T.thanksBody) + '</p></div>',
      buttons: [{ label: onEntry ? T.seeSuggestions : T.seeQueue, kind: 'primary', value: true }]
    }).then(function () {
      var to = reviewUrl || cfg.reviewUrl;
      if (onEntry) { goProposals(root); } else if (to) { window.location.assign(to); } else { window.location.reload(); }
    });
  }

  /**
   * The whole-entry form. mode: new-entry and edit-entry (editors save at
   * once), propose-new and propose-edit (members put it to a vote).
   */
  function entryForm(root, mode, entry, prefill) {
    var e = entry || {};
    var uid = 'sdf' + Date.now();
    var editor = mode === 'new-entry' || mode === 'edit-entry';
    var withDefs = mode !== 'propose-edit';
    var list = function (v) { return (v || []).join(', '); };
    var title = { 'new-entry': T.addWord, 'edit-entry': T.editWord, 'propose-new': T.suggestWord, 'propose-edit': T.suggestCorrection }[mode];

    // Which dictionary the word goes in. A new word on the list of dictionaries picks one; a
    // site editor can move a word to another one. Only dictionaries the visitor may use are offered.
    var dictId = dictOf(root, entry);
    var picks = DICT_LIST.filter(function (d) { return editor ? d.canManage : d.canPropose; });
    var pickDict = DICT_LIST.length > 1 && ((mode !== 'edit-entry' && mode !== 'propose-edit' && !dictId) || (mode === 'edit-entry' && cfg.canManage));
    // With one dictionary to choose from it is chosen already, and still shown so the visitor sees where the word goes.
    if (!dictId && picks.length === 1) { dictId = picks[0].id; }
    formCtx = ctx(dictId || (picks[0] && picks[0].id) || 0);
    var dictField = pickDict ? field(T.dictionary, '<select name="dictionary" required>'
      + (dictId ? '' : '<option value="">' + esc(T.chooseDict) + '</option>')
      + picks.map(function (d) { return '<option value="' + d.id + '"' + (d.id === dictId ? ' selected' : '') + '>' + esc(d.name) + '</option>'; }).join('')
      + '</select>', { name: 'dictionary' }) : '';

    var html = '<form class="sdict-form" novalidate>'
      + (mode === 'propose-edit' ? '<p class="sdict-form__intro">' + esc(T.correctionIntro) + '</p>' : '')
      + (mode === 'propose-new' ? '<p class="sdict-form__intro">' + esc(T.newIntro) + '</p>' : '')
      + ''
      + '<div class="sdict-grid">'
      + dictField
      + field(T.word, input('term', e.term || prefill || '', ' required autocomplete="off"'), { name: 'term' })
      + field(T.pronunciation, input('pronunciation', e.pronunciation, ' placeholder="' + esc(T.pronPh) + '"'), { optional: true })
      + field(T.pos, input('pos', e.pos, ' list="' + uid + '-pos" autocomplete="off"') + datalist(uid + '-pos', formCtx.pos), { optional: true })
      + ((cfg.languages || []).length || formCtx.canManage ? field(T.language, choice('language', e.language || cfg.defaultLanguage || '', cfg.languages, uid), { optional: true }) : '')
      + ((cfg.categories || []).length || formCtx.canManage ? field(T.category, choice('category', e.category, cfg.categories, uid), { optional: true }) : '')
      + (editor ? field(T.visibility, '<select name="status">' + [['publish', T.published], ['draft', T.draft], ['pending', T.pending], ['private', T.private]].map(function (s) {
          return '<option value="' + s[0] + '"' + ((e.status || 'publish') === s[0] ? ' selected' : '') + '>' + esc(s[1]) + '</option>';
        }).join('') + '</select>') : '')
      + '</div>'
      + (withDefs ? '<fieldset class="sdict-fieldset" data-sdict-field="definitions"><legend>' + esc(T.meanings) + '</legend>' + defsBlock(e.definitions) + '</fieldset>' : '')
      + '<div class="sdict-grid' + (editor ? '' : ' sdict-grid--3') + '">'
      + field(T.synonyms, input('synonyms', list(e.synonyms)), { optional: true })
      + field(T.antonyms, input('antonyms', list(e.antonyms)), { optional: true })
      + field(T.related, input('related', list(e.related)), { optional: true })
      + (editor ? field(T.tags, input('tags', list(e.tags), ' list="' + uid + '-tags"') + datalist(uid + '-tags', cfg.tags), { optional: true }) : '')
      + '</div><p class="sdict-form__note">' + esc(T.commaHint) + '</p>'
      + field(T.origin, textarea('etymology', e.etymology, 2), { optional: true, wide: true })
      + field(T.notes, textarea('notes', e.notes, 2), { optional: true, wide: true })
      + field(T.source, input('source', e.source, ' placeholder="' + esc(T.sourcePh) + '"'), { optional: true, wide: true })
      + (editor ? field(T.summary, input('summary', '', ' placeholder="' + esc(T.summaryPh) + '"'), { optional: true, wide: true })
        : field(T.reason, textarea('reason', '', 2, ' placeholder="' + esc(T.reasonPh) + '"'), { optional: true, wide: true }))
      + '</form>' + takeLists();

    dialog(root, {
      title: title, wide: true, html: html,
      buttons: [{ label: T.cancel, value: null }, {
        label: editor ? T.save : T.submitVote, kind: 'primary', onClick: function (btn, close) {
          var panel = btn.closest('.sdict-modal__panel');
          clearErrs(panel);
          var data = {
            term: val(panel, 'term'), pronunciation: val(panel, 'pronunciation'), pos: val(panel, 'pos'),
            language: val(panel, 'language'), category: val(panel, 'category'),
            synonyms: val(panel, 'synonyms'), antonyms: val(panel, 'antonyms'), related: val(panel, 'related'),
            etymology: val(panel, 'etymology'), notes: val(panel, 'notes'), source: val(panel, 'source')
          };
          if (withDefs) { data.definitions = readDefs(panel); }
          if (editor) { data.tags = val(panel, 'tags'); data.status = val(panel, 'status'); }
          var chosen = pickDict ? parseInt(val(panel, 'dictionary'), 10) || 0 : dictId;
          if (chosen && mode !== 'propose-edit') { data.dictionary = chosen; }
          var bad = false;
          if (pickDict && !chosen) { setErr(panel, 'dictionary', T.dictRequired); bad = true; }
          if (!data.term) { setErr(panel, 'term', T.wordRequired); bad = true; }
          if (withDefs && !data.definitions.length) { setErr(panel, 'definitions', T.meaningRequired); bad = true; }
          if (bad) { var first = panel.querySelector('.is-error select, .is-error input, .is-error textarea'); if (first) { first.focus(); } return; }

          var req;
          if (editor) { req = post('/entry', { id: mode === 'edit-entry' ? e.id : 0, entry: data, summary: val(panel, 'summary') }); }
          else if (mode === 'propose-new') { req = post('/propose', { kind: 'new', dictionary: chosen, entry: data, reason: val(panel, 'reason') }); }
          else { req = post('/propose', { kind: 'edit', entry_id: e.id, entry: data, reason: val(panel, 'reason') }); }
          busy(btn, true);
          req.then(function (b) {
            close(true);
            if (editor) { window.location.assign((b.data && b.data.url) || window.location.href); return; }
            thanks(root, b.message, mode === 'propose-edit', ctx(chosen).reviewUrl);
          }).catch(function (err) { busy(btn, false); formError(panel, err); });
        }
      }],
      onOpen: function (panel) {
        wireDefs(panel);
        var sel = panel.querySelector('select[name="dictionary"]');
        var posList = panel.querySelector('datalist[id="' + uid + '-pos"]');
        if (sel && posList) {
          // Each dictionary can keep its own parts of speech.
          sel.addEventListener('change', function () {
            var c = ctx(parseInt(sel.value, 10) || 0);
            posList.innerHTML = (c.pos || []).map(function (v) { return '<option value="' + esc(v) + '"></option>'; }).join('');
          });
        }
        panel.addEventListener('input', function (ev) {
          var f = ev.target.closest('.sdict-field.is-error, .sdict-fieldset.is-error');
          if (f) { fieldMessage(f, ''); }
        });
      }
    });
  }

  /** Add a meaning, replace one with a better one, or ask for one to be removed. */
  function meaningForm(root, op, target) {
    var e = entryData(root);
    if (!e) { return; }
    formCtx = ctx(dictOf(root, e));
    var def = null;
    (e.definitions || []).forEach(function (d) { if (d.id === target) { def = d; } });
    if (op !== 'add' && !def) { return; }
    var title = op === 'add' ? T.suggestMeaning : (op === 'replace' ? T.suggestBetter : T.flagWrong);
    var current = def ? '<div class="sdict-current"><span class="sdict-label">' + esc(T.currentMeaning) + '</span><p>' + esc(def.text) + '</p>' + (def.example ? '<p class="sdict-ex">' + esc(def.example) + '</p>' : '') + '</div>' : '';
    var body = '<form class="sdict-form" novalidate><p class="sdict-form__intro">' + esc(op === 'add' ? fmt(T.addIntro, e.term) : (op === 'replace' ? T.replaceIntro : T.removeIntro)) + '</p>'
      + current;
    if (op === 'add') {
      body += '<fieldset class="sdict-fieldset" data-sdict-field="definitions"><legend>' + esc(T.newMeanings) + '</legend>' + defsBlock([]) + '</fieldset>';
    } else if (op === 'replace') {
      body += field(T.betterMeaning, textarea('text', def.text, 3), { name: 'text', wide: true })
        + field(T.example, input('example', def.example, ' placeholder="' + esc(T.examplePh) + '"'), { optional: true, wide: true });
    }
    body += field(T.reason, textarea('reason', '', 2, ' placeholder="' + esc(op === 'remove' ? T.removeReasonPh : T.reasonPh) + '"'), { name: 'reason', optional: op !== 'remove', wide: true }) + '</form>';

    dialog(root, {
      title: title, wide: op !== 'remove', html: body,
      buttons: [{ label: T.cancel, value: null }, {
        label: T.submitVote, kind: op === 'remove' ? 'danger' : 'primary', onClick: function (btn, close) {
          var panel = btn.closest('.sdict-modal__panel');
          clearErrs(panel);
          var payload = { kind: 'meaning', op: op, entry_id: e.id, target: target || '', reason: val(panel, 'reason') };
          if (op === 'add') {
            payload.definitions = readDefs(panel);
            if (!payload.definitions.length) { setErr(panel, 'definitions', T.meaningRequired); return; }
          } else if (op === 'replace') {
            payload.definition = { text: val(panel, 'text'), example: val(panel, 'example') };
            if (!payload.definition.text) { setErr(panel, 'text', T.meaningRequired); return; }
            if (payload.definition.text === def.text && payload.definition.example === (def.example || '')) { setErr(panel, 'text', T.unchanged); return; }
          } else if (!payload.reason) {
            setErr(panel, 'reason', T.reasonRequired);
            return;
          }
          busy(btn, true);
          post('/propose', payload).then(function (b) { close(true); thanks(root, b.message, true); })
            .catch(function (err) { busy(btn, false); formError(panel, err); });
        }
      }],
      onOpen: function (panel) {
        wireDefs(panel);
        panel.addEventListener('input', function (ev) {
          var f = ev.target.closest('.is-error');
          if (f) { fieldMessage(f, ''); }
        });
      }
    });
  }

  // ------------------------------------------------------------ voting

  function paintVote(bar, p) {
    qa(bar, '[data-sdict-v]').forEach(function (b) { b.setAttribute('aria-pressed', String(parseInt(b.getAttribute('data-sdict-v'), 10) === p.mine)); });
    var up = bar.querySelector('[data-sdict-n="up"]');
    var down = bar.querySelector('[data-sdict-n="down"]');
    if (up) { up.textContent = p.up; }
    if (down) { down.textContent = p.down; }
    var meter = bar.querySelector('[data-sdict-meter]');
    if (meter && p.needs) { meter.style.width = p.needs.pct + '%'; }
    var needs = bar.querySelector('[data-sdict-needs]');
    if (needs) { needs.textContent = p.needsText; }
  }

  function vote(root, btn) {
    var bar = btn.closest('[data-sdict-vote]');
    var id = parseInt(bar.getAttribute('data-sdict-vote'), 10);
    var v = parseInt(btn.getAttribute('data-sdict-v'), 10);
    var pressed = btn.getAttribute('aria-pressed') === 'true';
    var value = pressed ? 0 : v;
    var ask = value === -1
      ? noteDialog(root, { title: T.notAccurateTitle, body: T.notAccurateBody, label: T.whatsWrong, placeholder: T.whatsWrongPh, yes: T.voteNotAccurate, kind: 'danger' })
      : Promise.resolve('');
    ask.then(function (note) {
      if (note === null) { return; }
      qa(bar, '[data-sdict-v]').forEach(function (b) { b.disabled = true; });
      post('/vote', { id: id, value: value, note: note }).then(function (b) {
        var p = b.data.proposal;
        paintVote(bar, p);
        qa(bar, '[data-sdict-v]').forEach(function (x) { x.disabled = false; });
        if (p.status !== 'open') {
          toast(root, p.status === 'accepted' ? T.nowAccepted : (p.status === 'passed' ? T.nowPassed : T.nowClosed), p.status === 'rejected' ? '' : 'good');
          setTimeout(function () { window.location.reload(); }, 1300);
        } else {
          toast(root, value === 0 ? T.voteCleared : T.voteSaved, 'good');
        }
      }).catch(function (err) {
        qa(bar, '[data-sdict-v]').forEach(function (x) { x.disabled = false; });
        toast(root, err.message, 'bad');
      });
    });
  }

  // ------------------------------------------------------------ pronunciation recording

  /** Send a recorded or chosen sound to the site for this entry. */
  function uploadAudio(entryId, blob, name) {
    var fd = new FormData();
    fd.append('entry_id', String(entryId));
    fd.append('file', blob, name);
    return fetch(String(cfg.api || '').replace(/\/$/, '') + '/audio', {
      method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce || '' }, body: fd
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        if (!data) { throw new Error(T.network); }
        if (!res.ok || data.code) { throw new Error(data.message || T.network); }
        return data;
      });
    }, function () { throw new Error(T.network); });
  }

  /** The recorder's file extension for its format. */
  function extFor(type) {
    if (/mp4|m4a|aac/.test(type)) { return 'm4a'; }
    if (/ogg/.test(type)) { return 'ogg'; }
    if (/wav/.test(type)) { return 'wav'; }
    return 'webm';
  }

  /**
   * Record the word with the microphone, or choose a sound file, and save it
   * as the entry's pronunciation. Recording uses the browser's own
   * MediaRecorder; it needs a secure (https) page.
   */
  function audioDialog(root) {
    var e = entryData(root);
    if (!e) { return; }
    var canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    var current = root.querySelector('.sdict-entry__head [data-sdict-audio]');
    var html = '<div class="sdict-rec">'
      + (current ? '<div class="sdict-rec__current"><span class="sdict-label">' + esc(T.currentRecording) + '</span><audio controls preload="none" src="' + esc(current.getAttribute('data-sdict-audio')) + '"></audio>'
        + '<button type="button" class="sdict-link sdict-link--danger" data-sdict-rec="remove">' + esc(T.removeRecording) + '</button></div>' : '')
      + ''
      + (canRecord
        ? '<div class="sdict-rec__box"><button type="button" class="sdict-rec__btn" data-sdict-rec="toggle" aria-pressed="false"><span class="sdict-rec__dot"></span><span data-sdict-rec-label>' + esc(T.startRecording) + '</span></button>'
          + '<span class="sdict-rec__time" data-sdict-rec-time>0:00</span></div>'
          + '<p class="sdict-rec__hint">' + esc(T.recordHint) + '</p>'
        : '<p class="sdict-rec__hint">' + esc(T.noRecorder) + '</p>')
      + '<audio class="sdict-rec__preview" data-sdict-rec-preview controls hidden></audio>'
      + '<label class="sdict-rec__file"><span class="sdict-field__label">' + esc(canRecord ? T.orUpload : T.uploadFile) + '</span>'
      + '<input type="file" accept="audio/*,.mp3,.m4a,.ogg,.wav,.webm" data-sdict-rec-file></label>'
      + '</div>';

    var chosen = null;
    var recorder = null, stream = null, chunks = [], timer = 0, started = 0;

    function stopStream() { if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } clearInterval(timer); }

    dialog(root, {
      title: T.recordTitle,
      html: html,
      buttons: [{ label: T.cancel, value: null }, {
        label: T.saveRecording, kind: 'primary', onClick: function (btn, close) {
          var panel = btn.closest('.sdict-modal__panel');
          clearErrs(panel);
          if (!chosen) { formError(panel, new Error(T.nothingRecorded)); return; }
          busy(btn, true);
          uploadAudio(e.id, chosen.blob, chosen.name).then(function (b) {
            close(true); toast(root, b.message, 'good'); setTimeout(function () { window.location.reload(); }, 700);
          }).catch(function (err) { busy(btn, false); formError(panel, err); });
        }
      }],
      onOpen: function (panel) {
        var preview = panel.querySelector('[data-sdict-rec-preview]');
        var label = panel.querySelector('[data-sdict-rec-label]');
        var time = panel.querySelector('[data-sdict-rec-time]');
        var toggle = panel.querySelector('[data-sdict-rec="toggle"]');
        function show(blob, name) {
          chosen = { blob: blob, name: name };
          preview.src = URL.createObjectURL(blob);
          preview.hidden = false;
        }
        panel.querySelector('[data-sdict-rec-file]').addEventListener('change', function (ev) {
          var f = ev.target.files && ev.target.files[0];
          if (f) { show(f, f.name); }
        });
        var remove = panel.querySelector('[data-sdict-rec="remove"]');
        if (remove) {
          remove.addEventListener('click', function () {
            remove.disabled = true;
            post('/audio', { entry_id: e.id, remove: '1' }).then(function (b) {
              toast(root, b.message, 'good'); setTimeout(function () { window.location.reload(); }, 700);
            }).catch(function (err) { remove.disabled = false; formError(panel, err); });
          });
        }
        if (!toggle) { return; }
        toggle.addEventListener('click', function () {
          if (recorder && recorder.state === 'recording') { recorder.stop(); return; }
          clearErrs(panel);
          navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
            stream = s;
            chunks = [];
            recorder = new MediaRecorder(s);
            recorder.ondataavailable = function (ev) { if (ev.data && ev.data.size) { chunks.push(ev.data); } };
            recorder.onstop = function () {
              stopStream();
              toggle.setAttribute('aria-pressed', 'false');
              label.textContent = T.recordAgain;
              var type = recorder.mimeType || (chunks[0] && chunks[0].type) || 'audio/webm';
              var blob = new Blob(chunks, { type: type });
              if (blob.size) { show(blob, 'pronunciation.' + extFor(type)); }
            };
            recorder.start();
            started = Date.now();
            toggle.setAttribute('aria-pressed', 'true');
            label.textContent = T.stopRecording;
            timer = setInterval(function () {
              var sec = Math.floor((Date.now() - started) / 1000);
              time.textContent = Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
              // A pronunciation is a few seconds; stop runaway recordings.
              if (sec >= 30 && recorder.state === 'recording') { recorder.stop(); }
            }, 250);
          }).catch(function () { formError(panel, new Error(T.micBlocked)); });
        });
      }
    }).then(function () { if (recorder && recorder.state === 'recording') { recorder.stop(); } stopStream(); });
  }

  // ------------------------------------------------------------ actions

  function act(root, btn) {
    var a = btn.getAttribute('data-sdict-act');
    var id = parseInt(btn.getAttribute('data-id') || '0', 10);
    var e = entryData(root);
    var reload = function (b) { toast(root, b.message, 'good'); setTimeout(function () { window.location.reload(); }, 700); };
    var fail = function (err) { toast(root, err.message, 'bad'); btn.disabled = false; };

    switch (a) {
      case 'new-entry': entryForm(root, 'new-entry', null, btn.getAttribute('data-prefill') || ''); break;
      case 'propose-new': entryForm(root, 'propose-new', null, btn.getAttribute('data-prefill') || ''); break;
      case 'edit-entry': if (e) { entryForm(root, 'edit-entry', e); } break;
      case 'audio': audioDialog(root); break;
      case 'propose-edit': if (e) { entryForm(root, 'propose-edit', e); } break;
      case 'propose-add': meaningForm(root, 'add'); break;
      case 'propose-replace': meaningForm(root, 'replace', btn.getAttribute('data-target')); break;
      case 'propose-remove': meaningForm(root, 'remove', btn.getAttribute('data-target')); break;
      case 'trash-entry':
        if (!e) { break; }
        confirmDialog(root, { title: T.trashTitle, body: fmt(T.trashBody, e.term), yes: T.trash, danger: true }).then(function (ok) {
          if (!ok) { return; }
          post('/entry/trash', { id: e.id }).then(function (b) { window.location.assign(b.data.url || '/'); }).catch(fail);
        });
        break;
      case 'withdraw':
        confirmDialog(root, { title: T.withdrawTitle, body: T.withdrawBody, yes: T.withdraw, danger: true }).then(function (ok) {
          if (ok) { btn.disabled = true; post('/proposal/withdraw', { id: id }).then(reload).catch(fail); }
        });
        break;
      case 'resubmit':
        btn.disabled = true;
        post('/proposal/resubmit', { id: id }).then(reload).catch(fail);
        break;
      case 'accept':
        confirmDialog(root, { title: T.acceptTitle, body: T.acceptBody, yes: T.acceptYes }).then(function (ok) {
          if (ok) { btn.disabled = true; post('/proposal/moderate', { id: id, action: 'accept' }).then(reload).catch(fail); }
        });
        break;
      case 'reject':
        noteDialog(root, { title: T.declineTitle, label: T.declineLabel, placeholder: T.declinePh, yes: T.decline, kind: 'danger' }).then(function (note) {
          if (note !== null) { btn.disabled = true; post('/proposal/moderate', { id: id, action: 'reject', note: note }).then(reload).catch(fail); }
        });
        break;
      case 'delete':
        confirmDialog(root, { title: T.deleteTitle, body: T.deleteBody, yes: T.delete, danger: true }).then(function (ok) {
          if (ok) { btn.disabled = true; post('/proposal/moderate', { id: id, action: 'delete' }).then(reload).catch(fail); }
        });
        break;
      case 'restore':
        confirmDialog(root, { title: T.restoreTitle, body: T.restoreBody, yes: T.restoreYes }).then(function (ok) {
          if (ok) {
            btn.disabled = true;
            post('/history/restore', { id: id }).then(function (b) {
              toast(root, b.message, 'good');
              var u = new URL(window.location.href); u.searchParams.set('sd_tab', 'history'); u.hash = '';
              setTimeout(function () { window.location.assign(u.toString()); }, 700);
            }).catch(fail);
          }
        });
        break;
    }
  }

  // ------------------------------------------------------------ entry page extras

  function showTab(root, name, push) {
    var tabs = qa(root, '[data-sdict-tab]');
    if (!tabs.length) { return; }
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-sdict-tab') === name;
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.setAttribute('tabindex', on ? '0' : '-1');
      var panel = root.querySelector('#' + t.getAttribute('aria-controls'));
      if (panel) { panel.hidden = !on; }
    });
    if (push && window.history && window.history.replaceState) {
      var u = new URL(window.location.href);
      if (name === 'meaning') { u.searchParams.delete('sd_tab'); } else { u.searchParams.set('sd_tab', name); }
      u.hash = '';
      window.history.replaceState(null, '', u.toString());
    }
  }

  var playing = null;
  function say(btn) {
    var src = btn.getAttribute('data-sdict-audio');
    if (src) {
      if (playing) { playing.pause(); }
      playing = new Audio(src);
      btn.classList.add('is-playing');
      playing.onended = playing.onerror = playing.onpause = function () { btn.classList.remove('is-playing'); };
      playing.play().catch(function () { btn.classList.remove('is-playing'); });
      return;
    }
    if (!('speechSynthesis' in window)) { return; }
    var u = new SpeechSynthesisUtterance(btn.getAttribute('data-sdict-say') || '');
    var lang = btn.getAttribute('data-sdict-lang') || '';
    if (lang) {
      u.lang = lang;
      var voices = window.speechSynthesis.getVoices() || [];
      for (var i = 0; i < voices.length; i++) {
        if (voices[i].lang && voices[i].lang.toLowerCase().indexOf(lang.toLowerCase()) === 0) { u.voice = voices[i]; break; }
      }
    }
    u.rate = 0.85;
    window.speechSynthesis.cancel();
    btn.classList.add('is-playing');
    u.onend = u.onerror = function () { btn.classList.remove('is-playing'); };
    window.speechSynthesis.speak(u);
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (err) { reject(err); }
      ta.remove();
    });
  }

  function cite(root) {
    var e = entryData(root);
    var el = root.querySelector('[data-sdict-entry]');
    if (!e || !el) { return; }
    var url = el.getAttribute('data-sdict-url');
    var today = new Date().toLocaleDateString(document.documentElement.lang || undefined, { day: 'numeric', month: 'long', year: 'numeric' });
    var text = fmt(T.citation, e.term, cfg.siteName || '', today, url);
    dialog(root, {
      title: T.citeTitle,
      html: '<p class="sdict-cite" data-sdict-cite-text>' + esc(text) + '</p>',
      buttons: [{ label: T.close, value: null }, { label: T.copy, kind: 'primary', onClick: function (b) {
        copyText(text).then(function () { b.textContent = T.copied; }, function () { b.textContent = T.copyFailed; });
      } }]
    });
  }

  // ------------------------------------------------------------ start

  function init(scope) {
    var roots = qa(scope || document, '[data-sdict-app]');
    if (scope && scope.matches && scope.matches('[data-sdict-app]')) { roots.push(scope); }
    roots.forEach(function (root) {
      if (root.getAttribute('data-sdict-ready') === '1') { return; }
      root.setAttribute('data-sdict-ready', '1');
      qa(root, '[data-sdict-search]').forEach(initSearch);
      qa(root, '[data-sdict-autosubmit] select').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });
      if ('speechSynthesis' in window) { qa(root, '[data-sdict-say]').forEach(function (b) { b.hidden = false; }); }

      root.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!t.closest) { return; }
        var tab = t.closest('[data-sdict-tab]');
        if (tab) { ev.preventDefault(); showTab(root, tab.getAttribute('data-sdict-tab'), true); return; }
        var link = t.closest('[data-sdict-tab-link]');
        if (link) {
          ev.preventDefault();
          showTab(root, link.getAttribute('data-sdict-tab-link'), true);
          var hash = (link.getAttribute('href') || '').split('#')[1];
          var target = hash ? document.getElementById(hash) : root.querySelector('.sdict-tabs');
          if (target) { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); target.classList.add('is-flash'); setTimeout(function () { target.classList.remove('is-flash'); }, 1600); }
          return;
        }
        var v = t.closest('[data-sdict-v]');
        if (v && !v.disabled) { ev.preventDefault(); vote(root, v); return; }
        var a = t.closest('[data-sdict-act]');
        if (a && !a.disabled) { ev.preventDefault(); act(root, a); return; }
        var sayBtn = t.closest('[data-sdict-say]');
        if (sayBtn) { say(sayBtn); return; }
        var copy = t.closest('[data-sdict-copy]');
        if (copy) {
          copyText(copy.getAttribute('data-sdict-copy')).then(function () { toast(root, T.linkCopied, 'good'); }, function () { toast(root, T.copyFailed, 'bad'); });
          return;
        }
        if (t.closest('[data-sdict-cite]')) { cite(root); }
      });

      // Arrow keys move between the entry tabs.
      var tablist = root.querySelector('.sdict-tabs');
      if (tablist) {
        tablist.addEventListener('keydown', function (ev) {
          if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') { return; }
          var tabs = qa(tablist, '[data-sdict-tab]');
          var i = tabs.indexOf(document.activeElement);
          if (i < 0) { return; }
          var next = tabs[(i + (ev.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
          next.focus();
          showTab(root, next.getAttribute('data-sdict-tab'), true);
        });
      }
      // A link to a proposal or change opens its tab.
      var hash = window.location.hash.slice(1);
      if (hash) {
        var target = document.getElementById(hash);
        var panel = target && target.closest('.sdict-panel');
        if (panel && panel.hidden) {
          showTab(root, panel.id.replace('sdict-panel-', ''), false);
          setTimeout(function () { target.scrollIntoView({ block: 'start' }); target.classList.add('is-flash'); }, 50);
        }
      }
    });
  }

  function start() {
    paintMode();
    init(document);
    var mo = new MutationObserver(paintMode);
    var opts = { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode', 'style'] };
    mo.observe(document.documentElement, opts);
    if (document.body) { mo.observe(document.body, opts); }
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) { mq.addEventListener('change', function () { setTimeout(paintMode, 50); }); }
    }
  }

  window.SepoDictionary = { init: function (el) { paintMode(); init(el || document); }, paintMode: paintMode };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
  // Elementor's editor renders widgets after load.
  if (window.jQuery) {
    window.jQuery(window).on('elementor/frontend/init', function () {
      if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/global', function ($el) { window.SepoDictionary.init($el[0]); });
      }
    });
  }
})();
