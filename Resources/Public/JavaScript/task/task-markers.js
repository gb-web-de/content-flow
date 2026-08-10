/*
 * Which task claims a rendered content element, and how it is coloured.
 *
 * Pure functions on purpose: the DOM half of the markers lives in a nested
 * iframe that only exists in a real backend (see visual-editor-task-select.js),
 * so the part worth testing is separated out here - no document, no ajax.
 *
 * The identity problem this solves: a task member row holds the LIVE uid of a
 * record, while the Visual Editor renders the page workspace-overlaid and
 * writes whichever uid its wrapper picked onto the element. The server
 * therefore sends every spelling a member can appear under
 * (TaskAjaxController::memberIdentifiers()), and matching means "does any of
 * this element's identifiers appear in that list", not "are the uids equal".
 */

/**
 * A stable, distinct-enough hue per task without a stored colour column - the
 * golden-angle step keeps consecutive uids visually apart.
 */
export function hueForTaskUid(taskUid) {
  return (Number(taskUid) * 137.508) % 360
}

/**
 * `table:uid` for one record.
 */
export function identifier(table, uid) {
  return String(table) + ':' + String(uid)
}

/**
 * Every identifier a rendered element can be recognised by, most reliable
 * first.
 *
 * `scrollPositionId` is `table:record->getUid()` and therefore carries the LIVE
 * uid even inside a workspace, which is the one a membership row holds. The
 * `table`/`uid` attribute pair is the fallback, and in a workspace that uid is
 * usually the VERSION - which is why the server sends both spellings rather
 * than the client guessing which one it is looking at.
 */
export function elementIdentifiers(element) {
  const identifiers = []

  const scrollPositionId = element.getAttribute('scrollPositionId') ?? element.getAttribute('scrollpositionid')
  if (scrollPositionId) {
    identifiers.push(scrollPositionId)
  }

  const table = element.getAttribute('table')
  const uid = element.getAttribute('uid')
  if (table && uid) {
    identifiers.push(identifier(table, uid))
  }

  return identifiers
}

/**
 * Build the lookup the marker pass reads: every identifier a member can appear
 * under, pointing at the task that claims it.
 *
 * @param members {Array<{taskUid: number, table: string, uid: number, identifiers?: string[]}>}
 * @returns {Map<string, number>}
 */
export function claimsByIdentifier(members) {
  const claims = new Map()

  for (const member of members) {
    const taskUid = Number(member.taskUid)
    const identifiers = Array.isArray(member.identifiers) && member.identifiers.length > 0
      ? member.identifiers
      : [identifier(member.table, member.uid)]

    for (const value of identifiers) {
      claims.set(String(value), taskUid)
    }
  }

  return claims
}

/**
 * The task claiming this element, but only when it is a *different* one than
 * the editor is working on. The active task's own records are the whole point
 * of the current session - marking those would be noise, not a warning.
 *
 * @returns {number|null} the foreign task uid, or null
 */
export function foreignTaskUidFor(element, claims, activeTaskUid) {
  for (const value of elementIdentifiers(element)) {
    const taskUid = claims.get(value)
    if (taskUid === undefined) {
      continue
    }

    return taskUid === Number(activeTaskUid) ? null : taskUid
  }

  return null
}
