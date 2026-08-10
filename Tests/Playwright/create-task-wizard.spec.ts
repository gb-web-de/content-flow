import { test, expect } from '@playwright/test'

/*
 * The "+" flow (task/create-wizard.js): pick a record via core's element
 * browser, then the same native wizard shell as the post-save wizard, with
 * priority and date fields shown (TaskWizardProvider's create_from_picker
 * mode). Same caveat as task-wizard.spec.ts: written without a reachable
 * DDEV instance to run it against, treat as a first draft.
 */

const pageId = process.env.CONTENTFLOW_TEST_PAGE_ID ?? '1'

test.describe('the "+" create-task flow', () => {
  test('creates a task for the current page banner button, with priority and dates', async ({ page }) => {
    await page.goto(`/typo3/module/web/contentflow?id=${pageId}`)

    await page.locator('[data-contentflow-action="create-task"][data-contentflow-page]').click()

    const wizard = page.locator('contentflow-task-wizard')
    await expect(wizard).toBeVisible({ timeout: 10000 })

    await wizard.locator('input[type="text"]').first().fill('Playwright-created task')

    const assigneeInput = wizard.locator('contentflow-assignee-picker input')
    await assigneeInput.click()
    await assigneeInput.fill('open')
    await page.getByRole('option', { name: /leave open/i }).click()

    await wizard.locator('select').selectOption({ label: 'High' })
    await wizard.locator('input[type="date"]').first().fill('2026-09-01')

    await page.getByRole('button', { name: /save/i }).click()

    await expect(page.getByText(/task created/i)).toBeVisible()
  })

  test('creates a task for a record picked via the element browser', async ({ page }) => {
    await page.goto(`/typo3/module/web/contentflow?id=${pageId}`)

    await page.locator('[data-contentflow-action="create-task"]:not([data-contentflow-page])').click()

    const browserFrame = page.frameLocator('iframe.t3js-modal-iframe')
    await browserFrame.locator('[data-uid]').first().click()

    const wizard = page.locator('contentflow-task-wizard')
    await expect(wizard).toBeVisible({ timeout: 10000 })

    await wizard.locator('input[type="text"]').first().fill('Task for picked record')
    await page.getByRole('button', { name: /save/i }).click()

    await expect(page.getByText(/task created/i)).toBeVisible()
  })
})
