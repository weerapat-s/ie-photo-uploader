(function () {
  'use strict';
  const root = document.getElementById('iephoto-request');
  if (!root) return;

  const nameInput = root.querySelector('[data-req-name]');
  const noteInput = root.querySelector('[data-req-note]');
  const submit = root.querySelector('[data-req-submit]');
  const status = root.querySelector('[data-req-status]');

  const csrf = document.querySelector('head')?.dataset.requesttoken
    || window.OC?.requestToken || '';

  function say(message, kind) {
    status.textContent = message || '';
    status.classList.remove('is-error', 'is-ok');
    if (kind) status.classList.add(kind);
  }

  submit.addEventListener('click', async () => {
    const name = (nameInput.value || '').trim();
    if (!name) {
      say('กรุณาระบุชื่ออัลบั้ม', 'is-error');
      nameInput.focus();
      return;
    }
    submit.disabled = true;
    say('กำลังส่ง…');
    try {
      const body = new URLSearchParams();
      body.append('name', name);
      body.append('note', (noteInput.value || '').trim());
      const response = await fetch(root.dataset.submitUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {requesttoken: csrf, Accept: 'application/json'},
        body,
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'ส่งคำขอไม่สำเร็จ');

      if (data.notified) {
        say('ส่งคำขอแล้ว รอแอดมินกดอนุมัติใน Discord', 'is-ok');
      } else {
        // The request is stored either way; say so rather than implying it was lost.
        say('บันทึกคำขอแล้ว แต่แจ้งเตือน Discord ไม่สำเร็จ' + (data.error ? ' (' + data.error + ')' : '')
          + ' กรุณาแจ้งแอดมินโดยตรง', 'is-error');
      }
      nameInput.value = '';
      noteInput.value = '';
    } catch (error) {
      say(error.message, 'is-error');
    } finally {
      submit.disabled = false;
    }
  });

  nameInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); submit.click(); }
  });
})();
