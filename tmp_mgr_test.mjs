export default async function run(page, ui) {
  // Login
  await page.goto('http://localhost/raportku23102026/index.php?page=login');
  await page.waitForLoadState('domcontentloaded');
  await page.locator('input[name="username"]').fill('superadmin');
  await page.locator('input[name="password"]').fill('password');
  await page.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(800);

  const afterLogin = await page.title();

  // Navigate to manage page
  await page.goto('http://localhost/raportku23102026/index.php?page=raport.manage&ta=5&kelas=0');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(600);

  const manageTitle = await page.title();

  // Check which JS functions are available and whether modals exist
  const jsCheck = await page.evaluate(() => {
    const md = document.getElementById('modalDelete');
    const me = document.getElementById('modalEditBulan');
    return {
      mgr_hapusPeriode: typeof window.mgr_hapusPeriode,
      mgr_editBulan:    typeof window.mgr_editBulan,
      mgr_closeModals:  typeof window.mgr_closeModals,
      confirmDelete:    typeof window.confirmDelete,
      modalDelete_found:     !!md,
      modalDelete_display:   md ? md.style.display : 'NOT FOUND',
      modalEditBulan_found:  !!me,
      modalEditBulan_display: me ? me.style.display : 'NOT FOUND',
      scriptTags: Array.from(document.scripts).map(s => s.src || 'inline').join(' | '),
      bodyHTML_end: document.body.innerHTML.slice(-800),
    };
  });

  return { afterLogin, manageTitle, jsCheck };
}
