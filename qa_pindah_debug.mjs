export default async function run(page, ui) {
  await page.setViewportSize({ width: 1440, height: 900 });
  const snap = await ui.snapshot();
  const uRef = snap.match(/@(e\d+) textbox "Username"/)?.[1];
  const pRef = snap.match(/@(e\d+) textbox "Password"/)?.[1];
  const bRef = snap.match(/@(e\d+) button "Masuk"/)?.[1];
  await ui.fill(uRef, 'superadmin');
  await ui.fill(pRef, 'TestMobile123');
  await ui.click(bRef);
  await page.waitForURL('**/index.php?page=dashboard', { timeout: 60000 }).catch(() => {});
  await page.waitForTimeout(800);

  await page.goto('http://localhost/raportku23102026/index.php?page=siswa');
  await page.waitForTimeout(2000);

  // Check for JS errors on the page
  const errors = [];
  page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });

  // Check modal and button exist
  const check = await page.evaluate(() => {
    const modal  = document.getElementById('modalPindahKelas');
    const btn    = document.getElementById('btnPindahKelas');
    const chkAll = document.getElementById('chkAll');
    const chks   = document.querySelectorAll('.chk-siswa').length;
    return {
      modal:  modal  ? modal.style.display  : 'NOT FOUND',
      btn:    btn    ? btn.style.display    : 'NOT FOUND',
      chkAll: chkAll ? 'found' : 'NOT FOUND',
      chks,
      bukaPindahKelasExists: typeof bukaPindahKelas !== 'undefined',
    };
  });

  // Click chkAll then check btn visibility
  const chkAllEl = await page.$('#chkAll');
  if (chkAllEl) {
    await chkAllEl.click();
    await page.waitForTimeout(400);
  }

  const afterCheck = await page.evaluate(() => {
    const btn   = document.getElementById('btnPindahKelas');
    const modal = document.getElementById('modalPindahKelas');
    return {
      btnDisplay:   btn   ? btn.style.display   : 'NOT FOUND',
      modalDisplay: modal ? modal.style.display : 'NOT FOUND',
    };
  });

  // Try clicking the button
  const btnEl = await page.$('#btnPindahKelas');
  if (btnEl) {
    await btnEl.click();
    await page.waitForTimeout(600);
  }

  const afterClick = await page.evaluate(() => {
    const modal = document.getElementById('modalPindahKelas');
    return { modalDisplay: modal ? modal.style.display : 'NOT FOUND' };
  });

  await page.screenshot({ path: 'qa_pindah_modal.png' });

  return { check, afterCheck, afterClick, jsErrors: errors };
}
