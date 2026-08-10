import { describe, it, expect } from 'vitest'
import {
  claimsByIdentifier,
  elementIdentifiers,
  foreignTaskUidFor,
  hueForTaskUid,
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
      { taskUid: 7, table: 'tt_content', uid: 12, identifiers: ['tt_content:12', 'tt_content:345'] },
    ])

    expect(claims.get('tt_content:12')).toBe(7)
    expect(claims.get('tt_content:345')).toBe(7)
  })

  it('falls back to table:uid when a member carries no identifier list', () => {
    const claims = claimsByIdentifier([{ taskUid: 3, table: 'pages', uid: 2 }])

    expect(claims.get('pages:2')).toBe(3)
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
