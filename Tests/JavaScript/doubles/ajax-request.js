/*
 * Stands in for @typo3/core/ajax/ajax-request.js, which only exists inside the
 * backend's importmap. It records what was sent so a test can assert on the
 * request, and lets a test decide what comes back.
 */
export const recorded = {
  url: null,
  queryArguments: null,
  body: null,
}

export const behaviour = {
  resolved: {},
  rejectWith: null,
}

export function reset() {
  recorded.url = null
  recorded.queryArguments = null
  recorded.body = null
  behaviour.resolved = {}
  behaviour.rejectWith = null
}

export default class AjaxRequest {
  constructor(url) {
    recorded.url = url
  }

  withQueryArguments(queryArguments) {
    recorded.queryArguments = queryArguments
    return this
  }

  async post(body) {
    recorded.body = body

    return this.answer()
  }

  async get() {
    return this.answer()
  }

  /*
   * AjaxRequest throws on any non-2xx answer, and what it throws still carries
   * the response body - which is how a caller reads the code and message the
   * server rejected with. `rejectWith` therefore stands in for both: a thrown
   * AjaxResponse, and a genuine transport failure.
   */
  async answer() {
    if (behaviour.rejectWith !== null) {
      throw behaviour.rejectWith
    }

    return { resolve: async () => behaviour.resolved }
  }
}
