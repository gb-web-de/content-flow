import { describe, it, expect, beforeEach } from 'vitest'
import { TaskWizardSubmissionService } from '../../Resources/Public/JavaScript/wizard/task-wizard-submission-service.js'
import { recorded, behaviour, reset } from './doubles/ajax-request.js'

/*
 * The service flattens the wizard's nested dataStore into the flat body
 * TaskWizardProvider::handleSubmit() reads. A key that lands one level off is
 * not visible in the UI - the wizard just submits something the PHP side then
 * ignores - so the shape of that body is what these tests pin down.
 */

const contextWith = (store) => ({ getDataStore: () => store })

beforeEach(() => {
  reset()
  globalThis.TYPO3 = { settings: { ajaxUrls: { wizard_submit: '/typo3/ajax/wizard/submit' } } }
})

describe('TaskWizardSubmissionService', () => {
  it('posts to the generic wizard route under the extension mode', async () => {
    await new TaskWizardSubmissionService(contextWith({ pending: { mode: 'create_from_picker' } })).execute()

    expect(recorded.url).toBe('/typo3/ajax/wizard/submit')
    expect(recorded.queryArguments).toEqual({ mode: 'contentflow_task_wizard' })
  })

  it('flattens pending, destination, details and stage into one body', async () => {
    const context = contextWith({
      pending: { mode: 'route_member', recordUid: 42 },
      destination: 7,
      taskDetails: { title: 'A task', priority: 'high' },
      stage: 'review',
    })

    await new TaskWizardSubmissionService(context).execute()

    expect(recorded.body).toEqual({
      mode: 'route_member',
      recordUid: 42,
      destination: 7,
      title: 'A task',
      priority: 'high',
      stageChoice: 'review',
    })
  })

  it('returns whatever the backend resolved to', async () => {
    behaviour.resolved = { success: true, taskUid: 12 }

    const result = await new TaskWizardSubmissionService(contextWith({ pending: {} })).execute()

    expect(result).toEqual({ success: true, taskUid: 12 })
  })

  it('submits an undefined mode rather than throwing when nothing is pending', async () => {
    // The optional chain in the service is load-bearing: an empty store is what
    // a cancelled-then-reopened wizard leaves behind.
    await new TaskWizardSubmissionService(contextWith({})).execute()

    expect(recorded.body.mode).toBeUndefined()
  })

  it('propagates a failing request instead of swallowing it', async () => {
    behaviour.rejectWith = new Error('Request failed with status code 500')

    await expect(
      new TaskWizardSubmissionService(contextWith({ pending: { mode: 'create_pending_page' } })).execute()
    ).rejects.toThrow(/500/)
  })
})
