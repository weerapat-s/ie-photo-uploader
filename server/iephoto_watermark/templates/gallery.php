<?php
declare(strict_types=1);

/** @var array $_ */
script('iephoto_watermark', 'gallery');
style('iephoto_watermark', 'gallery');
$e = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div
  id="iephoto-gallery"
  data-albums-url="<?= $e($_['albumsUrl'] ?? '') ?>"
  data-album-url-template="<?= $e($_['albumUrlTemplate'] ?? '') ?>"
  data-cover-api-base="<?= $e($_['adminUrl'] ?? '') ?>"
>
  <div class="iepg-page-loader" data-page-loader role="status" aria-label="กำลังเชื่อมต่อ IE-PHOTO Gallery">
    <div class="iepg-loader-space" aria-hidden="true">
      <span class="iepg-loader-stars is-far"></span>
      <span class="iepg-loader-stars is-near"></span>
      <span class="iepg-loader-nebula is-left"></span>
      <span class="iepg-loader-nebula is-right"></span>
      <span class="iepg-loader-orbit is-wide"><i></i></span>
      <span class="iepg-loader-orbit is-tight"><i></i></span>
      <span class="iepg-loader-planet"><i></i></span>
    </div>
    <div class="iepg-vf" aria-hidden="true">
      <span class="iepg-vf-grid"></span>
      <span class="iepg-vf-corner is-tl"></span>
      <span class="iepg-vf-corner is-tr"></span>
      <span class="iepg-vf-corner is-bl"></span>
      <span class="iepg-vf-corner is-br"></span>
      <span class="iepg-vf-scan"></span>
      <span class="iepg-vf-blade is-top"></span>
      <span class="iepg-vf-blade is-bottom"></span>
    </div>
    <div class="iepg-vf-hud" aria-hidden="true">
      <span class="iepg-hud is-tl"><i class="iepg-rec"></i>IE-PHOTO · ORBITAL NODE</span>
      <span class="iepg-hud is-tr">SECTOR 013 · SYNC 99.8%</span>
      <span class="iepg-hud is-bl">STAR MAP · ARCHIVE LINK</span>
      <span class="iepg-hud is-br">COSMIC GALLERY</span>
    </div>
    <div class="iepg-loader-aura" aria-hidden="true"></div>
    <div class="iepg-loader-mark" aria-hidden="true">
      <svg class="iepg-loader-iris" viewBox="0 0 120 120" focusable="false">
        <defs>
          <mask id="iepg-iris-mask">
            <rect width="120" height="120" fill="#fff"/>
            <circle cx="60" cy="60" fill="#000" r="17">
              <animate attributeName="r" dur="2.6s" repeatCount="indefinite"
                values="17;31;17" keyTimes="0;0.5;1" calcMode="spline"
                keySplines=".45 0 .2 1;.45 0 .2 1"/>
            </circle>
          </mask>
        </defs>
        <g class="iepg-iris-blades" mask="url(#iepg-iris-mask)">
          <polygon class="iepg-iris-blade" points="60,60 60.0,4.0 108.5,32.0"/>
          <polygon class="iepg-iris-blade" points="60,60 108.5,32.0 108.5,88.0"/>
          <polygon class="iepg-iris-blade" points="60,60 108.5,88.0 60.0,116.0"/>
          <polygon class="iepg-iris-blade" points="60,60 60.0,116.0 11.5,88.0"/>
          <polygon class="iepg-iris-blade" points="60,60 11.5,88.0 11.5,32.0"/>
          <polygon class="iepg-iris-blade" points="60,60 11.5,32.0 60.0,4.0"/>
        </g>
        <circle class="iepg-iris-track" cx="60" cy="60" r="57"/>
        <circle class="iepg-iris-arc" cx="60" cy="60" r="57"/>
      </svg>
      <span class="iepg-vf-reticle"></span>
      <span class="iepg-loader-diamond"></span>
      <i class="iepg-loader-spark"></i>
    </div>
    <strong class="iepg-loader-word" aria-hidden="true"><span style="--i:0">I</span><span style="--i:1">E</span><span style="--i:2">-</span><span style="--i:3">P</span><span style="--i:4">H</span><span style="--i:5">O</span><span style="--i:6">T</span><span style="--i:7">O</span></strong>
    <small>กำลังเปิดคลังภาพ…</small>
    <div class="iepg-loader-line" aria-hidden="true"><span></span></div>
  </div>

  <header class="iepg-topbar">
    <a class="iepg-brand" href="#top" aria-label="IE-PHOTO หน้าแรก">
      <img src="<?= $e(image_path('iephoto_watermark', 'watermark.png')) ?>" alt="">
      <span><strong>IE-PHOTO</strong><small>KMITL</small></span>
    </a>
    <nav class="iepg-nav" aria-label="เมนู IE-PHOTO">
      <a href="#albums">โฟลเดอร์งาน</a>
      <?php if (!empty($_['adminUrl'])): ?>
        <a href="<?= $e($_['adminUrl']) ?>">ตั้งค่า Admin</a>
      <?php endif; ?>
    </nav>
  </header>

  <main id="top" class="iepg-main">
    <section class="iepg-hero" aria-labelledby="iepg-title">
      <span class="iepg-hero-frame" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
      <div class="iepg-space-scene" aria-hidden="true">
        <span class="iepg-starfield is-far"></span>
        <span class="iepg-starfield is-near"></span>
        <span class="iepg-nebula is-one"></span>
        <span class="iepg-nebula is-two"></span>
        <span class="iepg-orbit is-outer"><i></i></span>
        <span class="iepg-orbit is-inner"><i></i></span>
        <span class="iepg-planet"><i></i></span>
      </div>
      <div class="iepg-space-telemetry" aria-hidden="true">
        <span>ORBITAL ARCHIVE · 013</span>
        <span>NODE ONLINE · 13.729°N 100.778°E</span>
      </div>
      <div class="iepg-eyebrow"><span></span> SCHOOL OF INDUSTRIAL EDUCATION</div>
      <h1 id="iepg-title" aria-label="IE-PHOTO Gallery">
        <span class="iepg-typewriter" data-typewriter aria-hidden="true">IE-PHOTO Gallery</span>
      </h1>
      <svg class="iepg-hero-rule" viewBox="0 0 520 9" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path d="M3 6 C 120 1.5, 265 8, 517 3"/>
      </svg>
      <p>ดูและดาวน์โหลดรูปกิจกรรมทุกงานของคณะครุศาสตร์อุตสาหกรรมฯ สจล. ฟรี ไม่ต้องสมัคร</p>
      <div class="iepg-actions">
        <a class="iepg-button iepg-primary" href="#albums">เข้าสู่คลังภาพ <span aria-hidden="true">↓</span></a>
      </div>
      <div class="iepg-showcase" data-hero-showcase hidden aria-label="ภาพแนะนำจาก IE-PHOTO">
        <div class="iepg-showcase-glow" aria-hidden="true"></div>
        <div class="iepg-showcase-stage" data-hero-stage></div>
      </div>
      <div class="iepg-ticker" data-ticker hidden aria-hidden="true">
        <div class="iepg-ticker__track" data-ticker-track></div>
      </div>
      <div class="iepg-summary" aria-label="สรุปคลังภาพ">
        <strong data-album-count>…</strong>
        <span>โฟลเดอร์งานในแกลเลอรี</span>
      </div>
    </section>

    <section id="albums" class="iepg-latest" aria-labelledby="iepg-albums-title">
      <div class="iepg-archive-chapter" aria-hidden="true"><span>01 / THE ARCHIVE</span><i></i><span>IE-PHOTO COLLECTION</span></div>
      <div class="iepg-section-heading">
        <div class="iepg-archive-intro">
          <span class="iepg-kicker">ALL EVENTS</span>
          <h2 id="iepg-albums-title">เลือก<span>งานของคุณ</span></h2>
          <p>กดที่งานที่คุณไปร่วม แล้วหารูปตัวเองได้เลย<br>ค้นหาด้วยชื่องาน ปี เดือน หรือผู้ถ่ายภาพ</p>
        </div>
        <div class="iepg-archive-overview">
          <svg class="iepg-archive-emblem" viewBox="0 0 160 160" fill="none" aria-hidden="true" focusable="false">
            <circle cx="80" cy="80" r="63"/><circle cx="80" cy="80" r="48" stroke-dasharray="2 7"/>
            <ellipse cx="80" cy="80" rx="75" ry="25" transform="rotate(-35 80 80)"/>
            <path d="M80 7v12M80 141v12M7 80h12M141 80h12M64 69l5-8h22l5 8h9v32H55V69Z"/>
            <circle cx="80" cy="84" r="11"/><circle class="iepg-emblem-star" cx="131" cy="43" r="4"/>
            <circle class="iepg-emblem-star" cx="26" cy="116" r="3"/>
          </svg>
          <dl class="iepg-archive-stats" aria-label="จำนวนงานและภาพในคลัง">
            <div><dt>โฟลเดอร์งาน</dt><dd data-archive-albums>—</dd></div>
            <div><dt>รูปทั้งหมด</dt><dd data-archive-photos>—</dd></div>
          </dl>
        </div>
      </div>
      <div class="iepg-album-tools" aria-label="ค้นหาและกรองโฟลเดอร์งาน">
        <label><span>ค้นหางาน</span><input type="search" data-album-search placeholder="ค้นหาชื่องาน…"></label>
        <label><span>ปี</span><select data-album-year><option value="">ทุกปี</option></select></label>
        <label><span>เดือน</span><select data-album-month><option value="">ทุกเดือน</option></select></label>
        <label><span>ผู้ถ่ายภาพ</span><select data-album-photographer><option value="">ทุกคน</option></select></label>
      </div>
      <div class="iepg-results-bar">
        <span data-album-results role="status" aria-live="polite">กำลังโหลดรายการงาน…</span>
        <button type="button" class="iepg-filter-reset" data-filter-reset hidden>ล้างตัวกรอง <span aria-hidden="true">↺</span></button>
        <span class="iepg-results-note" aria-hidden="true">เลือกงานด้านล่าง <i>↓</i></span>
      </div>
      <div class="iepg-album-status" data-album-status role="status">กำลังโหลดโฟลเดอร์งาน…</div>
      <div class="iepg-album-grid" data-album-grid hidden></div>
      <div class="iepg-empty" data-album-empty hidden>
        <div aria-hidden="true">▧</div>
        <h3>ยังไม่มีโฟลเดอร์งาน</h3>
        <p>เมื่อ Admin เพิ่มโฟลเดอร์งานใน IE Drive โฟลเดอร์จะมาแสดงตรงนี้อัตโนมัติ</p>
      </div>
    </section>
  </main>

  <footer class="iepg-footer">
    <strong>IE-PHOTO · KMITL</strong>
    <span>ภาพทั้งหมดผ่านระบบเปลี่ยนชื่อและลายน้ำแล้ว</span>
  </footer>


  <div class="iepg-lightbox" data-hero-lightbox hidden role="dialog" aria-modal="true" aria-labelledby="iepg-lightbox-title">
    <button class="iepg-lightbox-close" type="button" data-lightbox-close aria-label="ปิดภาพขนาดใหญ่">×</button>
    <button class="iepg-lightbox-nav is-prev" type="button" data-lightbox-prev aria-label="ภาพก่อนหน้า">‹</button>
    <figure>
      <img data-lightbox-image alt="">
      <figcaption>
        <div>
          <strong id="iepg-lightbox-title" data-lightbox-title>ภาพจาก IE-PHOTO</strong>
          <span>ดูภาพและดาวน์โหลดเท่านั้น · ไม่เปิดโฟลเดอร์ต้นทาง</span>
        </div>
        <a data-lightbox-download href="#" download>ดาวน์โหลดภาพ ↓</a>
      </figcaption>
    </figure>
    <button class="iepg-lightbox-nav is-next" type="button" data-lightbox-next aria-label="ภาพถัดไป">›</button>
  </div>

  <?php if (!empty($_['adminUrl'])): ?>
    <div class="iepg-cover-modal" data-cover-modal hidden role="dialog" aria-modal="true" aria-labelledby="iepg-cover-title">
      <section class="iepg-cover-panel">
        <header>
          <div>
            <span>ADMIN · ALBUM COVER</span>
            <h2 id="iepg-cover-title" data-cover-title>เลือกรูปปกโฟลเดอร์</h2>
          </div>
          <button type="button" data-cover-close aria-label="ปิด">×</button>
        </header>
        <p class="iepg-cover-help">เลือกรูปจากภายในโฟลเดอร์งาน รูปที่เลือกจะใช้เป็นภาพปกบนหน้า Gallery</p>
        <div class="iepg-cover-status" data-cover-status role="status"></div>
        <div class="iepg-cover-grid" data-cover-grid></div>
      </section>
    </div>
  <?php endif; ?>

</div>
