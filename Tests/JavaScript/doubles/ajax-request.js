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

    if (behaviour.rejectWith !== null) {
      throw behaviour.rejectWith
    }

    return { resolve: async () => behaviour.resolved }
  }
}
