/*
 * Stands in for @typo3/backend/modal.js. Keeps the last dialog's content node
 * and buttons reachable, so a test can look at the markup an editor would see
 * and press a button the way they would.
 *
 * confirm()'s default OK/Cancel buttons carry no `trigger` in real TYPO3
 * core - clicking one only dispatches 'confirm.button.ok'/
 * 'confirm.button.cancel' on the modal, which is why modal-confirm.js exists
 * (see its own comment). This double mirrors that: the instance is a real
 * EventTarget, and press() dispatches those events for a button that has no
 * `trigger` of its own, instead of calling one that was never there.
 */
export const opened = []

export function reset() {
  opened.length = 0
}

export function lastModal() {
  return opened[opened.length - 1] ?? null
}

export function press(name) {
  const modal = lastModal()
  const button = modal?.buttons.find((candidate) => candidate.name === name)
  if (button === undefined) {
    throw new Error('No button named "' + name + '" in the current modal')
  }

  if (typeof button.trigger === 'function') {
    return button.trigger(new Event('click'), modal.instance)
  }
  modal.instance.dispatchEvent(new Event('confirm.button.' + name))
}

function buildInstance() {
  const instance = new EventTarget()
  instance.hidden = false
  instance.hideModal = () => {
    instance.hidden = true
    instance.dispatchEvent(new Event('typo3-modal-hidden'))
  }
  return instance
}

export default {
  advanced(configuration) {
    const instance = buildInstance()
    opened.push({ ...configuration, instance })

    return instance
  },
  confirm(title, content, severity, buttons = []) {
    const resolvedButtons = buttons.length > 0 ? buttons : [
      { text: 'Cancel', active: true, btnClass: 'btn-default', name: 'cancel' },
      { text: 'OK', btnClass: 'btn-' + severity, name: 'ok' },
    ]
    return this.advanced({ title, content, severity, buttons: resolvedButtons })
  },
}
