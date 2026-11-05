/**
 * What a block's link to its original comes to (block-link.js), apart from the
 * editor, so a test can run it. `blocks` are the original's (block-link.php's
 * linkable_blocks()), `taken` the IDs the page's other blocks have.
 */

const ID = /^[\w-]+$/

/** The select's options: "not linked", and the original's blocks of this block's type. */
export function linkOptions ({ blocks, type, title, taken, notLinked, takenLabel }) {
  return [
    { value: '', label: notLinked },
    ...blocks.filter(block => block.type === type).map(block => {
      const name = `${block.position}. ${block.name || title}`
      const words = block.preview ? ` · ${block.preview}` : ''
      const used = taken.includes(block.id)

      return {
        value: block.id,
        label: `${name}${words}${used ? ` (${takenLabel})` : ''}`,
        disabled: used
      }
    })
  ]
}

/** The select's value: the block linked to, if there's one of this type. */
export function linkedTo (id, blocks, type) {
  return blocks.some(block => block.id === id && block.type === type) ? id : ''
}

/**
 * What's wrong with an ID typed by hand, which refuses it: `badId`, `usedId`,
 * `otherType`. Null when it can be used, one no block in the original has among
 * them: it may be one the original gets later, and the block is just not linked.
 */
export function idProblem (id, { blocks, type, taken }) {
  if (!ID.test(id)) {
    return 'badId'
  }

  if (taken.includes(id)) {
    return 'usedId'
  }

  return blocks.some(block => block.id === id && block.type !== type) ? 'otherType' : null
}

/**
 * The block's metadata with this ID; '' takes it away, and the save gives it a
 * new one (Core\stamp_ids()). Its synced marks go when the ID changes: they
 * followed another block, whose values the next save would pull.
 */
export function withId (metadata = {}, id = '') {
  const { wgId, wgSynced, ...rest } = metadata

  return {
    ...rest,
    ...(id ? { wgId: id } : {}),
    ...(wgSynced && id === wgId ? { wgSynced } : {})
  }
}
