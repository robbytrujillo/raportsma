export default async function run(page, ui) {
  await page.setViewportSize({ width: 820, height: 1024 });

  // Find admin user
  await page.goto('http://localhost/raportku23102026/index.php?page=login', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(500);

  // Try superadmin credentials
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'admin123');
  await page.click('button[type="submit"]');
  try { await page.waitForFunction(() => !location.href.includes('login'), { timeout: 6000 }); } catch(e){}
  await page.waitForTimeout(800);

  const role = await page.evaluate(() => document.querySelector('.topbar-user span')?.textContent || document.querySelector('[class*="role"]')?.textContent || '');
  const isLoggedIn = !page.url().includes('login');

  if (!isLoggedIn) {
    // Try other credentials
    await page.fill('input[name="username"]', 'superadmin');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    try { await page.waitForFunction(() => !location.href.includes('login'), { timeout: 6000 }); } catch(e){}
    await page.waitForTimeout(800);
  }

  const finalUrl = page.url();
  if (finalUrl.includes('login')) {
    return { error: 'login failed', url: finalUrl };
  }

  // Dashboard admin
  await page.goto('http://localhost/raportku23102026/index.php?page=dashboard', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  await page.screenshot({ path: 'd:\\laragon\\www\\raportku23102026\\qa_admin_dashboard.png', fullPage: false });

  // Users list (data table)
  await page.goto('http://localhost/raportku23102026/index.php?page=users', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await page.screenshot({ path: 'd:\\laragon\\www\\raportku23102026\\qa_admin_users.png', fullPage: false });

  // Nilai input page
  await page.goto('http://localhost/raportku23102026/index.php?page=nilai', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await page.screenshot({ path: 'd:\\laragon\\www\\raportku23102026\\qa_admin_nilai.png', fullPage: false });

  return { url: page.url(), loggedIn: true };
}
