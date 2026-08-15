import { describe, expect, it } from 'vitest'
import { taskContextTitle } from '../../Resources/Public/JavaScript/task/task-context-title.js'

const translations = {
  'modal.newTask.newPage': 'New task for a new page',
  'modal.newTask.newRecord': 'New task for a new record',
  'modal.newTask.newRecordType': 'New task for a new %s record',
  'modal.newTask.existingPage': 'New task for page: %s',
  'modal.newTask.existingRecord': 'New task for record: %s',
  'modal.finishPageDetails': 'Finish task details for page: %s',
  'modal.finishRecordDetails': 'Finish task details for record: %s',
  'modal.routeEditFor': 'Route this edit: %s',
  'modal.routeEdit': 'Route this edit',
  'modal.reopenedFor': 'Reopened for editing: %s',
  'modal.reopened': 'Reopened for editing',
  'modal.finishDetails': 'Finish task details',
}

const labels = {
  get(key, arguments_ = []) {
    return arguments_.reduce((value, argument) => value.replace('%s', argument), translations[key])
  },
}

describe('taskContextTitle', () => {
  it('keeps all four task entry types visible in the modal title', () => {
    expect(taskContextTitle({ mode: 'create_pending_page' }, labels)).toBe('New task for a new page')
    expect(taskContextTitle({ mode: 'create_pending_record' }, labels)).toBe('New task for a new record')
    expect(taskContextTitle({ mode: 'create_from_picker', table: 'pages', recordTitle: 'Camino' }, labels))
      .toBe('New task for page: Camino')
    expect(taskContextTitle({ mode: 'create_from_picker', table: 'tt_content', recordTitle: 'Hero' }, labels))
      .toBe('New task for record: Hero')
  })

  it('adds the selected record type once it is known', () => {
    expect(taskContextTitle({
      mode: 'create_pending_record',
      recordTypeLabel: 'Content Element',
    }, labels)).toBe('New task for a new Content Element record')
  })

  it('keeps the edited object visible for automatic follow-up dialogs', () => {
    expect(taskContextTitle({
      mode: 'configure_auto_task',
      subjectTable: 'pages',
      subjectTitle: 'Camino',
    }, labels)).toBe('Finish task details for page: Camino')
    expect(taskContextTitle({
      mode: 'route_member',
      recordTitle: 'Hero',
    }, labels)).toBe('Route this edit: Hero')
  })
})
