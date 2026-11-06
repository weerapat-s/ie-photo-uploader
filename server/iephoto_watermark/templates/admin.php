<?php
declare(strict_types=1);

/** @var array $_ */
script('iephoto_watermark', 'admin');
script('iephoto_watermark', 'admin-desktop');
style('iephoto_watermark', 'upload');
style('iephoto_watermark', 'admin-dashboard');
$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="iephoto-admin"
     data-desktop-codes-url="<?= $e($_['desktopCodesUrl'] ?? '') ?>"
     data-config-url="<?= $e($_['configUrl'] ?? '') ?>"
     data-album-api="<?= $e($_['albumApiUrl'] ?? '') ?>"
     data-album-view-base="<?= $e($_['albumViewBase'] ?? '') ?>"
     data-album-upload-base="<?= $e($_['albumUploadBase'] ?? '') ?>"
     data-requests-url="<?= $e($_['requestsUrl'] ?? '') ?>"
     data-pending-url="<?= $e($_['pendingUrl'] ?? '') ?>"
     data-scopes-url="<?= $e($_['scopesUrl'] ?? '') ?>"
     data-webhook-url="<?= $e($_['webhookUrl'] ?? '') ?>"
     data-folders-url="<?= $e($_['foldersUrl'] ?? '') ?>"
     data-stats-url="<?= $e($_['statsUrl'] ?? '') ?>"
     data-process-url="<?= $e($_['processUrl'] ?? '') ?>"
     data-albums-url="<?= $e($_['albumsUrl'] ?? '') ?>">
  <main class="iep-shell">
    <header class="iep-header">
      <img src="<?= $e(image_path('iephoto_watermark', 'watermark.png')) ?>" alt="IE-PHOTO">
      <div><h1>IE Photo Admin</h1><p>กำหนดพื้นที่จัดเก็บรูปจากหน้าอัปโหลด</p></div>
      <a class="iep-admin-link" href="<?= $e($_['uploadUrl'] ?? '') ?>">← หน้าอัปโหลด</a>
    </header>

    <section class="iep-card iep-albums">
      <h2>ขอสร้างโฟลเดอร์รูป</h2>
      <p class="iep-help">
        ตั้งชื่อกีฬาและเลือกกลางแจ้ง/ในร่ม (กำหนดสีลายน้ำ) แล้วส่ง <strong>ลิงก์ให้ช่างภาพอัป</strong> ไปให้ช่างภาพ
        — <strong>ระบบทำให้เองหลังอัป</strong>: รอไฟล์นิ่ง แล้วใส่ลายน้ำ เปลี่ยนชื่อ และเก็บต้นฉบับให้อัตโนมัติ
        ไม่ต้องมากดอะไรอีก · ถ้าจะเปลี่ยนกลางแจ้ง/ในร่มของกีฬาที่มีอยู่แล้ว ใส่ชื่อเดิมแล้วเลือกใหม่ กดสร้างซ้ำได้เลย
      </p>
      <p class="iep-albums-root">สร้างไว้ใน <code data-album-root>—</code></p>
      <div class="iep-row">
        <label class="iep-albums-field">
          <span>ชื่องาน / ชื่ออัลบั้ม</span>
          <input type="text" data-album-name maxlength="80" placeholder="เช่น ชอล์กเกมส์ - วอลเลย์บอล">
        </label>
        <label class="iep-albums-field">
          <span>ประเภทสนาม</span>
          <select data-album-venue>
            <option value="outdoor">กลางแจ้ง (ลายน้ำสีดำ)</option>
            <option value="indoor">ในร่ม (ลายน้ำสีขาว)</option>
          </select>
        </label>
        <button type="button" class="iep-primary" data-album-submit>สร้างอัลบั้ม</button>
      </div>
      <div class="iep-status" data-album-status role="status" aria-live="polite"></div>

      <div class="iep-links" data-album-result hidden>
        <div class="iep-link">
          <span>ลิงก์สำหรับอัปโหลดรูป <em>(ส่งให้ช่างภาพเท่านั้น)</em></span>
          <div><input type="text" readonly data-link-upload><button type="button" class="iep-secondary" data-copy="upload">คัดลอก</button></div>
        </div>
        <div class="iep-link">
          <span>ลิงก์สำหรับดูรูป <em>(ส่งให้ทุกคนได้)</em></span>
          <div><input type="text" readonly data-link-view><button type="button" class="iep-secondary" data-copy="view">คัดลอก</button></div>
        </div>
      </div>

      <h3 class="iep-albums-listtitle">อัลบั้มที่มีอยู่</h3>
      <div class="iep-albums-list" data-album-list><p class="iep-help">กำลังโหลด…</p></div>
    </section>

    <section class="iep-card" data-desktop-section>
      <h2>รหัสสำหรับโปรแกรม IE-PHOTO Uploader</h2>
      <p class="iep-help">ช่างภาพใส่รหัสนี้ในโปรแกรมบนเครื่องตัวเอง โปรแกรมใส่ลายน้ำเองแล้วส่งขึ้นมา เซิร์ฟเวอร์แค่ตั้งชื่อ · รหัสแสดงครั้งเดียว คัดลอกเก็บไว้</p>
      <div class="iep-row">
        <label class="iep-albums-field"><span>ชื่องาน</span><input type="text" data-dc-label maxlength="80" placeholder="เช่น ChalkGames 2569"></label>
        <label class="iep-albums-field"><span>ล็อกอัลบั้ม (ไม่บังคับ)</span><select data-dc-album><option value="">ให้ช่างภาพเลือกกีฬาเอง</option></select></label>
        <label class="iep-albums-field"><span>ใช้ได้กี่วัน</span><input type="number" data-dc-days min="1" max="90" value="7"></label>
        <button type="button" class="iep-primary" data-dc-create>สร้างรหัส</button>
      </div>
      <div class="iep-status" data-dc-status role="status" aria-live="polite"></div>
      <div class="iep-albums-list" data-dc-list><p class="iep-help">กำลังโหลด…</p></div>
    </section>

    <section class="iep-card iep-requests">
      <h2>คำขอสร้างโฟลเดอร์จากผู้ใช้</h2>
      <p class="iep-help">
        ส่งลิงก์นี้ให้คนอื่นกรอกคำขอ:
        <a href="<?= $e($_['requestPageUrl'] ?? '') ?>" target="_blank" rel="noopener"><?= $e($_['requestPageUrl'] ?? '') ?></a>
        <br>เมื่อมีคำขอเข้ามา ระบบจะส่งเข้า Discord พร้อมลิงก์ <strong>อนุญาต</strong> / <strong>ไม่อนุญาต</strong>
      </p>

      <label for="iep-webhook">Discord webhook URL</label>
      <input id="iep-webhook" type="url" data-webhook-input placeholder="https://discord.com/api/webhooks/..." autocomplete="off">
      <div class="iep-row">
        <button type="button" class="iep-secondary" data-webhook-save>บันทึก webhook</button>
        <span class="iep-help" data-webhook-state></span>
      </div>

      <h3 class="iep-albums-listtitle">คำขอล่าสุด</h3>
      <div class="iep-albums-list" data-request-list><p class="iep-help">กำลังโหลด…</p></div>
    </section>

    <section class="iep-card">
      <h2>โฟลเดอร์ปลายทางกลาง <span class="iep-locked-badge" data-destination-locked>ล็อกไว้</span></h2>
      <p class="iep-help">
        ล็อกไว้ที่งานชอล์กเกมส์แล้ว — รูปที่อัปเข้ามาจะลงโฟลเดอร์กีฬาใต้งานนี้เสมอ ไม่ต้องมาเปลี่ยนไดรฟ์อีก
        (ถ้าต้องย้ายไปงานใหม่จริงๆ ให้ปลดล็อกที่เซิร์ฟเวอร์)
      </p>
      <p class="iep-help">ผู้ใช้ทุกคนที่อัปโหลดผ่าน IE Photo Upload จะส่งรูปเข้าโฟลเดอร์นี้</p>

      <label for="iep-owner">เจ้าของพื้นที่ Nextcloud</label>
      <select id="iep-owner" data-owner <?= !empty($_['destinationLocked']) ? 'disabled' : '' ?>><option>กำลังโหลดผู้ใช้…</option></select>

      <label for="iep-admin-folder">โฟลเดอร์ปลายทาง</label>
      <div class="iep-row iep-folder-row">
        <div class="iep-folder-picker" data-folder-picker>
          <input type="hidden" value="/" data-folder>
          <button id="iep-admin-folder" type="button" class="iep-folder-toggle" data-folder-toggle aria-haspopup="listbox" aria-expanded="false" <?= !empty($_['destinationLocked']) ? 'disabled' : '' ?>>
            <span class="iep-folder-icon" aria-hidden="true">📁</span>
            <span data-folder-selected>กรุณาเลือกเจ้าของพื้นที่</span>
            <span class="iep-folder-chevron" aria-hidden="true">⌄</span>
          </button>
          <div class="iep-folder-popover" data-folder-popover hidden>
            <input type="search" class="iep-folder-search" placeholder="ค้นหาชื่อโฟลเดอร์…" aria-label="ค้นหาโฟลเดอร์" data-folder-search>
            <div class="iep-folder-options" role="listbox" aria-label="รายการโฟลเดอร์" data-folder-options></div>
            <div class="iep-folder-empty" data-folder-empty hidden>ไม่พบโฟลเดอร์ที่ค้นหา</div>
          </div>
        </div>
        <button type="button" class="iep-secondary" data-new-folder <?= !empty($_['destinationLocked']) ? 'disabled' : '' ?>>＋ สร้างโฟลเดอร์</button>
      </div>

      <button type="button" class="iep-primary" data-save disabled>บันทึกโฟลเดอร์ปลายทาง</button>
      <div class="iep-destination-lock" data-destination-lock <?= !empty($_['destinationLocked']) ? '' : 'hidden' ?>></div>
      <div class="iep-status" data-status role="status" aria-live="polite"></div>
    </section>

    <section class="iep-card">
      <h2>ใส่ลายน้ำ + เปลี่ยนชื่อ ให้โฟลเดอร์ที่อัปมาแล้ว</h2>
      <p class="iep-help">
        ใช้กับรูปที่อัปเข้ามาทาง Files หรือโปรแกรม Nextcloud (ที่ยังเป็นชื่อกล้องเดิม)
        ระบบจะเปลี่ยนชื่อเป็น IEPHOTO@KMTIL_xxxx ใส่ลายน้ำ และเก็บต้นฉบับไว้ในโฟลเดอร์
        &ldquo;&lt;ชื่อโฟลเดอร์&gt; ต้นฉบับ&rdquo; ให้อัตโนมัติ
      </p>

      <label for="iep-proc-owner">เจ้าของพื้นที่</label>
      <select id="iep-proc-owner" data-proc-owner><option>กำลังโหลดผู้ใช้…</option></select>

      <label for="iep-scopes">ขอบเขตที่ให้ใส่ลายน้ำ</label>
      <textarea id="iep-scopes" class="iep-scopes" rows="3" data-scopes
                placeholder="/ปีการศึกษา 2569"></textarea>
      <small class="iep-help">
        หนึ่งโฟลเดอร์ต่อหนึ่งบรรทัด ทั้งการตรวจและการประมวลผลจะทำเฉพาะในนี้เท่านั้น
        โฟลเดอร์ส่วนตัวนอกรายการนี้จะไม่ถูกแตะ เว้นว่าง = ทั้งพื้นที่
      </small>
      <div class="iep-row">
        <button type="button" class="iep-secondary" data-scopes-save>บันทึกขอบเขต</button>
        <span class="iep-help" data-scopes-status></span>
      </div>

      <div class="iep-scan">
        <div class="iep-row">
          <button type="button" class="iep-primary" data-scan-run>🔍 ตรวจหาโฟลเดอร์ที่ยังไม่ได้ทำ</button>
          <span class="iep-help" data-scan-summary></span>
        </div>
        <div class="iep-scan-list" data-scan-list></div>
      </div>

      <label for="iep-proc-path">หรือระบุโฟลเดอร์เอง</label>
      <input id="iep-proc-path" type="text" data-proc-path
             placeholder="วางลิงก์โฟลเดอร์ หรือพิมพ์เส้นทาง เช่น /ปีการศึกษา 2569/ชอล์กเกมส์">
      <small class="iep-help">วางลิงก์จากหน้า Files ได้เลย ระบบจะดึงเฉพาะเส้นทางให้เอง</small>

      <span class="iep-wmcolour-label" id="iep-wmcolour-label">สีลายน้ำ</span>
      <div class="iep-wmcolour" role="radiogroup" aria-labelledby="iep-wmcolour-label">
        <label class="iep-wmcolour-option">
          <input type="radio" name="iep-wmcolour" value="black" checked data-proc-colour>
          <span><i class="iep-wmcolour-chip is-black"></i>สีดำ</span>
        </label>
        <label class="iep-wmcolour-option">
          <input type="radio" name="iep-wmcolour" value="white" data-proc-colour>
          <span><i class="iep-wmcolour-chip is-white"></i>สีขาว</span>
        </label>
      </div>
      <small class="iep-help">ภาพสว่าง / กลางแจ้ง ใช้สีดำ · ภาพมืด / ในร่ม ใช้สีขาว</small>

      <div class="iep-row">
        <button type="button" class="iep-primary" data-proc-start>เริ่มประมวลผล</button>
        <button type="button" class="iep-secondary" data-proc-stop hidden>หยุด</button>
      </div>

      <div class="iep-progress" data-proc-progress hidden><span data-proc-bar></span></div>
      <div class="iep-status" data-proc-status role="status" aria-live="polite"></div>
      <ul class="iep-results" data-proc-results></ul>
    </section>

    <section class="iepad-dashboard" aria-labelledby="iepad-title">
      <header><div><span>ADMIN ANALYTICS</span><h2 id="iepad-title">ภาพรวมระบบ IE-PHOTO</h2></div><button type="button" data-refresh-stats>รีเฟรชข้อมูล</button></header>
      <div class="iepad-stats" data-stats-cards>
        <article><span>งานใน Gallery</span><strong>—</strong></article>
        <article><span>รูปทั้งหมด</span><strong>—</strong></article>
        <article><span>พื้นที่รวม</span><strong>—</strong></article>
        <article><span>อัปโหลดล่าสุด</span><strong>—</strong></article>
      </div>
      <div class="iepad-columns">
        <section><h3>สรุปตามผู้ถ่ายภาพ</h3><div class="iepad-list" data-photographer-stats><p>กำลังโหลด…</p></div></section>
        <section><h3>ประวัติอัปโหลดล่าสุด</h3><div class="iepad-history" data-upload-history><p>กำลังโหลด…</p></div></section>
      </div>
      <div class="iepad-status" data-stats-status role="status"></div>
    </section>
  </main>
</div>
