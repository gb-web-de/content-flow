import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerMemberActions } from '../../Resources/Public/JavaScript/task/member-actions.js'
import { recorded, behaviour, reset as resetAjax } from './doubles/ajax-request.js'
import { notifications, reset as resetNotifications } from './doubles/notification.js'
import { lastModal, press, reset as resetModals } from './doubles/modal.js'

function flush() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

/*
 * Discarding a member's pending changes is the one action in the ticket that
 * throws work away irreversibly - the confirm dialog in front of it is doing
 * real work, not decoration. Same failure mode as publish.js: Modal.confirm()
 * alone never resolves (see modal-confirm.js), so before that fix, both
 * "Discard" and Cancel just left the dialog sitting there unable to do
 * anything, and this covers that it now actually distinguishes the two.
 */
describe('discarding a member\'s pending changes', () => {
  let discardButton

  beforeEach(() => {
    resetAjax()
    resetNotifications()
    resetModals()
    document.body.innerHTML = ''
    discardButton = document.createElement('button')
    discardButton.className = 'editorialflow-member-discard'
    discardButton.dataset.table = 'tt_content'
    discardButton.dataset.uid = '12'
    discardButton.dataset.title = 'Intro'
    document.body.appendChild(discardButton)

    global.TYPO3 = { settings: { ajaxUrls: { editorialflow_task_discard_member: '/discard' } } }
    vi.stubGlobal('location', { reload: vi.fn() })

    registerMemberActions()
  })

  it('does nothing until the confirm dialog is answered', async () => {
    discardButton.click()
    await Promise.resolve()

    expect(recorded.url).toBeNull()
    expect(lastModal().title).toBe('Discard version')
  })

  it('discards nothing when Cancel is pressed', async () => {
    discardButton.click()
    await Promise.resolve()

    await press('cancel')
    await flush()

    expect(recorded.url).toBeNull()
    expect(lastModal().instance.hidden).toBe(true)
  })

  it('discards the version only once OK is pressed', async () => {
    behaviour.resolved = { success: true }

    discardButton.click()
    await Promise.resolve()
    await press('ok')
    await flush()

    expect(recorded.url).toBe('/discard')
    expect(recorded.body).toEqual({ table: 'tt_content', uid: 12 })
    expect(lastModal().instance.hidden).toBe(true)
    expect(notifications[0].severity).toBe('success')
    expect(window.location.reload).toHaveBeenCalled()
  })
})
