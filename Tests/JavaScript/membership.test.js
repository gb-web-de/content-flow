import { describe, it, expect, beforeEach } from 'vitest'
import { openMoveDialog, moveToTask, splitFromTask } from '../../Resources/Public/JavaScript/task/membership.js'
import AjaxRequest, { recorded, behaviour, reset as resetAjax } from './doubles/ajax-request.js'
import { notifications, reset as resetNotifications } from './doubles/notification.js'
import { lastModal, press, reset as resetModals } from './doubles/modal.js'

/*
 * Splitting a record out of its task and moving it onto another one. Both are
 * membership changes - the workspace version belongs to the record - so what
 * these tests guard is not "did it save" but the two ways the operation can
 * silently go wrong in the browser: sending the wrong record identity, and
 * swallowing the reason the server gave for a refusal.
 */
describe('membership actions', () => {
  beforeEach(() => {
    resetAjax()
    resetNotifications()
    resetModals()
    global.TYPO3 = {
      settings: {
        ajaxUrls: {
          contentflow_task_attach: '/attach',
          contentflow_task_detach: '/detach',
          contentflow_task_move_targets: '/move-targets',
        },
      },
    }
  })

  it('moves one record onto the chosen task', async () => {
    behaviour.resolved = { success: true, moved: [{ table: 'tt_content', uid: 12 }], refused: [] }

    await moveToTask('tt_content', 12, 7, { recordTitle: 'Intro', taskTitle: 'Campaign', onDone: () => {} })

    expect(recorded.url).toBe('/attach')
    expect(recorded.body).toEqual({ task: 7, records: [{ table: 'tt_content', uid: 12 }] })
    expect(notifications[0].severity).toBe('success')
  })

  /*
   * attachAction() reports per record and can answer "successful" with an empty
   * result, so a refusal has to be read out of `refused` - and the reason it
   * gives is the actionable half ("switch workspace first"), not a generic
   * failure.
   */
  it('reports the reason a single record was refused', async () => {
    behaviour.resolved = {
      success: false,
      moved: [],
      refused: [{ table: 'tt_content', uid: 12, code: 'access-denied', message: 'You may not edit this record.' }],
    }
    let done = false

    await moveToTask('tt_content', 12, 7, { onDone: () => { done = true } })

    expect(notifications[0].severity).toBe('error')
    expect(notifications[0].message).toBe('You may not edit this record.')
    expect(done).toBe(false)
  })

  /*
   * Every rejection this controller raises is a 400 carrying its own message,
   * and AjaxRequest throws on any non-2xx answer. Treating that as a transport
   * failure would replace "this task belongs to another workspace" with "could
   * not reach the server" - wrong, and nothing an editor can act on.
   */
  it('keeps the server\'s message when it answers with a rejection status', async () => {
    behaviour.rejectWith = {
      resolve: async () => ({
        success: false,
        code: 'task-in-other-workspace',
        message: 'This task belongs to another workspace.',
      }),
    }

    await moveToTask('tt_content', 12, 7, { onDone: () => {} })

    expect(notifications[0].message).toBe('This task belongs to another workspace.')
  })

  it('falls back to the transport message when nothing came back at all', async () => {
    behaviour.rejectWith = new TypeError('Failed to fetch')

    await splitFromTask('tt_content', 12, { onDone: () => {} })

    expect(notifications[0].message).toBe('membership.error.server')
  })

  it('sends the dialog\'s details along when splitting', async () => {
    behaviour.resolved = { success: true, task: 9, from: 3 }

    await splitFromTask('tt_content', 12, {
      title: 'Rewrite the intro',
      description: 'Shorter.',
      assignee: '2',
      recordTitle: 'Intro',
      onDone: () => {},
    })

    expect(recorded.url).toBe('/detach')
    expect(recorded.body).toEqual({
      table: 'tt_content',
      uid: 12,
      title: 'Rewrite the intro',
      description: 'Shorter.',
      assignee: '2',
    })
  })
})

describe('the move dialog', () => {
  beforeEach(() => {
    resetAjax()
    resetNotifications()
    resetModals()
    global.TYPO3 = { settings: { ajaxUrls: { contentflow_task_move_targets: '/move-targets', contentflow_task_attach: '/attach' } } }
  })

  it('offers every task the server named, with where it currently sits', async () => {
    behaviour.resolved = {
      success: true,
      currentTask: 3,
      currentTaskTitle: 'About us',
      tasks: [
        { uid: 7, title: 'Campaign copy', state: 'in_progress', stageLabel: 'Editing' },
        { uid: 8, title: 'Legal review', state: 'review', stageLabel: 'Review' },
      ],
    }

    await openMoveDialog('tt_content', 12, 'Intro', { onDone: () => {} })

    // The record's identity goes into the query, so the server decides which
    // tasks are reachable rather than the page guessing.
    expect(recorded.queryArguments).toEqual({ table: 'tt_content', uid: 12 })

    const content = lastModal().content
    expect(content.querySelectorAll('input[type="radio"]')).toHaveLength(2)
    expect(content.textContent).toContain('Campaign copy')
    expect(content.textContent).toContain('Review')
  })

  it('moves the record onto the task that was picked', async () => {
    behaviour.resolved = {
      success: true,
      currentTask: 3,
      currentTaskTitle: 'About us',
      tasks: [{ uid: 7, title: 'Campaign copy', state: 'backlog', stageLabel: 'Backlog' }],
    }
    await openMoveDialog('tt_content', 12, 'Intro', { onDone: () => {} })

    behaviour.resolved = { success: true, moved: [{ table: 'tt_content', uid: 12 }], refused: [] }
    await press('move')

    expect(recorded.url).toBe('/attach')
    expect(recorded.body).toEqual({ task: 7, records: [{ table: 'tt_content', uid: 12 }] })
  })

  /*
   * Nowhere to move it to is a regular answer, not a failure - and the useful
   * next step is the other operation, so the dialog says so instead of offering
   * an empty list with a live Move button.
   */
  it('explains an empty list instead of offering a dead button', async () => {
    behaviour.resolved = { success: true, currentTask: 3, currentTaskTitle: 'About us', tasks: [] }

    await openMoveDialog('tt_content', 12, 'Intro', { onDone: () => {} })

    const modal = lastModal()
    expect(modal.content.textContent).toContain('membership.move.empty')
    expect(modal.buttons.map((button) => button.name)).toEqual(['cancel'])
  })
})
