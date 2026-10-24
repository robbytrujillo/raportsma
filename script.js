/**
 * script.js — Raportku Vanilla JavaScript
 * Berisi fungsi utilitas, UI interactions, dan AJAX helpers
 */

/* ============================================================
   1. DOM Ready Helper
   ============================================================ */
function ready(fn) {
  if (document.readyState !== 'loading') fn();
  else document.addEventListener('DOMContentLoaded', fn);
}

/* ============================================================
   2. Sidebar Toggle (Mobile)
   ============================================================ */
function initSidebar() {
  const sidebar      = document.getElementById('sidebar');
  const overlay      = document.getElementById('sidebarOverlay');
  const hamburger    = document.getElementById('hamburgerBtn');
  const toggleBtn    = document.getElementById('sidebarToggleBtn');
  const appWrapper   = document.querySelector('.app-wrapper');
  const STORAGE_KEY  = 'raportku_sidebar_collapsed';

  if (!sidebar) return;

  // ── Mobile helpers (overlay slide) ──────────────────────────
  function openMobileSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('open');
  }
  function closeMobileSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
  }

  // ── Desktop helpers (collapse icon-only) ─────────────────────
  function isMobile() {
    return window.innerWidth <= 768;
  }

  function collapseSidebar() {
    sidebar.classList.add('collapsed');
    appWrapper?.classList.add('sidebar-collapsed');
    // Tampilkan tooltip keluar
    const logoutTooltip = document.querySelector('#logoutBtn .sidebar-tooltip');
    if (logoutTooltip) logoutTooltip.style.display = 'block';
    localStorage.setItem(STORAGE_KEY, '1');
  }

  function expandSidebar() {
    sidebar.classList.remove('collapsed');
    appWrapper?.classList.remove('sidebar-collapsed');
    // Sembunyikan tooltip keluar
    const logoutTooltip = document.querySelector('#logoutBtn .sidebar-tooltip');
    if (logoutTooltip) logoutTooltip.style.display = 'none';
    localStorage.setItem(STORAGE_KEY, '0');
  }

  function toggleCollapse() {
    if (sidebar.classList.contains('collapsed')) {
      expandSidebar();
    } else {
      collapseSidebar();
    }
  }

  // ── Restore state dari localStorage ─────────────────────────
  if (!isMobile() && localStorage.getItem(STORAGE_KEY) === '1') {
    collapseSidebar();
  }

  // ── Bind tombol toggle (di dalam sidebar header) ─────────────
  toggleBtn?.addEventListener('click', function(e) {
    e.stopPropagation();
    if (isMobile()) {
      // Di mobile, tombol toggle menutup sidebar
      closeMobileSidebar();
    } else {
      toggleCollapse();
    }
  });

  // ── Hamburger: mobile buka sidebar ───────────────────────────
  hamburger?.addEventListener('click', function() {
    if (isMobile()) {
      openMobileSidebar();
    } else {
      // Di desktop, hamburger juga bisa toggle
      toggleCollapse();
    }
  });

  // ── Overlay: tutup sidebar mobile ────────────────────────────
  overlay?.addEventListener('click', closeMobileSidebar);

  // ── Tutup mobile sidebar saat resize ke desktop ───────────────
  window.addEventListener('resize', function() {
    if (!isMobile()) {
      closeMobileSidebar();
      // Pulihkan state desktop dari storage
      if (localStorage.getItem(STORAGE_KEY) === '1') {
        collapseSidebar();
      }
    } else {
      // Di mobile, jangan ada collapsed state visual
      appWrapper?.classList.remove('sidebar-collapsed');
    }
  });
}

/* ============================================================
   3. Modal Helper
   ============================================================ */
const Modal = {
  open(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
  },
  close(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  },
  /** Tutup modal saat klik overlay (di luar modal box) */
  initCloseOnOverlay() {
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
      overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
      });
    });
  }
};

/* ============================================================
   4. Flash / Toast Notification
   ============================================================ */
function showToast(message, type = 'success', duration = 3500) {
  // Hapus toast lama jika ada
  const old = document.getElementById('toastContainer');
  if (old) old.remove();

  const colors = {
    success: 'bg-green-500',
    error:   'bg-red-500',
    warning: 'bg-yellow-500',
    info:    'bg-blue-500',
  };

  const container = document.createElement('div');
  container.id = 'toastContainer';
  container.className = `fixed bottom-6 right-6 z-[999] flex items-center gap-3 px-5 py-3 rounded-xl text-white shadow-xl text-sm font-semibold ${colors[type] || colors.info} transition-all duration-300 translate-y-4 opacity-0`;
  container.innerHTML = `
    <span>${message}</span>
    <button onclick="this.parentElement.remove()" class="ml-2 opacity-70 hover:opacity-100 text-lg leading-none">&times;</button>
  `;
  document.body.appendChild(container);

  // Animasi masuk
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      container.classList.remove('translate-y-4', 'opacity-0');
    });
  });

  // Auto hide
  setTimeout(() => {
    container.classList.add('translate-y-4', 'opacity-0');
    setTimeout(() => container.remove(), 350);
  }, duration);
}

/* ============================================================
   5. Confirm Delete Dialog
   ============================================================ */
function confirmDelete(message, callback) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay open';
  overlay.innerHTML = `
    <div class="modal" style="max-width:400px">
      <div class="modal-header">
        <span class="text-red-600 flex items-center gap-2">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
          Konfirmasi Hapus
        </span>
        <button class="btn-close-modal text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
      </div>
      <div class="modal-body">
        <p class="text-gray-700">${message || 'Apakah Anda yakin ingin menghapus data ini?'}</p>
        <p class="text-sm text-red-500 mt-2">⚠ Tindakan ini tidak dapat dibatalkan.</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-close-modal">Batal</button>
        <button class="btn btn-danger" id="confirmDeleteBtn">Hapus</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  overlay.querySelectorAll('.btn-close-modal').forEach(b => {
    b.addEventListener('click', () => overlay.remove());
  });

  overlay.addEventListener('click', function(e) {
    if (e.target === this) overlay.remove();
  });

  document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    overlay.remove();
    callback();
  });
}

/* ============================================================
   6. AJAX Helper (fetch wrapper)
   ============================================================ */
async function apiRequest(url, method = 'GET', data = null) {
  const opts = {
    method,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  };

  if (data) {
    if (data instanceof FormData) {
      opts.body = data;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(data);
    }
  }

  try {
    const res = await fetch(url, opts);
    const json = await res.json();
    return { ok: res.ok, data: json };
  } catch (err) {
    console.error('API Error:', err);
    return { ok: false, data: { message: 'Terjadi kesalahan jaringan.' } };
  }
}

/* ============================================================
   7. Form Validation Helper
   ============================================================ */
function validateForm(formEl) {
  let valid = true;
  formEl.querySelectorAll('[required]').forEach(input => {
    const group = input.closest('.form-group');
    const err = group?.querySelector('.form-error');
    if (!input.value.trim()) {
      input.classList.add('error');
      if (err) err.classList.remove('hidden');
      valid = false;
    } else {
      input.classList.remove('error');
      if (err) err.classList.add('hidden');
    }
  });
  return valid;
}

/* ============================================================
   8. Nilai Calculation (Live) + Toggle Group per Jenis
   ============================================================ */
function initNilaiCalculation() {
  const form = document.getElementById('formNilai');
  if (!form) return;

  const jenisSelect   = document.getElementById('jenisRaportSelect');
  const groupBulanan  = document.getElementById('groupBulanan');
  const groupSts      = document.getElementById('groupSts');
  const groupSemester = document.getElementById('groupSemester');
  const fieldBulan    = document.getElementById('fieldBulanWrap');

  /**
   * Tampilkan/sembunyikan grup field sesuai jenis raport
   */
  function toggleGroups() {
    const jenis = jenisSelect?.value || 'bulanan';
    if (groupBulanan)  groupBulanan.style.display  = jenis === 'bulanan'  ? '' : 'none';
    if (groupSts)      groupSts.style.display      = jenis === 'sts'      ? '' : 'none';
    if (groupSemester) groupSemester.style.display = jenis === 'semester' ? '' : 'none';
    // Bulan: tampil untuk bulanan dan STS, sembunyi untuk semester
    if (fieldBulan) fieldBulan.style.display = jenis === 'semester' ? 'none' : '';
  }

  /**
   * Hitung nilai akhir sesuai jenis dan update display
   */
  function recalculate() {
    const jenis = jenisSelect?.value || 'bulanan';
    let result = 0;

    if (jenis === 'semester') {
      // SAS saja
      result = parseFloat(form.querySelector('[name="nilai_sas"]')?.value) || 0;

    } else if (jenis === 'sts') {
      // STS saja
      result = parseFloat(form.querySelector('[name="nilai_sts"]')?.value) || 0;

    } else {
      // Bulanan: (rata NH * 70%) + (tugas * 30%)
      const h1  = parseFloat(form.querySelector('[name="nilai_harian_1"]')?.value) || 0;
      const h2  = parseFloat(form.querySelector('[name="nilai_harian_2"]')?.value) || 0;
      const h3  = parseFloat(form.querySelector('[name="nilai_harian_3"]')?.value) || 0;
      const tug = parseFloat(form.querySelector('[name="nilai_tugas"]')?.value) || 0;
      const rataHarian = (h1 + h2 + h3) / 3;
      result = (rataHarian * 0.7) + (tug * 0.3);
    }

    result = Math.round(result * 100) / 100;

    // Update field nilai akhir
    const nilaiAkhirEl = form.querySelector('[name="nilai_akhir"]');
    if (nilaiAkhirEl) nilaiAkhirEl.value = result.toFixed(2);

    // Update predikat display
    const predikatEl = form.querySelector('#predikatDisplay');
    if (predikatEl) {
      let p = 'D';
      if (result >= 90) p = 'A';
      else if (result >= 80) p = 'B';
      else if (result >= 70) p = 'C';
      predikatEl.textContent = p;
      predikatEl.className = `nilai-${p.toLowerCase()} font-bold text-lg`;
    }
  }

  // Bind semua field input
  ['nilai_harian_1','nilai_harian_2','nilai_harian_3','nilai_tugas','nilai_sts','nilai_sas']
    .forEach(f => form.querySelector(`[name="${f}"]`)?.addEventListener('input', recalculate));

  jenisSelect?.addEventListener('change', () => { toggleGroups(); recalculate(); });

  // Init state saat halaman pertama kali load
  toggleGroups();
  recalculate();
}

/* ============================================================
   9. Toggle Input Fields berdasarkan Jenis Raport (legacy stub)
   ============================================================ */
function initJenisRaportToggle() {
  // Sudah ditangani di initNilaiCalculation — fungsi ini dibiarkan kosong
  // agar tidak error jika ada kode lama yang memanggil
}

/* ============================================================
   10. Data Table Search (client-side)
   ============================================================ */
function initTableSearch(inputId, tableId) {
  const input = document.getElementById(inputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;

  input.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    table.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

/* ============================================================
   11. Signature Upload Preview
   ============================================================ */
function initSignaturePreview() {
  const input   = document.getElementById('ttdInput');
  const preview = document.getElementById('ttdPreview');
  if (!input || !preview) return;

  input.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    // Validasi ukuran maks 2MB
    if (file.size > 2 * 1024 * 1024) {
      showToast('File terlalu besar. Maksimal 2MB.', 'error');
      this.value = '';
      return;
    }

    const reader = new FileReader();
    reader.onload = e => {
      preview.innerHTML = `<img src="${e.target.result}" alt="Preview Tanda Tangan">`;
    };
    reader.readAsDataURL(file);
  });
}

/* ============================================================
   12. Active Sidebar Link
   ============================================================ */
function initActiveNav() {
  const currentPage = new URLSearchParams(window.location.search).get('page') || 'dashboard';
  document.querySelectorAll('.sidebar-nav a[data-page]').forEach(link => {
    const pages = link.dataset.page.split(',');
    if (pages.some(p => currentPage.startsWith(p.trim()))) {
      link.classList.add('active');
    }
  });
}

/* ============================================================
   13. Print Raport
   ============================================================ */
function printRaport() {
  window.print();
}

/* ============================================================
   14. Topbar dropdown user menu
   ============================================================ */
function initUserDropdown() {
  const trigger = document.getElementById('userMenuBtn');
  const menu    = document.getElementById('userMenu');
  if (!trigger || !menu) return;

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    menu.classList.toggle('hidden');
  });
  document.addEventListener('click', () => menu.classList.add('hidden'));
}

/* ============================================================
   INIT — jalankan semua saat DOM siap
   ============================================================ */
ready(function () {
  initSidebar();
  Modal.initCloseOnOverlay();
  initActiveNav();
  initNilaiCalculation();
  initJenisRaportToggle();
  initSignaturePreview();
  initUserDropdown();

  // Table search (jika ada)
  initTableSearch('tableSearch', 'dataTable');
});
