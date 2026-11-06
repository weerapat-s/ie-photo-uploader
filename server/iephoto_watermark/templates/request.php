<?php
declare(strict_types=1);

/** @var array $_ */
script('iephoto_watermark', 'request');
style('iephoto_watermark', 'upload');
style('iephoto_watermark', 'admin-dashboard');
$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="iephoto-request" data-submit-url="<?= $e($_['submitUrl'] ?? '') ?>">
  <main class="iep-shell">
    <header class="iep-header">
      <h1>ขอสร้างโฟลเดอร์รูป</h1>
      <p class="iep-help">ส่งคำขอไปที่แอดมิน เมื่ออนุมัติแล้วจะได้ลิงก์สำหรับอัปโหลดและลิงก์สำหรับดูรูป</p>
    </header>

    <section class="iep-card">
      <?php if (empty($_['configured'])): ?>
        <p class="iep-help" style="color:#c31f42">
          ยังไม่ได้ตั้งค่า Discord webhook ในหน้าแอดมิน — ส่งคำขอได้ แต่แอดมินจะไม่ได้รับแจ้งเตือน
        </p>
      <?php endif; ?>

      <label for="iep-req-name">ชื่องาน / ชื่ออัลบั้ม</label>
      <input id="iep-req-name" type="text" data-req-name maxlength="80" placeholder="เช่น ชอล์กเกมส์ - วอลเลย์บอล">

      <label for="iep-req-note">หมายเหตุ (ไม่บังคับ)</label>
      <input id="iep-req-note" type="text" data-req-note maxlength="300" placeholder="เช่น ถ่ายวันที่ 5 ก.ย. ช่างภาพ 3 คน">

      <div class="iep-row">
        <button type="button" class="iep-primary" data-req-submit>ส่งคำขอ</button>
      </div>
      <div class="iep-status" data-req-status role="status" aria-live="polite"></div>
    </section>
  </main>
</div>
