/*
 * Shared task-detail fields used by both the manual "+" flow and the post-save
 * wizard. Keeping them in one place avoids the two wizards drifting apart.
 */

function createField(labelText, input) {
  const field = document.createElement('div')
  field.className = 'form-group'

  const label = document.createElement('label')
  label.className = 'form-label'
  label.textContent = labelText

  field.append(label, input)

  return field
}

export function buildTaskDetailsForm(settings, defaults = {}) {
  const form = document.createElement('div')
  const initialTitle = typeof settings.title === 'string' ? settings.title : (defaults.title || '')
  const initialDescription = typeof settings.description === 'string'
    ? settings.description
    : (defaults.description || '')
  const initialAssignee = settings.assignee === 'open' ? 'open' : (defaults.assignee === 'open' ? 'open' : 'me')
  const assigneeChoices = [
    ['me', 'Assign to me'],
    ['open', 'Leave open for someone to take'],
  ]

  const titleInput = document.createElement('input')
  titleInput.type = 'text'
  titleInput.className = 'form-control'
  titleInput.value = initialTitle
  settings.title = titleInput.value
  titleInput.addEventListener('input', () => {
    settings.title = titleInput.value
  })
  form.append(createField('Title', titleInput))

  const descriptionInput = document.createElement('textarea')
  descriptionInput.className = 'form-control'
  descriptionInput.rows = 3
  descriptionInput.placeholder = 'Optional description'
  descriptionInput.value = initialDescription
  settings.description = descriptionInput.value
  descriptionInput.addEventListener('input', () => {
    settings.description = descriptionInput.value
  })
  form.append(createField('Description', descriptionInput))

  const assigneeSelect = document.createElement('select')
  assigneeSelect.className = 'form-select form-control'
  const assignee = initialAssignee
  assigneeChoices.forEach(([value, label]) => {
    assigneeSelect.add(new Option(label, value, value === assignee, value === assignee))
  })
  settings.assignee = assigneeSelect.value
  assigneeSelect.addEventListener('change', () => {
    settings.assignee = assigneeSelect.value
  })
  form.append(createField('Assignment', assigneeSelect))

  return {
    element: form,
    titleInput,
  }
}
