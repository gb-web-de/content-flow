import { test, expect } from '@playwright/test'

/*
 * Post-save routing wizard (wizard.js -> wizard/task-wizard.js ->
 * <typo3-backend-wizard>, driven by Classes/Wizard/TaskWizardProvider.php).
 *
 * Preconditions this spec assumes about the DDEV instance under test:
 *  - an open workspace exists with an in-progress page task for the "About
 *    us" page (or whichever CONTENTFLOW_TEST_PAGE_ID points at), so editing a
 *    content element on that page triggers TaskAutoCreationService's
 *    route_member pending payload;
 *  - the logged-in backend user (see auth.setup.ts) is switched into that
 *    workspace.
 * Adjust the navigation/edit steps below to match actual fixture data once
 * this spec is run against a real instance - it has not been run yet (no
 * DDEV instance was reachable while writing it, see the implementation
 * notes), so treat the selectors as a first draft to verify, not a
 * guaranteed-passing baseline.
 */

const pageId = process.env.CONTENTFLOW_TEST_PAGE_ID ?? '2'

test.describe('post-save routing wizard', () => {
  test('routes an edit to the existing page task', async ({ page }) => {
    await page.goto(`/typo3/module/web/layout?id=${pageId}`)

    // Edit any existing content element to trigger the auto-creation hook.
    await page.locator('[data-record-uid]').first().click()
    await page.getByRole('button', { name: /save/i }).click()

    const wizard = page.locator('contentflow-task-wizard')
    await expect(wizard).toBeVisible({ timeout: 10000 })

    await page.getByLabel(/add it to the existing page task/i).check()
    await page.getByRole('button', { name: /save/i }).click()

    await expect(page.getByText(/reload|added to the existing task/i)).toBeVisible()
  })

  test('splits an edit into a separate task, with title, assignee and stage', async ({ page }) => {
    await page.goto(`/typo3/module/web/layout?id=${pageId}`)

    await page.locator('[data-record-uid]').first().click()
    await page.getByRole('button', { name: /save/i }).click()

    const wizard = page.locator('contentflow-task-wizard')
    await expect(wizard).toBeVisible({ timeout: 10000 })

    await page.getByLabel(/create a separate task/i).check()
    await page.getByRole('button', { name: /next/i }).click()

    // Task details step: title is prefilled, only the assignee picker needs
    // interaction here to exercise its search/filter behaviour.
    const assigneeInput = wizard.locator('contentflow-assignee-picker input')
    await assigneeInput.click()
    await assigneeInput.fill('a')
    await expect(wizard.locator('contentflow-assignee-options li').first()).toBeVisible()
    await wizard.locator('contentflow-assignee-options li').first().click()

    await page.getByRole('button', { name: /next/i }).click()

    // Stage choice step.
    await page.getByLabel(/move directly to review/i).check()
    await page.getByRole('button', { name: /next/i }).click()

    // Confirm step: summary table, then Save.
    await expect(page.getByText(/title/i)).toBeVisible()
    await page.getByRole('button', { name: /save/i }).click()

    await expect(page.getByText(/separate task was created/i)).toBeVisible()
  })
})
