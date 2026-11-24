<?php
/**
 * pages/chatbot/widget.php
 * Widget floating Raporku.Bot — disertakan di layout/footer.php
 * Hanya muncul jika pengguna sudah login.
 */
if (!function_exists('current_user')) return;
$_cbot_user = current_user();
if (!$_cbot_user) return;
$_cbot_nama  = e($_cbot_user['nama_lengkap']);
$_cbot_role  = $_cbot_user['role'];
$_cbot_label = ['siswa' => 'Murid', 'guru' => 'Guru', 'wali_kelas' => 'Wali Kelas',
                 'kepala_sekolah' => 'Kepala Sekolah', 'admin' => 'Admin',
                 'super_admin' => 'Super Admin'][$_cbot_role] ?? ucfirst($_cbot_role);
$_cbot_api   = BASE_URL . '/?page=chatbot.api';
?>

<!-- ════════════════════════════════════════════════════════════
     Raporku.Bot — Floating Widget
════════════════════════════════════════════════════════════ -->

<!-- Tombol floating -->
<button id="cbotBtn" onclick="cbotBuka()" aria-label="Tanya Raporku.Bot"
    style="position:fixed;bottom:72px;right:24px;z-index:1200;
           display:flex;align-items:center;gap:8px;
           background:var(--primary,#f97316);color:#fff;
           border:none;border-radius:30px;padding:11px 20px 11px 14px;
           font-family:'Poppins',sans-serif;font-size:.8rem;font-weight:600;
           box-shadow:0 4px 20px rgba(0,0,0,.22);cursor:pointer;
           transition:transform .18s,box-shadow .18s">
    <!-- ikon chat gelembung -->
    <span style="display:flex;align-items:center;justify-content:center;
                 width:28px;height:28px;background:rgba(255,255,255,.22);
                 border-radius:50%;flex-shrink:0">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"
             viewBox="0 0 24 24">
            <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
        </svg>
    </span>
    Tanya Raporku.Bot
</button>

<!-- Indikator jumlah pesan belum dibaca -->
<span id="cbotBadge" style="display:none;position:fixed;bottom:50px;right:24px;
    z-index:1201;background:#ef4444;color:#fff;border-radius:50%;
    width:18px;height:18px;font-size:.65rem;font-weight:700;
    display:none;align-items:center;justify-content:center;
    font-family:'Poppins',sans-serif;pointer-events:none">0</span>

<!-- ── Modal Chat ──────────────────────────────────────────── -->
<div id="cbotModal" style="display:none;position:fixed;bottom:128px;right:24px;
    z-index:1200;width:360px;max-width:calc(100vw - 32px);
    max-height:calc(100vh - 100px);
    background:#fff;border-radius:20px;
    box-shadow:0 12px 48px rgba(0,0,0,.22);
    display:none;flex-direction:column;overflow:hidden;
    font-family:'Poppins',sans-serif">

    <!-- Header modal -->
    <div style="background:var(--primary,#f97316);padding:14px 16px;
        display:flex;align-items:center;gap:10px;flex-shrink:0">
        <!-- Avatar bot -->
        <div style="width:36px;height:36px;border-radius:50%;
            background:rgba(255,255,255,.25);display:flex;align-items:center;
            justify-content:center;flex-shrink:0">
            <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="2"
                 viewBox="0 0 24 24">
                <circle cx="12" cy="8" r="4"/>
                <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
            </svg>
        </div>
        <div style="flex:1;min-width:0">
            <div style="color:#fff;font-weight:700;font-size:.85rem;line-height:1.2">
                Raporku.Bot
            </div>
            <div style="color:rgba(255,255,255,.8);font-size:.68rem" id="cbotStatus">
                Online &bull; Siap membantu
            </div>
        </div>
        <button onclick="cbotTutup()" style="background:none;border:none;cursor:pointer;
            color:rgba(255,255,255,.8);font-size:1.3rem;line-height:1;padding:2px 4px"
            title="Tutup">&times;</button>
    </div>

    <!-- Area pesan -->
    <div id="cbotMessages" style="flex:1;overflow-y:auto;padding:12px 14px;
        display:flex;flex-direction:column;gap:10px;
        background:#f9fafb;min-height:260px;max-height:400px"></div>

    <!-- Input area -->
    <div style="padding:10px 12px;border-top:1px solid #f3f4f6;
        background:#fff;flex-shrink:0">
        <div style="display:flex;gap:8px;align-items:flex-end">
            <textarea id="cbotInput" rows="1" placeholder="Ketik pertanyaan Anda…"
                onkeydown="cbotKeydown(event)"
                oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,96)+'px'"
                style="flex:1;border:1.5px solid #e5e7eb;border-radius:14px;
                    padding:9px 13px;font-family:'Poppins',sans-serif;font-size:.8rem;
                    resize:none;outline:none;line-height:1.5;color:#374151;
                    transition:border-color .15s;overflow:hidden"></textarea>
            <button id="cbotSendBtn" onclick="cbotKirim()"
                style="background:var(--primary,#f97316);color:#fff;border:none;
                    border-radius:30px;padding:9px 16px;cursor:pointer;
                    font-family:'Poppins',sans-serif;font-size:.78rem;font-weight:600;
                    white-space:nowrap;flex-shrink:0;transition:opacity .15s">
                Kirim
            </button>
        </div>
        <div style="font-size:.62rem;color:#9ca3af;text-align:center;margin-top:6px">
            Jawaban AI bersifat informatif, bukan pengganti konsultasi langsung
        </div>
    </div>
</div>

<!-- Google Fonts Poppins (jika belum dimuat) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">

<style>
#cbotBtn:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(0,0,0,.28); }
#cbotInput:focus { border-color:var(--primary,#f97316); }
/* Bubble pesan */
.cbot-bubble {
    max-width:88%;font-size:.8rem;line-height:1.6;
    padding:9px 13px;border-radius:16px;word-break:break-word;
}
.cbot-bubble.user {
    background:var(--primary,#f97316);color:#fff;
    align-self:flex-end;border-bottom-right-radius:4px;
}
.cbot-bubble.bot {
    background:#fff;color:#374151;
    align-self:flex-start;border-bottom-left-radius:4px;
    border:1px solid #e5e7eb;
    box-shadow:0 1px 4px rgba(0,0,0,.06);
}
.cbot-bubble.bot strong { color:var(--primary,#f97316); }
/* Typing indicator */
.cbot-typing { display:flex;gap:4px;align-items:center;padding:10px 14px; }
.cbot-typing span {
    width:7px;height:7px;border-radius:50%;background:#9ca3af;
    animation:cbotBounce .9s infinite;
}
.cbot-typing span:nth-child(2) { animation-delay:.15s; }
.cbot-typing span:nth-child(3) { animation-delay:.3s; }
@keyframes cbotBounce {
    0%,60%,100% { transform:translateY(0); }
    30% { transform:translateY(-6px); }
}
/* Scrollbar tipis di area pesan */
#cbotMessages::-webkit-scrollbar { width:4px; }
#cbotMessages::-webkit-scrollbar-thumb { background:#e5e7eb;border-radius:4px; }
</style>

<script>
(function() {
'use strict';

/* ── State ─────────────────────────────────────────────── */
const CBOT_API    = <?= json_encode($_cbot_api) ?>;
const CBOT_NAMA   = <?= json_encode($_cbot_nama) ?>;
const CBOT_LABEL  = <?= json_encode($_cbot_label) ?>;
const IDLE_MS     = 90000;    // 90 detik tidak ada aktifitas → ucap salam perpisahan

let cbotHistory   = [];       // [{role,content}] dikirim ke API
let cbotOpen      = false;
let cbotTyping    = false;
let cbotIdleTimer = null;
let cbotGreeted   = false;

/* ── Helpers ───────────────────────────────────────────── */
function $(id) { return document.getElementById(id); }

function cbotResetIdle() {
    clearTimeout(cbotIdleTimer);
    cbotIdleTimer = setTimeout(cbotIdleClose, IDLE_MS);
}

function cbotIdleClose() {
    if (!cbotOpen) return;
    cbotTambahBubble('bot',
        'Terima kasih sudah bertanya kepada kami. ' +
        '<em>Jazaakumullah khairan.</em> 🤲\nSemoga bermanfaat!');
    setTimeout(() => {
        cbotTutup();
        cbotHistory   = [];
        cbotGreeted   = false;
    }, 3500);
}

/* ── Render bubble ─────────────────────────────────────── */
function cbotTambahBubble(role, text) {
    const wrap = $('cbotMessages');
    const div  = document.createElement('div');
    div.className = 'cbot-bubble ' + role;

    // Konversi baris baru & bold sederhana **text**
    let html = text
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
        .replace(/\n/g,'<br>');
    div.innerHTML = html;
    wrap.appendChild(div);
    wrap.scrollTop = wrap.scrollHeight;
    return div;
}

function cbotTampilTyping() {
    const wrap = $('cbotMessages');
    const div  = document.createElement('div');
    div.className = 'cbot-bubble bot cbot-typing';
    div.id = 'cbotTypingDot';
    div.innerHTML = '<span></span><span></span><span></span>';
    wrap.appendChild(div);
    wrap.scrollTop = wrap.scrollHeight;
}
function cbotHapusTyping() {
    const el = $('cbotTypingDot');
    if (el) el.remove();
}

/* ── Pesan selamat datang ──────────────────────────────── */
function cbotSapaan() {
    const wrap = $('cbotMessages');
    wrap.innerHTML = '';
    cbotHistory    = [];

    const nama   = CBOT_NAMA || 'Pengguna';
    const label  = CBOT_LABEL;

    const salam =
        'Assalamualaikum Warahmatullahi Wabarakatuh 🌙\n\n' +
        'Halo, **' + nama + '** (' + label + ')! Selamat datang di **Raporku.Bot**.\n\n' +
        'Silakan bertanya seputar:\n' +
        '• 📊 Nilai dan rapor murid\n' +
        '• 🏅 Predikat & peringkat kelas\n' +
        '• 📚 Saran cara belajar & peningkatan nilai\n' +
        '• ❓ Cara menggunakan fitur aplikasi rapor ini\n\n' +
        '_Untuk pertanyaan di luar topik tersebut, saya akan mengarahkan Anda ke pihak yang lebih berwenang._';

    cbotTambahBubble('bot', salam);
    cbotGreeted = true;
}

/* ── Buka / Tutup ──────────────────────────────────────── */
window.cbotBuka = function() {
    const modal = $('cbotModal');
    const btn   = $('cbotBtn');
    if (!modal) return;
    modal.style.display = 'flex';
    cbotOpen = true;
    btn.style.display = 'none';

    if (!cbotGreeted) cbotSapaan();
    cbotResetIdle();
    setTimeout(() => { const inp = $('cbotInput'); if(inp) inp.focus(); }, 80);
};

window.cbotTutup = function() {
    const modal = $('cbotModal');
    const btn   = $('cbotBtn');
    if (!modal) return;
    modal.style.display = 'none';
    cbotOpen = false;
    btn.style.display = '';
    clearTimeout(cbotIdleTimer);
};

/* ── Kirim pesan ───────────────────────────────────────── */
window.cbotKirim = async function() {
    if (cbotTyping) return;
    const inp  = $('cbotInput');
    const text = inp.value.trim();
    if (!text) return;

    inp.value = '';
    inp.style.height = 'auto';
    cbotTambahBubble('user', text);
    cbotHistory.push({ role: 'user', content: text });

    cbotTyping = true;
    $('cbotSendBtn').disabled = true;
    $('cbotStatus').textContent = 'Sedang mengetik…';
    cbotTampilTyping();
    cbotResetIdle();

    try {
        const res = await fetch(CBOT_API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                message: text,
                history: cbotHistory.slice(0, -1),
            }),
        });

        // Baca raw text dulu untuk debug jika bukan JSON
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch(e) {
            cbotHapusTyping();
            cbotTambahBubble('bot',
                '⚠️ Server mengembalikan respons tidak valid (HTTP ' + res.status + ').\n\n' +
                'Detail: ' + rawText.substring(0, 200));
            cbotTyping = false;
            $('cbotSendBtn').disabled = false;
            $('cbotStatus').textContent = 'Online \u2022 Siap membantu';
            return;
        }
        cbotHapusTyping();

        if (data.ok) {
            cbotTambahBubble('bot', data.reply);
            cbotHistory.push({ role: 'assistant', content: data.reply });
        } else if (data.msg === 'session_limit') {
            cbotTambahBubble('bot',
                'Sesi obrolan telah mencapai batas maksimum. ' +
                'Silakan muat ulang halaman untuk memulai sesi baru.');
        } else {
            cbotTambahBubble('bot',
                '⚠️ ' + (data.msg || 'Terjadi kendala tidak diketahui'));
        }
    } catch (err) {
        cbotHapusTyping();
        cbotTambahBubble('bot',
            '⚠️ Fetch gagal: ' + err.message);
    }

    cbotTyping = false;
    $('cbotSendBtn').disabled = false;
    $('cbotStatus').textContent = 'Online \u2022 Siap membantu';
    cbotResetIdle();
};

/* ── Enter kirim, Shift+Enter baris baru ───────────────── */
window.cbotKeydown = function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        cbotKirim();
    }
};

/* ── Tutup jika klik di luar modal ────────────────────── */
document.addEventListener('click', function(e) {
    const modal = $('cbotModal');
    const btn   = $('cbotBtn');
    if (!modal || !cbotOpen) return;
    if (!modal.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
        cbotTutup();
    }
});

})();
</script>
