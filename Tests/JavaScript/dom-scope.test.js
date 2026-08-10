import { describe, it, expect, afterEach } from 'vitest'
import { topDocument } from '../../Resources/Public/JavaScript/dom-scope.js'

/*
 * topDocument() decides which Document a delegated listener is bound to. Get it
 * wrong and every click inside a TYPO3 modal is silently missed, so both the
 * ordinary case and the two fallbacks are worth pinning down.
 */

const originalTop = Object.getOwnPropertyDescriptor(window, 'top')

const setTop = (value) => {
  Object.defineProperty(window, 'top', { value, configurable: true, writable: true })
}

afterEach(() => {
  if (originalTop) {
    Object.defineProperty(window, 'top', originalTop)
  }
})

describe('topDocument', () => {
  it('returns the top window document when running inside a frame', () => {
    const outer = { document: { marker: 'top-document' } }
    setTop(outer)

    expect(topDocument()).toBe(outer.document)
  })

  it('returns the own document when it is already the top window', () => {
    setTop(window)

    expect(topDocument()).toBe(document)
  })

  it('falls back to the own document when there is no top window', () => {
    setTop(null)

    expect(topDocument()).toBe(document)
  })

  it('falls back to the own document when reaching top throws', () => {
    // A cross-origin top throws on property access rather than returning null.
    Object.defineProperty(window, 'top', {
      configurable: true,
      get() {
        throw new DOMException('Blocked a frame from accessing a cross-origin frame.')
      },
    })

    expect(topDocument()).toBe(document)
  })
})
