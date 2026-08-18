/*
 * Stands in for @typo3/backend/modal.js. Keeps the last dialog's content node
 * and buttons reachable, so a test can look at the markup an editor would see
 * and press a button the way they would.
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

  return button.trigger(new Event('click'), modal.instance)
}

export default {
  advanced(configuration) {
    const instance = { hidden: false, hideModal() { this.hidden = true } }
    opened.push({ ...configuration, instance })

    return instance
  },
  async confirm() {
    return true
  },
}
