import { describe, it, expect, beforeEach, vi } from 'vitest'

/*
 * Flushes the microtask queue: each await inside the click handler's promise
 * chain (confirmModal -> AjaxRequest#post -> response.resolve) is its own
 * tick, and a fixed number of `await Promise.resolve()` calls is exactly the
 * kind of thing that keeps working until someone adds one more await.
 */
function flush() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}
import { registerPublishButtons } from '../../Resources/Public/JavaScript/task/publish.js'
import { recorded, behaviour, reset as resetAjax } from './doubles/ajax-request.js'
import { notifications, reset as resetNotifications } from './doubles/notification.js'
import { lastModal, press, reset as resetModals } from './doubles/modal.js'

/*
 * The confirm dialog in front of publishing is the only thing standing
 * between a click and an irreversible, workspace-wide "go live" - so what
 * these tests guard is that Cancel actually cancels and OK actually is the
 * one thing that triggers the request, not just that a request eventually
 * happens. See modal-confirm.js for why that was not previously true:
 * Modal.confirm() alone never resolves, so the button that says "Continue?"
 * never got answered - clicking it just left the dialog sitting there.
 */
describe('publishing a task', () => {
  let button

  beforeEach(() => {
    resetAjax()
    resetNotifications()
    resetModals()
    document.body.innerHTML = ''
    button = document.createElement('button')
    button.className = 'contentflow-action-publish'
    button.dataset.taskUid = '9'
    button.dataset.taskTitle = 'Nordheidehalle (Buchholz)'
    document.body.appendChild(button)

    global.TYPO3 = {
      settings: {
        ContentFlow: { canPublish: true },
        ajaxUrls: { contentflow_task_publish: '/publish' },
      },
    }
    vi.stubGlobal('location', { reload: vi.fn() })

    registerPublishButtons(null)
  })

  it('does nothing until the confirm dialog is answered', async () => {
    button.click()
    await Promise.resolve()

    expect(recorded.url).toBeNull()
    expect(lastModal().title).toBe('Publish "Nordheidehalle (Buchholz)"')
  })

  it('sends nothing and leaves the task alone when Cancel is pressed', async () => {
    button.click()
    await Promise.resolve()

    await press('cancel')

    expect(recorded.url).toBeNull()
    expect(lastModal().instance.hidden).toBe(true)
  })

  it('publishes only once OK is pressed', async () => {
    behaviour.resolved = { success: true, closed: true }

    button.click()
    await Promise.resolve()
    await press('ok')
    await flush()

    expect(recorded.url).toBe('/publish')
    expect(recorded.body).toEqual({ task: 9 })
    expect(lastModal().instance.hidden).toBe(true)
    expect(notifications[0].severity).toBe('success')
    expect(window.location.reload).toHaveBeenCalled()
  })

  it('reports a server error without pretending the task closed', async () => {
    behaviour.resolved = { success: false, message: 'This task belongs to another workspace.' }

    button.click()
    await Promise.resolve()
    await press('ok')
    await flush()

    expect(notifications[0].severity).toBe('error')
    expect(notifications[0].message).toBe('This task belongs to another workspace.')
    expect(window.location.reload).not.toHaveBeenCalled()
  })
})
