(function () {
  'use strict';
  const root = document.getElementById('iephoto-upload');
  const albumToken = (root && root.dataset.albumToken) || '';
  const sportsUrl = (root && root.dataset.sportsUrl) || '';
  // With no per-album token this is the universal link: the photographer must pick a sport,
  // which decides both the destination folder and the watermark colour on the server.
  const pickerMode = !albumToken;
  if (!root) return;
  const input = root.querySelector('[data-files]');
  const upload = root.querySelector('[data-upload]');
  const selection = root.querySelector('[data-selection]');
  const previews = root.querySelector('[data-previews]');
  const status = root.querySelector('[data-status]');
  const results = root.querySelector('[data-results]');
  const progress = root.querySelector('[data-progress]');
  const bar = root.querySelector('[data-progress-bar]');
  const uploaderSelect = root.querySelector('[data-uploader-select]');
  const otherWrap = root.querySelector('[data-other-wrap]');
  const otherName = root.querySelector('[data-other-name]');
  const sportSelect = root.querySelector('[data-sport]');
  const sportWrap = root.querySelector('[data-sport-wrap]');
  const sportVenue = root.querySelector('[data-sport-venue]');
  const sportOtherWrap = root.querySelector('[data-sport-other-wrap]');
  const sportOther = root.querySelector('[data-sport-other]');
  const sportVenueSelect = root.querySelector('[data-sport-venue-select]');
  const sportVenueMap = {};
  const positionInputs = Array.from(root.querySelectorAll('[data-position]'));
  const positionLabel = root.querySelector('[data-position-label]');
  const opacityInput = root.querySelector('[data-opacity]');
  const opacityLabel = root.querySelector('[data-opacity-label]');
  const previewCanvas = root.querySelector('[data-preview-canvas]');
  const previewEmpty = root.querySelector('[data-preview-empty]');
  const watermarkUrl = root.dataset.watermarkUrl || '';
  const configured = root.dataset.configured === '1';
  const csrf = document.querySelector('head meta[name="csrf-token"]')?.content || window.OC?.requestToken || '';
  let previewUrls = [];
  // FileList is read-only, so the current selection lives in this array and is mirrored
  // back onto the hidden <input> whenever it changes (removing one thumbnail, etc.).
  let selectedFiles = [];
  const positionNames = {
    'top-left': 'ซ้ายบน', 'top-center': 'กลางบน', 'top-right': 'ขวาบน',
    'middle-left': 'ซ้ายกลาง', 'center': 'กึ่งกลาง', 'middle-right': 'ขวากลาง',
    'bottom-left': 'ซ้ายล่าง', 'bottom-center': 'กลางล่าง', 'bottom-right': 'ขวาล่าง'
  };

  if (!configured) {
    status.textContent = 'แอดมินยังไม่ได้กำหนดโฟลเดอร์ปลายทาง';
  }

  function selectedUploader() {
    if (!uploaderSelect.value) return '';
    return uploaderSelect.value === '__other__' ? otherName.value.trim() : uploaderSelect.value;
  }

  function isOtherSport() {
    return !!(sportSelect && sportSelect.value === '__other__');
  }

  function selectedSport() {
    if (!sportSelect || !sportWrap || sportWrap.hidden) { return ''; }
    if (isOtherSport()) { return sportOther ? sportOther.value.trim() : ''; }
    return sportSelect.value || '';
  }

  // Only sent when the photographer typed a brand-new sport: the server needs the venue to know
  // which watermark colour the new sport gets.
  function selectedSportVenue() {
    return isOtherSport() && sportVenueSelect ? (sportVenueSelect.value || '') : '';
  }

  function syncOtherSport() {
    const other = isOtherSport();
    if (sportOtherWrap) { sportOtherWrap.hidden = !other; }
    if (sportOther) { sportOther.disabled = !other; }
    if (sportVenueSelect) { sportVenueSelect.disabled = !other; }
  }

  function showVenueHint() {
    if (!sportVenue) return;
    const v = sportVenueMap[selectedSport()];
    if (isOtherSport()) {
      const chosen = selectedSportVenue();
      sportVenue.textContent = chosen === 'indoor' ? 'ในร่ม → ลายน้ำสีขาว'
        : chosen === 'outdoor' ? 'กลางแจ้ง → ลายน้ำสีดำ' : 'เลือกประเภทสนามด้วย';
      return;
    }
    sportVenue.textContent = v === 'indoor' ? 'ในร่ม → ลายน้ำสีขาว'
      : v === 'outdoor' ? 'กลางแจ้ง → ลายน้ำสีดำ' : '';
  }

  async function loadSports() {
    if (!pickerMode || !sportsUrl || !sportWrap) return;
    sportWrap.hidden = false;
    try {
      const res = await fetch(sportsUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
      const data = await res.json().catch(() => ({}));
      const list = Array.isArray(data.sports) ? data.sports : [];
      sportSelect.innerHTML = '<option value="">— กรุณาเลือกกีฬา —</option>';
      list.forEach(function (sport) {
        sportVenueMap[sport.name] = sport.venue;
        const opt = document.createElement('option');
        opt.value = sport.name;
        opt.textContent = sport.name + (sport.venue === 'indoor' ? ' (ในร่ม)' : ' (กลางแจ้ง)');
        sportSelect.append(opt);
      });
      const other = document.createElement('option');
      other.value = '__other__';
      other.textContent = '+ กีฬาอื่น (พิมพ์เอง)';
      sportSelect.append(other);
      if (!list.length) {
        sportSelect.insertAdjacentHTML('afterbegin', '<option value="">— ยังไม่มีกีฬา พิมพ์เองได้ —</option>');
      }
      syncOtherSport();
    } catch (e) {
      sportSelect.innerHTML = '<option value="">— โหลดรายการกีฬาไม่สำเร็จ —</option>';
    }
    updateUploadButton();
  }

  function selectedPosition() {
    return positionInputs.find(item => item.checked)?.value || 'top-left';
  }

  function selectedOpacity() {
    return opacityInput ? Number(opacityInput.value) : 100;
  }

  // Live preview: mirror the server's watermark math (logo width 10% of image,
  // margin 2% of the shorter side, same 9 positions) so what the user sees is what
  // the backend will actually produce.
  let watermarkLogo = null;
  let previewPhoto = null;
  if (watermarkUrl) {
    const logo = new Image();
    logo.onload = () => { watermarkLogo = logo; drawPreview(); };
    logo.src = watermarkUrl;
  }

  function drawPreview() {
    if (!previewCanvas) return;
    if (!previewPhoto) {
      previewCanvas.hidden = true;
      if (previewEmpty) previewEmpty.hidden = false;
      return;
    }
    previewCanvas.hidden = false;
    if (previewEmpty) previewEmpty.hidden = true;

    const maxEdge = 640;
    const scale = Math.min(1, maxEdge / Math.max(previewPhoto.naturalWidth, previewPhoto.naturalHeight));
    const w = Math.max(1, Math.round(previewPhoto.naturalWidth * scale));
    const h = Math.max(1, Math.round(previewPhoto.naturalHeight * scale));
    previewCanvas.width = w;
    previewCanvas.height = h;
    const ctx = previewCanvas.getContext('2d');
    ctx.clearRect(0, 0, w, h);
    ctx.drawImage(previewPhoto, 0, 0, w, h);

    if (!watermarkLogo) return;
    // Mirror the server's WATERMARK_WIDTH_RATIO.
    const logoW = Math.max(1, Math.round(w * 0.07));
    const logoH = Math.max(1, Math.round(watermarkLogo.naturalHeight * (logoW / watermarkLogo.naturalWidth)));
    const margin = Math.round(Math.min(w, h) * 0.02);
    const pos = selectedPosition();
    let x = Math.round((w - logoW) / 2);
    if (pos.endsWith('-left')) x = margin;
    else if (pos.endsWith('-right')) x = Math.max(0, w - logoW - margin);
    let y = Math.round((h - logoH) / 2);
    if (pos.startsWith('top-')) y = margin;
    else if (pos.startsWith('bottom-')) y = Math.max(0, h - logoH - margin);

    // Mirror the server: the mark is repainted black, keeping the PNG's alpha shape.
    var stamp = document.createElement('canvas');
    stamp.width = logoW;
    stamp.height = logoH;
    var sctx = stamp.getContext('2d');
    sctx.drawImage(watermarkLogo, 0, 0, logoW, logoH);
    sctx.globalCompositeOperation = 'source-in';
    sctx.fillStyle = '#000';
    sctx.fillRect(0, 0, logoW, logoH);

    ctx.globalAlpha = Math.max(0.1, Math.min(1, selectedOpacity() / 100));
    ctx.drawImage(stamp, x, y);
    ctx.globalAlpha = 1;
  }

  function loadPreviewPhoto(file) {
    if (!file) { previewPhoto = null; drawPreview(); return; }
    const image = new Image();
    const url = URL.createObjectURL(file);
    image.onload = () => { previewPhoto = image; URL.revokeObjectURL(url); drawPreview(); };
    image.onerror = () => { URL.revokeObjectURL(url); };
    image.src = url;
  }

  function updateUploadButton() {
    upload.disabled = input.files.length === 0 || !selectedUploader() || !configured
      || (pickerMode && !selectedSport()) || (isOtherSport() && !selectedSportVenue());
  }

  function clearPreviews() {
    previewUrls.forEach(url => URL.revokeObjectURL(url));
    previewUrls = [];
    previews.innerHTML = '';
    previews.hidden = true;
  }

  function syncInputFiles() {
    const transfer = new DataTransfer();
    selectedFiles.forEach(file => transfer.items.add(file));
    input.files = transfer.files;
  }

  function removeAt(index) {
    selectedFiles.splice(index, 1);
    syncInputFiles();
    renderPreviews();
    loadPreviewPhoto(selectedFiles[0] || null);
    selection.textContent = selectedFiles.length ? `เลือกแล้ว ${selectedFiles.length} ไฟล์` : 'ยังไม่ได้เลือกไฟล์';
    updateUploadButton();
  }

  // Rendering a thumbnail (an <img> + its own object URL) for every file freezes the tab once a
  // batch reaches the hundreds — the browser decodes them all at once. So only the first
  // PREVIEW_CAP get a thumbnail; the rest are represented by a single count tile.
  const PREVIEW_CAP = 24;

  function renderPreviews() {
    clearPreviews();
    if (!selectedFiles.length) return;
    previews.hidden = false;
    const shown = selectedFiles.slice(0, PREVIEW_CAP);
    shown.forEach((file, index) => {
      const figure = document.createElement('figure');
      const image = document.createElement('img');
      const caption = document.createElement('figcaption');
      const remove = document.createElement('button');
      const url = URL.createObjectURL(file);
      previewUrls.push(url);
      image.src = url;
      image.alt = `ตัวอย่าง ${file.name}`;
      image.loading = 'lazy';
      caption.textContent = file.name;
      caption.title = file.name;
      remove.type = 'button';
      remove.className = 'iep-preview-remove';
      remove.textContent = '×';
      remove.setAttribute('aria-label', `ลบ ${file.name}`);
      remove.title = 'ลบรูปนี้';
      // The preview grid sits inside the drop <label>, so a bare click would also open
      // the file picker — swallow the event before it bubbles to the label.
      remove.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        removeAt(index);
      });
      figure.append(image, remove, caption);
      previews.appendChild(figure);
    });
    const extra = selectedFiles.length - shown.length;
    if (extra > 0) {
      const more = document.createElement('figure');
      more.className = 'iep-preview-more';
      more.textContent = '+ อีก ' + extra + ' รูป';
      previews.appendChild(more);
    }
  }

  uploaderSelect.addEventListener('change', () => {
    const isOther = uploaderSelect.value === '__other__';
    otherWrap.hidden = !isOther;
    otherName.disabled = !isOther;
    if (isOther) otherName.focus();
    updateUploadButton();
  });

  otherName.addEventListener('input', updateUploadButton);

  positionInputs.forEach(item => item.addEventListener('change', () => {
    if (positionLabel) positionLabel.textContent = positionNames[selectedPosition()] || selectedPosition();
    drawPreview();
  }));

  if (opacityInput) {
    opacityInput.addEventListener('input', () => {
      if (opacityLabel) opacityLabel.textContent = `${selectedOpacity()}%`;
      drawPreview();
    });
  }

  input.addEventListener('change', () => {
    selectedFiles = Array.from(input.files);
    const count = selectedFiles.length;
    selection.textContent = count ? `เลือกแล้ว ${count} ไฟล์` : 'ยังไม่ได้เลือกไฟล์';
    renderPreviews();
    loadPreviewPhoto(selectedFiles[0] || null);
    updateUploadButton();
    results.innerHTML = '';
  });

  root.querySelector('[data-drop]').addEventListener('dragover', event => {
    event.preventDefault();
    event.currentTarget.classList.add('is-dragging');
  });
  root.querySelector('[data-drop]').addEventListener('dragleave', event => {
    event.currentTarget.classList.remove('is-dragging');
  });
  root.querySelector('[data-drop]').addEventListener('drop', event => {
    event.preventDefault();
    event.currentTarget.classList.remove('is-dragging');
    const images = Array.from(event.dataTransfer?.files || []).filter(file => file.type.startsWith('image/'));
    if (!images.length) return;
    const transfer = new DataTransfer();
    images.forEach(file => transfer.items.add(file));
    input.files = transfer.files;
    input.dispatchEvent(new Event('change', {bubbles: true}));
  });

  window.addEventListener('beforeunload', clearPreviews);

  // Large batches (500+ files) blow past PHP's max_file_uploads and the
  // Apache request Timeout if sent as one giant request. Split into fixed-size
  // chunks sent sequentially instead — each chunk is its own short request,
  // so one slow/failed chunk doesn't take the whole batch down with it.
  const UPLOAD_CHUNK_SIZE = 10;
  // Cloudflare rejects request bodies over 100 MB, and a rejected body never reaches PHP — the
  // browser just gets an error page instead of JSON. Camera originals run 15-20 MB each, so cap a
  // request by total bytes as well as by file count and stay well under that limit.
  const UPLOAD_CHUNK_BYTES = 60 * 1024 * 1024;
  // Send several chunks at once so the 8-core server watermarks them in parallel instead of one
  // file at a time. Each ImageMagick op uses a single thread, so N concurrent chunks ≈ N cores.
  // Set to 6 on the 8-core box: near-full throughput while leaving ~2 cores for the database,
  // Immich and the tunnel, so the site stays responsive for viewers during a big upload. Each
  // request stays far under the Cloudflare 100s edge timeout, and server-side name allocation is
  // atomic, so concurrent chunks never collide.
  const UPLOAD_CONCURRENCY = 6;

  function uploadChunk(files, uploaderName, watermarkPosition) {
    return new Promise((resolve) => {
      const form = new FormData();
      files.forEach(file => form.append('photos[]', file, file.name));
      form.append('uploader', uploaderName);
      form.append('watermarkPosition', watermarkPosition);
      form.append('watermarkOpacity', String(selectedOpacity()));
      if (albumToken) { form.append('album', albumToken); }
      if (selectedSport()) { form.append('sport', selectedSport()); }
      if (selectedSportVenue()) { form.append('sportVenue', selectedSportVenue()); }
      const xhr = new XMLHttpRequest();
      xhr.open('POST', root.dataset.uploadUrl);
      xhr.setRequestHeader('requesttoken', csrf);
      xhr.onload = () => {
        // Surface what actually went wrong instead of one catch-all message: a 401 (public upload
        // disabled), a 413 (body too large) and malformed JSON each need a different fix.
        const describe = () => {
          if (xhr.status === 401 || xhr.status === 403) { return 'เซิร์ฟเวอร์ไม่อนุญาต (' + xhr.status + ') — อาจต้องเข้าสู่ระบบ'; }
          if (xhr.status === 413) { return 'ไฟล์ชุดนี้ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (413)'; }
          if (xhr.status >= 500) { return 'เซิร์ฟเวอร์ผิดพลาด (' + xhr.status + ')'; }
          return 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (' + xhr.status + ')';
        };
        try {
          const data = JSON.parse(xhr.responseText);
          if (Array.isArray(data.results)) { resolve(data.results); return; }
          const message = data.error || data.message || describe();
          resolve(files.map(file => ({source: file.name, error: message, ok: false})));
        } catch (_) {
          resolve(files.map(file => ({source: file.name, error: describe(), ok: false})));
        }
      };
      xhr.onerror = () => {
        resolve(files.map(file => ({source: file.name, error: 'เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ', ok: false})));
      };
      xhr.send(form);
    });
  }

  upload.addEventListener('click', async () => {
    const uploaderName = selectedUploader();
    const watermarkPosition = selectedPosition();
    const allFiles = Array.from(input.files);
    if (!allFiles.length || !uploaderName || !configured) return;
    if (pickerMode && !selectedSport()) { status.textContent = 'กรุณาเลือกประเภทกีฬา'; return; }
    if (isOtherSport() && !selectedSportVenue()) { status.textContent = 'กรุณาเลือกว่ากีฬานี้เล่นกลางแจ้งหรือในร่ม'; return; }

    upload.disabled = true;
    progress.hidden = false;
    bar.style.width = '0%';
    results.innerHTML = '';
    const allResults = [];
    const chunks = [];
    let current = [];
    let currentBytes = 0;
    allFiles.forEach(file => {
      const size = file.size || 0;
      if (current.length && (current.length >= UPLOAD_CHUNK_SIZE || currentBytes + size > UPLOAD_CHUNK_BYTES)) {
        chunks.push(current);
        current = [];
        currentBytes = 0;
      }
      current.push(file);
      currentBytes += size;
    });
    if (current.length) { chunks.push(current); }

    let nextChunk = 0;
    function absorb(chunkResults) {
      chunkResults.forEach(item => {
        allResults.push(item);
        const li = document.createElement('li');
        li.className = item.ok ? 'ok' : 'error';
        li.textContent = item.ok ? `${item.source} → ${item.name}` : `${item.source}: ${item.error}`;
        results.appendChild(li);
      });
      status.textContent = `กำลังส่งรูปของ ${uploaderName} เข้า NAS เปลี่ยนชื่อ และใส่ลายน้ำ… (${allResults.length}/${allFiles.length})`;
      bar.style.width = `${Math.round((allResults.length / allFiles.length) * 100)}%`;
    }
    // A pool of UPLOAD_CONCURRENCY workers, each pulling the next chunk until none remain.
    async function worker() {
      while (nextChunk < chunks.length) {
        const mine = chunks[nextChunk++];
        absorb(await uploadChunk(mine, uploaderName, watermarkPosition));
      }
    }
    await Promise.all(
      Array.from({length: Math.min(UPLOAD_CONCURRENCY, chunks.length)}, worker)
    );

    const ok = allResults.filter(item => item.ok).length;
    status.textContent = `สำเร็จ ${ok} จาก ${allResults.length} ไฟล์ — โฟลเดอร์ ${uploaderName}`;
    const allSucceeded = allResults.length > 0 && allResults.every(item => item.ok);
    if (allSucceeded) {
      input.value = '';
      selectedFiles = [];
      clearPreviews();
      previewPhoto = null;
      drawPreview();
      selection.textContent = 'อัปโหลดเสร็จแล้ว — เลือกรูปใหม่ได้เลย';
    }
    updateUploadButton();
    setTimeout(() => { progress.hidden = true; bar.style.width = '0'; }, 1000);
  });

  if (sportSelect) {
    sportSelect.addEventListener('change', function () {
      syncOtherSport(); showVenueHint(); updateUploadButton();
      if (isOtherSport() && sportOther) { sportOther.focus(); }
    });
  }
  if (sportOther) { sportOther.addEventListener('input', updateUploadButton); }
  if (sportVenueSelect) {
    sportVenueSelect.addEventListener('change', function () { showVenueHint(); updateUploadButton(); });
  }
  loadSports();
})();
