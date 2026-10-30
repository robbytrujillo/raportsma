export default async function run(page, ui) {
  // Login
  await page.goto('http://localhost/raportku23102026/index.php?page=login');
  await page.waitForLoadState('domcontentloaded');
  await page.locator('input[name="username"]').fill('superadmin');
  await page.locator('input[name="password"]').fill('password');
  await page.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(600);

  // Go to manage page
  await page.goto('http://localhost/raportku23102026/index.php?page=raport.manage&ta=5&kelas=0');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(800);

  // 1. Check JS functions and DOM
  const state = await page.evaluate(() => {
    const md  = document.getElementById('modalDelete');
    const me  = document.getElementById('modalEditBulan');
    const activeLinks = Array.from(document.querySelectorAll('.sidebar-nav a.active'))
                             .map(a => a.dataset.page || a.textContent.trim());
    return {
      fn_mgr_hapusPeriode: typeof window.mgr_hapusPeriode,
      fn_mgr_editBulan:    typeof window.mgr_editBulan,
      fn_mgr_closeModals:  typeof window.mgr_closeModals,
      fn_confirmDelete:    typeof window.confirmDelete,
      modalDelete_exists:  !!md,
      modalDelete_class:   md ? md.className : 'NOT FOUND',
      modalEditBulan_exists: !!me,
      modalEditBulan_class:  me ? me.className : 'NOT FOUND',
      activeNavLinks: activeLinks,
      scriptJs_loaded: Array.from(document.scripts).some(s => s.src.includes('script.js')),
      consoleErrors: window.__consoleErrors || [],
    };
  });

  // 2. Try clicking the first delete button and check what happens
  const deleteButtons = await page.locator('button.btn-danger.tooltip-wrap').all();
  let clickResult = 'no delete buttons found';
  if (deleteButtons.length > 0) {
    await deleteButtons[0].click();
    await page.waitForTimeout(300);
    clickResult = await page.evaluate(() => {
      const md = document.getElementById('modalDelete');
      return {
        modalClass: md ? md.className : 'NOT FOUND',
        modalVisible: md ? window.getComputedStyle(md).display : 'N/A',
      };
    });
  }

  // 3. Check rendered HTML of a delete button onclick attr
  const btnOnclick = await page.evaluate(() => {
    const btn = document.querySelector('button.btn-danger.tooltip-wrap');
    return btn ? btn.getAttribute('onclick') : 'no button found';
  });

  return { state, clickResult, btnOnclick };
}
