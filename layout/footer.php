<?php
/**
 * layout/footer.php
 * Penutup HTML &mdash; footer bar + script JS
 *
 * Urutan penutup tag di index.php:
 *   include footer.php    &rarr; tutup <footer> (masih dalam .main-content)
 *   echo </div>           &rarr; tutup .main-content
 *   echo </div>           &rarr; tutup .app-wrapper  (dari header.php)
 *   include footer_end.php (atau langsung di index.php)
 *
 * Jadi footer.php HANYA berisi footer + script + </body></html>
 * Tag </div> .app-wrapper ada di index.php
 */
?>

<?php $sekolah_f = sekolah_info(); ?>

<!-- ── APP FOOTER ─────────────────────────────────────────────
     Di dalam .main-content (flex-column) &rarr; margin-top:auto
     mendorongnya ke bawah konten halaman.
     ─────────────────────────────────────────────────────────── -->
<footer class="app-footer">

    <!-- Kiri: logo kecil + nama sekolah -->
    <div class="app-footer-left">
        <?php if (!empty($sekolah_f['logo'])): ?>
        <img src="<?= UPLOAD_URL . e($sekolah_f['logo']) ?>" alt="logo" class="app-footer-logo">
        <?php else: ?>
        <span class="app-footer-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" />
                <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
        </span>
        <?php endif; ?>
        <span class="app-footer-appname"><?= APP_NAME ?></span>
        <?php if (!empty($sekolah_f['nama_sekolah']) && $sekolah_f['nama_sekolah'] !== 'NAMA SEKOLAH'): ?>
        <span class="app-footer-school"><?= e($sekolah_f['nama_sekolah']) ?></span>
        <?php endif; ?>
    </div>

    <!-- Kanan: copyright + credit -->
    <div class="app-footer-right">
        <span class="app-footer-copy">&copy; <?= date('Y') ?> <?= APP_NAME ?></span>
        <span class="app-footer-dot"></span>
        <span class="app-footer-credit">
            Build with
            <a href="https://robbyilham.com" target="_blank" rel="noopener">robby</a> IT Development
        </span>
    </div>

</footer>

<script>
// ── Bersihkan preview-raport-mode jika halaman ini bukan raport.view ───
// Melindungi dari bfcache yang merestorasi class dari halaman sebelumnya.
(function () {
  var page = new URLSearchParams(window.location.search).get('page') || '';
  if (page !== 'raport.view') {
    document.body.classList.remove('preview-raport-mode');
  }
  // Juga tangani bfcache pageshow di halaman non-preview
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      var p = new URLSearchParams(window.location.search).get('page') || '';
      if (p !== 'raport.view') {
        document.body.classList.remove('preview-raport-mode');
      }
    }
  });
})();
</script>