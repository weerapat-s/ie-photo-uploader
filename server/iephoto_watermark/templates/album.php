<?php
declare(strict_types=1);

/** @var array $_ */
script('iephoto_watermark', 'album');
style('iephoto_watermark', 'album');
$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div
  id="iephoto-album"
  data-token="<?= $e($_['token'] ?? '') ?>"
  data-data-url="<?= $e($_['dataUrl'] ?? '') ?>"
  data-track-view-url="<?= $e($_['trackViewUrl'] ?? '') ?>"
  data-download-selected-url="<?= $e($_['downloadSelectedUrl'] ?? '') ?>"
  data-gallery-url="<?= $e($_['galleryUrl'] ?? '') ?>"
>
  <div class="iepa-loader" data-loader role="status">
    <span aria-hidden="true"></span>
    <strong>IE-PHOTO</strong>
    <small>กำลังเปิดอัลบั้ม</small>
  </div>

  <header class="iepa-topbar">
    <a class="iepa-brand" href="<?= $e($_['galleryUrl'] ?? '') ?>">
      <span aria-hidden="true"></span>
      <strong>IE-PHOTO</strong>
    </a>
    <a class="iepa-back" href="<?= $e($_['galleryUrl'] ?? '') ?>">← กลับหน้า Gallery</a>
  </header>

  <nav class="iepa-pathbar" data-pathbar hidden aria-label="ตำแหน่งโฟลเดอร์ปัจจุบัน">
    <button type="button" class="iepa-pathbar__back" data-pathbar-back aria-label="ย้อนกลับหนึ่งขั้น">←</button>
    <div class="iepa-pathbar__crumbs" data-pathbar-crumbs></div>
  </nav>

  <main>
    <section class="iepa-hero" data-hero-mosaic hidden aria-hidden="true"></section>

    <section class="iepa-head">
      <div class="iepa-kicker">IE-PHOTO ALBUM VIEWER</div>
      <h1 data-album-name>กำลังโหลดอัลบั้ม…</h1>
      <p data-album-description>ดูภาพและดาวน์โหลดได้โดยไม่เปิดโฟลเดอร์ต้นทาง</p>
      <div class="iepa-meta" data-album-meta></div>
      <div class="iepa-actions">
        <button type="button" class="iepa-primary iepa-cta" data-proxy="[data-download-all-web]">⬇ ดาวน์โหลดทั้งอัลบั้ม (ขนาดเว็บ)</button>
        <button type="button" class="iepa-secondary" data-proxy="[data-selection-toggle]">เลือกเฉพาะบางรูป</button>
        <button type="button" class="iepa-secondary" data-copy-link>คัดลอกลิงก์อัลบั้ม</button>
      </div>
    </section>

    <section class="iepa-folders" data-folder-section hidden aria-label="โฟลเดอร์ย่อย">
      <div class="iepa-folders__head">
        <h2>เลือกโฟลเดอร์</h2>
        <p data-folder-count></p>
      </div>
      <div class="iepa-folder-grid" data-folder-grid></div>
    </section>

    <section class="iepa-gallery" data-gallery-section aria-labelledby="iepa-gallery-title">
      <div class="iepa-toolbar">
        <div>
          <span>PHOTO ARCHIVE</span>
          <h2 id="iepa-gallery-title">ภาพทั้งหมดในงาน</h2>
        </div>
        <div class="iepa-filters">
          <label>
            <span>ค้นหารูป</span>
            <input type="search" data-search placeholder="ค้นหาชื่อไฟล์…">
          </label>
          <label>
            <span>คนที่อัปโหลด</span>
            <select data-photographer><option value="">ทุกคน</option></select>
          </label>
        </div>
      </div>
      <div class="iepa-browse-tools">
        <button type="button" class="iepa-secondary" data-selection-toggle aria-pressed="false">เลือกรูปหลายภาพ</button>
        <button type="button" class="iepa-secondary" data-download-all>⬇ ดาวน์โหลดทั้งอัลบั้ม (เร็ว)</button>
        <button type="button" class="iepa-secondary" data-download-all-web>⬇ ทั้งอัลบั้ม ขนาดเว็บ (เล็ก/เร็วมาก)</button>
        <button type="button" class="iepa-secondary" data-download-cancel hidden>✕ ยกเลิกดาวน์โหลด</button>
        <div class="iepa-load-progress" data-load-progress hidden>
          <span data-load-summary></span>
          <progress data-load-meter value="0" max="1"></progress>
        </div>
      </div>
      <div class="iepa-status" data-status role="status">กำลังโหลดรูปภาพ…</div>
      <div class="iepa-grid" data-grid hidden></div>
      <div class="iepa-empty" data-empty hidden><p>ไม่พบรูปของคนที่เลือกหรือตรงกับคำค้น</p><button type="button" class="iepa-secondary" data-empty-clear>ล้างคำค้นและตัวกรอง ↺</button></div>
      <button type="button" class="iepa-more" data-more hidden>โหลดรูปเพิ่มเติม</button>
    </section>
  </main>

  <footer><strong>IE-PHOTO · KMITL</strong><span>VIEW / DOWNLOAD ONLY</span></footer>

  <div class="iepa-toast" data-toast hidden role="status"></div>
  <div class="iepa-selection-bar" data-selection-bar hidden>
    <span>เลือกแล้ว <strong data-selected-count>0</strong> รูป <small>สูงสุด 60 รูป</small></span>
    <div>
      <button type="button" class="iepa-secondary" data-selection-clear>ล้าง</button>
      <button type="button" class="iepa-download-zip" data-download-selected disabled>ดาวน์โหลด ZIP ↓</button>
    </div>
  </div>
  <div class="iepa-lightbox" data-lightbox hidden role="dialog" aria-modal="true" aria-labelledby="iepa-lightbox-title">
    <button type="button" class="iepa-close" data-close aria-label="ปิด">×</button>
    <button type="button" class="iepa-nav is-prev" data-prev aria-label="รูปก่อนหน้า">‹</button>
    <figure>
      <div class="iepa-stage">
        <img data-lightbox-image alt="">
        <div class="iepa-lightbox-spin" data-lightbox-spin hidden aria-hidden="true"></div>
      </div>
      <figcaption>
        <div><strong id="iepa-lightbox-title" data-lightbox-title></strong><span data-lightbox-meta></span></div>
        <a class="iepa-download-btn" data-download-web href="#">ดาวน์โหลดขนาดเว็บ ↓</a>
        <a class="iepa-download-btn" data-download href="#">ดาวน์โหลดต้นฉบับ ↓</a>
      </figcaption>
    </figure>
    <button type="button" class="iepa-nav is-next" data-next aria-label="รูปถัดไป">›</button>
  </div>
</div>
