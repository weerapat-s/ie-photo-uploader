(function () {
  'use strict';
  const root = document.getElementById('iephoto-admin');
  if (!root) return;
  const owner = root.querySelector('[data-owner]');
  const folder = root.querySelector('[data-folder]');
  const picker = root.querySelector('[data-folder-picker]');
  const toggle = root.querySelector('[data-folder-toggle]');
  const selectedText = root.querySelector('[data-folder-selected]');
  const popover = root.querySelector('[data-folder-popover]');
  const search = root.querySelector('[data-folder-search]');
  const options = root.querySelector('[data-folder-options]');
  const empty = root.querySelector('[data-folder-empty]');
  const save = root.querySelector('[data-save]');
  const status = root.querySelector('[data-status]');
  const statsCards = root.querySelector('[data-stats-cards]');
  const photographerStats = root.querySelector('[data-photographer-stats]');
  const uploadHistory = root.querySelector('[data-upload-history]');
  const statsStatus = root.querySelector('[data-stats-status]');
  const refreshStats = root.querySelector('[data-refresh-stats]');
  const csrf = document.querySelector('head meta[name="csrf-token"]')?.content || window.OC?.requestToken || '';
  let configuredPath = '/';
  let folderPaths = [];

  async function json(url, requestOptions = {}) {
    requestOptions.headers = Object.assign({'requesttoken': csrf}, requestOptions.headers || {});
    const response = await fetch(url, requestOptions);
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'เกิดข้อผิดพลาด');
    return data;
  }

  function folderLabel(path) {
    return path === '/' ? 'หน้าหลัก (/)' : path;
  }

  function formatBytes(bytes) {
    const n = Number(bytes || 0);
    if (n < 1024) return `${n} B`;
    if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
    if (n < 1073741824) return `${(n / 1048576).toFixed(1)} MB`;
    return `${(n / 1073741824).toFixed(2)} GB`;
  }

  function formatDate(timestamp) {
    return timestamp ? new Date(Number(timestamp) * 1000).toLocaleString('th-TH', {dateStyle: 'medium', timeStyle: 'short'}) : '—';
  }

  async function loadStats() {
    if (!root.dataset.statsUrl || !statsCards) return;
    refreshStats.disabled = true;
    statsStatus.textContent = 'กำลังอัปเดตสถิติ…';
    try {
      const [data, gallery] = await Promise.all([
        json(root.dataset.statsUrl),
        fetch(root.dataset.albumsUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
          .then(response => { if (!response.ok) throw new Error(`Gallery HTTP ${response.status}`); return response.json(); }),
      ]);
      const albums = Array.isArray(gallery.albums) ? gallery.albums : [];
      const summary = albums.reduce((result, album) => {
        result.photos += Number(album.photoCount || 0);
        result.bytes += Number(album.totalBytes || 0);
        result.lastModified = Math.max(result.lastModified, Number(album.updatedAt || 0));
        return result;
      }, {photos: 0, bytes: 0, lastModified: 0});
      const values = [
        albums.length.toLocaleString('th-TH'),
        Number(summary.photos || 0).toLocaleString('th-TH'),
        formatBytes(summary.bytes),
        formatDate(summary.lastModified),
      ];
      statsCards.querySelectorAll('strong').forEach((node, index) => { node.textContent = values[index] || '—'; });

      photographerStats.innerHTML = '';
      const peopleByName = {};
      albums.forEach(album => {
        const stats = Array.isArray(album.photographerStats) ? album.photographerStats : [];
        stats.forEach(item => {
          const name = item.name || 'IE-PHOTO';
          if (!peopleByName[name]) peopleByName[name] = {name, photos: 0, bytes: 0, lastModified: 0};
          peopleByName[name].photos += Number(item.photos || 0);
          peopleByName[name].bytes += Number(item.bytes || 0);
          peopleByName[name].lastModified = Math.max(peopleByName[name].lastModified, Number(item.lastModified || 0));
        });
      });
      const people = Object.values(peopleByName).sort((a, b) => b.photos - a.photos);
      if (!people.length) photographerStats.innerHTML = '<p>ยังไม่มีข้อมูลผู้ถ่ายภาพ</p>';
      people.forEach(item => {
        const article = document.createElement('article');
        const copy = document.createElement('div');
        const name = document.createElement('strong'); name.textContent = item.name || 'ไม่ระบุชื่อ';
        const detail = document.createElement('small'); detail.textContent = `${Number(item.photos || 0).toLocaleString('th-TH')} รูป · ${formatBytes(item.bytes)}`;
        const date = document.createElement('small'); date.textContent = formatDate(item.lastModified);
        copy.append(name, detail); article.append(copy, date); photographerStats.appendChild(article);
      });

      uploadHistory.innerHTML = '';
      const history = Array.isArray(data.history) ? data.history : [];
      if (!history.length) uploadHistory.innerHTML = '<p>ประวัติจะเริ่มบันทึกจากเวอร์ชันนี้</p>';
      history.forEach(item => {
        const article = document.createElement('article');
        const copy = document.createElement('div');
        const title = document.createElement('strong'); title.textContent = `${item.source || 'ไฟล์'} → ${item.output || '—'}`;
        const detail = document.createElement('small'); detail.textContent = `${item.photographer || 'ไม่ระบุ'} · อัปโหลดโดย ${item.uploadedBy || 'unknown'}`;
        const date = document.createElement('small'); date.textContent = formatDate(item.timestamp);
        copy.append(title, detail); article.append(copy, date); uploadHistory.appendChild(article);
      });
      statsStatus.textContent = `Gallery ${albums.length} งาน · ปลายทางอัปโหลด: ${data.destination || 'ยังไม่ได้ตั้งค่า'}`;
    } catch (error) {
      statsStatus.textContent = error.message;
    } finally {
      refreshStats.disabled = false;
    }
  }

  function closePicker() {
    popover.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  }

  function renderFolders(query = '') {
    const keyword = query.trim().toLocaleLowerCase('th-TH');
    const visiblePaths = folderPaths.filter(path => folderLabel(path).toLocaleLowerCase('th-TH').includes(keyword));
    options.innerHTML = '';
    visiblePaths.forEach(path => {
      const button = document.createElement('button');
      const icon = document.createElement('span');
      const text = document.createElement('span');
      button.type = 'button';
      button.className = 'iep-folder-option';
      button.setAttribute('role', 'option');
      button.setAttribute('aria-selected', path === folder.value ? 'true' : 'false');
      icon.className = 'iep-folder-option-icon';
      icon.textContent = path === '/' ? '⌂' : '📁';
      icon.setAttribute('aria-hidden', 'true');
      text.textContent = folderLabel(path);
      button.append(icon, text);
      button.addEventListener('click', () => selectFolder(path));
      options.appendChild(button);
    });
    empty.hidden = visiblePaths.length !== 0;
  }

  function selectFolder(path, shouldClose = true) {
    const nextPath = folderPaths.includes(path) ? path : (folderPaths[0] || '/');
    folder.value = nextPath;
    selectedText.textContent = folderLabel(nextPath);
    renderFolders(search.value);
    save.disabled = !owner.value || folderPaths.length === 0;
    if (shouldClose) closePicker();
  }

  function showFolders(paths, selected = '/') {
    folderPaths = paths;
    search.value = '';
    toggle.disabled = paths.length === 0;
    selectFolder(paths.includes(selected) ? selected : '/', false);
  }

  async function loadFolders(selected = '/') {
    if (!owner.value) return;
    closePicker();
    folderPaths = [];
    folder.value = '';
    selectedText.textContent = 'กำลังโหลดโฟลเดอร์…';
    toggle.disabled = true;
    save.disabled = true;
    const data = await json(`${root.dataset.foldersUrl}?uid=${encodeURIComponent(owner.value)}`);
    showFolders(data.folders, selected);
  }

  async function init() {
    const data = await json(root.dataset.configUrl);
    owner.innerHTML = '';
    data.users.forEach(user => {
      const option = document.createElement('option');
      option.value = user.uid;
      option.textContent = `${user.name} (${user.uid})`;
      option.selected = user.uid === data.uid;
      owner.appendChild(option);
    });
    if (!owner.value && owner.options.length) owner.selectedIndex = 0;
    configuredPath = data.path || '/';
    await loadFolders(configuredPath);
  }

  toggle.addEventListener('click', () => {
    if (toggle.disabled) return;
    const willOpen = popover.hidden;
    if (!willOpen) {
      closePicker();
      return;
    }
    popover.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    search.value = '';
    renderFolders();
    search.focus();
  });

  search.addEventListener('input', () => renderFolders(search.value));
  search.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closePicker();
      toggle.focus();
    }
  });
  document.addEventListener('click', event => {
    if (!picker.contains(event.target)) closePicker();
  });

  owner.addEventListener('change', () => loadFolders('/').catch(error => { status.textContent = error.message; }));

  root.querySelector('[data-new-folder]').addEventListener('click', async () => {
    const path = window.prompt('ชื่อหรือ path โฟลเดอร์ใหม่ เช่น งานกิจกรรม/13-08-2569');
    if (!path) return;
    const body = new URLSearchParams({uid: owner.value, path});
    try {
      const data = await json(root.dataset.foldersUrl, {method: 'POST', body});
      showFolders(data.folders, '/' + path.replace(/^\/+|\/+$/g, ''));
      status.textContent = 'สร้างโฟลเดอร์แล้ว กรุณากดบันทึก';
    } catch (error) { status.textContent = error.message; }
  });

  // The destination is pinned server-side; keep the control inert so nobody thinks it will save.
  var destinationLock = root.querySelector('[data-destination-lock]');
  var destinationIsLocked = !!(destinationLock && !destinationLock.hidden);
  if (destinationIsLocked) { save.disabled = true; }

  save.addEventListener('click', async () => {
    if (destinationIsLocked) { status.textContent = 'โฟลเดอร์ปลายทางถูกล็อกไว้'; return; }
    save.disabled = true;
    const body = new URLSearchParams({uid: owner.value, path: folder.value});
    try {
      const data = await json(root.dataset.configUrl, {method: 'POST', body});
      status.textContent = `บันทึกแล้ว: ${data.destination}`;
      loadStats();
    } catch (error) { status.textContent = error.message; }
    save.disabled = false;
  });

  refreshStats?.addEventListener('click', loadStats);
  init().catch(error => { status.textContent = error.message; });
  loadStats();
})();

  // ---------- batch watermark/rename for folders uploaded outside the upload page ----------
  // The endpoint handles a few files per call; we loop until it reports done so a big folder
  // never depends on one long-running request surviving the PHP/Apache timeout.
  (function () {
    var root = document.getElementById('iephoto-admin');
    if (!root) return;
    var procUrl = root.dataset.processUrl || '';
    var owner = root.querySelector('[data-proc-owner]');
    var pathInput = root.querySelector('[data-proc-path]');
    var startBtn = root.querySelector('[data-proc-start]');
    var stopBtn = root.querySelector('[data-proc-stop]');
    var status = root.querySelector('[data-proc-status]');
    var results = root.querySelector('[data-proc-results]');
    var progress = root.querySelector('[data-proc-progress]');
    var bar = root.querySelector('[data-proc-bar]');
    if (!procUrl || !startBtn) return;

    var stopping = false;
    var csrf = document.querySelector('head meta[name="csrf-token"]')?.content
      || document.querySelector('head')?.dataset.requesttoken
      || window.OC?.requestToken || '';

    // Accept a Files URL, a public share link, or a plain path. Share links are passed through
    // untouched — the server resolves the token to the shared folder.
    function normalisePath(raw) {
      var value = String(raw || '').trim();
      if (!value) return '';
      if (/\/s\/[A-Za-z0-9]+/.test(value)) return value;      // public share link: server handles it
      if (/^https?:\/\//i.test(value)) {
        try {
          var u = new URL(value);
          var dir = u.searchParams.get('dir');
          if (dir) return dir;
          var f = u.pathname.split('/apps/files')[1];
          if (f && !/^\/files\/\d+$/.test(f)) return decodeURIComponent(f);
        } catch (_e) { /* fall through */ }
      }
      return value;
    }

    function line(item) {
      var li = document.createElement('li');
      li.className = item.ok ? 'ok' : 'error';
      li.textContent = item.ok ? (item.source + ' → ' + item.name) : (item.source + ': ' + item.error);
      results.appendChild(li);
      while (results.children.length > 200) results.removeChild(results.firstChild);
    }

    function chosenColour() {
      var picked = root.querySelector('[data-proc-colour]:checked');
      return picked && picked.value === 'white' ? 'white' : 'black';
    }

    async function runBatch(uid, path) {
      var body = new FormData();
      body.append('uid', uid);
      body.append('path', path);
      body.append('limit', '5');
      body.append('watermarkColour', chosenColour());
      var res = await fetch(procUrl, {
        method: 'POST',
        headers: { requesttoken: csrf, Accept: 'application/json' },
        credentials: 'same-origin',
        body: body,
      });
      var data = await res.json().catch(function () { return { error: 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง' }; });
      if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));
      return data;
    }

    startBtn.addEventListener('click', async function () {
      var uid = owner ? owner.value : '';
      var path = normalisePath(pathInput ? pathInput.value : '');
      if (!uid) { status.textContent = 'กรุณาเลือกเจ้าของพื้นที่'; return; }
      if (!path) { status.textContent = 'กรุณาใส่โฟลเดอร์'; return; }
      if (pathInput) pathInput.value = path;

      stopping = false;
      startBtn.disabled = true;
      if (stopBtn) stopBtn.hidden = false;
      results.innerHTML = '';
      if (progress) { progress.hidden = false; bar.style.width = '0'; }

      var total = 0;
      var failed = 0;
      var blocked = 0;
      var blockedNames = [];
      var stalled = false;
      var idleRounds = 0;
      var colour = chosenColour();
      try {
        while (!stopping) {
          status.textContent = 'กำลังประมวลผล… (เสร็จแล้ว ' + total + ' รูป)'
            + ' · ลายน้ำ' + (colour === 'white' ? 'สีขาว' : 'สีดำ');
          var data = await runBatch(uid, path);
          // Another run holds this folder: wait for the turn instead of treating it as an error.
          if (data.busy) {
            status.textContent = 'มีการประมวลผลโฟลเดอร์นี้อยู่แล้ว รอคิว… (เสร็จแล้ว ' + total + ' รูป)';
            await new Promise(function (r) { setTimeout(r, 3000); });
            continue;
          }
          (data.results || []).forEach(line);
          total += data.processed || 0;
          failed += data.failed || 0;
          blocked = data.blocked || 0;
          blockedNames = data.blockedNames || [];
          // Hard stop against a run that cannot advance. Without it a batch that processes
          // nothing is requested again with the same files, forever.
          if (!data.done && (data.processed || 0) === 0) {
            // Photos still being uploaded are skipped, not failed; give them a few chances.
            if ((data.skipped || 0) > 0 && idleRounds < 6) {
              idleRounds++;
              status.textContent = 'รอไฟล์ที่ยังอัปโหลดไม่เสร็จ… (' + idleRounds + '/6)';
              await new Promise(function (r) { setTimeout(r, 5000); });
              continue;
            }
            stalled = true;
            break;
          }
          idleRounds = 0;
          var known = total + (data.remaining || 0);
          if (bar && known > 0) bar.style.width = Math.round((total / known) * 100) + '%';
          if (data.done) break;
        }
        if (bar) bar.style.width = '100%';
        if (blocked) {
          var li = document.createElement('li');
          li.className = 'error';
          li.textContent = 'ย้ายไฟล์เสีย ' + blocked + ' ไฟล์ออกไปโฟลเดอร์ “<ชื่อโฟลเดอร์> ไฟล์เสีย” (ต้องอัปใหม่): '
            + blockedNames.join(', ');
          results.appendChild(li);
        }
        if (stalled) {
          status.textContent = 'หยุดเอง — ไม่มีไฟล์ที่ประมวลผลได้เหลือแล้ว'
            + (blocked ? (' ย้ายไฟล์เสียออกไปแล้ว ' + blocked + ' ไฟล์ ต้องอัปโหลดใหม่') : '')
            + (total ? (' · ทำไปทั้งหมด ' + total + ' รูป') : '');
          return;
        }
        status.textContent = stopping
          ? ('หยุดแล้ว — ประมวลผลไป ' + total + ' รูป' + (failed ? (' ผิดพลาด ' + failed) : ''))
          : ('เสร็จสิ้น — ประมวลผล ' + total + ' รูป' + (failed ? (' ผิดพลาด ' + failed) : '') + ' · ต้นฉบับถูกเก็บไว้ในโฟลเดอร์ “ต้นฉบับ”');
      } catch (err) {
        status.textContent = 'ผิดพลาด: ' + err.message;
      } finally {
        startBtn.disabled = false;
        if (stopBtn) stopBtn.hidden = true;
        setTimeout(function () { if (progress) progress.hidden = true; }, 1200);
      }
    });

    if (stopBtn) stopBtn.addEventListener('click', function () { stopping = true; stopBtn.disabled = true; setTimeout(function(){ stopBtn.disabled = false; }, 500); });

    // Scope box: the scan and the batch processor both refuse anything outside these folders.
    (function bindScopes() {
      var box = root.querySelector('[data-scopes]');
      var saveBtn = root.querySelector('[data-scopes-save]');
      var note = root.querySelector('[data-scopes-status]');
      if (!box || !saveBtn || !root.dataset.scopesUrl) return;

      saveBtn.addEventListener('click', async function () {
        saveBtn.disabled = true;
        note.textContent = 'กำลังบันทึก…';
        try {
          var body = new FormData();
          body.append('uid', owner ? owner.value : '');
          body.append('scopes', box.value);
          var res = await fetch(root.dataset.scopesUrl, {
            method: 'POST',
            headers: { requesttoken: csrf, Accept: 'application/json' },
            credentials: 'same-origin',
            body: body,
          });
          var data = await res.json().catch(function () { return {}; });
          if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));
          var saved = data.scopes || [];
          box.value = saved.map(function (p) { return '/' + p; }).join('\n');
          note.textContent = saved.length
            ? ('บันทึกแล้ว ' + saved.length + ' โฟลเดอร์')
            : 'บันทึกแล้ว — ไม่จำกัดขอบเขต (ทั้งพื้นที่)';
        } catch (e) {
          note.textContent = e.message;
        } finally {
          saveBtn.disabled = false;
        }
      });
    })();

    // populate the owner picker from the same config endpoint the main card uses
    (async function loadOwners() {
      if (!owner || !root.dataset.configUrl) return;
      try {
        var res = await fetch(root.dataset.configUrl, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json', requesttoken: csrf },
        });
        var data = await res.json();
        // Without requesttoken Nextcloud answers 403 with {error:...}; surface that instead of
        // silently leaving an empty picker.
        if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));
        owner.replaceChildren();
        (data.users || []).forEach(function (u) {
          var o = document.createElement('option');
          o.value = u.uid;
          o.textContent = u.name + ' (' + u.uid + ')';
          owner.appendChild(o);
        });
        if (data.uid) owner.value = data.uid;
        else if (owner.options.length) owner.selectedIndex = 0;
        var scopeBox = root.querySelector('[data-scopes]');
        if (scopeBox && typeof data.scopes === 'string') scopeBox.value = data.scopes;
      } catch (_e) {
        owner.replaceChildren();
        var o = document.createElement('option');
        o.textContent = 'โหลดรายชื่อผู้ใช้ไม่สำเร็จ';
        owner.appendChild(o);
      }
    })();
  })();

  // ---- ขอสร้างโฟลเดอร์รูป: create an album and hand back its two links ----
  (function () {
    var root = document.getElementById('iephoto-admin');
    if (!root || !root.dataset.albumApi) return;

    var nameInput = root.querySelector('[data-album-name]');
    var venueSelect = root.querySelector('[data-album-venue]');
    var submit = root.querySelector('[data-album-submit]');
    var status = root.querySelector('[data-album-status]');
    var result = root.querySelector('[data-album-result]');
    var linkUpload = root.querySelector('[data-link-upload]');
    var linkView = root.querySelector('[data-link-view]');
    var list = root.querySelector('[data-album-list]');
    var rootLabel = root.querySelector('[data-album-root]');
    if (!nameInput || !submit) return;

    var csrf = document.querySelector('head')?.dataset.requesttoken
      || window.OC?.requestToken || '';

    function uploadLink(token) {
      if (!token) return '';
      var base = root.dataset.albumUploadBase || '';
      return base + (base.indexOf('?') === -1 ? '?' : '&') + 'album=' + encodeURIComponent(token);
    }

    // The link photographers actually get: a Nextcloud file-drop share. They open it, drop photos
    // in, and the server watermarks and renames them on its own — no upload page involved.
    function dropLink(token) {
      return token ? (window.location.origin + '/s/' + token) : '';
    }

    function viewLink(token) {
      if (!token) return '';
      return (root.dataset.albumViewBase || '').replace('__TOKEN__', encodeURIComponent(token));
    }

    function say(message, kind) {
      status.textContent = message || '';
      status.classList.remove('is-error', 'is-ok');
      if (kind) status.classList.add(kind);
    }

    async function api(url, options) {
      options = options || {};
      options.credentials = 'same-origin';
      options.headers = Object.assign({requesttoken: csrf, Accept: 'application/json'}, options.headers || {});
      var response = await fetch(url, options);
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok) throw new Error(data.error || 'เกิดข้อผิดพลาด');
      return data;
    }

    function showLinks(album) {
      linkUpload.value = uploadLink(album.uploadToken);
      linkView.value = viewLink(album.viewToken);
      result.hidden = false;
    }

    function renderList(albums) {
      list.textContent = '';
      if (!albums.length) {
        var empty = document.createElement('p');
        empty.className = 'iep-help';
        empty.textContent = 'ยังไม่มีอัลบั้ม';
        list.append(empty);
        return;
      }
      albums.forEach(function (album) {
        var row = document.createElement('div');
        row.className = 'iep-album-row';

        var name = document.createElement('strong');
        name.textContent = album.name;

        var count = document.createElement('small');
        count.textContent = album.photos + ' รายการ';

        var venue = document.createElement('small');
        venue.textContent = album.venue === 'indoor' ? 'ในร่ม · ลายน้ำขาว' : 'กลางแจ้ง · ลายน้ำดำ';
        row.append(name, count, venue);

        if (album.dropToken) {
          var drop = document.createElement('button');
          drop.type = 'button';
          drop.className = 'iep-secondary';
          drop.textContent = '📤 คัดลอกลิงก์ให้ช่างภาพอัป';
          drop.addEventListener('click', function () { copy(dropLink(album.dropToken), drop); });
          row.append(drop);
        }
        if (album.viewToken) {
          var view = document.createElement('button');
          view.type = 'button';
          view.className = 'iep-secondary';
          view.textContent = 'คัดลอกลิงก์ดูรูป';
          view.addEventListener('click', function () { copy(viewLink(album.viewToken), view); });
          row.append(view);
        }
        list.append(row);
      });
    }

    function copy(text, button) {
      if (!text) return;
      var done = function () {
        var original = button.textContent;
        button.textContent = 'คัดลอกแล้ว';
        setTimeout(function () { button.textContent = original; }, 1400);
      };
      // navigator.clipboard needs a secure context; fall back to selecting the field.
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { prompt('คัดลอกลิงก์นี้', text); });
      } else {
        prompt('คัดลอกลิงก์นี้', text);
      }
    }

    async function refresh() {
      try {
        var data = await api(root.dataset.albumApi);
        if (rootLabel && data.root) rootLabel.textContent = data.root;
        renderList(Array.isArray(data.albums) ? data.albums : []);
      } catch (error) {
        list.textContent = '';
        var p = document.createElement('p');
        p.className = 'iep-help';
        p.textContent = 'โหลดรายการอัลบั้มไม่สำเร็จ: ' + error.message;
        list.append(p);
      }
    }

    submit.addEventListener('click', async function () {
      var name = (nameInput.value || '').trim();
      if (!name) { say('กรุณาระบุชื่ออัลบั้ม', 'is-error'); nameInput.focus(); return; }
      submit.disabled = true;
      say('กำลังสร้าง…');
      try {
        var body = new URLSearchParams();
        body.append('name', name);
        body.append('venue', venueSelect ? venueSelect.value : 'outdoor');
        var data = await api(root.dataset.albumApi, {method: 'POST', body: body});
        var album = data.album || {};
        say(album.created ? 'สร้างอัลบั้ม "' + album.name + '" แล้ว' : 'อัลบั้ม "' + album.name + '" มีอยู่แล้ว — แสดงลิงก์เดิม', 'is-ok');
        showLinks(album);
        nameInput.value = '';
        await refresh();
      } catch (error) {
        say(error.message, 'is-error');
      } finally {
        submit.disabled = false;
      }
    });

    nameInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); submit.click(); }
    });

    root.querySelectorAll('[data-copy]').forEach(function (button) {
      button.addEventListener('click', function () {
        var field = button.dataset.copy === 'upload' ? linkUpload : linkView;
        copy(field.value, button);
      });
    });

    refresh();
  })();

  // ---- คำขอสร้างโฟลเดอร์ + Discord webhook ----
  (function () {
    var root = document.getElementById('iephoto-admin');
    if (!root || !root.dataset.requestsUrl) return;

    var list = root.querySelector('[data-request-list]');
    var webhookInput = root.querySelector('[data-webhook-input]');
    var webhookSave = root.querySelector('[data-webhook-save]');
    var webhookState = root.querySelector('[data-webhook-state]');
    if (!list) return;

    var csrf = document.querySelector('head')?.dataset.requesttoken
      || window.OC?.requestToken || '';

    async function api(url, options) {
      options = options || {};
      options.credentials = 'same-origin';
      options.headers = Object.assign({requesttoken: csrf, Accept: 'application/json'}, options.headers || {});
      var response = await fetch(url, options);
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok) throw new Error(data.error || 'เกิดข้อผิดพลาด');
      return data;
    }

    var LABEL = {pending: 'รออนุมัติ', approved: 'อนุมัติแล้ว', denied: 'ไม่อนุมัติ'};

    function render(requests) {
      list.textContent = '';
      if (!requests.length) {
        var empty = document.createElement('p');
        empty.className = 'iep-help';
        empty.textContent = 'ยังไม่มีคำขอ';
        list.append(empty);
        return;
      }
      requests.slice(0, 20).forEach(function (request) {
        var row = document.createElement('div');
        row.className = 'iep-album-row';

        var name = document.createElement('strong');
        name.textContent = request.name || '(ไม่มีชื่อ)';

        var who = document.createElement('small');
        who.textContent = (request.displayName || request.uid || '') +
          (request.note ? ' — ' + request.note : '');

        var state = document.createElement('small');
        state.textContent = LABEL[request.status] || request.status || '';

        row.append(name, who, state);
        list.append(row);
      });
    }

    async function refresh() {
      try {
        var data = await api(root.dataset.requestsUrl);
        render(Array.isArray(data.requests) ? data.requests : []);
        if (webhookState) {
          webhookState.textContent = data.webhookSet ? 'ตั้งค่า webhook แล้ว' : 'ยังไม่ได้ตั้งค่า webhook';
        }
      } catch (error) {
        list.textContent = '';
        var p = document.createElement('p');
        p.className = 'iep-help';
        p.textContent = 'โหลดคำขอไม่สำเร็จ: ' + error.message;
        list.append(p);
      }
    }

    webhookSave?.addEventListener('click', async function () {
      webhookSave.disabled = true;
      try {
        var body = new URLSearchParams();
        body.append('url', (webhookInput.value || '').trim());
        var data = await api(root.dataset.webhookUrl, {method: 'POST', body: body});
        webhookState.textContent = data.webhookSet ? 'บันทึกแล้ว' : 'ล้างค่าแล้ว';
        // never echo the saved URL back into the page
        webhookInput.value = '';
      } catch (error) {
        webhookState.textContent = error.message;
      } finally {
        webhookSave.disabled = false;
      }
    });

    refresh();
  
  // ---- ตรวจหาโฟลเดอร์ที่ยังไม่ได้ใส่ลายน้ำ/เปลี่ยนชื่อ ----
  (function () {
    var root = document.getElementById('iephoto-admin');
    if (!root || !root.dataset.pendingUrl) return;
    var runBtn = root.querySelector('[data-scan-run]');
    var summary = root.querySelector('[data-scan-summary]');
    var list = root.querySelector('[data-scan-list]');
    var pathInput = root.querySelector('[data-proc-path]');
    var ownerSelect = root.querySelector('[data-proc-owner]');
    if (!runBtn || !list) return;

    var csrf = document.querySelector('head')?.dataset.requesttoken || window.OC?.requestToken || '';

    async function scan() {
      runBtn.disabled = true;
      summary.textContent = 'กำลังตรวจ…';
      list.textContent = '';
      try {
        var url = new URL(root.dataset.pendingUrl, window.location.origin);
        if (ownerSelect && ownerSelect.value) {
          url.searchParams.set('owner', ownerSelect.value);
        }
        var res = await fetch(url, {
          credentials: 'same-origin',
          headers: {requesttoken: csrf, Accept: 'application/json'},
        });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok) throw new Error(data.error || 'ตรวจไม่สำเร็จ');

        var folders = Array.isArray(data.folders) ? data.folders : [];
        // Name the space that was actually scanned. The old wording claimed every folder was
        // done, while the scan had only ever looked inside one album.
        var scope = data.root ? ' (พื้นที่ของ ' + data.root + ')' : '';
        if (!folders.length) {
          summary.textContent = '✓ ไม่มีไฟล์ค้าง' + scope;
          return;
        }
        summary.textContent = 'ค้างรอทำ ' + data.pending + ' ไฟล์ ใน ' + folders.length + ' โฟลเดอร์'
          + (data.broken ? ' · ไฟล์ขนาด 0 ไบต์ ' + data.broken + ' (ต้องอัปใหม่)' : '')
          + (data.truncated ? ' · แสดงบางส่วน' : '')
          + scope;

        folders.forEach(function (f) {
          var row = document.createElement('div');
          row.className = 'iep-scan-row';

          var name = document.createElement('strong');
          name.textContent = f.folder || '(โฟลเดอร์หลัก)';

          var counts = document.createElement('small');
          counts.textContent = (f.pending ? f.pending + ' รอทำ' : '')
            + (f.pending && f.broken ? ' · ' : '')
            + (f.broken ? f.broken + ' เสีย' : '');

          row.append(name, counts);

          if (f.pending) {
            var go = document.createElement('button');
            go.type = 'button';
            go.className = 'iep-secondary';
            go.textContent = 'ประมวลผลโฟลเดอร์นี้';
            go.addEventListener('click', function () {
              if (pathInput) {
                pathInput.value = f.folder;
                pathInput.scrollIntoView({block: 'center'});
                pathInput.focus();
              }
              var start = root.querySelector('[data-proc-start]');
              if (start) start.click();
            });
            row.append(go);
          }
          list.append(row);
        });
      } catch (e) {
        summary.textContent = e.message;
      } finally {
        runBtn.disabled = false;
      }
    }

    runBtn.addEventListener('click', scan);
  })();
})();
