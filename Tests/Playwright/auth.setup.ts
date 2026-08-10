import { test as setup, expect } from '@playwright/test'

/*
 * Logs into the TYPO3 backend once and persists the session as Playwright's
 * storageState, reused by every other spec (playwright.config.ts's
 * `use.storageState`) - avoids repeating a full backend login per test.
 *
 * Credentials come from the environment rather than being hardcoded, since
 * they depend on whichever DDEV instance CONTENTFLOW_BASE_URL points at.
 */
const authFile = '.Build/playwright/backend-auth.json'

setup('authenticate against the TYPO3 backend', async ({ page }) => {
  await page.goto('/typo3/')

  await page.getByLabel(/username/i).fill(process.env.CONTENTFLOW_BE_USER ?? 'admin')
  await page.getByLabel(/password/i).fill(process.env.CONTENTFLOW_BE_PASSWORD ?? 'password')
  await page.getByRole('button', { name: /log in/i }).click()

  await expect(page.locator('#typo3-module-menu, .modulemenu')).toBeVisible({ timeout: 15000 })

  await page.context().storageState({ path: authFile })
})
