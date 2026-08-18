import { describe, it, expect } from 'vitest'
import {
  claimFor,
  claimsByIdentifier,
  elementIdentifiers,
  foreignTaskUidFor,
  hueForTaskUid,
  legendEntries,
} from '../../Resources/Public/JavaScript/task/task-markers.js'

/*
 * The markers exist to warn an editor off content another task already claims,
 * and the whole warning turns on one question: does this rendered element name
 * the same record as a membership row? The two sides disagree about which uid
 * that is - membership holds the live uid, EXT:visual_editor writes the version
 * uid onto an element whenever a workspace version exists - so the matching is
 * what these tests pin down.
 */

const contentElement = (attributes) => {
  const element = document.createElement('ve-content-element')
  Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, String(value)))

  return element
}

describe('elementIdentifiers', () => {
  it('reads scrollPositionId first, because it carries the live uid', () => {
    const element = contentElement({ table: 'tt_content', uid: 345, scrollPositionId: 'tt_content:12' })

    expect(elementIdentifiers(element)).toEqual(['tt_content:12', 'tt_content:345'])
  })

  it('falls back to the table and uid attributes alone', () => {
    const element = contentElement({ table: 'tt_content', uid: 12 })

    expect(elementIdentifiers(element)).toEqual(['tt_content:12'])
  })

  it('yields nothing for an element that names no record', () => {
    expect(elementIdentifiers(contentElement({}))).toEqual([])
  })
})

describe('claimsByIdentifier', () => {
  it('indexes every spelling the server sent for one member', () => {
    const claims = claimsByIdentifier([
      { taskUid: 7, table: 'tt_content', uid: 12, title: 'Intro', identifiers: ['tt_content:12', 'tt_content:345'] },
    ])

    expect(claims.get('tt_content:12')).toEqual({ taskUid: 7, table: 'tt_content', uid: 12, title: 'Intro' })
    expect(claims.get('tt_content:345')).toEqual({ taskUid: 7, table: 'tt_content', uid: 12, title: 'Intro' })
  })

  /*
   * The live uid is the point of carrying the identity at all: the split and
   * move endpoints work on it, and under 'tt_content:345' the element itself
   * only knows the version uid.
   */
  it('keeps the live identity under every spelling, including the version one', () => {
    const claims = claimsByIdentifier([
      { taskUid: 7, table: 'tt_content', uid: 12, identifiers: ['tt_content:345'] },
    ])

    expect(claims.get('tt_content:345').uid).toBe(12)
  })

  it('falls back to table:uid when a member carries no identifier list', () => {
    const claims = claimsByIdentifier([{ taskUid: 3, table: 'pages', uid: 2 }])

    expect(claims.get('pages:2')).toMatchObject({ taskUid: 3, table: 'pages', uid: 2 })
  })
})

describe('foreignTaskUidFor', () => {
  const claims = claimsByIdentifier([
    { taskUid: 7, table: 'tt_content', uid: 12, identifiers: ['tt_content:12', 'tt_content:345'] },
  ])

  it('recognises a claim through the version uid the element carries', () => {
    // The regression this guards: in a workspace the element says 345 while the
    // membership row says 12, so comparing a single uid finds nothing exactly
    // when a task is being worked on.
    const element = contentElement({ table: 'tt_content', uid: 345 })

    expect(foreignTaskUidFor(element, claims, 9)).toBe(7)
  })

  it('recognises a claim through scrollPositionId', () => {
    const element = contentElement({ table: 'tt_content', uid: 345, scrollPositionId: 'tt_content:12' })

    expect(foreignTaskUidFor(element, claims, 9)).toBe(7)
  })

  it('stays silent about the task the editor is working on', () => {
    const element = contentElement({ table: 'tt_content', uid: 12 })

    expect(foreignTaskUidFor(element, claims, 7)).toBeNull()
  })

  it('treats the active task as foreign when nothing is active', () => {
    const element = contentElement({ table: 'tt_content', uid: 12 })

    expect(foreignTaskUidFor(element, claims, 0)).toBe(7)
  })

  it('leaves an unclaimed element alone', () => {
    const element = contentElement({ table: 'tt_content', uid: 99 })

    expect(foreignTaskUidFor(element, claims, 7)).toBeNull()
  })
})

describe('claimFor', () => {
  const claims = claimsByIdentifier([
    { taskUid: 7, table: 'tt_content', uid: 12, title: 'Intro', identifiers: ['tt_content:12', 'tt_content:345'] },
  ])

  /*
   * foreignTaskUidFor() answers null for "mine" and for "nobody's" alike, which
   * is all its one caller needed. The markers now say something about both, so
   * the two have to be distinguishable - that is the whole reason this function
   * exists next to it.
   */
  it('tells the editor\'s own task apart from an unclaimed element', () => {
    const mine = contentElement({ table: 'tt_content', uid: 12 })
    const nobodys = contentElement({ table: 'tt_content', uid: 99 })

    expect(claimFor(mine, claims, 7)).toMatchObject({ taskUid: 7, isActive: true })
    expect(claimFor(nobodys, claims, 7)).toBeNull()
  })

  it('reports a foreign claim as inactive', () => {
    const element = contentElement({ table: 'tt_content', uid: 345 })

    expect(claimFor(element, claims, 9)).toMatchObject({ taskUid: 7, isActive: false })
  })

  /*
   * What the bubble's split/move menu acts on. Reading table/uid off the
   * element instead would hand the server the VERSION uid, which holds no
   * membership row - the move would be refused with record-not-in-open-task.
   */
  it('hands back the live record identity, not the element\'s own spelling', () => {
    const element = contentElement({ table: 'tt_content', uid: 345 })

    expect(claimFor(element, claims, 9)).toMatchObject({ table: 'tt_content', uid: 12, title: 'Intro' })
  })
})

describe('legendEntries', () => {
  it('gives every task the same hue its markers carry', () => {
    const [entry] = legendEntries([{ uid: 7, title: 'Rewrite the intro' }], 0)

    expect(entry.hue).toBe(hueForTaskUid(7))
  })

  it('flags the active task and fills in what the server left out', () => {
    const entries = legendEntries(
      [
        { uid: 7, title: 'Rewrite the intro', stageLabel: 'Editing', assigneeName: 'Ada' },
        { uid: 8, title: 'Fix the footer' },
      ],
      8,
    )

    expect(entries[0]).toMatchObject({
      taskUid: 7,
      stageLabel: 'Editing',
      assigneeName: 'Ada',
      isActive: false,
    })
    expect(entries[1]).toMatchObject({ taskUid: 8, stageLabel: '', assigneeName: '', isActive: true })
  })

  it('survives a page with no tasks at all', () => {
    expect(legendEntries([], 0)).toEqual([])
    expect(legendEntries(undefined, 0)).toEqual([])
  })
})

describe('hueForTaskUid', () => {
  it('keeps consecutive tasks visually apart and stays inside the colour wheel', () => {
    const first = hueForTaskUid(1)
    const second = hueForTaskUid(2)

    expect(first).toBeGreaterThanOrEqual(0)
    expect(first).toBeLessThan(360)
    expect(Math.abs(first - second)).toBeGreaterThan(30)
  })

  it('is stable for the same task', () => {
    expect(hueForTaskUid(42)).toBe(hueForTaskUid(42))
  })
})
