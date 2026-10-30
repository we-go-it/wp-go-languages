/**
 * The Translation strings screen (src/SiteTexts/screen.php).
 *
 *   editing()      a text turns into its field when clicked
 *   removing()     the trash buttons and "Remove selected" ask first
 *   suggesting()   "Suggest a translation", with the browser's Translator
 *   details()      a key's details, when its key is clicked
 *   addDialog()    the "Add a key" dialog
 *   columns()      sorting by a column, and the width as columns are hidden
 *
 * Its texts and the Cache Tags address come from PHP, as window.wgSiteTexts
 * (wp_go_site_texts_script_data()).
 */
(function () {
  const { texts = {}, cache = null } = window.wgSiteTexts || {}
  const form = document.querySelector('.wg-site-texts__form')
  const table = form?.querySelector('.wg-site-texts__table')

  // a count in words: `one` for 1, `many` (%s the number) otherwise
  const plural = (text, n) => n === 1 ? text.one : text.many.replace('%s', n)

  /**
   * A text is shown as text, and becomes its field when clicked. The field is
   * disabled until then, so a save posts only the texts opened. Left
   * unchanged, it goes back to text; changed, it stays open and marked until
   * saved. Escape puts the text back as it was.
   */
  function editing () {
    const save = form.querySelector('[name="submit"]')
    const status = form.querySelector('.wg-site-texts__changed')
    let submitting = false

    const control = cell => cell.querySelector('.wg-site-texts__field input, .wg-site-texts__field textarea')

    // how many texts (and domains) are changed and not saved, said next to Save
    const count = () => {
      const changed = table.querySelectorAll('.wg-site-texts__cell.is-edited, .wg-site-texts__domain.is-edited').length

      if (status) {
        status.textContent = changed ? plural(texts.changed, changed) : ''
      }

      return changed
    }

    const open = cell => {
      const input = control(cell)
      const view = cell.querySelector('.wg-site-texts__view')

      // as tall as the text it replaces, wrapped lines included
      input.style.height = view.offsetHeight + 'px'
      view.hidden = true
      cell.querySelector('.wg-site-texts__field').hidden = false
      input.disabled = false
      input.focus()
      input.setSelectionRange(input.value.length, input.value.length)
    }

    const close = cell => {
      const view = cell.querySelector('.wg-site-texts__view')

      cell.querySelector('.wg-site-texts__field').hidden = true
      cell.querySelector('.wg-site-texts__note').textContent = ''
      control(cell).disabled = true
      view.hidden = false

      return view
    }

    const mark = cell => {
      cell.classList.toggle('is-edited', control(cell).value !== cell.dataset.value)
      count()
    }

    table.addEventListener('click', event => {
      const view = event.target.closest('.wg-site-texts__view')

      if (view) {
        open(view.closest('.wg-site-texts__cell'))
      }
    })

    table.addEventListener('input', event => {
      const cell = event.target.closest('.wg-site-texts__cell')

      if (cell) {
        mark(cell)
      }
    })

    // a key moved to another domain, by an administrator: saved with Save
    // (each goes with the domain it showed on load, so the save moves only
    // those changed here: domains_was, in screen.php)
    table.querySelectorAll('.wg-site-texts__domain select').forEach(select => {
      const was = select.value

      select.addEventListener('change', () => {
        select.parentElement.classList.toggle('is-edited', select.value !== was)
        count()
      })
    })

    // leaving a field closes it if unchanged, but not for its own suggest button
    table.addEventListener('focusout', event => {
      const cell = event.target.closest('.wg-site-texts__cell')

      if (!cell || !event.target.matches('input, textarea') || cell.contains(event.relatedTarget)) {
        return
      }

      mark(cell)

      if (!cell.classList.contains('is-edited')) {
        close(cell)
      }
    })

    table.addEventListener('keydown', event => {
      const cell = event.target.closest('.wg-site-texts__cell')

      if (!cell || !event.target.matches('input, textarea')) {
        return
      }

      if (event.key === 'Escape') {
        event.preventDefault()
        control(cell).value = cell.dataset.value
        control(cell).classList.remove('is-suggested')
        mark(cell)
        close(cell).focus()
      }

      // Enter in a one-line field saves, with Save: the form's first submit
      // button would be a row's trash button
      if (event.key === 'Enter' && event.target.tagName === 'INPUT') {
        event.preventDefault()
        form.requestSubmit(save)
      }
    })

    form.addEventListener('submit', () => {
      submitting = true
    })

    // changes not saved, on leaving the page
    window.addEventListener('beforeunload', event => {
      if (!submitting && count()) {
        event.preventDefault()
        event.returnValue = ''
      }
    })

    // the checkbox column's "select all"
    const all = table.querySelector('.wg-site-texts__select-all')

    all?.addEventListener('change', () => {
      table.querySelectorAll('.wg-site-texts__select').forEach(box => {
        box.checked = all.checked
      })
    })
  }

  /**
   * Removing asks first: a row's trash button with what removing that key
   * does (its `data-confirm`), "Remove selected" with how many are ticked.
   */
  function removing () {
    form.addEventListener('click', event => {
      const one = event.target.closest('.wg-site-texts__remove')

      if (one && !window.confirm(one.dataset.confirm)) {
        event.preventDefault()
      }

      const selected = event.target.closest('.wg-site-texts__remove-selected')

      if (selected) {
        const ticked = form.querySelectorAll('.wg-site-texts__select:checked').length

        if (!ticked || !window.confirm(plural(texts.removeSelected, ticked))) {
          event.preventDefault()
        }
      }
    })
  }

  /**
   * "Suggest a translation", inside each open field but the default
   * language's: the default language's text as it is now (its field, if
   * open), translated into the field's language by the browser. Only where
   * the browser has the Translator API (Chrome 138+ on desktop).
   */
  function suggesting () {
    if (!('Translator' in self)) {
      return
    }

    // the languages' codes and locales (pt-br, it, it_IT) to the browser's (pt, it)
    const lang = code => code.split(/[-_]/)[0]
    // one translator per language pair, kept for the page's life
    const translators = {}

    // the note under the field each language pair is suggesting for now: the
    // model downloads once per pair, and its progress shows there, not under
    // the field that first asked
    const notes = {}

    table.querySelectorAll('.wg-site-texts__suggest').forEach(button => {
      button.hidden = false
    })

    // a suggestion edited is the editor's own text
    table.addEventListener('input', event => {
      event.target.classList.remove('is-suggested')
    })

    // pressing the button keeps the focus in its field, which stays open
    table.addEventListener('mousedown', event => {
      if (event.target.closest('.wg-site-texts__suggest')) {
        event.preventDefault()
      }
    })

    table.addEventListener('click', async event => {
      const button = event.target.closest('.wg-site-texts__suggest')

      if (!button) {
        return
      }

      const field = button.parentElement.querySelector('input, textarea')
      const note = button.closest('td').querySelector('.wg-site-texts__note')
      const origin = button.closest('tr').querySelector(`.wg-site-texts__cell[data-lang="${button.dataset.from}"]`)
      const opened = origin?.querySelector('.wg-site-texts__field input:enabled, .wg-site-texts__field textarea:enabled')
      const source = (opened ? opened.value : origin?.dataset.value ?? '').trim()
      const from = lang(button.dataset.from)
      const to = lang(button.dataset.to)
      const pair = from + ':' + to

      if (source === '') {
        note.textContent = texts.noSource
        return
      }

      button.disabled = true
      note.textContent = ''
      notes[pair] = note

      try {
        let shown = null

        translators[pair] ??= Translator.create({
          sourceLanguage: from,
          targetLanguage: to,
          monitor: monitor => monitor.addEventListener('downloadprogress', progress => {
            // moved on to another field: the progress leaves the previous one
            if (shown && shown !== notes[pair]) {
              shown.textContent = ''
            }

            shown = notes[pair]
            shown.textContent = progress.loaded < 1
              ? texts.download.replace('%d', Math.round(progress.loaded * 100)).replace('%%', '%')
              : ''
          })
        })

        const suggestion = await (await translators[pair]).translate(source)

        // typed in, so Cmd+Z brings back what was there
        field.focus()
        field.select()

        if (!document.execCommand('insertText', false, suggestion)) {
          field.value = suggestion
          field.dispatchEvent(new Event('input', { bubbles: true }))
        }

        field.classList.add('is-suggested')
        note.textContent = ''
      } catch (error) {
        delete translators[pair]

        // what the browser says, for whoever has to find out why
        const availability = await Translator.availability({ sourceLanguage: from, targetLanguage: to }).catch(() => '?')

        console.error('Translator', {
          from, to, availability, error
        })
        note.textContent = `${texts.cannot} (${from} → ${to}: ${availability}; ${error.name}: ${error.message})`
      } finally {
        button.disabled = false
      }
    })
  }

  /**
   * Which cached pages print a key, asked of Cache Tags each time the
   * details open (a key is its own "file" there, in the site-texts domain).
   * Shown in `list`, unless the dialog moved on to another key meanwhile.
   */
  async function cachedPages (key, list) {
    // pages listed at most: the count is always the whole
    const LIMIT = 50

    // the path, and the query string a REST answer is kept by
    const path = url => {
      try {
        const { pathname, search } = new URL(url)
        return pathname + search
      } catch {
        return url
      }
    }

    const render = result => {
      if (result.global) {
        list.textContent = texts.global
        return
      }

      if (!result.known) {
        list.textContent = texts.unknown
        return
      }

      if (!result.count) {
        list.textContent = texts.none
        return
      }

      const items = result.pages.slice(0, LIMIT).map(url => {
        const item = document.createElement('li')
        const link = document.createElement('a')
        link.href = url
        link.target = '_blank'
        link.rel = 'noreferrer'
        link.textContent = path(url)
        item.append(link)
        return item
      })

      if (result.count > LIMIT) {
        const more = document.createElement('li')
        more.textContent = texts.more.replace('%d', result.count - LIMIT)
        items.push(more)
      }

      const ul = document.createElement('ul')
      ul.append(...items)
      list.replaceChildren(plural(texts.count, result.count), ul)
    }

    const url = new URL(cache.endpoint)
    url.searchParams.set('domain', cache.domain)
    url.searchParams.set('context', key)
    list.textContent = texts.loading

    try {
      const response = await fetch(url, { headers: { 'X-WP-Nonce': cache.nonce }, credentials: 'same-origin' })

      if (!response.ok) {
        throw response.status
      }

      if (list.dataset.key === key) {
        render(await response.json())
      }
    } catch {
      if (list.dataset.key === key) {
        list.textContent = texts.failed
      }
    }
  }

  /**
   * A key's details, when its key is clicked: its group, where the code
   * prints it (as of the last scan), its texts, and where it's cached. The
   * row's own buttons and fields keep their clicks.
   */
  function details () {
    const dialog = document.querySelector('.wg-site-texts__details')

    if (!dialog) {
      return
    }

    const entry = (term, ...nodes) => {
      const dt = document.createElement('dt')
      const dd = document.createElement('dd')
      dt.textContent = term
      dd.append(...nodes)
      dialog.querySelector('dl').append(dt, dd)
      return dd
    }

    table.addEventListener('click', event => {
      const cell = event.target.closest('.wg-site-texts__key')

      if (!cell || event.target.closest('a, button, input, label')) {
        return
      }

      const row = JSON.parse(cell.closest('tr').dataset.details)

      dialog.querySelector('h2').textContent = row.key
      dialog.querySelector('dl').replaceChildren()
      entry(texts.group, row.group)
      entry(texts.domain, row.domain)

      if (row.places.length) {
        const list = document.createElement('ul')

        row.places.forEach(place => {
          const item = document.createElement('li')
          const code = document.createElement('code')
          code.textContent = place
          item.append(code)
          list.append(item)
        })

        const note = document.createElement('span')
        note.className = 'description'
        note.textContent = texts.asOf
        entry(texts.printed, list, note)
      } else {
        entry(texts.printed, texts.nowhere).classList.add('is-empty')
      }

      Object.entries(row.texts).forEach(([ language, value ]) => {
        entry(language, value || texts.empty).classList.toggle('is-empty', !value)
      })

      if (cache) {
        const cached = entry(texts.cached)
        cached.dataset.key = row.key
        cachedPages(row.key, cached)
      }

      dialog.showModal()
    })

    dialog.querySelector('.wg-site-texts__details-close').addEventListener('click', () => dialog.close())
  }

  /**
   * The columns. A click on a header sorts the rows by that column, within
   * each group: A→Z, then Z→A, empty texts last either way. The order is
   * kept per tab in this browser, for the next visit. And as languages are
   * hidden or shown in Screen Options (WordPress hides their cells itself),
   * the table's width follows: sideways scrolling past two (`is-wide`).
   */
  function columns () {
    const head = table.tHead.rows[0]
    const scroll = table.closest('.wg-site-texts__scroll')
    const stored = 'wgSiteTexts.sort.' + (new URLSearchParams(location.search).get('tab') || 'site')
    const collator = new Intl.Collator(document.documentElement.lang || undefined, { numeric: true, sensitivity: 'base' })
    const headers = [ ...head.querySelectorAll('th[data-column]') ]

    // what a cell is sorted by: a text as saved, or the key
    const value = cell => cell?.dataset.value ?? cell?.querySelector('code')?.textContent.trim() ?? ''

    const sort = (th, direction) => {
      const index = [ ...head.cells ].indexOf(th)

      for (const body of table.tBodies) {
        // not a group's heading, nor "No key matches."
        const rows = [ ...body.rows ].filter(row => row.cells.length > 1)

        rows.sort((a, b) => {
          const x = value(a.cells[index])
          const y = value(b.cells[index])

          if (!x !== !y) {
            return x ? -1 : 1
          }

          return direction === 'asc' ? collator.compare(x, y) : collator.compare(y, x)
        })

        body.append(...rows)
      }

      // as WordPress's list tables say it: `sorted asc|desc` for the column
      // sorted by, `sortable desc` for the others (a click sorts A→Z)
      headers.forEach(other => {
        const sorted = other === th

        other.classList.remove('sorted', 'sortable', 'asc', 'desc')
        other.classList.add(sorted ? 'sorted' : 'sortable', sorted ? direction : 'desc')
        other.setAttribute('aria-sort', sorted ? (direction === 'asc' ? 'ascending' : 'descending') : 'none')
      })

      try {
        localStorage.setItem(stored, JSON.stringify({ column: th.dataset.column, direction }))
      } catch {}
    }

    headers.forEach(th => {
      const link = document.createElement('a')
      const label = document.createElement('span')
      const indicators = document.createElement('span')

      link.href = '#'
      label.append(...th.childNodes)
      indicators.className = 'sorting-indicators'
      indicators.innerHTML = '<span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span>'
      link.append(label, indicators)
      th.append(link)
      th.classList.add('sortable', 'desc')
      th.setAttribute('aria-sort', 'none')

      link.addEventListener('click', event => {
        event.preventDefault()
        sort(th, th.classList.contains('sorted') && th.classList.contains('asc') ? 'desc' : 'asc')
      })
    })

    // the last order, if its column is still there
    let last = null

    try {
      last = JSON.parse(localStorage.getItem(stored) || 'null')
    } catch {}

    const column = last && headers.find(th => th.dataset.column === last.column)

    if (column) {
      sort(column, last.direction === 'desc' ? 'desc' : 'asc')

      // the row the address points to (a key just added) moved: back to it
      if (location.hash) {
        document.getElementById(decodeURIComponent(location.hash.slice(1)))?.scrollIntoView({ block: 'center' })
      }
    }

    // a language hidden or shown in Screen Options: after WordPress's own
    // handler, which hides the cells and fixes the colspans
    document.addEventListener('change', event => {
      if (!scroll || !event.target.matches('.hide-column-tog')) {
        return
      }

      const shown = head.querySelectorAll('.wg-site-texts__col-lang:not(.hidden)').length

      scroll.style.setProperty('--wg-site-texts-langs', shown)
      scroll.classList.toggle('is-wide', shown > 2)
    })
  }

  /**
   * "Add a key": the title's button opens it, and it opens again by itself
   * when the key written was refused (`data-open`).
   */
  function addDialog () {
    const dialog = document.querySelector('.wg-site-texts__add')

    if (!dialog) {
      return
    }

    document.querySelector('.wg-site-texts__add-open')?.addEventListener('click', () => dialog.showModal())
    dialog.querySelector('.wg-site-texts__modal-cancel').addEventListener('click', () => dialog.close())

    if (dialog.hasAttribute('data-open')) {
      dialog.showModal()
    }
  }

  // The dialog works without the table (no keys yet); the rest needs it.
  addDialog()

  if (table) {
    editing()
    removing()
    suggesting()
    details()
    columns()
  }
}())
