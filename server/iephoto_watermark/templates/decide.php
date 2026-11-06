<?php
declare(strict_types=1);

/** @var array $_ */
style('iephoto_watermark', 'upload');
style('iephoto_watermark', 'admin-dashboard');
$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ok = !empty($_['ok']);
$approved = ($_['status'] ?? '') === 'approved';
?>
<div id="iephoto-decide">
  <main class="iep-shell">
    <section class="iep-card">
      <?php if (!$ok): ?>
        <h1>ทำรายการไม่สำเร็จ</h1>
        <p class="iep-help"><?= $e($_['error'] ?? '') ?></p>
      <?php else: ?>
        <h1><?= $approved ? '✅ อนุมัติแล้ว' : '❌ ไม่อนุมัติ' ?></h1>
        <?php if (!empty($_['alreadyDecided'])): ?>
          <p class="iep-help">คำขอนี้ถูกตัดสินไปก่อนหน้านี้แล้ว — แสดงผลเดิม ไม่ได้ทำซ้ำ</p>
        <?php endif; ?>
        <p class="iep-help">
          <strong>ชื่องาน:</strong> <?= $e($_['name'] ?? '') ?><br>
          <strong>ผู้ขอ:</strong> <?= $e($_['requestedBy'] ?? '') ?>
        </p>

        <?php if ($approved): ?>
          <div class="iep-links">
            <div class="iep-link">
              <span>ลิงก์สำหรับอัปโหลดรูป <em>(ส่งให้ช่างภาพเท่านั้น)</em></span>
              <div><input type="text" readonly value="<?= $e($_['uploadLink'] ?? '') ?>"></div>
            </div>
            <div class="iep-link">
              <span>ลิงก์สำหรับดูรูป <em>(ส่งได้ทุกคน)</em></span>
              <div><input type="text" readonly value="<?= $e($_['viewLink'] ?? '') ?>"></div>
            </div>
          </div>
          <p class="iep-help">ลิงก์ทั้งสองถูกส่งกลับเข้า Discord แล้วเช่นกัน</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
</div>
