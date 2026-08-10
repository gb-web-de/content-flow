import { test, expect } from '@playwright/test'
import { openBoard, openTaskWizard } from './fixtures/board'

/*
 * The "+ New task" flow: the board's own button opens a chooser with the four
 * entry points, and each one hands off to TaskWizardProvider's matching mode
 * rendered through core's native <typo3-backend-wizard>.
 */

const planANewPage = /plan a new page/i

/*
 * The wizard's fields carry a visible <label> that is not associated with its
 * control (no for/id pair, no aria-label), so getByLabel() resolves to nothing
 * and they have to be addressed structurally here. Worth fixing in the markup -
 * that association is what a screen reader needs too.
 */

test.describe('the "New task" wizard', () => {
  test('offers all four ways to start a task', async ({ page }) => {
    const board = await openBoard(page)
    const modal = await openTaskWizard(page, board)

    await expect(modal.getByRole('button', { name: planANewPage })).toBeVisible()
    await expect(modal.getByRole('button', { name: /pick an existing page/i })).toBeVisible()
    await expect(modal.getByRole('button', { name: /pick any record/i })).toBeVisible()
    await expect(modal.getByRole('button', { name: /record wizard/i })).toBeVisible()
  })

  test('asks for the task details once an entry point is chosen', async ({ page }) => {
    const board = await openBoard(page)
    const modal = await openTaskWizard(page, board)
    await modal.getByRole('button', { name: planANewPage }).click()

    // The custom element host has no layout box of its own, so visibility is
    // asserted on the controls it renders rather than on the host.
    const wizard = page.locator('contentflow-task-wizard')
    await expect(wizard.locator('input[type="text"]').first()).toBeVisible()
    await expect(wizard.locator('select')).toBeVisible()
    await expect(wizard.locator('input[type="date"]')).toHaveCount(2)
  })

  test('reaches the confirmation step with a title filled in', async ({ page }) => {
    const board = await openBoard(page)
    const modal = await openTaskWizard(page, board)
    await modal.getByRole('button', { name: planANewPage }).click()

    const wizard = page.locator('contentflow-task-wizard')
    await wizard.locator('input[type="text"]').first().fill('A task Playwright planned')
    await wizard.getByRole('button', { name: /next/i }).click()

    await expect(wizard.getByRole('button', { name: /save/i })).toBeVisible()
  })

  test('refuses to advance while the title is still empty', async ({ page }) => {
    const board = await openBoard(page)
    const modal = await openTaskWizard(page, board)
    await modal.getByRole('button', { name: planANewPage }).click()

    const wizard = page.locator('contentflow-task-wizard')
    const next = wizard.getByRole('button', { name: /next/i })

    // Core's wizard marks the step invalid with a class rather than the
    // disabled attribute, so toBeDisabled() would pass on a broken build.
    await expect(next).toHaveClass(/disabled/)
    await expect(wizard.getByRole('button', { name: /save/i })).toHaveCount(0)

    await wizard.locator('input[type="text"]').first().fill('Now it has one')

    await expect(next).not.toHaveClass(/disabled/)
  })

  test('creates nothing when the chooser is cancelled', async ({ page }) => {
    const board = await openBoard(page)
    const modal = await openTaskWizard(page, board)

    await modal.getByRole('button', { name: /^cancel$/i }).click()

    // The modal animates out before it is removed, so waiting for it to be
    // gone from the layout is the stable signal - toHaveCount(0) races it.
    await expect(modal).toBeHidden()
    await expect(page.locator('contentflow-task-wizard')).toHaveCount(0)
  })
})
