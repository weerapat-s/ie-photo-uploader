(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    document.title = 'ทดลองธีม IE-PHOTO - Nextcloud';
    var root = document.querySelector('#iephoto-theme-preview');
    var fan = document.querySelector('[data-preview-fan]');
    var grid = document.querySelector('[data-preview-grid]');
    var status = document.querySelector('[data-preview-status]');
    var count = document.querySelector('[data-preview-count]');
    if (!root || !fan || !grid || !status || !count) {
      return;
    }

    function safeUrl(value) {
      try {
        var parsed = new URL(String(value || ''), window.location.origin);
        return parsed.origin === window.location.origin && /^https?:$/.test(parsed.protocol) ? parsed.href : '';
      } catch (error) {
        return '';
      }
    }

    function publicShareUrl(value) {
      var url = safeUrl(value);
      if (!url) return '';
      var parsed = new URL(url);
      return /^\/(?:index\.php\/)?s\/[A-Za-z0-9]+\/?$/.test(parsed.pathname) ? url : '';
    }

    function renderFan(items) {
      var usable = items.filter(function (item) { return safeUrl(item.url || item.coverUrl); }).slice(0, 7);
      usable.forEach(function (item, index) {
        var link = document.createElement('a');
        link.className = 'iept-fan-card';
        link.href = publicShareUrl(item.shareUrl) || '#';
        link.target = '_blank';
        link.rel = 'noopener';
        link.style.setProperty('--iept-index', String(index));
        var image = document.createElement('img');
        image.src = safeUrl(item.url || item.coverUrl);
        image.alt = String(item.name || 'ภาพ IE-PHOTO');
        var badge = document.createElement('span');
        badge.textContent = 'IE-PHOTO';
        link.appendChild(image);
        link.appendChild(badge);
        fan.appendChild(link);
      });
      fan.hidden = usable.length === 0;
    }

    function renderFolders(albums) {
      albums.forEach(function (album, index) {
        var card = document.createElement('a');
        card.className = 'iept-roll';
        card.href = publicShareUrl(album.shareUrl) || '#';
        card.target = '_blank';
        card.rel = 'noopener';

        var holes = document.createElement('div');
        holes.className = 'iept-holes';
        holes.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < 5; i++) holes.appendChild(document.createElement('i'));

        var image = document.createElement('img');
        image.src = safeUrl(album.coverUrl);
        image.alt = '';
        image.loading = 'lazy';

        var meta = document.createElement('div');
        meta.className = 'iept-roll-meta';
        var number = document.createElement('span');
        number.className = 'iept-roll-number';
        number.textContent = String(index + 1).padStart(2, '0');
        var copy = document.createElement('span');
        copy.className = 'iept-roll-copy';
        var title = document.createElement('strong');
        title.textContent = String(album.name || 'โฟลเดอร์ไม่มีชื่อ');
        var hint = document.createElement('small');
        hint.textContent = 'PUBLIC GALLERY · ดูและดาวน์โหลด';
        copy.appendChild(title);
        copy.appendChild(hint);
        var arrow = document.createElement('span');
        arrow.className = 'iept-roll-arrow';
        arrow.textContent = '↗';
        meta.appendChild(number);
        meta.appendChild(copy);
        meta.appendChild(arrow);

        card.appendChild(holes);
        card.appendChild(image);
        card.appendChild(meta);
        grid.appendChild(card);
      });
      grid.hidden = albums.length === 0;
    }

    fetch(root.dataset.albumsUrl || '', {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (payload) {
        var albums = Array.isArray(payload.albums) ? payload.albums : [];
        var hero = Array.isArray(payload.hero) && payload.hero.length
          ? payload.hero
          : albums.map(function (album) {
              return {name: album.name, url: album.coverUrl, shareUrl: album.shareUrl};
            });
        count.textContent = String(albums.length).padStart(2, '0');
        renderFan(hero);
        renderFolders(albums);
        status.hidden = true;
      })
      .catch(function () {
        count.textContent = '00';
        status.textContent = 'โหลดข้อมูลตัวอย่างไม่สำเร็จ กรุณารีเฟรชหน้า';
        status.classList.add('is-error');
      });
  });
})();
