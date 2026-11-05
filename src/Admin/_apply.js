/**
 * Where AI translation puts a translation in the editor, unsaved: the save
 * that follows saves it, as anything typed (editor-panel.js's dialog, through
 * _ai.js's translateFields() and its `apply`). Bundled into the panel's
 * script; its dependencies (`wp`, the texts, the document, TinyMCE, ACF) are
 * handed in, so a test can hand in its own.
 */

// Yoast's fields, put in through its editor store (as its own AI does);
// without it, its form's hidden field
export const YOAST = {
  '_yoast_wpseo_title': (yoast, text) => yoast.updateData({ title: text }),
  '_yoast_wpseo_metadesc': (yoast, text) => yoast.updateData({ description: text }),
  '_yoast_wpseo_opengraph-title': (yoast, text) => yoast.setFacebookPreviewTitle(text),
  '_yoast_wpseo_opengraph-description': (yoast, text) => yoast.setFacebookPreviewDescription(text),
  '_yoast_wpseo_twitter-title': (yoast, text) => yoast.setTwitterPreviewTitle(text),
  '_yoast_wpseo_twitter-description': (yoast, text) => yoast.setTwitterPreviewDescription(text)
}

/**
 * Where a translation goes, unsaved: the save that follows saves it, as
 * anything typed. In the block editor, its state: the title (and a draft's
 * slug, while it's the original's), the excerpt, a block's HTML (parsed
 * into its attributes) or an ACF block's field. Elsewhere the screen's form:
 * a term's name and description, a classic screen's title, excerpt and
 * content. A page field in ACF's form (its `field` key, the `nth` of them),
 * Yoast's in its store. A contact form's mails and messages in CF7's form,
 * a mail's MJML through wp-go's cf7-mjml (`window.wgMjml`), which compiles
 * it into the body. Answers '' once it's in, else why it isn't.
 */
export function applier (state, {
  wp, texts, document, tinymce, acf
}) {
  const blocks = wp.data?.select('core/block-editor')
  const editing = blocks && wp.data.select('core/editor')?.getCurrentPostId?.()
  const fieldOf = Object.fromEntries((state.fields || []).map(field => [ field.key, field ]))

  // a block by its address: `@2.0`, its place (the first block in the
  // third: a page with no block IDs yet, ai-fields.php's ai_block_address()),
  // else its ID (`metadata.wgId`), inner blocks too
  const byId = (id, list = blocks.getBlocks()) => {
    if (id.startsWith('@')) {
      let level = list
      let found = null

      for (const n of id.slice(1).split('.')) {
        found = level?.[Number(n)] || null
        level = found?.innerBlocks
      }

      return found
    }

    for (const block of list) {
      if (block.attributes?.metadata?.wgId === id) {
        return block
      }

      const inner = byId(id, block.innerBlocks || [])

      if (inner) {
        return inner
      }
    }

    return null
  }

  // a form's field, its value set as typing would
  const input = (selector, text) => {
    const node = document.querySelector(selector)

    if (!node) {
      return texts.aiNotHere
    }

    node.value = text
    node.dispatchEvent(new document.defaultView.Event('input', { bubbles: true }))
    node.dispatchEvent(new document.defaultView.Event('change', { bubbles: true }))

    return ''
  }

  const post = (part, text) => {
    if (editing) {
      const { editPost } = wp.data.dispatch('core/editor')

      editPost({ [part]: text })

      if (part === 'title' && state.slug_follows && wp.url?.cleanForSlug) {
        editPost({ slug: wp.url.cleanForSlug(text) })
      }

      return ''
    }

    // a classic screen's content: its editor, or the textarea
    if (part === 'content' && tinymce?.get('content')) {
      tinymce.get('content').setContent(text)

      return ''
    }

    return input(`#${part}`, text)
  }

  const block = (id, text) => {
    const found = editing && byId(id)

    if (!found) {
      return texts.aiNotHere
    }

    // its markup with the translated HTML in, parsed back into attributes
    const markup = wp.blocks.serialize(found).replace(wp.blocks.getBlockContent(found), () => text)
    const parsed = wp.blocks.parse(markup)[0]

    if (!parsed) {
      return texts.aiNotHere
    }

    wp.data.dispatch('core/block-editor').updateBlockAttributes(found.clientId, parsed.attributes)

    return ''
  }

  // Where an ACF block's field is in its data once ACF's form has redrawn it
  // by key: nested in its clone or group (`field_title` holding
  // `field_title_field_text`, a clone's fields written with its key before
  // theirs), its repeater's rows (`row-0`, or an array); the key path, or
  // null. The field's key is its own (`field_text`); where it's there more
  // than once (a repeater's rows), the row numbers in its name pick it
  // (`items_2_title`: row 2).
  const keyedPath = (data, field, name) => {
    const found = []
    const own = key => key === field || key.endsWith('_' + field)
    const walk = (value, path, rows) => Object.entries(value).forEach(([ key, item ]) => {
      const row = /^row-(\d+)$/.exec(key)?.[1] ?? (Array.isArray(value) ? key : null)
      const next = [ ...path, key ]

      if (own(key) && (item === null || typeof item !== 'object' || 'url' in item)) {
        found.push({ path: next, rows })
      } else if (item && typeof item === 'object') {
        walk(item, next, row !== null ? [ ...rows, Number(row) ] : rows)
      }
    })

    walk(data, [], [])

    if (found.length < 2) {
      return found[0]?.path || null
    }

    const numbers = (name.match(/_(\d+)_/g) || []).map(step => Number(step.slice(1, -1)))

    return found.find(match => match.rows.join() === numbers.join())?.path || null
  }

  // a copy of the data with the value at `path` set
  const setAt = (data, path, value) => {
    const [ step, ...more ] = path
    const copy = Array.isArray(data) ? [ ...data ] : { ...data }

    copy[step] = more.length ? setAt(data[step] || {}, more, value) : value

    return copy
  }

  const acfBlock = (rest, text, field) => {
    const [ id, path ] = rest.split('/')
    const [ name, part ] = path.split('#')
    const found = editing && byId(id)
    const data = found?.attributes?.data || {}
    // stored by name, as the page was saved; or, once its form is drawn, by
    // the field's key, where that has put it
    const at = name in data ? [ name ] : field ? keyedPath(data, field, name) : null

    if (!at) {
      return texts.aiNotHere
    }

    const current = at.reduce((value, step) => value?.[step], data)
    const next = setAt(data, at, part ? { ...(current || {}), [part]: text } : text)

    wp.data.dispatch('core/block-editor').updateBlockAttributes(found.clientId, { data: next })

    return ''
  }

  const meta = (rest, text, field) => {
    const [ key, part ] = rest.split('#')
    const yoast = wp.data?.select('yoast-seo/editor') && wp.data.dispatch('yoast-seo/editor')

    if (YOAST[key] && yoast) {
      YOAST[key](yoast, text)

      return ''
    }

    if (key.startsWith('_yoast_wpseo_')) {
      return input(`#yoast_wpseo_${key.slice('_yoast_wpseo_'.length)}`, text)
    }

    const acfField = field && acf?.getFields?.({ key: field.field })?.[field.nth]

    if (!acfField) {
      return texts.aiNotHere
    }

    if (part) {
      acfField.val({ ...(acfField.val() || {}), [part]: text })
    } else {
      acfField.val(text)

      // a WYSIWYG field's editor shows what its textarea holds only once told
      const editor = tinymce?.get(acfField.$input?.().attr('id'))

      editor && editor.setContent(text)
    }

    return ''
  }

  // CF7's form: `mail/subject`, `mail_2/body` (its `wpcf7-mail-2[body]`),
  // `messages/<key>`; a mail's `mjml`, its MJML editor's
  const cf7 = (rest, text) => {
    const [ where, part ] = rest.split('/')

    if (where === 'messages') {
      return input(`[name="wpcf7-messages[${part}]"]`, text)
    }

    if (part === 'mjml') {
      return document.defaultView.wgMjml?.setSource(where, text) ? '' : texts.aiNotHere
    }

    return input(`[name="${where === 'mail_2' ? 'wpcf7-mail-2' : 'wpcf7-mail'}[${part}]"]`, text)
  }

  return (key, text) => {
    const [ kind, ...rest ] = key.split(':')
    const where = rest.join(':')

    switch (kind) {
      case 'post': return post(where, text)
      case 'term': return input(`#${where}`, text)
      case 'block': return block(where, text)
      case 'acf': return acfBlock(where, text, fieldOf[key]?.field)
      case 'meta': return meta(where, text, fieldOf[key])
      case 'cf7': return cf7(where, text)
      default: return texts.aiNotHere
    }
  }
}
