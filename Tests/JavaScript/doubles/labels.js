/*
 * Stands in for the `~labels/editorial_flow.messages` module TYPO3 generates from
 * the XLF catalogue. Returns the key itself, with any %s placeholders filled the
 * way core's LabelProvider fills them - a test asserting on a key is asserting
 * on which label was chosen, which is the part this extension controls.
 */
export default {
  get(key, args) {
    if (!Array.isArray(args)) {
      return key
    }
    let index = 0

    return key + '(' + args.map(() => String(args[index++])).join(', ') + ')'
  },
}
