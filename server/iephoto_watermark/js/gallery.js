(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    document.title = 'IE-PHOTO Gallery · รูปกิจกรรมคณะครุศาสตร์อุตสาหกรรมฯ';
    document.documentElement.lang = 'th';
    var root = document.querySelector('#iephoto-gallery');
    var grid = document.querySelector('[data-album-grid]');
    var status = document.querySelector('[data-album-status]');
    var empty = document.querySelector('[data-album-empty]');
    var count = document.querySelector('[data-album-count]');
    var showcase = document.querySelector('[data-hero-showcase]');
    var stage = document.querySelector('[data-hero-stage]');
    var coverApiBase = (root ? root.dataset.coverApiBase || '' : '').replace(/\/+$/, '');
    var coverModal = document.querySelector('[data-cover-modal]');
    var coverTitle = document.querySelector('[data-cover-title]');
    var coverStatus = document.querySelector('[data-cover-status]');
    var coverGrid = document.querySelector('[data-cover-grid]');
    var coverClose = document.querySelector('[data-cover-close]');
    var typewriter = document.querySelector('[data-typewriter]');
    var albumSearch = document.querySelector('[data-album-search]');
    var albumYear = document.querySelector('[data-album-year]');
    var albumMonth = document.querySelector('[data-album-month]');
    var albumPhotographer = document.querySelector('[data-album-photographer]');
    var archiveAlbums = document.querySelector('[data-archive-albums]');
    var archivePhotos = document.querySelector('[data-archive-photos]');
    var albumResults = document.querySelector('[data-album-results]');
    var filterReset = document.querySelector('[data-filter-reset]');
    var pageLoader = document.querySelector('[data-page-loader]');
    // #content is position:fixed, which makes it a stacking context, so the loader's z-index is
    // capped inside it and Nextcloud's #header (z 2000) paints over the "full screen" loader.
    // Reparenting to <body> puts it in the root stacking context where its z-index actually wins.
    if (pageLoader && pageLoader.parentElement !== document.body) {
      document.body.appendChild(pageLoader);
    }
    var heroLightbox = document.querySelector('[data-hero-lightbox]');
    var lightboxImage = document.querySelector('[data-lightbox-image]');
    var lightboxTitle = document.querySelector('[data-lightbox-title]');
    var lightboxDownload = document.querySelector('[data-lightbox-download]');
    var lightboxClose = document.querySelector('[data-lightbox-close]');
    var lightboxPrev = document.querySelector('[data-lightbox-prev]');
    var lightboxNext = document.querySelector('[data-lightbox-next]');
    var albums = [];
    var lastCoverButton = null;
    var lastHeroButton = null;
    var heroViewItems = [];
    var activeHeroIndex = 0;
    var heroLightboxOpen = false;
    var loaderStartedAt = Date.now();
    var loaderHidden = false;
    var scrollHost = document.querySelector('#content');
    var lockedScrollTop = 0;
    if (!root || !grid || !status || !empty || !count) {
      return;
    }

    var apiUrl = root.dataset.albumsUrl || '';
    var albumUrlTemplate = root.dataset.albumUrlTemplate || '';

    function albumViewerUrl(token) {
      return albumUrlTemplate.replace('__TOKEN__', encodeURIComponent(String(token || '')));
    }

    function lockBackgroundScroll() {
      if (scrollHost) {
        lockedScrollTop = scrollHost.scrollTop;
        scrollHost.classList.add('iepg-scroll-locked');
      } else {
        lockedScrollTop = window.scrollY || 0;
      }
      document.documentElement.classList.add('iepg-no-scroll');
      document.body.classList.add('iepg-no-scroll');
    }

    function unlockBackgroundScroll() {
      document.documentElement.classList.remove('iepg-no-scroll');
      document.body.classList.remove('iepg-no-scroll');
      if (scrollHost) {
        scrollHost.classList.remove('iepg-scroll-locked');
        scrollHost.scrollTop = lockedScrollTop;
      } else {
        window.scrollTo(0, lockedScrollTop);
      }
    }

    function formatDate(timestamp) {
      var value = Number(timestamp || 0);
      return value ? new Date(value * 1000).toLocaleDateString('th-TH', {day:'numeric',month:'short',year:'numeric'}) : '';
    }

    function startTypewriter() {
      if (!typewriter || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
      }

      var text = 'IE-PHOTO Gallery';
      var letters = Array.from(text);
      var index = 0;

      typewriter.textContent = '';

      function typeNext() {
        index += 1;
        typewriter.textContent = letters.slice(0, index).join('');

        if (index === letters.length) {
          typewriter.classList.add('is-complete');
          return;
        }
        window.setTimeout(typeNext, 70);
      }

      window.setTimeout(typeNext, 350);
    }

    window.setTimeout(hidePageLoader, 5000);

    function safeUrl(value) {
      try {
        var parsed = new URL(String(value || ''), window.location.origin);
        return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : '';
      } catch (error) {
        return '';
      }
    }

    function largePreviewUrl(value) {
      var url = safeUrl(value);
      if (!url) {
        return '';
      }
      try {
        var parsed = new URL(url);
        if (parsed.origin === window.location.origin && parsed.pathname.indexOf('/publicpreview/') !== -1) {
          parsed.searchParams.set('x', '1920');
          parsed.searchParams.set('y', '1920');
          parsed.searchParams.set('a', '1');
        }
        return parsed.href;
      } catch (error) {
        return url;
      }
    }

    function hidePageLoader() {
      if (!pageLoader || loaderHidden) {
        return;
      }
      loaderHidden = true;
      startTypewriter();
      root.classList.add('iepg-page-ready');
      pageLoader.classList.add('is-leaving');
      // The exit is a shutter blink (blades close, then the frame lifts) — hold the node until
      // that has actually finished playing, otherwise it pops out mid-animation.
      window.setTimeout(function () {
        pageLoader.hidden = true;
      }, 760);
    }

    function finishPageLoad() {
      if (!pageLoader || loaderHidden) {
        return;
      }

      var images = stage
        ? Array.prototype.slice.call(stage.querySelectorAll('img')).slice(0, 5)
        : [];
      var imageReady = images.map(function (image) {
        if (image.complete) {
          return Promise.resolve();
        }
        return new Promise(function (resolve) {
          image.addEventListener('load', resolve, {once: true});
          image.addEventListener('error', resolve, {once: true});
        });
      });
      var waitForImages = Promise.race([
        Promise.all(imageReady),
        new Promise(function (resolve) { window.setTimeout(resolve, 1900); })
      ]);

      waitForImages.then(function () {
        var minimumDelay = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 760;
        var remaining = Math.max(0, minimumDelay - (Date.now() - loaderStartedAt));
        window.setTimeout(hidePageLoader, remaining);
      });
    }

    function updateHeroLightbox() {
      if (!heroLightbox || heroViewItems.length === 0) {
        return;
      }
      var item = heroViewItems[activeHeroIndex];
      lightboxImage.src = item.url;
      lightboxImage.alt = item.name;
      lightboxTitle.textContent = item.name;
      lightboxDownload.href = item.url;
      lightboxDownload.setAttribute('download', item.name);
    }

    function openHeroLightbox(index, trigger) {
      if (!heroLightbox || !lightboxImage || !lightboxTitle || !lightboxDownload || heroViewItems.length === 0) {
        return;
      }
      activeHeroIndex = index;
      lastHeroButton = trigger;
      heroLightboxOpen = true;
      updateHeroLightbox();
      heroLightbox.hidden = false;
      lockBackgroundScroll();
      lightboxClose.focus();
    }

    function closeHeroLightbox() {
      if (!heroLightbox || !heroLightboxOpen) {
        return;
      }
      heroLightbox.hidden = true;
      heroLightboxOpen = false;
      lightboxImage.removeAttribute('src');
      unlockBackgroundScroll();
      if (lastHeroButton && lastHeroButton.isConnected) {
        lastHeroButton.focus();
      }
      lastHeroButton = null;
    }

    function moveHeroLightbox(offset) {
      if (!heroLightboxOpen || heroViewItems.length < 2) {
        return;
      }
      activeHeroIndex = (activeHeroIndex + offset + heroViewItems.length) % heroViewItems.length;
      updateHeroLightbox();
    }

    function publicShareUrl(value) {
      try {
        var parsed = new URL(String(value || ''), window.location.origin);
        var isSharePath = /^\/(?:index\.php\/)?s\/[A-Za-z0-9]+\/?$/.test(parsed.pathname);
        return parsed.origin === window.location.origin && isSharePath ? parsed.href : '';
      } catch (error) {
        return '';
      }
    }

    function adminHeaders() {
      var token = window.OC && window.OC.requestToken;
      if (!token) {
        throw new Error('ไม่พบ CSRF token กรุณารีเฟรชหน้าแล้วลองใหม่');
      }
      return {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'requesttoken': token
      };
    }

    function adminApi(path, options) {
      options = options || {};
      return fetch(coverApiBase + path, {
        credentials: 'same-origin',
        method: options.method || 'GET',
        body: options.body,
        headers: adminHeaders()
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (body) {
          if (!response.ok) {
            throw new Error(body.error || 'ดำเนินการไม่สำเร็จ (HTTP ' + response.status + ')');
          }
          return body;
        });
      });
    }

    function albumCard(album, position) {
      var shell = document.createElement('article');
      var link = document.createElement('a');
      var viewerUrl = albumViewerUrl(album.token);
      var coverUrl = safeUrl(album.coverUrl);
      link.className = 'iepg-album';
      link.href = viewerUrl || '#';

      var rail = document.createElement('div');
      rail.className = 'iepg-card-rail';
      rail.setAttribute('aria-hidden', 'true');
      var archiveNumber = document.createElement('span');
      archiveNumber.textContent = 'ARCHIVE / ' + String(albums.indexOf(album) + 1).padStart(2, '0');
      var railCount = document.createElement('span');
      railCount.textContent = Number(album.photoCount || 0).toLocaleString('en-US') + ' FRAMES';
      rail.appendChild(archiveNumber);
      rail.appendChild(railCount);
      link.appendChild(rail);

      var figure = document.createElement('figure');
      // Prefer a mosaic of random shots from the album; fall back to the single cover.
      var mosaic = Array.isArray(album.mosaic) ? album.mosaic.map(safeUrl).filter(Boolean) : [];
      if (mosaic.length >= 6) {
        var grid = document.createElement('div');
        grid.className = 'iepg-album-mosaic';
        mosaic.slice(0, 5).forEach(function (url) {
          var cell = document.createElement('img');
          cell.src = url;
          cell.alt = '';
          cell.loading = 'lazy';
          cell.decoding = 'async';
          grid.appendChild(cell);
        });
        figure.appendChild(grid);
      } else if (coverUrl) {
        var image = document.createElement('img');
        image.src = coverUrl;
        image.alt = '';
        image.loading = 'lazy';
        figure.appendChild(image);
      } else {
        var fallback = document.createElement('div');
        fallback.className = 'iepg-album-fallback';
        fallback.textContent = '▧';
        figure.appendChild(fallback);
      }

      var caption = document.createElement('figcaption');
      var folderIcon = document.createElement('span');
      folderIcon.className = 'iepg-album-icon';
      folderIcon.setAttribute('aria-hidden', 'true');
      folderIcon.textContent = '▰';
      var detail = document.createElement('span');
      var title = document.createElement('strong');
      title.textContent = String(album.name || 'โฟลเดอร์ไม่มีชื่อ');
      var hint = document.createElement('small');
      var photographers = Array.isArray(album.photographers) ? album.photographers : [];
      hint.textContent = String(album.photoCount || 0) + ' รูป'
        + (photographers.length ? ' · ' + photographers.slice(0, 2).join(', ') : '')
        + (album.updatedAt ? ' · ' + formatDate(album.updatedAt) : '');
      detail.appendChild(title);
      detail.appendChild(hint);
      var arrow = document.createElement('span');
      arrow.className = 'iepg-album-arrow';
      arrow.setAttribute('aria-hidden', 'true');
      arrow.textContent = '↗';
      caption.appendChild(folderIcon);
      caption.appendChild(detail);
      caption.appendChild(arrow);
      figure.appendChild(caption);
      link.appendChild(figure);
      shell.className = 'iepg-album-shell';
      shell.style.setProperty('--iepg-card-delay', String(Math.min(position || 0, 5) * 45) + 'ms');
      shell.appendChild(link);

      if (album.isNew) {
        var fresh = document.createElement('span');
        fresh.className = 'iepg-new-badge';
        fresh.textContent = 'งานใหม่';
        shell.appendChild(fresh);
      }

      if (coverApiBase) {
        var edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'iepg-cover-edit';
        edit.textContent = 'เปลี่ยนรูปปก';
        edit.setAttribute('aria-label', 'เปลี่ยนรูปปก ' + String(album.name || 'โฟลเดอร์'));
        edit.addEventListener('click', function () {
          openCoverPicker(album, edit);
        });
        shell.appendChild(edit);
      }
      return shell;
    }

    // The hero strip lists the folders that actually exist, so it stays truthful as albums come
    // and go. The list is repeated once because the marquee loops by translating exactly half.
    function renderTicker() {
      var ticker = document.querySelector('[data-ticker]');
      var track = document.querySelector('[data-ticker-track]');
      if (!ticker || !track) { return; }
      var names = albums.map(function (album) { return String(album.name || '').trim(); })
        .filter(Boolean);
      if (names.length < 2) { ticker.hidden = true; return; }
      track.replaceChildren();
      names.concat(names).forEach(function (name) {
        var label = document.createElement('b');
        label.textContent = name;
        var dot = document.createElement('i');
        track.appendChild(label);
        track.appendChild(dot);
      });
      ticker.hidden = false;
    }

    function renderAlbums() {
      grid.replaceChildren();
      var query = albumSearch ? albumSearch.value.trim().toLocaleLowerCase('th') : '';
      var year = albumYear ? albumYear.value : '';
      var month = albumMonth ? albumMonth.value : '';
      var photographer = albumPhotographer ? albumPhotographer.value : '';
      var visibleAlbums = albums.filter(function (album) {
        var names = Array.isArray(album.photographers) ? album.photographers : [];
        return (!query || String(album.name || '').toLocaleLowerCase('th').indexOf(query) !== -1)
          && (!year || String(album.year || '') === year)
          && (!month || String(album.month || '') === month)
          && (!photographer || names.indexOf(photographer) !== -1);
      });
      visibleAlbums.forEach(function (album, position) {
        grid.appendChild(albumCard(album, position));
      });
      grid.hidden = visibleAlbums.length === 0;
      empty.hidden = visibleAlbums.length !== 0;
      count.textContent = String(albums.length);
      if (archiveAlbums) { archiveAlbums.textContent = String(albums.length).padStart(2, '0'); }
      if (archivePhotos) {
        archivePhotos.textContent = albums.reduce(function (total, album) {
          return total + Number(album.photoCount || 0);
        }, 0).toLocaleString('en-US');
      }
      var filtering = Boolean(query || year || month || photographer);
      if (filterReset) { filterReset.hidden = !filtering; }
      if (albumResults) {
        albumResults.textContent = filtering
          ? 'พบ ' + visibleAlbums.length + ' จาก ' + albums.length + ' โฟลเดอร์'
          : albums.length + ' โฟลเดอร์งาน';
      }
      var emptyTitle = empty.querySelector('h3');
      var emptyDescription = empty.querySelector('p');
      if (emptyTitle) { emptyTitle.textContent = filtering ? 'ไม่พบงานที่ค้นหา' : 'ยังไม่มีโฟลเดอร์งาน'; }
      if (emptyDescription) {
        emptyDescription.textContent = filtering
          ? 'ลองเปลี่ยนคำค้นหาหรือล้างตัวกรองเพื่อดูทุกงาน'
          : 'เมื่อมีโฟลเดอร์งานใหม่ ภาพจะปรากฏในคลังนี้';
      }
      renderTicker();
    }

    function fillAlbumFilters() {
      function fill(select, values, label) {
        if (!select) { return; }
        var current = select.value;
        select.replaceChildren();
        var all = document.createElement('option'); all.value = ''; all.textContent = label; select.appendChild(all);
        values.forEach(function (value) { var option = document.createElement('option'); option.value = value; option.textContent = value; select.appendChild(option); });
        select.value = current;
      }
      var years = [];
      var months = [];
      var people = [];
      albums.forEach(function (album) {
        if (album.year && years.indexOf(String(album.year)) === -1) { years.push(String(album.year)); }
        if (album.month && months.indexOf(String(album.month)) === -1) { months.push(String(album.month)); }
        (album.photographers || []).forEach(function (name) { if (people.indexOf(name) === -1) { people.push(name); } });
      });
      years.sort().reverse(); months.sort(); people.sort(function (a,b) { return a.localeCompare(b,'th'); });
      fill(albumYear, years, 'ทุกปี');
      fill(albumMonth, months.map(function (value) { var d=new Date(2026,Number(value)-1,1); return value + ' · ' + d.toLocaleDateString('th-TH',{month:'long'}); }), 'ทุกเดือน');
      if (albumMonth) {
        Array.prototype.forEach.call(albumMonth.options, function (option) { if (option.value) { option.value = option.value.slice(0,2); } });
      }
      fill(albumPhotographer, people, 'ทุกคน');
    }

    function closeCoverPicker() {
      if (!coverModal) {
        return;
      }
      coverModal.hidden = true;
      unlockBackgroundScroll();
      if (lastCoverButton && lastCoverButton.isConnected) {
        lastCoverButton.focus();
      }
      lastCoverButton = null;
    }

    function saveCover(album, path) {
      var buttons = Array.prototype.slice.call(coverGrid.querySelectorAll('button'));
      buttons.forEach(function (button) { button.disabled = true; });
      coverStatus.textContent = 'กำลังเปลี่ยนรูปปก…';
      coverStatus.className = 'iepg-cover-status';

      adminApi('/albums/' + encodeURIComponent(album.token) + '/cover', {
        method: 'POST',
        body: new URLSearchParams({path: path})
      }).then(function (payload) {
        album.coverUrl = payload.cover && payload.cover.coverUrl ? payload.cover.coverUrl : album.coverUrl;
        renderAlbums();
        coverStatus.textContent = 'เปลี่ยนรูปปกแล้ว';
        coverStatus.classList.add('is-success');
        window.setTimeout(closeCoverPicker, 450);
      }).catch(function (error) {
        coverStatus.textContent = error.message;
        coverStatus.classList.add('is-error');
        buttons.forEach(function (button) { button.disabled = false; });
      });
    }

    function openCoverPicker(album, trigger) {
      if (!coverModal || !coverGrid || !coverStatus || !coverApiBase) {
        return;
      }
      lastCoverButton = trigger;
      coverTitle.textContent = 'เลือกรูปปก · ' + String(album.name || 'โฟลเดอร์งาน');
      coverStatus.textContent = 'กำลังโหลดรูปในโฟลเดอร์…';
      coverStatus.className = 'iepg-cover-status';
      coverGrid.replaceChildren();
      coverModal.hidden = false;
      lockBackgroundScroll();
      coverClose.focus();

      adminApi('/albums/' + encodeURIComponent(album.token) + '/covers', {method: 'GET'})
        .then(function (payload) {
          var images = Array.isArray(payload.images) ? payload.images : [];
          coverStatus.textContent = images.length
            ? 'พบ ' + images.length + ' รูป — กดรูปที่ต้องการใช้เป็นปก'
            : 'ไม่พบรูปภาพในโฟลเดอร์นี้';
          images.forEach(function (item) {
            var option = document.createElement('button');
            option.type = 'button';
            option.className = 'iepg-cover-option';
            option.dataset.selected = item.path === payload.selected ? 'true' : 'false';
            option.title = String(item.path || '');

            var image = document.createElement('img');
            image.src = safeUrl(item.previewUrl);
            image.alt = '';
            image.loading = 'lazy';
            var label = document.createElement('span');
            label.textContent = String(item.name || item.path || 'รูปภาพ');
            var mark = document.createElement('strong');
            mark.textContent = item.path === payload.selected ? 'ปกปัจจุบัน' : 'เลือก';
            option.appendChild(image);
            option.appendChild(label);
            option.appendChild(mark);
            option.addEventListener('click', function () { saveCover(album, item.path); });
            coverGrid.appendChild(option);
          });
        })
        .catch(function (error) {
          coverStatus.textContent = error.message;
          coverStatus.classList.add('is-error');
        });
    }

    function renderShowcase(items) {
      if (!showcase || !stage || !Array.isArray(items)) {
        return;
      }

      var usable = items.filter(function (item) {
        return safeUrl(item.url || item.coverUrl);
      }).slice(0, 9);
      if (usable.length === 0) {
        return;
      }

      stage.replaceChildren();
      heroViewItems = usable.map(function (item, index) {
        return {
          name: String(item.name || 'ภาพจาก IE-PHOTO ' + (index + 1)),
          url: largePreviewUrl(item.url || item.coverUrl)
        };
      });

      var dragging = false;
      var dragPointerId = null;
      var dragStartX = 0;
      var dragStartY = 0;
      var dragDeltaX = 0;
      var dragDeltaY = 0;
      var dragCard = null;
      var suppressCardClick = false;

      var cards = usable.map(function (item, index) {
        var card = document.createElement('button');
        var imageUrl = safeUrl(item.url || item.coverUrl);
        card.type = 'button';
        card.draggable = false;
        card.className = 'iepg-showcase-card';
        card.setAttribute('aria-label', 'ดูภาพขนาดใหญ่ ' + String(item.name || index + 1));

        var image = document.createElement('img');
        image.src = imageUrl;
        image.alt = String(item.name || 'ภาพจาก IE-PHOTO');
        image.loading = index < 5 ? 'eager' : 'lazy';
        image.draggable = false;
        image.style.animationDelay = String(index * -0.37) + 's';
        var badge = document.createElement('span');
        badge.textContent = 'IE-PHOTO';
        card.appendChild(image);
        card.appendChild(badge);
        card.addEventListener('click', function () {
          if (suppressCardClick) {
            return;
          }
          openHeroLightbox(index, card);
        });
        stage.appendChild(card);
        return card;
      });

      var active = Math.floor(cards.length / 2);
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      function positionCards(instant) {
        var compact = window.matchMedia('(max-width: 720px)').matches;
        var half = Math.floor(cards.length / 2);
        var visible = compact ? 2 : Math.min(3, half);
        cards.forEach(function (card, index) {
          var relative = ((index - active + half + cards.length) % cards.length) - half;
          var distance = Math.abs(relative);
          var x = relative * (compact ? 76 : 132);
          var y = distance * (compact ? 13 : 21);
          var z = 90 - distance * 30;
          var rotation = relative * (compact ? 7 : 5.5);
          var scale = Math.max(compact ? 0.76 : 0.68, 1.04 - distance * (compact ? 0.11 : 0.075));
          card.style.transition = instant ? 'none' : '';
          card.style.transform = 'translate(-50%, -50%) translate3d(' + x + 'px,' + y + 'px,' + z + 'px) rotate(' + rotation + 'deg) scale(' + scale + ')';
          card.style.opacity = distance <= visible ? String(Math.max(0.28, 1 - distance * 0.16)) : '0';
          card.style.zIndex = String(100 - distance);
          card.style.pointerEvents = distance <= visible ? 'auto' : 'none';
          card.dataset.active = distance === 0 ? 'true' : 'false';
          card.tabIndex = distance <= visible ? 0 : -1;
        });
        if (instant) {
          window.requestAnimationFrame(function () {
            cards.forEach(function (card) { card.style.transition = ''; });
          });
        }
      }

      positionCards(true);
      showcase.hidden = false;

      function startDrag(event) {
        var targetCard = event.target && event.target.closest
          ? event.target.closest('.iepg-showcase-card')
          : null;
        if (event.button !== 0 || !targetCard || cards.length < 2 || heroLightboxOpen) {
          return;
        }
        dragging = true;
        dragCard = targetCard;
        dragPointerId = event.pointerId;
        dragStartX = event.clientX;
        dragStartY = event.clientY;
        dragDeltaX = 0;
        dragDeltaY = 0;
        suppressCardClick = false;
        dragCard.dataset.dragging = 'true';
        dragCard.style.zIndex = '250';
        showcase.classList.add('is-dragging');
        try {
          showcase.setPointerCapture(event.pointerId);
        } catch (error) {
          // Pointer capture may be unavailable in older embedded browsers.
        }
      }

      function moveDrag(event) {
        if (!dragging || event.pointerId !== dragPointerId) {
          return;
        }
        dragDeltaX = event.clientX - dragStartX;
        dragDeltaY = event.clientY - dragStartY;
        if (Math.hypot(dragDeltaX, dragDeltaY) > 6) {
          suppressCardClick = true;
          event.preventDefault();
        }
        dragCard.style.left = 'calc(50% + ' + String(dragDeltaX) + 'px)';
        dragCard.style.top = 'calc(48% + ' + String(dragDeltaY) + 'px)';
        dragCard.style.rotate = String(Math.max(-8, Math.min(8, dragDeltaX * 0.035))) + 'deg';
      }

      function endDrag(event) {
        if (!dragging || event.pointerId !== dragPointerId) {
          return;
        }
        if (showcase.hasPointerCapture(event.pointerId)) {
          showcase.releasePointerCapture(event.pointerId);
        }
        var releasedCard = dragCard;
        var releasedIndex = cards.indexOf(releasedCard);
        dragging = false;
        dragPointerId = null;
        dragCard = null;
        showcase.classList.remove('is-dragging');
        releasedCard.dataset.dragging = 'false';

        var dragDistance = Math.hypot(dragDeltaX, dragDeltaY);
        if (dragDistance <= 6) {
          suppressCardClick = true;
          openHeroLightbox(releasedIndex, releasedCard);
        } else if (Math.abs(dragDeltaX) > 38) {
          var steps = Math.min(3, Math.max(1, Math.round(Math.abs(dragDeltaX) / 110)));
          active = (releasedIndex + (dragDeltaX < 0 ? steps : -steps) + cards.length) % cards.length;
        }

        window.requestAnimationFrame(function () {
          releasedCard.style.left = '';
          releasedCard.style.top = '';
          releasedCard.style.rotate = '';
          positionCards(false);
        });

        window.setTimeout(function () {
          suppressCardClick = false;
        }, 80);
      }

      showcase.addEventListener('pointerdown', startDrag);
      showcase.addEventListener('pointermove', moveDrag);
      showcase.addEventListener('pointerup', endDrag);
      showcase.addEventListener('pointercancel', endDrag);

      if (!reduceMotion && cards.length > 1) {
        window.setInterval(function () {
          if (!dragging && !heroLightboxOpen && !document.hidden) {
            active = (active + 1) % cards.length;
            positionCards(false);
          }
        }, 2600);

        showcase.addEventListener('pointermove', function (event) {
          if (dragging) {
            return;
          }
          var rect = showcase.getBoundingClientRect();
          var x = ((event.clientX - rect.left) / rect.width - 0.5) * 2;
          var y = ((event.clientY - rect.top) / rect.height - 0.5) * 2;
          stage.style.setProperty('--iepg-tilt-x', String(-y * 2.5) + 'deg');
          stage.style.setProperty('--iepg-tilt-y', String(x * 4) + 'deg');
        });
        showcase.addEventListener('pointerleave', function () {
          if (!dragging) {
            stage.style.setProperty('--iepg-tilt-x', '0deg');
            stage.style.setProperty('--iepg-tilt-y', '0deg');
          }
        });
      }

      window.addEventListener('resize', function () { positionCards(true); });
    }

    if (!apiUrl) {
      status.textContent = 'ไม่พบแหล่งข้อมูลโฟลเดอร์งาน';
      if (albumResults) { albumResults.textContent = 'ยังไม่สามารถแสดงคลังภาพได้'; }
      count.textContent = '0';
      finishPageLoad();
      return;
    }

    fetch(apiUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (payload) {
        albums = Array.isArray(payload.albums)
          ? payload.albums.filter(function (album) { return Number(album.photoCount || 0) > 0; })
          : [];
        var hero = Array.isArray(payload.hero) ? payload.hero : [];
        if (hero.length === 0) {
          hero = albums.map(function (album) {
            return {
              name: album.name,
              url: album.coverUrl,
              shareUrl: album.shareUrl
            };
          });
        }
        renderShowcase(hero);
        status.hidden = true;
        fillAlbumFilters();
        renderAlbums();
        finishPageLoad();
      })
      .catch(function () {
        count.textContent = '0';
        if (albumResults) { albumResults.textContent = 'โหลดคลังภาพไม่สำเร็จ กรุณาลองรีเฟรชหน้า'; }
        status.textContent = 'โหลดโฟลเดอร์งานไม่สำเร็จ กรุณาลองรีเฟรชหน้าอีกครั้ง';
        status.classList.add('is-error');
        finishPageLoad();
      });

    if (heroLightbox && lightboxClose && lightboxPrev && lightboxNext) {
      var swipePointerId = null;
      var swipeStartX = 0;
      var swipeStartY = 0;
      var swipeDeltaX = 0;
      var swipeDeltaY = 0;

      function resetLightboxSwipe() {
        swipePointerId = null;
        swipeDeltaX = 0;
        swipeDeltaY = 0;
        lightboxImage.classList.remove('is-swiping');
        lightboxImage.style.transform = '';
        lightboxImage.style.opacity = '';
      }

      function beginLightboxSwipe(event) {
        if (!heroLightboxOpen || heroViewItems.length < 2 || event.button > 0) {
          return;
        }
        if (event.target && event.target.closest && event.target.closest('button,a')) {
          return;
        }
        swipePointerId = event.pointerId;
        swipeStartX = event.clientX;
        swipeStartY = event.clientY;
        swipeDeltaX = 0;
        swipeDeltaY = 0;
        lightboxImage.classList.add('is-swiping');
        try { heroLightbox.setPointerCapture(event.pointerId); } catch (error) {}
      }

      function dragLightboxSwipe(event) {
        if (swipePointerId === null || event.pointerId !== swipePointerId) {
          return;
        }
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
        if (swipePointerId === null || event.pointerId !== swipePointerId) {
          return;
        }
        try {
          if (heroLightbox.hasPointerCapture(event.pointerId)) {
            heroLightbox.releasePointerCapture(event.pointerId);
          }
        } catch (error) {}
        var shouldMove = Math.abs(swipeDeltaX) >= 48
          && Math.abs(swipeDeltaX) > Math.abs(swipeDeltaY) * 1.15;
        var direction = swipeDeltaX < 0 ? 1 : -1;
        resetLightboxSwipe();
        if (shouldMove) {
          moveHeroLightbox(direction);
        }
      }

      lightboxClose.addEventListener('click', closeHeroLightbox);
      lightboxPrev.addEventListener('click', function () { moveHeroLightbox(-1); });
      lightboxNext.addEventListener('click', function () { moveHeroLightbox(1); });
      heroLightbox.addEventListener('pointerdown', beginLightboxSwipe);
      heroLightbox.addEventListener('pointermove', dragLightboxSwipe);
      heroLightbox.addEventListener('pointerup', finishLightboxSwipe);
      heroLightbox.addEventListener('pointercancel', resetLightboxSwipe);
      heroLightbox.addEventListener('click', function (event) {
        if (event.target === heroLightbox) {
          closeHeroLightbox();
        }
      });
      document.addEventListener('keydown', function (event) {
        if (!heroLightboxOpen) {
          return;
        }
        if (event.key === 'Escape') {
          closeHeroLightbox();
        } else if (event.key === 'ArrowLeft') {
          moveHeroLightbox(-1);
        } else if (event.key === 'ArrowRight') {
          moveHeroLightbox(1);
        }
      });
    }

    if (coverModal && coverClose) {
      coverClose.addEventListener('click', closeCoverPicker);
      coverModal.addEventListener('click', function (event) {
        if (event.target === coverModal) {
          closeCoverPicker();
        }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !coverModal.hidden) {
          closeCoverPicker();
        }
      });
    }

    if (albumSearch) { albumSearch.addEventListener('input', renderAlbums); }
    if (albumYear) { albumYear.addEventListener('change', renderAlbums); }
    if (albumMonth) { albumMonth.addEventListener('change', renderAlbums); }
    if (albumPhotographer) { albumPhotographer.addEventListener('change', renderAlbums); }
    if (filterReset) {
      filterReset.addEventListener('click', function () {
        [albumSearch, albumYear, albumMonth, albumPhotographer].forEach(function (input) {
          if (input) { input.value = ''; }
        });
        renderAlbums();
        if (albumSearch) { albumSearch.focus(); }
      });
    }
  });
})();
