/**
 * The lists' "+" (admin.php column()): a dialog to choose what the missing
 * translation is. A card is picked (Duplicate, Fixed, or Link; the first to
 * start with), and Create does it: a copy, by the "+"'s own link for it
 * (create_url()), or, with "Translate its texts with AI" ticked, made and
 * translated here (_ai.js); or Link, a search of the language's free pages,
 * the one picked linked (wgl/v1/posts/<id>/…, or wgl/v1/terms/<id>/… on a
 * taxonomy's list, admin.php rest_routes()), then the list again. Without this script the
 * "+" makes an independent translation, as its href says.
 *
 * "Translate" (a row's action) and "Translate…" (the bulk action) open
 * another dialog, for one item or the rows ticked: bulk.php's. And the
 * Trash asks first, for items with translations: trash.php's.
 *
 * Contact Form 7's list has the column's header but no hook for its cells:
 * they come printed (`window.wglList.cells`, by form ID, admin.php
 * cf7_assets()) and are put in each row here.
 */

import {
  AI_ICON, fieldsOf, translateFields
} from './_ai.js'

const {
  texts = {}, cells = null, ai = false
} = window.wglList || {}

if (cells) {
  document.querySelectorAll('.wp-list-table tbody tr').forEach(row => {
    const id = row.querySelector('.check-column input[type="checkbox"]')?.value
    const cell = row.querySelector('td.column-wgl_languages')

    if (id && cell && cells[id]) {
      cell.innerHTML = cells[id]
    }
  })
}
const { apiFetch } = window.wp
const { addQueryArgs } = window.wp.url

const el = (tag, props = {}, ...children) => {
  const node = Object.assign(document.createElement(tag), props)
  node.append(...children.filter(child => child !== null && child !== undefined))

  return node
}

const format = (text, ...values) => values.reduce((out, value, i) => out.replace(`%${i + 1}$s`, value).replace('%s', value), text || '')

let dialog = null

// the AI checkbox as it was last ticked, in this browser: on, the first time
const remembered = () => {
  try {
    return window.localStorage.getItem('wglAi') !== '0'
  } catch {
    return true
  }
}

const remember = on => {
  try {
    window.localStorage.setItem('wglAi', on ? '1' : '0')
  } catch {}
}

function open (plus) {
  const {
    wglId: post, wglKind: kind = 'posts', wglLang: lang, wglLangName: name, wglTitle: title, wglText: fixed, wglBlocks: structure, wglDefault: isDefault, wglCopy: canEmpty
  } = plus.dataset

  dialog?.remove()

  // a card only picks what Create does: make a copy (the "+"'s own link for
  // it, and its mode) or link one made already
  const choice = (href, label, help, icon, mode = 'independent') => {
    const button = el('button', {
      type: 'button', className: 'wgl-choice', ariaPressed: 'false'
    },
    el('span', { className: `dashicons ${icon}`, ariaHidden: 'true' }),
    el('span', {}, el('strong', { textContent: label }), el('span', { className: 'wgl-choice__help', textContent: help }))
    )

    button.dataset.href = href
    button.dataset.mode = mode

    return button
  }

  // the default language's page is the original: one copy, which becomes it;
  // a type without blocks (no fixed link) has independent translations only, a form (no
  // structure link) no fixed blocks with free fields
  const copies = isDefault === '1'
    ? [ choice(plus.href, format(texts.original, name), texts.originalHelp, 'dashicons-star-filled') ]
    : [
      // from the freest to the strictest
      choice(plus.href, texts.independent, texts.independentHelp, 'dashicons-admin-page'),
      structure && choice(structure, texts.blocks, texts.blocksHelp, 'dashicons-layout', 'blocks'),
      fixed && choice(fixed, texts.text, texts.textHelp, 'dashicons-lock', 'text')
    ].filter(Boolean)
  const linking = choice('', format(texts.linkCard, name), texts.linkHelp, 'dashicons-admin-links', 'link')

  // Link on a row of its own, under the copies
  linking.classList.add('wgl-choice--link')

  // AI translation, ticked as it was last time (on, the first): a card as the
  // choices are, full width under them, its checkbox inside. Not into the
  // default language, whose copy becomes the original (nothing to translate
  // it from), and not for a link, which keeps the page's content
  const withAi = ai && isDefault !== '1'
    ? el('label', { className: 'wgl-choice wgl-choice--ai wgl-dialog__ai' },
      el('input', {
        type: 'checkbox', className: 'wgl-choice__check', checked: remembered()
      }),
      el('span', { className: 'wgl-choice__icon', innerHTML: AI_ICON }),
      el('span', {}, el('strong', { textContent: texts.aiOption }), el('span', { className: 'wgl-choice__help', textContent: texts.aiOptionHelp }))
    )
    : null

  // Start from the original's content, or empty (starting-content.php): not
  // for a form or a term, or into the default language, always copies; and not
  // for a link, which keeps the page's content. AI, translating from the
  // original's, goes with it
  const withCopy = canEmpty === '1'
    ? el('label', { className: 'wgl-choice wgl-choice--ai wgl-dialog__copy' },
      el('input', {
        type: 'checkbox', className: 'wgl-choice__check', checked: true
      }),
      el('span', { className: 'dashicons dashicons-admin-page', ariaHidden: 'true' }),
      el('span', {}, el('strong', { textContent: texts.copyOption }), el('span', { className: 'wgl-choice__help', textContent: texts.copyHelp }))
    )
    : null
  const copying = () => !withCopy || withCopy.querySelector('input').checked

  // Link's search: the language's free pages, one picked for Create
  const input = el('input', {
    type: 'search', className: 'wgl-dialog__search', placeholder: texts.search, ariaLabel: texts.search
  })
  const results = el('ul', { className: 'wgl-dialog__results' })
  const search = el('div', { className: 'wgl-dialog__link', hidden: true }, input, results)

  const status = el('p', { className: 'wgl-dialog__progress', ariaLive: 'polite' })
  const errors = el('ul', { className: 'wgl-dialog__errors' })
  const create = el('button', {
    type: 'button', className: 'button button-primary wgl-dialog__create', textContent: texts.createOne
  })
  // Cancel; while AI translates, Stop (after the part it's on); once the
  // translation is made, Close (the list again, its row showing it)
  const cancel = el('button', {
    className: 'button', value: 'cancel', textContent: texts.cancel
  })

  dialog = el('dialog', { className: 'wgl-dialog' },
    el('form', { method: 'dialog', className: 'wgl-dialog__inner' },
      el('h2', { className: 'wgl-dialog__title', textContent: format(texts.title, name, title) }),
      el('div', { className: `wgl-choices wgl-choices--${copies.length}` }, ...copies, linking),
      withCopy,
      withAi,
      search,
      status,
      errors,
      el('div', { className: 'wgl-dialog__actions' }, cancel, create)
    )
  )

  let running = false
  let stopping = false
  let made = false
  let page = null
  let searched = false

  // a card picked: Link shows its search, and hides AI, which a link has
  // nothing to do with; Create waits for a page to be picked there
  const pick = picked => {
    dialog.querySelectorAll('button.wgl-choice').forEach(item => {
      item.classList.toggle('is-selected', item === picked)
      item.setAttribute('aria-pressed', item === picked ? 'true' : 'false')
    })

    const isLink = picked === linking

    search.hidden = !isLink

    if (withAi) {
      withAi.hidden = isLink || !copying()
    }

    if (withCopy) {
      withCopy.hidden = isLink
    }

    create.disabled = isLink && !page

    if (isLink && !searched) {
      searched = true
      find()
      input.focus()
    }
  }

  // a page of the search picked: what Create links
  const choose = (button, found) => {
    page = found
    results.querySelectorAll('.wgl-dialog__result').forEach(item => {
      item.classList.toggle('is-selected', item === button)
      item.setAttribute('aria-pressed', item === button ? 'true' : 'false')
    })
    create.disabled = false
  }

  let timer = null
  let asked = 0

  const find = () => {
    const ask = ++asked

    apiFetch({ path: addQueryArgs(`/wgl/v1/${kind}/${post}/candidates`, { lang, search: input.value.trim() }) })
      .then(pages => {
        // a slower answer to an older search: dropped
        if (ask !== asked) {
          return
        }

        // a new search drops the page picked
        page = null
        create.disabled = true

        results.replaceChildren(...(pages.length
          ? pages.map(found => {
            const button = el('button', {
              type: 'button',
              className: 'wgl-dialog__result',
              ariaPressed: 'false',
              textContent: found.title + (found.status !== 'publish' ? ` (${texts.draft})` : '')
            })

            button.addEventListener('click', () => choose(button, found))

            return el('li', {}, button)
          })
          : [ el('li', { className: 'wgl-dialog__empty', textContent: texts.noCandidates }) ]))
      })
      .catch(() => results.replaceChildren(el('li', { className: 'wgl-dialog__empty', textContent: texts.failed })))
  }

  input.addEventListener('input', () => {
    clearTimeout(timer)
    timer = setTimeout(find, 250)
  })

  // The page picked linked, then the list again: its row shows the translation.
  const link = () => {
    running = true
    create.disabled = true

    apiFetch({
      path: `/wgl/v1/${kind}/${post}/link`, method: 'POST', data: { other: page.id }
    })
      .then(() => window.location.reload())
      .catch(error => {
        running = false
        create.disabled = false
        status.textContent = error.message || texts.failed
      })
  }

  // With AI: the translation made (wgl/v1/…/translate), its untranslated
  // fields translated a chunk at a time, then its editor, as Create without
  // AI opens it. What failed is listed first, with a link to it.
  const translate = async mode => {
    const form = dialog.querySelector('form')

    running = true
    stopping = false
    create.disabled = true
    form.classList.add('is-busy')
    status.classList.add('is-running')
    status.textContent = texts.aiCreating
    cancel.textContent = texts.aiStop

    try {
      const created = await apiFetch({
        path: `/wgl/v1/${kind}/${post}/translate`, method: 'POST', data: { langs: [ lang ], mode }
      })
      const id = created.created?.[lang]

      if (!id) {
        throw new Error(Object.values(created.failed || {})[0] || texts.failed)
      }

      made = true

      const edit = new URL(created.edit[lang], window.location.href)
      edit.searchParams.set('wgl_created', kind === 'terms' ? 'term' : '1')

      const base = `/wgl/v1/${kind}/${id}`
      const { fields = [] } = await fieldsOf(base)
      const labels = Object.fromEntries(fields.map(field => [ field.key, field.label ]))
      const done = await translateFields({
        base,
        fields: fields.filter(field => field.untranslated),
        onProgress: (n, of) => { stopping || (status.textContent = format(texts.aiRunning, n, of)) },
        stopped: () => stopping
      })
      const failed = Object.entries(done.failed)

      if (!failed.length && !done.stopped) {
        window.location.assign(edit.toString())
        return
      }

      status.classList.remove('is-running')
      status.textContent = done.stopped
        ? format(texts.aiStopped, done.written.length)
        : format(texts.aiDone, done.written.length, failed.length)
      errors.replaceChildren(...failed.map(([ key, why ]) => el('li', { textContent: `${labels[key] || key}: ${why}` })))
      // made already: opened, not made again
      create.hidden = true
      cancel.textContent = texts.aiClose
      cancel.disabled = false
      form.querySelector('.wgl-dialog__actions').append(el('a', {
        className: 'button button-primary', href: edit.toString(), textContent: texts.aiOpen
      }))
    } catch (error) {
      status.classList.remove('is-running')
      status.textContent = error.message || texts.failed
      form.classList.remove('is-busy')
      create.disabled = false
      cancel.disabled = false
      cancel.textContent = made ? texts.aiClose : texts.cancel
    } finally {
      running = false
    }
  }

  dialog.addEventListener('click', event => {
    const picked = event.target.closest('button.wgl-choice')

    if (picked && !running) {
      pick(picked)
    }
  })

  // Create: the page picked linked; or a copy, with AI when it's ticked,
  // else the "+"'s own link for it, which makes it and opens it
  create.addEventListener('click', () => {
    const picked = dialog.querySelector('button.wgl-choice.is-selected')

    if (!picked || running) {
      return
    }

    if (picked === linking) {
      page && link()
    } else if (copying() && withAi?.querySelector('input').checked) {
      translate(picked.dataset.mode)
    } else {
      const url = new URL(picked.dataset.href, window.location.href)

      copying() || url.searchParams.set('copy', '0')
      window.location.assign(url.toString())
    }
  })

  withAi?.querySelector('input').addEventListener('change', event => remember(event.target.checked))
  withCopy?.querySelector('input').addEventListener('change', () => {
    if (withAi) {
      withAi.hidden = !copying()
    }
  })

  // Cancel while it translates: Stop, after the part the model is on; the
  // dialog stays, to say what was done
  cancel.addEventListener('click', event => {
    if (!running) {
      return
    }

    event.preventDefault()
    stopping = true
    cancel.disabled = true
    status.textContent = texts.aiStopping
  })

  // closed with a translation made (stopped, or with failures): the list
  // again, its row showing it
  dialog.addEventListener('close', () => {
    if (made) {
      window.location.reload()
    }
  })

  // not closed while it's making it
  dialog.addEventListener('cancel', event => {
    if (running) {
      event.preventDefault()
    }
  })

  // a click on the backdrop closes it, as Escape does (not while it's making it)
  dialog.addEventListener('click', event => {
    if (event.target === dialog && !running) {
      dialog.close()
    }
  })

  document.body.append(dialog)
  dialog.showModal()

  // the first card picked to start with
  pick(copies[0])
}

document.addEventListener('click', event => {
  const plus = event.target.closest('a.wgl-lang--missing[data-wgl-id]')

  if (!plus || event.metaKey || event.ctrlKey || event.shiftKey || typeof HTMLDialogElement === 'undefined') {
    return
  }

  event.preventDefault()
  open(plus)
})

/**
 * An item, read from its row's Languages cell: each "+" is a language it
 * lacks. Null for a row with none (every language there, or no language
 * yet).
 */
function itemOf (row) {
  const missing = [ ...(row?.querySelectorAll('a.wgl-lang--missing[data-wgl-id]') || []) ]

  if (!missing.length) {
    return null
  }

  const {
    wglId: id, wglKind: kind = 'posts', wglTitle: title
  } = missing[0].dataset

  return {
    id,
    kind,
    title,
    // a type with blocks: its "+" carries a fixed translation's link, and
    // (not a form) one with free fields
    canEmpty: missing.some(plus => plus.dataset.wglCopy === '1'),
    text: missing.some(plus => plus.dataset.wglText),
    blocks: missing.some(plus => plus.dataset.wglBlocks),
    langs: missing.map(plus => ({
      code: plus.dataset.wglLang,
      name: plus.dataset.wglLangName,
      isDefault: plus.dataset.wglDefault === '1',
      flag: plus.querySelector('.wgl-flag')
    }))
  }
}

/**
 * Translate items into the languages they lack: the languages (every one
 * some item lacks, ticked), and for a type with blocks an independent translation or a
 * fixed translation. Each item is asked of wgl/v1/{posts|terms}/<id>/translate
 * in turn, then the list is reloaded with the counts (bulk.php's notice).
 * What failed stays listed here first, and closing reloads.
 */
function translateMany (items) {
  dialog?.remove()

  // every language some item lacks, in the cells' order, with how many lack it
  const languages = new Map()

  items.forEach(item => item.langs.forEach(lang => {
    const known = languages.get(lang.code) || { ...lang, count: 0 }

    known.count++
    languages.set(lang.code, known)
  }))

  const fixed = items.some(item => item.text)
  const structure = items.some(item => item.blocks)
  const toDefault = [ ...languages.values() ].find(lang => lang.isDefault)

  const box = lang => el('label', { className: 'wgl-dialog__lang' },
    el('input', {
      type: 'checkbox', name: 'langs', value: lang.code, checked: true
    }),
    lang.flag ? lang.flag.cloneNode(true) : null,
    el('span', { textContent: lang.name }),
    items.length > 1 ? el('span', { className: 'wgl-dialog__count', textContent: format(texts.lacking, lang.count, items.length) }) : null
  )

  const radio = (value, label, help, icon) => el('label', { className: 'wgl-choice' },
    el('input', {
      type: 'radio', name: 'mode', value, checked: value === 'independent', className: 'wgl-choice__radio'
    }),
    el('span', { className: `dashicons ${icon}`, ariaHidden: 'true' }),
    el('span', {}, el('strong', { textContent: label }), el('span', { className: 'wgl-choice__help', textContent: help }))
  )

  const progress = el('p', { className: 'wgl-dialog__progress', ariaLive: 'polite' })
  const errors = el('ul', { className: 'wgl-dialog__errors' })
  const cancel = el('button', {
    className: 'button', value: 'cancel', textContent: texts.cancel
  })
  const create = el('button', {
    className: 'button button-primary', value: 'create', textContent: texts.create
  })

  const form = el('form', { method: 'dialog', className: 'wgl-dialog__inner' },
    el('h2', {
      className: 'wgl-dialog__title',
      textContent: items.length === 1 ? format(texts.translateOne, items[0].title) : format(texts.translateMany, items.length)
    }),
    el('fieldset', { className: 'wgl-dialog__langs' },
      el('legend', { className: 'wgl-dialog__subtitle', textContent: texts.into }),
      ...[ ...languages.values() ].map(box)
    ),
    fixed
      ? el('fieldset', { className: 'wgl-choices wgl-choices--radio' },
        el('legend', { className: 'wgl-dialog__subtitle', textContent: texts.as }),
        radio('independent', texts.independent, texts.independentHelp, 'dashicons-admin-page'),
        structure ? radio('blocks', texts.blocks, texts.blocksHelp, 'dashicons-layout') : null,
        radio('text', texts.text, texts.textHelp, 'dashicons-lock')
      )
      : null,
    items.some(item => item.canEmpty)
      ? el('label', { className: 'wgl-choice wgl-choice--ai wgl-dialog__copy' },
        el('input', {
          type: 'checkbox', name: 'copy', className: 'wgl-choice__check', checked: true
        }),
        el('span', { className: 'dashicons dashicons-admin-page', ariaHidden: 'true' }),
        el('span', {}, el('strong', { textContent: texts.copyOption }), el('span', { className: 'wgl-choice__help', textContent: texts.copyHelp }))
      )
      : null,
    toDefault ? el('p', { className: 'wgl-dialog__note', textContent: format(texts.intoDefault, toDefault.name) }) : null,
    fixed && items.some(item => !item.text) ? el('p', { className: 'wgl-dialog__note', textContent: texts.noText }) : null,
    progress,
    errors,
    el('div', { className: 'wgl-dialog__actions' }, cancel, create)
  )

  dialog = el('dialog', { className: 'wgl-dialog' }, form)

  let running = false
  let reload = null

  // the list, again, with the counts for the notice
  const listUrl = (created, failed) => {
    const url = new URL(window.location.href)

    url.searchParams.delete('wgl_translated')
    url.searchParams.delete('wgl_failed')
    url.searchParams.set('wgl_translated', created)

    if (failed) {
      url.searchParams.set('wgl_failed', failed)
    }

    return url.toString()
  }

  form.addEventListener('submit', async event => {
    if (event.submitter?.value !== 'create') {
      return
    }

    event.preventDefault()

    const langs = [ ...form.querySelectorAll('input[name="langs"]:checked') ].map(input => input.value)

    if (!langs.length) {
      progress.textContent = texts.noLanguage
      return
    }

    const mode = form.querySelector('input[name="mode"]:checked')?.value || 'independent'
    const copy = form.querySelector('input[name="copy"]')?.checked ?? true
    const todo = items
      .map(item => ({ ...item, codes: item.langs.map(lang => lang.code).filter(code => langs.includes(code)) }))
      .filter(item => item.codes.length)
    let created = 0
    let failed = 0

    running = true
    form.querySelectorAll('input, button').forEach(node => { node.disabled = true })

    for (const [ index, item ] of todo.entries()) {
      progress.textContent = format(texts.creating, index + 1, todo.length)

      try {
        const done = await apiFetch({
          path: `/wgl/v1/${item.kind}/${item.id}/translate`, method: 'POST', data: { langs: item.codes, mode, copy }
        })

        created += Object.keys(done.created || {}).length

        Object.entries(done.failed || {}).forEach(([ code, message ]) => {
          failed++
          errors.append(el('li', { textContent: `${item.title} → ${languages.get(code)?.name || code}: ${message}` }))
        })
      } catch (error) {
        failed += item.codes.length
        errors.append(el('li', { textContent: `${item.title}: ${error.message || texts.failed}` }))
      }
    }

    running = false
    reload = listUrl(created, failed)

    if (!failed) {
      window.location.assign(reload)
      return
    }

    // what failed, read before the list comes back
    progress.textContent = format(texts.done, created, failed)
    cancel.disabled = false
    cancel.textContent = texts.close
    cancel.focus()
  })

  // not while it's making them; closed after, the list comes back
  dialog.addEventListener('cancel', event => {
    if (running) {
      event.preventDefault()
    }
  })

  dialog.addEventListener('close', () => {
    if (reload) {
      window.location.assign(reload)
    }
  })

  dialog.addEventListener('click', event => {
    if (event.target === dialog && !running) {
      dialog.close()
    }
  })

  document.body.append(dialog)
  dialog.showModal()
}

document.addEventListener('click', event => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || typeof HTMLDialogElement === 'undefined') {
    return
  }

  // a row's "Translate"
  const action = event.target.closest('a.wgl-translate[data-wgl-id]')
  const item = action && itemOf(action.closest('tr'))

  if (item) {
    event.preventDefault()
    translateMany([ item ])
    return
  }

  // "Translate…" applied to the rows ticked
  const apply = event.target.closest('#doaction, #doaction2')
  const select = apply && document.getElementById(apply.id === 'doaction' ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom')

  if (select?.value !== 'wgl_translate') {
    return
  }

  event.preventDefault()

  const items = [ ...document.querySelectorAll('#the-list .check-column input[type="checkbox"]:checked') ]
    .map(box => itemOf(box.closest('tr')))
    .filter(Boolean)

  if (items.length) {
    translateMany(items)
  } else {
    window.alert(texts.nothingToTranslate)
  }
})

/**
 * A row's translations, from its Languages cell, those in the Trash left
 * out: what moving it to the Trash may take along.
 */
function translationsOf (row) {
  return [ ...(row?.querySelectorAll('td.column-wgl_languages a.wgl-lang[data-wgl-translation]') || []) ]
    .filter(link => link.dataset.wglStatus !== 'trash')
    .map(link => ({ id: link.dataset.wglTranslation, name: link.dataset.wglLangName }))
}

/**
 * Only these, or their translations too: `only` and `all` go on with
 * WordPress's own request, the second with `wgl_all=1` (trash.php). Each is
 * a link's address (a row's), or what submits the bulk form.
 */
function askTrash ({
  title, count, translations, only, all
}) {
  dialog?.remove()

  const names = [ ...new Set(translations.map(translation => translation.name)) ].join(', ')

  // a link for a row (WordPress's own address), a button for the bulk form
  const choice = (label, help, icon, go) => {
    const props = typeof go === 'string' ? { href: go } : { type: 'button', onclick: go }

    return el(typeof go === 'string' ? 'a' : 'button', { className: 'wgl-choice', ...props },
      el('span', { className: `dashicons ${icon}`, ariaHidden: 'true' }),
      el('span', {}, el('strong', { textContent: label }), el('span', { className: 'wgl-choice__help', textContent: help }))
    )
  }

  dialog = el('dialog', { className: 'wgl-dialog' },
    el('form', { method: 'dialog', className: 'wgl-dialog__inner' },
      el('h2', { className: 'wgl-dialog__title', textContent: count === 1 ? format(texts.trashOne, title) : format(texts.trashMany, count) }),
      el('div', { className: 'wgl-choices' },
        choice(count === 1 ? texts.trashOnly : texts.trashOnlyThese, texts.trashOnlyHelp, 'dashicons-trash', only),
        choice(texts.trashAll, format(texts.trashAllHelp, translations.length, names), 'dashicons-translation', all)
      ),
      el('div', { className: 'wgl-dialog__actions' },
        el('button', {
          className: 'button', value: 'cancel', textContent: texts.cancel
        })
      )
    )
  )

  dialog.addEventListener('click', event => {
    if (event.target === dialog) {
      dialog.close()
    }
  })

  document.body.append(dialog)
  dialog.showModal()
}

document.addEventListener('click', event => {
  if (event.metaKey || event.ctrlKey || event.shiftKey || typeof HTMLDialogElement === 'undefined') {
    return
  }

  // a row's "Trash" (not "Delete Permanently", in the Trash)
  const link = event.target.closest('#the-list .row-actions a.submitdelete')

  if (link && new URL(link.href, window.location.href).searchParams.get('action') === 'trash') {
    const row = link.closest('tr')
    const translations = translationsOf(row)

    if (!translations.length) {
      return
    }

    event.preventDefault()

    const all = new URL(link.href, window.location.href)
    all.searchParams.set('wgl_all', '1')

    askTrash({
      title: row.querySelector('a.wgl-lang--missing, a.wgl-translate')?.dataset.wglTitle || row.querySelector('.row-title')?.textContent.trim() || '',
      count: 1,
      translations,
      only: link.href,
      all: all.toString()
    })

    return
  }

  // "Move to Trash" applied to the rows ticked
  const apply = event.target.closest('#doaction, #doaction2')
  const select = apply && document.getElementById(apply.id === 'doaction' ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom')

  if (select?.value !== 'trash') {
    return
  }

  const rows = [ ...document.querySelectorAll('#the-list .check-column input[type="checkbox"]:checked') ].map(box => box.closest('tr'))
  const ticked = new Set(rows.map(row => row.querySelector('.check-column input')?.value))

  // each translation once, those ticked too left out: they go anyway
  const translations = [
    ...new Map(rows.flatMap(translationsOf)
      .filter(translation => !ticked.has(translation.id))
      .map(translation => [ translation.id, translation ])).values()
  ]

  if (!translations.length) {
    return
  }

  event.preventDefault()

  const form = apply.closest('form')

  askTrash({
    title: '',
    count: rows.length,
    translations,
    only: () => form.submit(),
    all: () => {
      form.append(el('input', {
        type: 'hidden', name: 'wgl_all', value: '1'
      }))
      form.submit()
    }
  })
})
