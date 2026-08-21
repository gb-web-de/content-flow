import { test as setup, expect } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { dirname } from 'node:path'
import { authFile } from '../../playwright.config'

/*
 * Logs into the TYPO3 backend once and persists the session as Playwright's
 * storageState, reused by every other spec (playwright.config.ts's
 * `use.storageState`) - avoids repeating a full backend login per test.
 *
 * Credentials come from the environment rather than being hardcoded, since
 * they depend on whichever DDEV instance EDITORIALFLOW_BASE_URL points at.
 */
setup('authenticate against the TYPO3 backend', async ({ page }) => {
  await page.goto('/typo3/')

  // Defaults match the credentials the README documents for the dev instance,
  // so a run that forgets the environment fails on something real rather than
  // on a password nobody configured.
  await page.getByLabel(/username/i).fill(process.env.EDITORIALFLOW_BE_USER ?? 'admin')
  await page.getByLabel(/password/i).fill(process.env.EDITORIALFLOW_BE_PASSWORD ?? 'Password.1')
  await page.getByRole('button', { name: /^login$/i }).click()

  await expect(page.locator('#typo3-module-menu, .modulemenu')).toBeVisible({ timeout: 15000 })

  mkdirSync(dirname(authFile), { recursive: true })
  await page.context().storageState({ path: authFile })
})
