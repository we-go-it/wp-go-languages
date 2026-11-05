/**
 * "Translate empty with AI", on both tabs of the Translation strings screen
 * (src/SiteTexts/screen.php, wp_go_site_texts_render_ai_button()): the empty
 * translations of the rows shown filled in by the AI endpoint
 * (wgl/v1/ai/texts, src/Admin/ai.php), as "Suggest a translation" fills one.
 *
 * Every field shown that's empty, in the language picked in the admin bar,
 * whose source has text (its cell's `data-source`): on Site strings, the
 * default language's text; on Admin strings, the English. The texts go in
 * chunks, one request after the other (a local model takes seconds per text).
 * Each translation is put in its field, marked as a suggestion and as changed:
 * nothing is saved until Save, as for anything typed.
 *
 * Its texts, the route and its nonce come from PHP, as window.wgSiteTextsAi
 * (wp_go_site_texts_ai_script_data()). WP-Go's copy of the screen has no
 * AI: this file is WP-Go Languages' alone.
 */
(function () {
  const { texts = {}, route = '', nonce = '' } = window.wgSiteTextsAi || {}
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
  const format = (text, ...values) => values.reduce((out, value, i) => out.replace(`%${i + 1}$s`, () => value).replace('%s', () => value), text || '')

  const control = cell => cell.querySelector('.wg-site-texts__field input, .wg-site-texts__field textarea')

  /**
   * What to translate: the empty fields shown, each with its source, its
   * target language (`data-code` on Admin strings, whose `data-lang` is a
   * locale) and a name for the errors. On the default language's Site strings
   * there's no source: nothing.
   */
  function empties () {
    const items = []

    table.querySelectorAll('.wg-site-texts__cell').forEach(cell => {
      const text = cell.dataset.source || ''
      const input = control(cell)

      if (!text.trim() || !input || input.value !== '') {
        return
      }

      const to = cell.dataset.code || cell.dataset.lang

      items.push({
        cell, text, from: cell.dataset.from, to, name: (cell.closest('tr').id || text) + ' (' + to + ')'
      })
    })

    return items
  }

  // the items, cut into requests
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
   * A translation into its cell, as a suggestion: the value set, then `input`
   * (site-texts.js marks it changed and counts it) and only then `is-suggested`,
   * which that same event would take off.
   */
  function fill (cell, value) {
    const input = control(cell)

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
  function askFirst (items) {
    const to = items[0].to
    const named = table.querySelector(`thead th[data-column="lang-${to}"]`)?.dataset.name || to
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
        el('p', { textContent: format(plural(texts.confirm, items.length), items.length, named) }),
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

      const done = await translate(items, (n, of) => {
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
   * The texts asked a request at a time, each translation put in its cell;
   * `stopped()` asked before each request.
   */
  async function translate (items, onProgress, stopped) {
    const all = chunks(items)
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
    askFirst(items)
  })
}())
