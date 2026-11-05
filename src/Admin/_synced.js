/**
 * A translation's synced fields, in ACF's forms (synced-fields.php): each box
 * moved beside its field's title, the field locked while it's ticked, and what
 * the original has shown at once where that's a matter of setting a value (the
 * save pulls it anyway, Core/synced.php). In a block's form, the marks are
 * written in the block's `metadata`. Its dependencies are handed in, so a test
 * can hand in its own.
 */

const children = (node, selector) => [ ...node.children ].filter(child => child.matches(selector))

// a block by the ID ACF gives its form: its `id` attribute, or its client ID;
// with or without `block_` before it
const findBlock = (id, list) => {
  const bare = id.replace(/^block_/, '')

  for (const block of list) {
    if ([ id, bare ].includes(block.attributes?.id) || block.clientId === bare) {
      return block
    }

    const inner = findBlock(id, block.innerBlocks || [])

    if (inner) {
      return inner
    }
  }

  return null
}

/** The block's marks with this form's boxes as they are; those it doesn't show stay. */
export function formMarks (form, stored = []) {
  const boxes = [ ...form.querySelectorAll('.wgl-sync__box') ]
  const shown = boxes.map(box => box.dataset.mark)

  return [ ...stored.filter(mark => !shown.includes(mark)), ...boxes.filter(box => box.checked).map(box => box.dataset.mark) ]
}

function storeMarks (sync, { wp }) {
  const form = sync.closest('.acf-block-fields')
  const select = wp?.data?.select('core/block-editor')

  if (!form || !select) {
    return
  }

  const block = findBlock(form.dataset.blockId || '', select.getBlocks()) || select.getSelectedBlock()

  if (!block) {
    return
  }

  const { wgSynced, ...metadata } = block.attributes.metadata || {}
  const marks = formMarks(form, wgSynced || [])

  wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, {
    metadata: marks.length ? { ...metadata, wgSynced: marks } : metadata
  })
}

/**
 * A page's form: its boxes as hidden inputs, in the form and out of any field,
 * each shown and those ticked (synced-fields.php says why a box has no name).
 */
export function writePosted (form, document) {
  if (!form) {
    return
  }

  let posted = children(form, '.wgl-sync-posted')[0]

  if (!posted) {
    posted = document.createElement('div')
    posted.className = 'wgl-sync-posted'
    posted.hidden = true
    form.append(posted)
  }

  posted.replaceChildren()

  form.querySelectorAll('.wgl-sync[data-block="0"] .wgl-sync__box').forEach(box => {
    for (const name of box.checked ? [ 'wgl_synced_shown[]', 'wgl_synced[]' ] : [ 'wgl_synced_shown[]' ]) {
      const input = document.createElement('input')

      input.type = 'hidden'
      input.name = name
      input.value = box.dataset.mark
      posted.append(input)
    }
  })
}

// what a box's tick replaced in the form, until it's unticked
const held = new WeakMap()

const liveField = (sync, element, { acf, $ }) => (sync.dataset.live === '1' ? acf?.getField?.($(element)) : null) || null

/** A box just ticked: what the field holds is kept, and it shows what the original has, if it can. */
function showOriginal (sync, box, element, env) {
  const field = liveField(sync, element, env)

  if (!field) {
    return
  }

  if (!held.has(box)) {
    held.set(box, field.val())
  }

  showValue(sync, box, field, JSON.parse(sync.dataset.value || 'null'), env)
}

/**
 * A box just unticked: the field goes back to what the tick replaced, from this
 * form, or from before it was saved (`data-before`, synced-fields.php). What can't
 * be shown comes back on save.
 */
function showBefore (sync, box, element, env) {
  const field = liveField(sync, element, env)

  if (!field) {
    return
  }

  if (held.has(box)) {
    showValue(sync, box, field, held.get(box), env)
    held.delete(box)
  } else if (box.dataset.before !== undefined) {
    showValue(sync, box, field, JSON.parse(box.dataset.before), env)
    delete box.dataset.before
  }
}

/** Whether a group's field follows by itself, or by another group holding it, inside `group`. */
function followsAnyway (element, box, group) {
  if (box.checked) {
    return true
  }

  for (let up = element.parentElement?.closest('.acf-field'); up && up !== group; up = up.parentElement?.closest('.acf-field')) {
    if (children(children(up, '.acf-label')[0] || up, '.wgl-sync')[0]?.querySelector('.wgl-sync__box:checked')) {
      return true
    }
  }

  return false
}

/** A group's box just changed: the fields it holds show what follows from it, those it doesn't decide left alone. */
function showGroup (sync, group, ticked, env) {
  group.querySelectorAll('.wgl-sync').forEach(inner => {
    const element = inner.closest('.acf-field')

    if (inner === sync || !element) {
      return
    }

    inner.querySelectorAll('.wgl-sync__box').forEach(box => {
      if (!followsAnyway(element, box, group)) {
        (ticked ? showOriginal : showBefore)(inner, box, element, env)
      }
    })
  })
}

/** A value in the field: for a link, the part this box is for. */
function showValue (sync, box, field, value, { tinymce }) {
  if (sync.dataset.type !== 'link') {
    field.val(value ?? '')
    tinymce?.get(field.$input?.().attr('id'))?.setContent(value ?? '')

    return
  }

  const current = field.val() || {}
  const source = value && typeof value === 'object' ? value : {}

  field.val(box.dataset.part === 'label'
    ? { ...current, title: source.title || '' }
    : { ...current, url: source.url || '', target: source.target || '' })
}

/** A link with only its page synced: its label, in a field of its own, as its dialog is locked. */
function labelInput (sync, input, element, shown, { acf, $, document }) {
  let row = children(input, '.wgl-sync__label')[0]

  if (!shown) {
    row?.remove()

    return
  }

  if (row) {
    return
  }

  const field = acf?.getField?.($(element))

  row = document.createElement('label')
  row.className = 'wgl-sync__label'
  row.textContent = sync.dataset.label

  const text = document.createElement('input')

  text.type = 'text'
  text.value = field?.val()?.title || ''
  text.addEventListener('input', () => field?.val({ ...(field.val() || {}), title: text.value }))
  row.append(text)
  input.append(row)
}

/** The field as its boxes say: locked, and marked for the stylesheet. */
export function paint (sync, element, env) {
  const boxes = [ ...sync.querySelectorAll('.wgl-sync__box') ]
  const on = part => boxes.some(box => box.dataset.part === part && box.checked)
  const link = sync.dataset.type === 'link'
  const locked = link ? on('page') : boxes.some(box => box.checked)
  const input = children(element, '.acf-input')[0]

  element.classList.toggle('wgl-synced', locked)
  element.classList.toggle('wgl-synced--all', boxes.every(box => box.checked))
  element.classList.toggle('wgl-synced--group', sync.dataset.type === 'group' && locked)

  if (!input) {
    return
  }

  if (link) {
    labelInput(sync, input, element, on('page') && !on('label'), env)
  }

  for (const child of input.children) {
    if (child !== sync && !child.matches('.wgl-original, .wgl-sync__label')) {
      child.toggleAttribute('inert', locked)
    }
  }
}

/** Every box under `root` not seen yet: moved, painted, listened to. */
export function initBoxes (root, env) {
  root.querySelectorAll('.wgl-sync:not([data-bound])').forEach(sync => {
    const element = sync.closest('.acf-field')

    if (!element) {
      return
    }

    sync.dataset.bound = '1'

    // WP-Go's collapsible labels take a click anywhere on them (acf-forms.js),
    // and cancel it: a tick isn't one
    sync.addEventListener('click', event => event.stopPropagation())

    // beside the title; a table's cell has its title in the header row
    const label = children(element, '.acf-label')[0]

    if (label) {
      label.append(sync)
    } else {
      children(element, '.acf-input')[0]?.prepend(sync)
    }

    sync.querySelectorAll('.wgl-sync__box').forEach(box => box.addEventListener('change', () => {
      if (sync.dataset.type === 'group') {
        showGroup(sync, element, box.checked, env)
      } else {
        (box.checked ? showOriginal : showBefore)(sync, box, element, env)
      }

      paint(sync, element, env)

      if (sync.dataset.block === '1') {
        storeMarks(sync, env)
      } else {
        writePosted(box.form, env.document)
      }
    }))

    paint(sync, element, env)

    if (sync.dataset.block !== '1') {
      writePosted(sync.querySelector('.wgl-sync__box')?.form, env.document)
    }
  })
}
