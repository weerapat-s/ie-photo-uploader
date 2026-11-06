(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('#iephoto-album');
    if (!root) { return; }
    document.documentElement.lang = 'th';
    var lastLightboxTrigger = null;
    var grid = root.querySelector('[data-grid]');
    var status = root.querySelector('[data-status]');
    var empty = root.querySelector('[data-empty]');
    var search = root.querySelector('[data-search]');
    var photographer = root.querySelector('[data-photographer]');
    var more = root.querySelector('[data-more]');
    var loader = root.querySelector('[data-loader]');
    var lightbox = root.querySelector('[data-lightbox]');
    var lightboxImage = root.querySelector('[data-lightbox-image]');
    var lightboxSpin = root.querySelector('[data-lightbox-spin]');
    var lightboxTitle = root.querySelector('[data-lightbox-title]');
    var lightboxMeta = root.querySelector('[data-lightbox-meta]');
    var download = root.querySelector('[data-download]');
    var downloadWeb = root.querySelector('[data-download-web]');
    var toast = root.querySelector('[data-toast]');
    var selectionToggle = root.querySelector('[data-selection-toggle]');
    var selectionBar = root.querySelector('[data-selection-bar]');
    var selectedCount = root.querySelector('[data-selected-count]');
    var selectionClear = root.querySelector('[data-selection-clear]');
    var downloadSelected = root.querySelector('[data-download-selected]');
    var loadProgress = root.querySelector('[data-load-progress]');
    var loadSummary = root.querySelector('[data-load-summary]');
    var loadMeter = root.querySelector('[data-load-meter]');
    var images = [];
    var filtered = [];
    var selectedPaths = new Set();
    var page = 1;
    var active = 0;
    var filteredTotal = 0;
    var overallTotal = 0;
    var selecting = false;
    var lightboxOpen = false;
    var albumRendered = false;
    // Where the viewer currently is inside the album, as path segments: [] is the album root,
    // ['บาส'] is inside that sport, ['บาส','2026-09-01'] a single match day. Folder cards push a
    // segment, the breadcrumb pops one, so every album nests as deep as its folders go.
    var folderStack = [];
    var requestVersion = 0;
    var searchTimer = 0;
    var hasMore = false;
    var nextPageLoad = null;
    var viewTracked = false;
    var scrollHost = document.querySelector('#content');
    var lockedScrollTop = 0;
    var swipePointerId = null;
    var swipeStartX = 0;
    var swipeStartY = 0;
    var swipeDeltaX = 0;
    var swipeDeltaY = 0;

    function text(value) { return String(value || ''); }
    function uploadedBy(item) { return text(item.uploadedBy || item.photographer || 'IE-PHOTO'); }
    function itemPath(item) { return text(item && item.path); }
    function formatBytes(bytes) {
      var n = Number(bytes || 0);
      if (n < 1024) { return n + ' B'; }
      if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
      if (n < 1073741824) { return (n / 1048576).toFixed(1) + ' MB'; }
      return (n / 1073741824).toFixed(1) + ' GB';
    }
    function showToast(message) {
      toast.textContent = message;
      toast.hidden = false;
      window.clearTimeout(showToast.timer);
      showToast.timer = window.setTimeout(function () { toast.hidden = true; }, 3200);
    }
    function copyLink() {
      var value = window.location.href.split('#')[0];
      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(value).catch(function () { return fallbackCopy(value); });
      }
      return fallbackCopy(value);
    }
    function fallbackCopy(value) {
      var input = document.createElement('textarea');
      input.value = value;
      input.style.position = 'fixed'; input.style.opacity = '0';
      document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove();
      return Promise.resolve();
    }
    function hideLoader() {
      loader.classList.add('is-leaving');
      window.setTimeout(function () { loader.hidden = true; }, 470);
    }

    function trackAlbumView() {
      if (viewTracked) { return; }
      var url = text(root.dataset.trackViewUrl);
      var token = text(root.dataset.token);
      if (!url || !token) { return; }
      var storageKey = 'iephoto-album-view:' + token;
      try {
        if (window.sessionStorage.getItem(storageKey) === '1') { return; }
      } catch (error) {}
      viewTracked = true;
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {Accept: 'application/json'}
      }).then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }
        try { window.sessionStorage.setItem(storageKey, '1'); } catch (error) {}
      }).catch(function () {
        viewTracked = false;
      });
    }

    function updateLoadProgress() {
      if (!loadProgress || !loadSummary || !loadMeter || filteredTotal <= 0) {
        if (loadProgress) { loadProgress.hidden = true; }
        return;
      }
      loadProgress.hidden = false;
      loadSummary.textContent = 'โหลดแล้ว ' + String(filtered.length) + ' จาก ' + String(filteredTotal) + ' รูป'
        + (overallTotal !== filteredTotal ? ' · จากทั้งงาน ' + String(overallTotal) + ' รูป' : '');
      loadMeter.max = Math.max(1, filteredTotal);
      loadMeter.value = Math.min(filtered.length, filteredTotal);
    }

    function updateSelectionUI() {
      root.classList.toggle('is-selecting', selecting);
      selectionToggle.setAttribute('aria-pressed', selecting ? 'true' : 'false');
      selectionToggle.textContent = selecting ? 'ยกเลิกการเลือก' : 'เลือกรูปหลายภาพ';
      selectedCount.textContent = String(selectedPaths.size);
      selectionBar.hidden = selectedPaths.size === 0;
      downloadSelected.disabled = selectedPaths.size === 0;
      Array.prototype.forEach.call(grid.querySelectorAll('.iepa-card'), function (card) {
        var path = card.dataset.path || '';
        var isSelected = selectedPaths.has(path);
        card.dataset.selected = isSelected ? 'true' : 'false';
        var toggle = card.querySelector('[data-select-photo]');
        if (toggle) {
          toggle.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
          toggle.textContent = isSelected ? '✓' : '';
        }
      });
    }

    function clearSelection() {
      selectedPaths.clear();
      selecting = false;
      updateSelectionUI();
    }

    function toggleSelection(item) {
      var path = itemPath(item);
      if (!path) { return; }
      if (selectedPaths.has(path)) {
        selectedPaths.delete(path);
      } else if (selectedPaths.size >= 60) {
        showToast('เลือกดาวน์โหลดได้สูงสุด 60 รูปต่อครั้ง');
        return;
      } else {
        selectedPaths.add(path);
      }
      updateSelectionUI();
    }

    // Fast whole-album download: pull the edge-cached originals in parallel (many connections,
    // each ~6 Mbps, so aggregate is several times a single ZIP stream) and stream each file
    // straight to a folder the viewer picks — no server ZIP, no browser memory blow-up on GB
    // albums. Chrome/Edge only (File System Access API); other browsers keep the ZIP button.
    function downloadWholeAlbum(useWeb) {
      var token = text(root.dataset.token);
      if (!token) { return; }
      if (!window.showDirectoryPicker) {
        showToast('ดาวน์โหลดเร็วรองรับบน Chrome/Edge — ใช้ปุ่ม ZIP แทนได้');
        return;
      }
      var btn = root.querySelector('[data-download-all]');
      var cancelBtn = root.querySelector('[data-download-cancel]');
      var controller = window.AbortController ? new AbortController() : null;
      var downloadAborted = false;
      function stopDownload() {
        downloadAborted = true;
        if (controller) { try { controller.abort(); } catch (e) {} }
        if (cancelBtn) { cancelBtn.hidden = true; }
        if (btn) { btn.disabled = false; }
        showToast('ยกเลิกดาวน์โหลดแล้ว');
      }
      if (cancelBtn) {
        cancelBtn.hidden = false;
        cancelBtn.onclick = stopDownload;
      }
      var origin = window.location.origin;
      // `v` is the photo's mtime, so every version of a file gets its own edge-cache key and a
      // bad cached copy can never outlive the file it came from.
      function origUrl(rel, version) {
        var enc = String(rel).split('/').map(encodeURIComponent).join('/');
        return origin + '/index.php/apps/ie_drive/p/orig/' + token + '/img.jpg?p=/' + enc
          + (version ? '&v=' + encodeURIComponent(version) : '');
      }
      window.showDirectoryPicker().then(function (dir) {
        if (btn) { btn.disabled = true; }
        if (cancelBtn) { cancelBtn.hidden = false; }
        showToast('กำลังรวบรวมรายการรูป…');
        var guard = 0;
        function loadAll() {
          if (downloadAborted || !hasMore || guard > 2000) { return Promise.resolve(); }
          guard++;
          return loadNextPage().then(loadAll);
        }
        return loadAll().then(function () {
          var all = images.slice();
          var total = all.length;
          if (downloadAborted) { return; }
          if (!total) {
            showToast('ไม่มีรูปให้ดาวน์โหลด');
            if (btn) { btn.disabled = false; }
            if (cancelBtn) { cancelBtn.hidden = true; }
            return;
          }
          var idx = 0, done = 0, failed = 0, CONC = 6;
          function worker() {
            if (downloadAborted || idx >= all.length) { return Promise.resolve(); }
            var item = all[idx++];
            var rel = itemPath(item);
            if (!rel) { return worker(); }
            var name = String(rel).split('/').pop() || ('photo-' + idx + '.jpg');
            var srcUrl = (useWeb && item && item.fullUrl) ? text(item.fullUrl) : origUrl(rel, item && item.timestamp);
            return fetch(srcUrl, {credentials: 'omit', signal: controller ? controller.signal : undefined})
              .then(function (res) { if (!res.ok) { throw new Error('http'); } return res.blob(); })
              .then(function (blob) { return dir.getFileHandle(name, {create: true}).then(function (fh) {
                return fh.createWritable().then(function (w) { return w.write(blob).then(function () { return w.close(); }); });
              }); })
              .then(function () { done++; }, function () { failed++; })
              .then(function () {
                if (downloadAborted) { return; }
                showToast('ดาวน์โหลด ' + (done + failed) + '/' + total + (failed ? (' · พลาด ' + failed) : ''));
                return worker();
              });
          }
          var pool = [];
          for (var i = 0; i < Math.min(CONC, total); i++) { pool.push(worker()); }
          return Promise.all(pool).then(function () {
            if (cancelBtn) { cancelBtn.hidden = true; }
            if (btn) { btn.disabled = false; }
            if (downloadAborted) {
              showToast('หยุดแล้ว — บันทึกไป ' + done + '/' + total + ' รูป');
              return;
            }
            showToast('เสร็จ ' + done + '/' + total + ' รูป' + (failed ? (' · พลาด ' + failed) : ''));
          });
        });
      }).catch(function (e) {
        if (btn) { btn.disabled = false; }
        if (cancelBtn) { cancelBtn.hidden = true; }
        if (downloadAborted || (e && e.name === 'AbortError')) { return; }
        showToast('ดาวน์โหลดไม่สำเร็จ');
      });
    }

    function startSelectedDownload() {
      var action = text(root.dataset.downloadSelectedUrl);
      if (!action || selectedPaths.size === 0) { return; }
      var form = document.createElement('form');
      var field = document.createElement('input');
      form.method = 'post';
      form.action = action;
      form.hidden = true;
      field.type = 'hidden';
      field.name = 'paths';
      field.value = JSON.stringify(Array.from(selectedPaths));
      form.appendChild(field);
      document.body.appendChild(form);
      downloadSelected.disabled = true;
      showToast('กำลังเตรียมไฟล์ ZIP ' + String(selectedPaths.size) + ' รูป…');
      form.submit();
      window.setTimeout(function () {
        form.remove();
        downloadSelected.disabled = false;
      }, 1200);
    }

    function lockBackgroundScroll() {
      if (scrollHost) {
        lockedScrollTop = scrollHost.scrollTop;
        scrollHost.classList.add('iepa-scroll-locked');
      } else {
        lockedScrollTop = window.scrollY || 0;
      }
      document.documentElement.classList.add('iepa-no-scroll');
      document.body.classList.add('iepa-no-scroll');
    }
    function unlockBackgroundScroll() {
      document.documentElement.classList.remove('iepa-no-scroll');
      document.body.classList.remove('iepa-no-scroll');
      if (scrollHost) {
        scrollHost.classList.remove('iepa-scroll-locked');
        scrollHost.scrollTop = lockedScrollTop;
      } else {
        window.scrollTo(0, lockedScrollTop);
      }
    }
    function resetLightboxSwipe() {
      swipePointerId = null;
      swipeDeltaX = 0;
      swipeDeltaY = 0;
      lightboxImage.classList.remove('is-swiping');
      lightboxImage.style.transform = '';
      lightboxImage.style.opacity = '';
    }
    function beginLightboxSwipe(event) {
      if (!lightboxOpen || filtered.length < 2 || event.button > 0) { return; }
      if (event.target && event.target.closest && event.target.closest('button,a')) { return; }
      swipePointerId = event.pointerId;
      swipeStartX = event.clientX;
      swipeStartY = event.clientY;
      swipeDeltaX = 0;
      swipeDeltaY = 0;
      lightboxImage.classList.add('is-swiping');
      try { lightbox.setPointerCapture(event.pointerId); } catch (error) {}
    }
    function dragLightboxSwipe(event) {
      if (swipePointerId === null || event.pointerId !== swipePointerId) { return; }
      swipeDeltaX = event.clientX - swipeStartX;
      swipeDeltaY = event.clientY - swipeStartY;
      if (Math.abs(swipeDeltaX) > 8 && Math.abs(swipeDeltaX) > Math.abs(swipeDeltaY)) {
        event.preventDefault();
        var travel = Math.max(-110, Math.min(110, swipeDeltaX * .28));
        lightboxImage.style.transform = 'translateX(' + String(travel) + 'px) scale(.99)';
        lightboxImage.style.opacity = String(Math.max(.62, 1 - Math.abs(swipeDeltaX) / 700));
      }
    }
    function finishLightboxSwipe(event) {
      if (swipePointerId === null || event.pointerId !== swipePointerId) { return; }
      try {
        if (lightbox.hasPointerCapture(event.pointerId)) { lightbox.releasePointerCapture(event.pointerId); }
      } catch (error) {}
      var shouldMove = Math.abs(swipeDeltaX) >= 48
        && Math.abs(swipeDeltaX) > Math.abs(swipeDeltaY) * 1.15;
      var direction = swipeDeltaX < 0 ? 1 : -1;
      resetLightboxSwipe();
      if (shouldMove) { move(direction); }
    }

    function card(item, index) {
      var shell = document.createElement('article');
      shell.className = 'iepa-card';
      shell.dataset.path = itemPath(item);
      var button = document.createElement('button');
      button.type = 'button'; button.className = 'iepa-card-open';
      button.setAttribute('aria-label', 'ดูภาพ ' + text(item.name));
      var image = document.createElement('img');
      var previewUrl = text(item.previewUrl);
      // IE Drive's regular 720x560 preview crops portraits. Asking for an equal
      // width/height box keeps the original composition while staying lightweight.
      previewUrl = previewUrl.replace(/\/(\d+)\/(\d+)\/img\.jpg(?=\?|$)/, function (_match, width) {
        return '/' + width + '/' + width + '/img.jpg';
      });
      image.src = previewUrl + (previewUrl.indexOf('?') === -1 ? '?' : '&') + 'fit=full-v3';
      image.alt = text(item.name); image.loading = index < 8 ? 'eager' : 'lazy';
      var caption = document.createElement('span');
      var name = document.createElement('strong'); name.textContent = text(item.name);
      var owner = document.createElement('small'); owner.textContent = uploadedBy(item);
      caption.appendChild(name); caption.appendChild(owner);
      button.appendChild(image); button.appendChild(caption);
      button.addEventListener('click', function () {
        if (selecting) { toggleSelection(item); }
        else { openLightbox(filtered.indexOf(item)); }
      });
      var select = document.createElement('button');
      select.type = 'button';
      select.className = 'iepa-card-select';
      select.setAttribute('data-select-photo', '');
      select.setAttribute('aria-label', 'เลือกรูป ' + text(item.name));
      select.setAttribute('aria-pressed', selectedPaths.has(itemPath(item)) ? 'true' : 'false');
      select.textContent = selectedPaths.has(itemPath(item)) ? '✓' : '';
      select.addEventListener('click', function () { toggleSelection(item); });
      shell.dataset.selected = selectedPaths.has(itemPath(item)) ? 'true' : 'false';
      shell.appendChild(button);
      shell.appendChild(select);
      return shell;
    }
    function renderImages() {
      filtered = images.slice();
      grid.replaceChildren();
      filtered.forEach(function (item, index) { grid.appendChild(card(item, index)); });
      grid.hidden = filtered.length === 0;
      empty.hidden = filtered.length !== 0;
      updateLoadProgress();
      updateSelectionUI();
    }
    function showLightboxSpinner() {
      if (lightboxSpin) { lightboxSpin.hidden = false; }
      lightboxImage.classList.add('is-loading');
    }
    function hideLightboxSpinner() {
      if (lightboxSpin) { lightboxSpin.hidden = true; }
      lightboxImage.classList.remove('is-loading');
    }
    if (lightboxImage) {
      // The full-resolution originals can be several MB, so show a spinner from the
      // moment src changes until the browser has actually decoded the new image.
      lightboxImage.addEventListener('load', hideLightboxSpinner);
      lightboxImage.addEventListener('error', hideLightboxSpinner);
    }
    function updateLightbox() {
      var item = filtered[active];
      if (!item) { return; }
      var nextSrc = text(item.fullUrl);
      // Only spin if the image really has to fetch. A cached image fires no load
      // event on some browsers, which would otherwise leave the spinner stuck.
      if (nextSrc !== lightboxImage.getAttribute('src')) {
        showLightboxSpinner();
      }
      lightboxImage.src = nextSrc;
      if (lightboxImage.complete && lightboxImage.naturalWidth > 0) {
        hideLightboxSpinner();
      }
      lightboxImage.alt = text(item.name);
      lightboxTitle.textContent = text(item.name);
      lightboxMeta.textContent = 'รูปที่ ' + String(active + 1) + ' / ' + String(Math.max(filteredTotal, filtered.length))
        + ' · อัปโหลดโดย ' + uploadedBy(item) + ' · ' + text(item.date) + ' · ' + formatBytes(item.size);
      download.href = text(item.downloadUrl);
      download.setAttribute('download', text(item.name));
      if (downloadWeb) {
        downloadWeb.href = text(item.fullUrl);
        downloadWeb.setAttribute('download', text(item.name));
      }
    }
    function triggerDownload(event, useWeb) {
      // Mobile browsers frequently ignore the <a download> attribute for images
      // served inline and just open them in a new view instead. Fetch the bytes as
      // a blob and hand the browser an object URL so the save actually fires.
      var item = filtered[active];
      if (!item) { return; }
      var url = text(useWeb ? item.fullUrl : item.downloadUrl);
      if (!url) { return; }
      event.preventDefault();
      var name = text(item.name) || 'photo.jpg';
      var busyBtn = useWeb && downloadWeb ? downloadWeb : download;
      busyBtn.classList.add('is-busy');
      fetch(url, { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) { throw new Error('HTTP ' + res.status); }
          return res.blob();
        })
        .then(function (blob) {
          var objectUrl = URL.createObjectURL(blob);
          var temp = document.createElement('a');
          temp.href = objectUrl;
          temp.download = name;
          document.body.appendChild(temp);
          temp.click();
          document.body.removeChild(temp);
          window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 4000);
        })
        .catch(function () {
          // Fallback: let the browser follow the original link in a new tab so the
          // user can still save manually if the blob fetch was blocked.
          window.open(url, '_blank', 'noopener');
        })
        .then(function () { busyBtn.classList.remove('is-busy'); });
    }
    function openLightbox(index) {
      if (index < 0 || !filtered[index]) { return; }
      lastLightboxTrigger = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
      active = index; lightboxOpen = true; updateLightbox(); lightbox.hidden = false;
      lockBackgroundScroll(); root.querySelector('[data-close]').focus();
    }
    function closeLightbox() {
      lightbox.hidden = true; lightboxOpen = false; lightboxImage.removeAttribute('src');
      resetLightboxSwipe(); unlockBackgroundScroll();
      if (lastLightboxTrigger && document.contains(lastLightboxTrigger)) { try { lastLightboxTrigger.focus({ preventScroll: true }); } catch (e) { lastLightboxTrigger.focus(); } }
      lastLightboxTrigger = null;
    }
    function move(offset) {
      if (!lightboxOpen || filtered.length === 0) { return; }
      if (offset > 0 && active === filtered.length - 1 && hasMore) {
        var nextIndex = filtered.length;
        var currentItem = filtered[active];
        lightboxMeta.textContent = 'กำลังโหลดรูปถัดไป…';
        loadNextPage().then(function (added) {
          if (!lightboxOpen) { return; }
          if (filtered[active] !== currentItem) { return; }
          if (added && filtered[nextIndex]) { active = nextIndex; }
          else if (filtered.length > 1) { active = 0; }
          updateLightbox();
        });
        return;
      }
      if (filtered.length < 2) { return; }
      active = (active + offset + filtered.length) % filtered.length;
      updateLightbox();
      if (offset > 0 && hasMore && active >= filtered.length - 5) { loadNextPage(); }
    }
    // Rebuilt on every load: the uploader list belongs to the folder being viewed, not the album.
    function appendOptions(album) {
      var names = Array.isArray(album.photographers) ? album.photographers : [];
      var keep = text(photographer.value);
      photographer.replaceChildren();
      var all = document.createElement('option'); all.value = ''; all.textContent = 'ทุกคน'; photographer.appendChild(all);
      names.forEach(function (name) { var option = document.createElement('option'); option.value = name; option.textContent = name; photographer.appendChild(option); });
      if (keep && names.indexOf(keep) !== -1) { photographer.value = keep; }
    }
    // Random preview strip for the top of the page. Purely decorative: it is marked
    // aria-hidden and the same photos are reachable through the grid below.
    function renderHeroMosaic(urls) {
      var host = root.querySelector('[data-hero-mosaic]');
      if (!host) { return; }
      if (!urls.length) { host.hidden = true; return; }
      host.replaceChildren();
      urls.forEach(function (url) {
        var cell = document.createElement('span');
        cell.className = 'iepa-hero__cell';
        var img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        cell.appendChild(img);
        host.appendChild(cell);
      });
      host.hidden = false;
    }

    // One card per sub-folder of wherever the viewer currently stands. Clicking a card descends a
    // level rather than filtering, so a sport opens onto its match days and those onto their own
    // folders — the same at every depth, for every sport.
    function renderFolders(folders) {
      var section = root.querySelector('[data-folder-section]');
      var grid = root.querySelector('[data-folder-grid]');
      var countEl = root.querySelector('[data-folder-count]');
      if (!section || !grid) { return; }

      var gallery = root.querySelector('[data-gallery-section]');
      var owner = text(photographer.value).trim();
      var queryText = text(search.value).trim();
      // A name filter or a search is a view across folders, so the picker steps aside for it.
      var filtering = owner !== '' || queryText !== '';

      renderPathbar();

      if (!folders.length || filtering) {
        section.hidden = true;
        if (gallery) { gallery.hidden = false; }   // nothing left to split: photos fill the page
        return;
      }

      // showing the folder picker: the flat "all photos" grid would just duplicate it
      if (gallery) { gallery.hidden = true; }

      if (countEl) { countEl.textContent = folders.length + ' โฟลเดอร์'; }
      grid.replaceChildren();
      folders.forEach(function (folder) {
        var card = document.createElement('button');
        card.type = 'button';
        card.className = 'iepa-folder';
        card.dataset.folderName = folder.name;

        var mos = document.createElement('div');
        mos.className = 'iepa-folder__mosaic';
        (folder.mosaic || []).slice(0, 24).forEach(function (url) {
          var img = document.createElement('img');
          img.src = url; img.alt = ''; img.loading = 'lazy'; img.decoding = 'async';
          mos.appendChild(img);
        });

        // Badge + arrow make it read as "a place to go into", not another photo to open.
        var badge = document.createElement('span');
        badge.className = 'iepa-folder__badge';
        badge.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6z"/></svg>โฟลเดอร์';
        var arrow = document.createElement('span');
        arrow.className = 'iepa-folder__arrow';
        arrow.setAttribute('aria-hidden', 'true');
        arrow.textContent = '→';
        mos.appendChild(badge); mos.appendChild(arrow);

        var cap = document.createElement('span');
        cap.className = 'iepa-folder__cap';
        var nm = document.createElement('strong');
        nm.textContent = folder.name;
        var sub = document.createElement('small');
        sub.textContent = Number(folder.photos || 0).toLocaleString('th-TH') + ' รูป';
        cap.appendChild(nm); cap.appendChild(sub);

        card.appendChild(mos); card.appendChild(cap);
        grid.appendChild(card);
      });
      section.hidden = false;
    }

    // Counts describe the folder currently in view, so this is rebuilt on every load rather than
    // once per page. The sub-folder names are deliberately not listed here: they are match days or
    // camera folders, not people, and the folder cards below already show them with their counts.
    function renderMeta(album) {
      var meta = root.querySelector('[data-album-meta]');
      if (!meta) { return; }
      meta.replaceChildren();
      var values = [text(album.photoCount) + ' รูป', formatBytes(album.totalBytes), album.updatedAt ? 'อัปเดต ' + new Date(album.updatedAt * 1000).toLocaleDateString('th-TH') : ''];
      if (album.isNew) { values.unshift('งานใหม่'); }
      values.filter(Boolean).forEach(function (value, index) { var span = document.createElement('span'); span.textContent = value; if (album.isNew && index === 0) { span.className = 'is-new'; } meta.appendChild(span); });
    }
    function renderAlbum(payload) {
      var album = payload.album || {};
      document.title = text(album.name) + ' - IE-PHOTO';
      root.querySelector('[data-album-name]').textContent = text(album.name || 'IE-PHOTO Album');
      renderMeta(album);
      renderHeroMosaic(album.heroMosaic || []);
      renderFolders(album.folders || []);
      appendOptions(album);
    }
    function goToDepth(depth) {
      folderStack = folderStack.slice(0, depth);
      photographer.value = '';
      search.value = '';
      reloadFilters();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function renderPathbar() {
      var bar = root.querySelector('[data-pathbar]');
      var crumbs = root.querySelector('[data-pathbar-crumbs]');
      if (!bar || !crumbs) { return; }
      var owner = text(photographer.value).trim();
      var queryText = text(search.value).trim();
      if (folderStack.length === 0 && !owner && !queryText) { bar.hidden = true; return; }
      bar.hidden = false;
      crumbs.replaceChildren();
      function addCrumb(label, clickDepth) {
        if (crumbs.children.length) {
          var sep = document.createElement('span');
          sep.className = 'iepa-pathbar__sep';
          sep.textContent = '›';
          sep.setAttribute('aria-hidden', 'true');
          crumbs.appendChild(sep);
        }
        if (clickDepth === null) {
          var current = document.createElement('span');
          current.className = 'iepa-pathbar__current';
          current.textContent = label;
          crumbs.appendChild(current);
          return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'iepa-pathbar__crumb';
        btn.textContent = label;
        btn.addEventListener('click', function () { goToDepth(clickDepth); });
        crumbs.appendChild(btn);
      }
      var isLastFolder = folderStack.length > 0 && !owner && !queryText;
      addCrumb('ทั้งหมด', (folderStack.length === 0 && !owner && !queryText) ? null : 0);
      folderStack.forEach(function (segment, i) {
        addCrumb(segment, (isLastFolder && i === folderStack.length - 1) ? null : i + 1);
      });
      if (owner) { addCrumb(owner, queryText ? folderStack.length : null); }
      if (queryText) { addCrumb('ค้นหา "' + queryText + '"', null); }
    }
    function dataUrl(nextPage) {
      var url = new URL(root.dataset.dataUrl, window.location.origin);
      url.searchParams.set('page', String(nextPage));
      url.searchParams.set('limit', '60');
      var owner = text(photographer.value).trim();
      var query = text(search.value).trim();
      var dir = folderStack.join('/');
      if (owner) { url.searchParams.set('photographer', owner); } else { url.searchParams.delete('photographer'); }
      if (query) { url.searchParams.set('q', query); } else { url.searchParams.delete('q'); }
      if (dir) { url.searchParams.set('folderPath', dir); } else { url.searchParams.delete('folderPath'); }
      return url.toString();
    }
    function load(nextPage, replace) {
      var version = ++requestVersion;
      if (replace) {
        status.textContent = 'กำลังค้นหารูปจากทั้งอัลบั้ม…';
        status.hidden = false; empty.hidden = true; grid.hidden = true; more.hidden = true;
      }
      return fetch(dataUrl(nextPage), {credentials:'same-origin',headers:{Accept:'application/json'}})
        .then(function (response) { if (!response.ok) { throw new Error('HTTP ' + response.status); } return response.json(); })
        .then(function (payload) {
          if (version !== requestVersion) { return; }
          if (!albumRendered) { renderAlbum(payload); albumRendered = true; }
          else {
            // counts, picker, breadcrumb and the name list all describe the current folder
            renderMeta(payload.album || {});
            renderHeroMosaic((payload.album || {}).heroMosaic || []);
            renderFolders((payload.album || {}).folders || []);
            appendOptions(payload.album || {});
          }
          if (replace) { images = Array.isArray(payload.images) ? payload.images : []; }
          else { images = images.concat(payload.images || []); }
          filteredTotal = Number(payload.pagination && payload.pagination.total || images.length);
          overallTotal = Number(payload.pagination && payload.pagination.overallTotal || filteredTotal);
          status.hidden = true; renderImages();
          hasMore = !!(payload.pagination && payload.pagination.hasMore);
          more.hidden = !hasMore;
          page = nextPage;
          hideLoader();
        });
    }
    function loadNextPage() {
      if (!hasMore) { return Promise.resolve(false); }
      if (nextPageLoad) { return nextPageLoad; }
      var previousLength = images.length;
      more.disabled = true;
      if (loadProgress) { loadProgress.classList.add('is-loading'); }
      nextPageLoad = load(page + 1, false)
        .then(function () { return images.length > previousLength; })
        .catch(function () {
          showToast('โหลดรูปชุดถัดไปไม่สำเร็จ กรุณาลองอีกครั้ง');
          return false;
        })
        .finally(function () {
          nextPageLoad = null;
          more.disabled = false;
          if (loadProgress) { loadProgress.classList.remove('is-loading'); }
        });
      return nextPageLoad;
    }
    function reloadFilters() {
      clearSelection();
      hasMore = false;
      load(1, true).catch(function () {
        status.textContent = 'ค้นหารูปไม่สำเร็จ กรุณาลองอีกครั้ง'; status.hidden = false; hideLoader();
      });
    }

    root.querySelector('[data-copy-link]').addEventListener('click', function () { copyLink().then(function () { showToast('คัดลอกลิงก์อัลบั้มแล้ว'); }); });
    search.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(reloadFilters, 320);
    });
    root.querySelector('[data-folder-grid]')?.addEventListener('click', function (event) {
      var card = event.target.closest('[data-folder-name]');
      if (!card) { return; }
      folderStack.push(text(card.dataset.folderName));
      photographer.value = '';
      reloadFilters();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    root.querySelector('[data-pathbar-back]')?.addEventListener('click', function () {
      // Unwind the narrowest thing first: a filter sits inside the folder it was applied in, so
      // clearing it must not also throw the viewer back up a level.
      if (text(photographer.value).trim() !== '') { photographer.value = ''; }
      else if (text(search.value).trim() !== '') { search.value = ''; }
      else { folderStack.pop(); }
      reloadFilters();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    photographer.addEventListener('change', reloadFilters);
    selectionToggle.addEventListener('click', function () {
      if (selecting) { clearSelection(); }
      else { selecting = true; updateSelectionUI(); }
    });
    selectionClear.addEventListener('click', clearSelection);
    downloadSelected.addEventListener('click', startSelectedDownload);
    var _dlAll = root.querySelector('[data-download-all]'); if (_dlAll) { _dlAll.addEventListener('click', function () { downloadWholeAlbum(false); }); }
    var _dlAllWeb = root.querySelector('[data-download-all-web]'); if (_dlAllWeb) { _dlAllWeb.addEventListener('click', function () { downloadWholeAlbum(true); }); }
    more.addEventListener('click', loadNextPage);
    download.addEventListener('click', function (ev) { triggerDownload(ev, false); });
    if (downloadWeb) { downloadWeb.addEventListener('click', function (ev) { triggerDownload(ev, true); }); }
    root.querySelector('[data-close]').addEventListener('click', closeLightbox);
    root.querySelector('[data-prev]').addEventListener('click', function () { move(-1); });
    root.querySelector('[data-next]').addEventListener('click', function () { move(1); });
    lightbox.addEventListener('pointerdown', beginLightboxSwipe);
    lightbox.addEventListener('pointermove', dragLightboxSwipe);
    lightbox.addEventListener('pointerup', finishLightboxSwipe);
    lightbox.addEventListener('pointercancel', resetLightboxSwipe);
    lightbox.addEventListener('click', function (event) { if (event.target === lightbox) { closeLightbox(); } });
    document.addEventListener('keydown', function (event) { if (!lightboxOpen) { return; } if (event.key === 'Escape') { closeLightbox(); } else if (event.key === 'ArrowLeft') { move(-1); } else if (event.key === 'ArrowRight') { move(1); } });

    function showAlbumUnavailable() {
      document.title = 'ไม่พบอัลบั้ม - IE-PHOTO';
      root.classList.add('is-unavailable');
      root.querySelector('[data-album-name]').textContent = 'ไม่พบอัลบั้มนี้';
      var desc = root.querySelector('[data-album-description]');
      if (desc) { desc.textContent = 'ลิงก์อาจหมดอายุ หรืออัลบั้มถูกยกเลิกการแชร์แล้ว ลองกลับไปเลือกอัลบั้มจากหน้า Gallery'; }
      var meta = root.querySelector('[data-album-meta]'); if (meta) { meta.replaceChildren(); }
      var actions = root.querySelector('.iepa-actions');
      if (actions) {
        actions.replaceChildren();
        var back = document.createElement('a');
        back.className = 'iepa-primary iepa-cta';
        back.href = root.dataset.galleryUrl || '/';
        back.textContent = '← กลับไปเลือกอัลบั้ม';
        actions.appendChild(back);
      }
      var gallerySection = root.querySelector('[data-gallery-section]'); if (gallerySection) { gallerySection.hidden = true; }
      var folderSection = root.querySelector('[data-folder-section]'); if (folderSection) { folderSection.hidden = true; }
      var heroSection = root.querySelector('[data-hero-mosaic]'); if (heroSection) { heroSection.hidden = true; }
      hideLoader();
    }
    // Whole-album download writes straight into a folder via the File System Access API,
    // which only Chromium desktop has. Elsewhere (iPhone, Safari, Firefox) point the main
    // button at multi-select + ZIP, which works on every browser.
    if (!window.showDirectoryPicker) {
      var topCta = root.querySelector('[data-proxy="[data-download-all-web]"]');
      if (topCta) {
        topCta.dataset.proxy = '[data-selection-toggle]';
        topCta.textContent = '⬇ เลือกรูปแล้วโหลด ZIP';
        var dupSelect = root.querySelector('.iepa-actions [data-proxy="[data-selection-toggle]"]:not(.iepa-cta)');
        if (dupSelect) { dupSelect.hidden = true; }
      }
      ['[data-download-all]', '[data-download-all-web]'].forEach(function (sel) {
        var el = root.querySelector(sel); if (el) { el.hidden = true; }
      });
    }
    root.querySelectorAll('[data-proxy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = root.querySelector(btn.dataset.proxy);
        if (!target || target.hidden || target.disabled) { return; }
        var gallerySec = root.querySelector('[data-gallery-section]');
        var photosVisible = gallerySec && !gallerySec.hidden;
        if (!photosVisible && btn.dataset.proxy === '[data-selection-toggle]') {
          var folders = root.querySelector('[data-folder-section]');
          if (folders) { folders.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
          showToast('เปิดโฟลเดอร์ก่อน แล้วเลือกรูปที่ต้องการได้เลย');
          return;
        }
        target.click();
        var tools = root.querySelector('.iepa-browse-tools');
        if (photosVisible && tools) { tools.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      });
    });
    var emptyClear = root.querySelector('[data-empty-clear]');
    if (emptyClear) {
      emptyClear.addEventListener('click', function () { search.value = ''; photographer.value = ''; reloadFilters(); search.focus(); });
    }

    load(1, true).then(trackAlbumView).catch(showAlbumUnavailable);
  });
})();
