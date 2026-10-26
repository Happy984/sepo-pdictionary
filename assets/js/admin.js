/* Sepo Dictionary — the meanings list in the entry editor: add, remove and reorder. No dependencies. */
(function () {
  'use strict';
  var T = window.SepoDictionaryAdmin || {};

  // ---- Pronunciation recording: record with the microphone, or pick from the media library.
  var box = document.querySelector('[data-sdict-admin-audio]');
  if (box) {
    var idField = box.querySelector('[data-sdict-audio-id]');
    var player = box.querySelector('[data-sdict-audio-player]');
    var removeBtn = box.querySelector('[data-sdict-audio="remove"]');
    var recBtn = box.querySelector('[data-sdict-audio="record"]');
    var status = box.querySelector('[data-sdict-audio-status]');
    var frame = null, recorder = null, stream = null, chunks = [];

    var set = function (id, url) {
      idField.value = id || 0;
      player.src = url || '';
      player.hidden = !url;
      removeBtn.hidden = !url;
    };
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-sdict-audio]');
      if (!b) { return; }
      e.preventDefault();
      var act = b.getAttribute('data-sdict-audio');
      if (act === 'remove') { set(0, ''); status.textContent = ''; return; }
      if (act === 'pick') {
        if (!window.wp || !window.wp.media) { return; }
        if (!frame) {
          frame = window.wp.media({ title: T.pick, button: { text: T.use }, library: { type: ['audio', 'video/webm'] }, multiple: false });
          frame.on('select', function () {
            var a = frame.state().get('selection').first().toJSON();
            set(a.id, a.url);
            status.textContent = '';
          });
        }
        frame.open();
        return;
      }
      if (act === 'record') {
        if (recorder && recorder.state === 'recording') { recorder.stop(); return; }
        if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder)) { status.textContent = T.noRec; return; }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
          stream = s; chunks = [];
          recorder = new MediaRecorder(s);
          recorder.ondataavailable = function (ev) { if (ev.data && ev.data.size) { chunks.push(ev.data); } };
          recorder.onstop = function () {
            stream.getTracks().forEach(function (t) { t.stop(); });
            recBtn.textContent = T.record;
            recBtn.classList.remove('is-recording');
            var type = recorder.mimeType || 'audio/webm';
            var ext = /mp4|m4a|aac/.test(type) ? 'm4a' : (/ogg/.test(type) ? 'ogg' : 'webm');
            var fd = new FormData();
            fd.append('file', new Blob(chunks, { type: type }), 'pronunciation.' + ext);
            status.textContent = T.saving;
            fetch(T.api + '/audio', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': T.nonce }, body: fd })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (!d || d.code || !d.data) { throw new Error((d && d.message) || ''); }
                set(d.data.id, d.data.url);
                status.textContent = T.saved;
              })
              .catch(function (err) { status.textContent = err.message || T.micError; });
          };
          recorder.start();
          recBtn.textContent = T.stop;
          recBtn.classList.add('is-recording');
        }).catch(function () { status.textContent = T.micError; });
      }
    });
  }

  var list = document.querySelector('[data-sdict-admin-defs]');
  if (!list) { return; }

  function renumber() {
    Array.prototype.forEach.call(list.children, function (li, i) {
      Array.prototype.forEach.call(li.querySelectorAll('[name^="sd[definitions]"]'), function (f) {
        f.name = f.name.replace(/sd\[definitions\]\[\d+\]/, 'sd[definitions][' + i + ']');
      });
    });
  }

  function blank() {
    var li = list.firstElementChild.cloneNode(true);
    Array.prototype.forEach.call(li.querySelectorAll('input, textarea'), function (f) { f.value = ''; });
    return li;
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-sdict-def]');
    if (!b) { return; }
    e.preventDefault();
    var act = b.getAttribute('data-sdict-def');
    var li = b.closest('.sdict-admin__def');
    if (act === 'add') {
      var n = blank();
      list.appendChild(n);
      n.querySelector('textarea').focus();
    } else if (act === 'del' && li) {
      if (list.children.length > 1) { li.remove(); } else { Array.prototype.forEach.call(li.querySelectorAll('input, textarea'), function (f) { f.value = ''; }); }
    } else if (act === 'up' && li && li.previousElementSibling) {
      list.insertBefore(li, li.previousElementSibling);
    } else if (act === 'down' && li && li.nextElementSibling) {
      list.insertBefore(li.nextElementSibling, li);
    }
    renumber();
  });
})();
