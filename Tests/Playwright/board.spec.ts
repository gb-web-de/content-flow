import { test, expect } from '@playwright/test'
import { openBoard, boardPageId } from './fixtures/board'

test.describe('the Content Flow board module', () => {
  test('renders the backlog columns it owns', async ({ page }) => {
    const board = await openBoard(page)

    for (const column of ['backlog', 'planned', 'done', 'other-workspaces']) {
      await expect(board.locator(`[data-contentflow-column="${column}"]`)).toBeVisible()
    }
  })

  test('offers the search and filter controls', async ({ page }) => {
    const board = await openBoard(page)

    await expect(board.locator('#cf-search-input')).toBeVisible()
    await expect(board.locator('#cf-filter-assignee')).toBeVisible()
    await expect(board.locator('#cf-filter-status')).toBeVisible()
  })

  test('clears a typed search term again', async ({ page }) => {
    const board = await openBoard(page)
    const search = board.locator('#cf-search-input')

    await search.fill('a term that matches nothing')
    await expect(search).toHaveValue('a term that matches nothing')

    await board.locator('#cf-clear-filters').click()

    await expect(search).toHaveValue('')
  })

})

test.describe('the board without a session', () => {
  // Drops the authenticated storageState the chromium project supplies, while
  // keeping its baseURL - a hand-built context would lose that.
  test.use({ storageState: { cookies: [], origins: [] } })

  test('sends a signed-out visitor to the login form instead', async ({ page }) => {
    await page.goto(`/typo3/module/web/contentflow?id=${boardPageId}`)

    await expect(page).toHaveURL(/\/typo3\/login/)
    await expect(page.locator('#t3-login-submit')).toBeVisible()
    await expect(page.locator('[data-contentflow-column]')).toHaveCount(0)
  })
})
