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

  /*
   * page.frame() is a synchronous look at the frames attached at that instant,
   * and the backend chrome builds its content iframe after its own document
   * has loaded - so asking straight after goto() returns null often enough to
   * fail a random spec per run. Wait for the element, then take its frame.
   */
  const iframe = await page.waitForSelector('iframe[name="list_frame"]', { state: 'attached' })
  const frame = await iframe.contentFrame()
  if (frame === null) {
    throw new Error('The backend content iframe (list_frame) never appeared.')
  }

  await expect(frame.locator('[data-contentflow-column]').first()).toBeVisible()

  return frame
}

/*
 * EXT:visual_editor's module, one document deeper than the board: its own
 * toolbar and language columns render inside the backend content iframe, and
 * the rendered frontend page sits in another iframe inside that. The task
 * select lives in the first, its markers in the second - see
 * visual-editor-task-select.js's docblock for why that split exists.
 *
 * frameLocator rather than the frame handle openBoard() takes: nothing here
 * needs a Frame object, and chaining is what reaches the inner document.
 */
export function openVisualEditor(page: Page, pageId: string = boardPageId) {
  return {
    goto: async () => {
      await page.goto(`/typo3/module/web/edit?id=${pageId}`)
      const moduleFrame = page.frameLocator('iframe#typo3-contentIframe')
      // The select is inserted by wizard.js from the chrome document once the
      // module document has loaded, so waiting on the module's own toolbar
      // first would still race the insertion.
      await expect(moduleFrame.locator('.contentflow-ve-task-select select')).toBeVisible({ timeout: 20000 })

      return moduleFrame
    },
  }
}

export function visualEditorContentFrame(page: Page) {
  return page.frameLocator('iframe#typo3-contentIframe').frameLocator('iframe.visual-editor-iframe')
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
