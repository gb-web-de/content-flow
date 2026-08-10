import { type Frame, type Page, expect } from '@playwright/test'

/*
 * The board is a backend module, so it renders inside the backend's content
 * iframe (name `list_frame`) while anything TYPO3.Modal opens - the whole task
 * wizard - lives in the outer chrome document. Every spec here needs both, and
 * mixing them up is why a locator silently matches nothing.
 */

export const boardPageId = process.env.CONTENTFLOW_TEST_PAGE_ID ?? '1'

export async function openBoard(page: Page, pageId: string = boardPageId): Promise<Frame> {
  await page.goto(`/typo3/module/web/contentflow?id=${pageId}`)

  const frame = page.frame({ name: 'list_frame' })
  if (frame === null) {
    throw new Error('The backend content iframe (list_frame) never appeared.')
  }

  await expect(frame.locator('[data-contentflow-column]').first()).toBeVisible()

  return frame
}

/*
 * The <typo3-backend-modal> host itself never becomes visible - it wraps the
 * <dialog> that does - so the dialog is what a spec waits on and queries.
 */
export function taskWizardModal(page: Page) {
  return page.locator('typo3-backend-modal').getByRole('dialog')
}

export async function openTaskWizard(page: Page, frame: Frame) {
  const trigger = frame.locator('[data-contentflow-action="create-task"]').first()
  const modal = taskWizardModal(page)

  /*
   * The button is in the markup before board.js has bound its handler, so a
   * click can land on a dead element and be lost silently. There is no
   * readiness flag to wait for, hence retrying the click until the modal is
   * actually up - re-clicking is safe precisely because a click that did
   * nothing is what put us back here.
   */
  await expect(async () => {
    if (!(await modal.isVisible())) {
      await trigger.click()
    }
    await expect(modal).toBeVisible({ timeout: 2000 })
  }).toPass({ timeout: 20000 })

  return modal
}
