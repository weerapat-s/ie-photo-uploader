<?php
declare(strict_types=1);

/** @var array $_ */
script('iephoto_watermark', 'upload');
style('iephoto_watermark', 'upload');
$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="iephoto-upload" data-upload-url="<?= $e($_['uploadUrl'] ?? '') ?>" data-album-token="<?= $e($_['albumToken'] ?? '') ?>" data-sports-url="<?= $e($_['sportsUrl'] ?? '') ?>" data-configured="<?= !empty($_['configured']) ? '1' : '0' ?>" data-watermark-url="<?= $e(image_path('iephoto_watermark', 'watermark.png')) ?>">
  <main class="iep-shell">
    <header class="iep-header">
      <img src="<?= $e(image_path('iephoto_watermark', 'watermark.png')) ?>" alt="IE-PHOTO">
      <div><h1>IE Photo Upload</h1><p>อัปโหลด เปลี่ยนชื่อ และใส่ลายน้ำอัตโนมัติ</p></div>
      <?php if (!empty($_['adminUrl'])): ?>
        <a class="iep-admin-link" href="<?= $e($_['adminUrl']) ?>">⚙ ตั้งค่า Admin</a>
      <?php endif; ?>
    </header>

    <section class="iep-card">
      <div class="iep-destination">
        <span>โฟลเดอร์ปลายทางที่แอดมินกำหนด</span>
        <strong><?= $e($_['destination'] ?? '') ?></strong>
      </div>

      <fieldset class="iep-fieldset" data-sport-wrap hidden>
        <legend>ประเภทกีฬา <span class="iep-required">จำเป็น</span></legend>
        <p class="iep-help">เลือกกีฬาที่ถ่าย — ระบบใส่ลายน้ำสีอัตโนมัติ (กลางแจ้ง = ดำ, ในร่ม = ขาว)</p>
        <label for="iep-sport-select">รายการกีฬา</label>
        <select id="iep-sport-select" class="iep-uploader-select" data-sport required>
          <option value="">— กำลังโหลด… —</option>
        </select>
        <div class="iep-other-name" data-sport-other-wrap hidden>
          <label for="iep-sport-other">พิมพ์ชื่อกีฬา</label>
          <input id="iep-sport-other" type="text" maxlength="80" autocomplete="off" placeholder="เช่น แบดมินตัน" data-sport-other disabled>
          <label for="iep-sport-venue">กีฬานี้เล่นที่ไหน <span class="iep-required">จำเป็น</span></label>
          <select id="iep-sport-venue" class="iep-uploader-select" data-sport-venue-select disabled>
            <option value="">— เลือกประเภทสนาม —</option>
            <option value="outdoor">กลางแจ้ง (ลายน้ำสีดำ)</option>
            <option value="indoor">ในร่ม (ลายน้ำสีขาว)</option>
          </select>
        </div>
        <p class="iep-help" data-sport-venue></p>
      </fieldset>

      <fieldset class="iep-fieldset">
        <legend>1. ผู้ถ่ายภาพ / ผู้อัปโหลด <span class="iep-required">จำเป็น</span></legend>
        <p class="iep-help">เลือกผู้ถ่ายภาพหรือผู้อัปโหลดจากรายการ</p>
        <label for="iep-uploader-select">รายชื่อผู้ถ่ายภาพ / ผู้อัปโหลด</label>
        <select id="iep-uploader-select" class="iep-uploader-select" data-uploader-select required>
          <option value="">— กรุณาเลือกชื่อ —</option>
          <?php foreach (['เดียว', 'ปลื้ม', 'เก้า', 'วีโก้', 'ผา', 'อิงค์', 'เบ้ย', 'เนี๋ยบ', 'เชฟ', 'เอิร์ท'] as $index => $person): ?>
            <option value="<?= $e($person) ?>"><?= $index + 1 ?> — <?= $e($person) ?></option>
          <?php endforeach; ?>
          <option value="__other__">+ ชื่ออื่น (พิมพ์เอง)</option>
        </select>
        <div class="iep-other-name" data-other-wrap hidden>
          <label for="iep-other-uploader">พิมพ์ชื่อผู้ถ่ายภาพ / ผู้อัปโหลด</label>
          <input id="iep-other-uploader" type="text" maxlength="80" autocomplete="off" placeholder="เช่น สมชาย" data-other-name disabled>
        </div>
      </fieldset>

      <div class="iep-wm-studio">
        <div class="iep-wm-controls">
          <?php // ตำแหน่งลายน้ำล็อกไว้ที่ "ซ้ายบน" อัตโนมัติ — ช่างภาพไม่ต้องเลือกเอง ?>
          <input type="radio" name="watermark-position" value="top-left" data-position checked hidden>

          <fieldset class="iep-fieldset">
            <legend>2. ระดับความเข้มลายน้ำ</legend>
            <p class="iep-help">ลากแถบเพื่อปรับความเข้ม — ดูผลได้จากภาพตัวอย่างข้างๆ</p>
            <input type="range" min="20" max="100" step="5" value="100" class="iep-opacity" data-opacity aria-label="ระดับความเข้มลายน้ำ">
            <div class="iep-position-label">ความเข้ม: <strong data-opacity-label>100%</strong></div>
          </fieldset>
        </div>

        <div class="iep-wm-preview">
          <span class="iep-wm-preview-title">ตัวอย่างลายน้ำ</span>
          <div class="iep-wm-canvas-wrap" data-preview-wrap>
            <canvas data-preview-canvas></canvas>
            <p class="iep-wm-preview-empty" data-preview-empty>เลือกรูปเพื่อดูตัวอย่างลายน้ำ</p>
          </div>
        </div>
      </div>

      <label class="iep-drop" data-drop>
        <input type="file" accept="image/jpeg,image/png,image/webp" multiple data-files>
        <strong>4. ลากรูปมาวาง หรือคลิกเพื่อเลือก</strong>
        <span>รองรับ JPG, JPEG, PNG และ WebP</span>
        <div class="iep-preview-grid" data-previews hidden aria-label="ตัวอย่างรูปที่เลือก"></div>
      </label>

      <div class="iep-selection" data-selection>ยังไม่ได้เลือกไฟล์</div>
      <button type="button" class="iep-primary" data-upload disabled>อัปโหลดและใส่ลายน้ำ</button>
      <div class="iep-progress" data-progress hidden><span data-progress-bar></span></div>
      <div class="iep-status" data-status role="status" aria-live="polite"></div>
      <ul class="iep-results" data-results></ul>
    </section>
  </main>
</div>
