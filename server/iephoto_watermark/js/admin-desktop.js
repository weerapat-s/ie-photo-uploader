(function () {
  'use strict';
  const root = document.getElementById('iephoto-admin');
  const section = root && root.querySelector('[data-desktop-section]');
  if (!section) return;
  const url = root.dataset.desktopCodesUrl;
  const csrf = document.querySelector('head')?.dataset.requesttoken || window.OC?.requestToken || '';
  const $ = (s) => section.querySelector(s);
  const status = $('[data-dc-status]');
  const list = $('[data-dc-list]');
  const albumSel = $('[data-dc-album]');

  async function api(path, opts = {}) {
    opts.headers = Object.assign({ requesttoken: csrf, Accept: 'application/json' }, opts.headers || {});
    opts.credentials = 'same-origin';
    const res = await fetch(path, opts);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'เกิดข้อผิดพลาด');
    return data;
  }

  function render(codes) {
    list.replaceChildren();
    if (!codes.length) { list.innerHTML = '<p class="iep-help">ยังไม่มีรหัส</p>'; return; }
    const now = Date.now() / 1000;
    codes.forEach((c) => {
      const row = document.createElement('div');
      row.className = 'iep-link';
      const state = c.revoked ? 'ยกเลิกแล้ว' : (c.expiresAt < now ? 'หมดอายุ' : 'ใช้ได้ถึง ' + new Date(c.expiresAt * 1000).toLocaleDateString('th-TH'));
      const text = document.createElement('span');
      text.textContent = c.label + (c.album ? ' · ' + c.album : '') + ' · ' + state;
      row.appendChild(text);
      if (!c.revoked && c.expiresAt > now) {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'iep-secondary'; btn.textContent = 'ยกเลิกรหัส';
        btn.addEventListener('click', async () => {
          if (!confirm('ยกเลิกรหัส "' + c.label + '"? โปรแกรมที่ใช้รหัสนี้จะส่งรูปไม่ได้ทันที')) return;
          try {
            await api(url + '/' + encodeURIComponent(c.id) + '/revoke', { method: 'POST' });
          } catch (e) { status.textContent = e.message; return; }
          load();
        });
        row.appendChild(btn);
      }
      list.appendChild(row);
    });
  }

  async function load() {
    try {
      const data = await api(url);
      const current = albumSel.value;
      albumSel.querySelectorAll('option:not([value=""])').forEach((o) => o.remove());
      data.albums.forEach((a) => { const o = document.createElement('option'); o.value = a.name; o.textContent = a.name; albumSel.appendChild(o); });
      albumSel.value = current;
      render(data.codes);
    } catch (e) { list.textContent = e.message; }
  }

  $('[data-dc-create]').addEventListener('click', async () => {
    status.textContent = 'กำลังสร้าง…';
    const body = new URLSearchParams({ label: $('[data-dc-label]').value, album: albumSel.value, days: $('[data-dc-days]').value });
    try {
      const made = await api(url, { method: 'POST', body });
      status.textContent = 'รหัส: ' + made.code + '  (แสดงครั้งเดียว คัดลอกเก็บไว้)';
      $('[data-dc-label]').value = '';
      load();
    } catch (e) { status.textContent = e.message; }
  });

  load();
})();
