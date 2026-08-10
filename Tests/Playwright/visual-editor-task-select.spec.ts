import { test, expect } from '@playwright/test'
import { openVisualEditor, visualEditorContentFrame } from './fixtures/board'

/*
 * B4 end to end: the task select in EXT:visual_editor's toolbar, its colour
 * legend, and the markers on content elements a task has claimed.
 *
 * This is the part no unit or functional test can reach. The feature turns
 * entirely on three documents finding each other at runtime - the backend
 * chrome that loads the module, EXT:visual_editor's module document that gets
 * the select, and the frontend iframe inside that which gets the markers - and
 * every earlier bug in it was a document mix-up, not a wrong answer from the
 * server. Only a real browser can say whether they meet.
 *
 * Deliberately free of writes: an e2e run that creates a task leaves a card on
 * the board of whatever installation it was pointed at. The one exception is
 * "no task", which the server documents as moving nothing.
 */

test.describe('the Visual Editor task select', () => {
  test('mounts into the Visual Editor toolbar with its fixed choices', async ({ page }) => {
    const moduleFrame = await openVisualEditor(page).goto()
    const select = moduleFrame.locator('.contentflow-ve-task-select select')

    // Inserted before ve-auto-save-toggle, so it sits in the toolbar rather
    // than somewhere in the page body - the difference between "the element
    // exists" and "an editor can find it".
    await expect(moduleFrame.locator('.contentflow-ve-task-select')).toBeVisible()
    await expect(select.locator('option', { hasText: /no task/i })).toHaveCount(1)
    await expect(select.locator('option', { hasText: /create new task/i })).toHaveCount(1)
  })

  test('renders one legend swatch per task on the page', async ({ page }) => {
    const moduleFrame = await openVisualEditor(page).goto()
    const legend = moduleFrame.locator('.contentflow-ve-legend')
    const swatches = legend.locator('.contentflow-ve-legend-swatch')

    // The legend mirrors the select: every task offered there is a colour here,
    // which is the property that makes a coloured dot in the page mean
    // anything. Both counts come from the same endpoint, so a mismatch is a
    // rendering bug rather than a data one.
    const taskOptions = await moduleFrame
      .locator('.contentflow-ve-task-select select option[data-task]')
      .count()
    // minus "No task" and "+ Create new task", which are not tasks
    const expected = Math.max(taskOptions - 2, 0)

    await expect(swatches).toHaveCount(expected)
    if (expected > 0) {
      await expect(legend).toHaveAttribute('title', /tasks on this page/i)
    }
  })

  test('marks every claimed content element consistently, or none at all', async ({ page }) => {
    const moduleFrame = await openVisualEditor(page).goto()
    const contentFrame = visualEditorContentFrame(page)

    // Wait for the frontend document to have rendered at least one element,
    // otherwise an empty page passes this by saying nothing.
    await expect(contentFrame.locator('ve-content-element').first()).toBeAttached({ timeout: 20000 })

    // How many task options does the toolbar offer? If there are tasks on this
    // page the markers must actually appear - zero markers while the select
    // lists tasks is the document-mix-up regression the whole suite is here
    // to catch.
    const taskOptions = await moduleFrame
      .locator('.contentflow-ve-task-select select option[data-task]')
      .count()
    const taskCount = Math.max(taskOptions - 2, 0)

    /*
     * The invariant that holds whether or not this page has tasks: a bubble
     * never appears without the outline and the hue that explain it. Each of
     * those three was added by a different pass over the same element, and a
     * marker that carries only some of them is the failure mode worth catching
     * - it renders, so it looks fine, and it tells the editor nothing.
     */
    const { inconsistent, marked } = await contentFrame.locator('ve-content-element').evaluateAll((elements) => {
      let inconsistent = 0
      let marked = 0

      for (const element of elements) {
        const hasBubble = element.querySelector(':scope > .contentflow-task-bubble') !== null
        const hasOutline = element.classList.contains('contentflow-task-claimed')
        const hasHue = element.style.getPropertyValue('--contentflow-task-hue') !== ''

        if (hasBubble !== hasOutline || hasBubble !== hasHue) {
          inconsistent++
        }
        if (hasBubble || hasOutline || hasHue) {
          marked++
        }
      }

      return { inconsistent, marked }
    })

    expect(inconsistent).toBe(0)

    // If the toolbar lists tasks for this page, at least one content element
    // must carry a marker. Zero markers while tasks exist means the frontend
    // document never received the claiming data - the silent regression.
    if (taskCount > 0) {
      expect(marked).toBeGreaterThan(0)
    }
  })

  test('names the task a marker belongs to, without a click', async ({ page }) => {
    await openVisualEditor(page).goto()
    const contentFrame = visualEditorContentFrame(page)
    await expect(contentFrame.locator('ve-content-element').first()).toBeAttached({ timeout: 20000 })

    const bubble = contentFrame.locator('.contentflow-task-bubble').first()
    if (await bubble.count() === 0) {
      test.skip(true, 'no task claims anything on this page - nothing to name')
    }

    // The tooltip is the whole point of the bubble: hovering has to answer
    // "whose is this?" rather than just confirming something is there.
    await expect(bubble).toHaveAttribute('data-contentflow-label', /\S/)
    await expect(bubble).toHaveAttribute('aria-label', /\S/)
  })

  test('lets a declaration be taken back', async ({ page }) => {
    const moduleFrame = await openVisualEditor(page).goto()
    const select = moduleFrame.locator('.contentflow-ve-task-select select')

    // The server treats this as "drop the choice" and moves nothing, so it is
    // the one write safe to make against a real installation. It is also the
    // escape hatch an editor needs: a choice that cannot be unmade keeps
    // routing saves long after they stopped thinking about it.
    await select.selectOption('__none__')

    await expect(select).toBeEnabled({ timeout: 10000 })
    await expect(select).toHaveValue('__none__')
  })
})
