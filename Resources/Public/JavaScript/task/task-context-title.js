function fallbackIdentifier(pending) {
  const table = pending.subjectTable || pending.table || ''
  const uid = parseInt(pending.subjectUid || pending.uid || '0', 10)
  return table !== '' && uid > 0 ? `${table}:${uid}` : ''
}

function objectTitle(pending) {
  return pending.recordTitle
    || pending.subjectTitle
    || pending.editedTitle
    || fallbackIdentifier(pending)
}

export function taskContextTitle(pending = {}, labels) {
  const title = objectTitle(pending)
  switch (pending.mode) {
    case 'create_pending_page':
      return labels.get('modal.newTask.newPage')
    case 'create_pending_record':
      return pending.recordTypeLabel
        ? labels.get('modal.newTask.newRecordType', [pending.recordTypeLabel])
        : labels.get('modal.newTask.newRecord')
    case 'create_from_picker':
      return pending.table === 'pages'
        ? labels.get('modal.newTask.existingPage', [title])
        : labels.get('modal.newTask.existingRecord', [title])
    case 'configure_auto_task':
      return pending.subjectTable === 'pages'
        ? labels.get('modal.finishPageDetails', [title])
        : labels.get('modal.finishRecordDetails', [title])
    case 'route_member':
      return title ? labels.get('modal.routeEditFor', [title]) : labels.get('modal.routeEdit')
    case 'regression_comment':
      return title ? labels.get('modal.reopenedFor', [title]) : labels.get('modal.reopened')
    default:
      return labels.get('modal.finishDetails')
  }
}
