/**
 * "Translate empty with AI", on both tabs of the Translation strings screen
 * (src/SiteTexts/screen.php, wp_go_site_texts_render_ai_button()): the empty
 * translations of the rows shown filled in by the AI endpoint
 * (wgl/v1/ai/texts, src/Admin/ai.php), as "Suggest a translation" fills one.
 *
 * For each language column shown (not hidden in Screen Options) but the
 * source's, every cell with no value whose source has text: on Site
 * strings, the default language's text; on Admin strings, the English. The
 * texts go a target language at a time, in chunks, one request after the
 * other (a local model takes seconds per text). Each translation is put in
 * its cell's field, opened, marked as a suggestion and as changed: nothing
 * is saved until Save, as for anything typed.
 *
 * Its texts, the route and its nonce come from PHP, as window.wgSiteTextsAi
 * (wp_go_site_texts_ai_script_data()). WP-Go's copy of the screen has no
 * AI: this file is WP-Go Languages' alone, site-texts.js stays the same in
 * both.
 */
(function () {
  const {
    texts = {}, route = '', nonce = '', fallback = ''
  } = window.wgSiteTextsAi || {}
  const button = document.querySelector('.wg-site-texts__ai')
  const table = document.querySelector('.wg-site-texts__table')
  const status = document.querySelector('.wg-site-texts__ai-status')
  const errors = document.querySelector('.wg-site-texts__ai-errors')

  if (!button || !table) {
    return
  }

  // a chunk: so many texts, so long a source at most, answered within a web
  // server's usual 60 s for PHP (as src/Admin/_ai.js's)
  const MAX_TEXTS = 15
  const MAX_CHARS = 1200

  // a count in words: `one` for 1, `many` (%s the number) otherwise
  const plural = (text, n) => n === 1 ? text.one : text.many.replace('%s', n)
  const format = (text, ...values) => values.reduce((out, value, i) => out.replace(`%${i + 1}$s`, value).replace('%s', value), text || '')

  // the language of a cell's column: its Screen Options column, `column-lang-<code>`
  const columnOf = cell => [ ...cell.classList ].find(name => name.startsWith('column-lang-'))?.slice('column-lang-'.length) || ''

  /**
   * What to translate: the empty cells of the columns shown, each with its
   * source, its target language and a name for the errors.
   */
  function empties () {
    const admin = !!table.querySelector('.wg-site-texts__source')
    const items = []

    table.querySelectorAll('tbody tr').forEach(row => {
      const source = admin
        ? row.querySelector('.wg-site-texts__source')
        : row.querySelector(`.wg-site-texts__cell[data-lang="${fallback}"]`)
      const text = source?.dataset.value || ''

      if (!source || !text.trim()) {
        return
      }

      row.querySelectorAll('.wg-site-texts__cell').forEach(cell => {
        const to = admin ? columnOf(cell) : cell.dataset.lang

        if (cell === source || cell.classList.contains('hidden') || !to || (cell.dataset.value || '') !== '' || !cell.querySelector('.wg-site-texts__field')) {
          return
        }

        items.push({
          cell,
          text,
          from: admin ? 'en' : fallback,
          to,
          name: (admin ? text : row.id) + ' (' + to + ')'
        })
      })
    })

    return items
  }

  // the items of one language, cut into requests
  function chunks (items) {
    const out = []
    let chunk = []
    let chars = 0

    items.forEach(item => {
      if (chunk.length && (chunk.length >= MAX_TEXTS || chars + item.text.length > MAX_CHARS)) {
        out.push(chunk)
        chunk = []
        chars = 0
      }

      chunk.push(item)
      chars += item.text.length
    })

    if (chunk.length) {
      out.push(chunk)
    }

    return out
  }

  /**
   * A translation into its cell, as a suggestion: the field opened as
   * editing() opens it, the value set, then `input` (site-texts.js marks it
   * changed and counts it) and only then `is-suggested`, which that same
   * event would take off.
   */
  function fill (cell, value) {
    const view = cell.querySelector('.wg-site-texts__view')
    const field = cell.querySelector('.wg-site-texts__field')
    const input = field.querySelector('input, textarea')

    if (view) {
      input.style.height = view.offsetHeight + 'px'
      view.hidden = true
    }

    field.hidden = false
    input.disabled = false
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
    input.classList.add('is-suggested')
  }

  async function ask (chunk) {
    const body = {
      texts: {}, from: chunk[0].from, to: chunk[0].to
    }

    chunk.forEach((item, i) => {
      body.texts['t' + i] = item.text
    })

    const response = await fetch(route, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify(body)
    })
    const answer = await response.json().catch(() => ({}))

    if (!response.ok) {
      throw new Error(answer.message || response.statusText || String(response.status))
    }

    return answer
  }

  const line = text => {
    const item = document.createElement('li')
    item.textContent = text
    errors.append(item)
  }

  const el = (tag, props = {}, ...children) => {
    const node = Object.assign(document.createElement(tag), props)
    node.append(...children.filter(Boolean))

    return node
  }

  /**
   * The dialog, as the lists' and the editor's: what it will do and
   * Translate; then it stays, a spinner and how far it got, Cancel turned
   * Stop (after the request the model is on). Done or stopped, it closes,
   * and the line under the title says what was filled in.
   */
  function askFirst (items, languages) {
    const named = languages.map(to => table.querySelector(`thead th[data-column="lang-${to}"]`)?.firstChild?.textContent.trim() || to)
    const progress = el('p', { className: 'wg-site-texts__ai-progress', ariaLive: 'polite' })
    const cancel = el('button', {
      type: 'button', className: 'button', textContent: texts.cancel
    })
    const go = el('button', {
      type: 'button', className: 'button button-primary', textContent: texts.translate
    })
    const dialog = el('dialog', { className: 'wg-site-texts__modal wg-site-texts__ai-dialog' },
      el('div', { className: 'wg-site-texts__details-body' },
        el('h2', { textContent: texts.title }),
        el('p', { textContent: format(plural(texts.confirm, items.length), items.length, named.join(', ')) }),
        progress,
        el('div', { className: 'wg-site-texts__modal-actions' }, cancel, go)
      )
    )

    let running = false
    let stopping = false

    // not closed while it translates: Stop is the way
    dialog.addEventListener('cancel', event => {
      if (running) {
        event.preventDefault()
      }
    })

    dialog.addEventListener('close', () => dialog.remove())

    cancel.addEventListener('click', () => {
      if (!running) {
        dialog.close()
        return
      }

      stopping = true
      cancel.disabled = true
      progress.textContent = texts.stopping
    })

    go.addEventListener('click', async () => {
      running = true
      go.hidden = true
      cancel.textContent = texts.stop
      progress.classList.add('is-running')
      button.disabled = true
      errors.replaceChildren()

      const done = await translate(items, languages, (n, of) => {
        stopping || (progress.textContent = format(texts.running, n, of))
      }, () => stopping)

      running = false
      button.disabled = false
      dialog.close()

      status.hidden = false
      status.textContent = [ done.stopped ? plural(texts.stopped, done.filled) : done.filled ? plural(texts.done, done.filled) : '', done.failed ? plural(texts.failed, done.failed) : '' ].filter(Boolean).join(' ') || texts.nothing
    })

    document.body.append(dialog)
    dialog.showModal()
  }

  /**
   * The texts asked a request at a time, a language after the other, each
   * translation put in its cell; `stopped()` asked before each request.
   */
  async function translate (items, languages, onProgress, stopped) {
    const all = languages.flatMap(to => chunks(items.filter(item => item.to === to)))
    const done = {
      filled: 0, failed: 0, stopped: false
    }

    for (const [ index, chunk ] of all.entries()) {
      if (stopped()) {
        done.stopped = true
        break
      }

      onProgress(index + 1, all.length)

      let answer

      try {
        answer = await ask(chunk)
      } catch (error) {
        // the endpoint itself: no use asking it the rest
        line(format(texts.error, error.message))
        done.failed += all.slice(index).reduce((n, rest) => n + rest.length, 0)
        break
      }

      chunk.forEach((item, i) => {
        const value = answer.translated?.['t' + i]

        if (typeof value === 'string' && value !== '') {
          fill(item.cell, value)
          done.filled++
        } else {
          done.failed++
          line(item.name + ': ' + (answer.failed?.['t' + i] || texts.missing))
        }
      })
    }

    return done
  }

  button.addEventListener('click', () => {
    const items = empties()

    errors.replaceChildren()

    if (!items.length) {
      status.hidden = false
      status.textContent = texts.nothing
      return
    }

    status.hidden = true
    askFirst(items, [ ...new Set(items.map(item => item.to)) ])
  })
}())
