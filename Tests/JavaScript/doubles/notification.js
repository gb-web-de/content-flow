/*
 * Stands in for @typo3/backend/notification.js. Records what an editor would
 * have been told, so a test can assert on the message rather than on a mock
 * having been called.
 */
export const notifications = []

export function reset() {
  notifications.length = 0
}

export default {
  success(title, message) {
    notifications.push({ severity: 'success', title, message })
  },
  error(title, message) {
    notifications.push({ severity: 'error', title, message })
  },
  warning(title, message) {
    notifications.push({ severity: 'warning', title, message })
  },
}
