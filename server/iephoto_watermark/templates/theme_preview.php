<?php
declare(strict_types=1);

/** @var array $_ */
script('iephoto_watermark', 'theme-preview');
style('iephoto_watermark', 'theme-preview');
$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div
  id="iephoto-theme-preview"
  data-albums-url="<?= $e($_['albumsUrl'] ?? '') ?>"
>
  <div class="iept-preview-ribbon">ตัวอย่างธีมใหม่ · ยังไม่ใช้จริง</div>

  <header class="iept-header">
    <a class="iept-brand" href="#iept-top" aria-label="IE-PHOTO Theme Preview">
      <span class="iept-brand-mark"><span></span></span>
      <span><strong>IE–PHOTO</strong><small>KMITL ARCHIVE</small></span>
    </a>
    <nav aria-label="เมนูตัวอย่างธีม">
      <a href="#iept-folders">คลังภาพ</a>
      <a href="<?= $e($_['uploadUrl'] ?? '') ?>">ระบบอัปโหลด</a>
      <a class="iept-back" href="<?= $e($_['currentThemeUrl'] ?? '') ?>">กลับธีมปัจจุบัน</a>
    </nav>
  </header>

  <main>
    <section class="iept-hero" id="iept-top">
      <span class="iept-kicker">SCHOOL OF INDUSTRIAL EDUCATION</span>
      <h1>IE–PHOTO<br>VISUAL ARCHIVE</h1>
      <p>ภาพถ่ายทุกกิจกรรม บันทึกอย่างเป็นระบบ เปิดดูและดาวน์โหลดได้ในคลังเดียว</p>
      <div class="iept-actions">
        <a href="#iept-folders">เปิดคลังภาพ ↓</a>
        <span><strong data-preview-count>…</strong> โฟลเดอร์งาน</span>
      </div>
      <div class="iept-fan" data-preview-fan hidden aria-label="ภาพแนะนำจาก IE-PHOTO"></div>
    </section>

    <section class="iept-archive" id="iept-folders">
      <div class="iept-archive-head">
        <div><span>ARCHIVE / 2026</span><h2>โฟลเดอร์รูปทั้งหมดทุกงาน</h2></div>
        <p>FILM CONTACT SHEET<br>VIEW / DOWNLOAD ONLY</p>
      </div>
      <div class="iept-status" data-preview-status>กำลังโหลดข้อมูลจริงจาก Gallery…</div>
      <div class="iept-grid" data-preview-grid hidden></div>
    </section>
  </main>

  <footer><strong>IE–PHOTO · KMITL</strong><span>DARKROOM EDITORIAL THEME PREVIEW</span></footer>
</div>
