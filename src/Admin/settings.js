/**
 * The Languages screen's table: "Add a language", a search over every
 * language WordPress is translated into (locales.php), each with its flag,
 * adds a row filled in (code, locale, name, slug), from the
 * `#wgl-language-row` template; a row's ✕ removes it. Save keeps both. The
 * flag beside a locale follows it as it's typed.
 * Data from settings.php:
 * window.wglSettings = { locales: [{ locale, english, native, code, flag }], texts }.
 */

const { locales = [], texts = {} } = window.wglSettings || {}

const table = document.querySelector('.wgl-languages tbody')
const template = document.getElementById('wgl-language-row')
const picker = document.getElementById('wgl-add-language')

if (table && template && picker) {
  const input = picker.querySelector('input')
  const list = picker.querySelector('ul')
  let matches = []
  let active = -1

  // the codes and locales in the table now, typed or picked
  const taken = field => [ ...table.querySelectorAll(`input[name$="[${field}]"]`) ].map(el => el.value.trim().toLowerCase()).filter(Boolean)

  const option = (item, i) => {
    const li = document.createElement('li')
    li.id = `wgl-add-language-${i}`
    li.setAttribute('role', 'option')
    li.className = 'wgl-picker__option'

    if (item.flag) {
      const img = document.createElement('img')
      img.className = 'wgl-flag'
      img.src = item.flag
      img.alt = ''
      li.append(img)
    } else {
      const blank = document.createElement('span')
      blank.className = 'wgl-flag wgl-flag--blank'
      li.append(blank)
    }

    const name = document.createElement('span')
    name.className = 'wgl-picker__name'
    name.textContent = item.native

    const meta = document.createElement('span')
    meta.className = 'wgl-picker__meta'
    meta.textContent = item.english === item.native ? item.locale : `${item.english} · ${item.locale}`

    li.append(name, meta)

    if (taken('locale').includes(item.locale.toLowerCase())) {
      li.classList.add('is-taken')
      li.setAttribute('aria-disabled', 'true')
      meta.textContent += ` · ${texts.added}`
    }

    li.addEventListener('mousedown', event => {
      event.preventDefault()
      pick(item)
    })

    return li
  }

  const render = () => {
    const query = input.value.trim().toLowerCase()

    matches = query ? rank(query) : locales
    active = matches.findIndex(item => !taken('locale').includes(item.locale.toLowerCase()))
    list.replaceChildren(...matches.map(option))

    if (!matches.length) {
      const empty = document.createElement('li')
      empty.className = 'wgl-picker__empty'
      empty.textContent = texts.none
      list.append(empty)
    }

    highlight()
    open(true)
  }

  // What starts with the query first (a code, a locale, a word of a name),
  // then what has it anywhere: `de` lists German before Bangladesh.
  const rank = query => {
    const score = item => {
      const words = `${item.native} ${item.english}`.toLowerCase().split(/[\s()·,]+/)

      const locale = item.locale.toLowerCase()

      // the language's main locale first: fr_FR before fr_BE, de_DE before de_AT
      if (locale === query || locale === `${query}_${query}`) return 0
      if (item.code === query) return 1
      if (locale.startsWith(query)) return 2
      if (words.some(word => word.startsWith(query))) return 3
      if ([
        item.native,
        item.english,
        item.locale
      ].some(text => text.toLowerCase().includes(query))) return 4

      return -1
    }

    return locales
      .map((item, order) => ({
        item, order, score: score(item)
      }))
      .filter(entry => entry.score >= 0)
      .sort((a, b) => a.score - b.score || a.order - b.order)
      .map(entry => entry.item)
  }

  const highlight = () => {
    list.querySelectorAll('.wgl-picker__option').forEach((li, i) => li.classList.toggle('is-active', i === active))

    const current = list.children[active]

    if (current) {
      current.scrollIntoView({ block: 'nearest' })
      input.setAttribute('aria-activedescendant', current.id)
    } else {
      input.removeAttribute('aria-activedescendant')
    }
  }

  const open = state => {
    list.hidden = !state
    input.setAttribute('aria-expanded', state ? 'true' : 'false')
  }

  const move = step => {
    if (!matches.length) {
      return
    }

    let next = active

    // skip the languages already in the table
    for (let n = 0; n < matches.length; n++) {
      next = (next + step + matches.length) % matches.length

      if (!list.children[next].classList.contains('is-taken')) {
        break
      }
    }

    active = next
    highlight()
  }

  // A code no row has: the ISO one (`de`), else the locale's (`de-at`).
  const freeCode = item => {
    const codes = taken('code')

    return [ item.code, item.locale.toLowerCase().replace(/_/g, '-') ].find(code => !codes.includes(code)) || ''
  }

  const field = (row, name) => row.querySelector(`input[name$="[${name}]"]`)

  const pick = item => {
    if (taken('locale').includes(item.locale.toLowerCase())) {
      return
    }

    const row = addRow()
    const code = freeCode(item)

    field(row, 'code').value = code
    field(row, 'locale').value = item.locale
    field(row, 'name').value = item.native

    field(row, 'slug').value = code
    preview(row, item.locale)
    row.classList.add('wgl-row--new')

    input.value = ''
    open(false)
    picker.querySelector('.wgl-picker__note').hidden = false
  }

  // The flag beside a row's locale: the list's for that locale, else a blank
  // (a locale the list hasn't, its flag shows once saved).
  const preview = (row, locale) => {
    const cell = field(row, 'locale').parentElement
    const url = locales.find(item => item.locale.toLowerCase() === locale.trim().toLowerCase())?.flag
    const old = cell.querySelector('.wgl-flag')
    let flag

    if (url) {
      flag = document.createElement('img')
      flag.src = url
      flag.alt = ''
    } else {
      flag = document.createElement('span')
    }

    flag.className = url ? 'wgl-flag' : 'wgl-flag wgl-flag--blank'
    old ? old.replaceWith(flag) : cell.prepend(flag)
  }

  table.addEventListener('input', event => {
    if (event.target.matches('input[name$="[locale]"]')) {
      preview(event.target.closest('tr'), event.target.value)
    }
  })

  // A new row from the template, at the end, numbered after the others (the
  // save takes them in order, whatever the numbers).
  let next = table.rows.length

  const addRow = () => {
    const html = template.innerHTML.replace(/__i__/g, String(next++))

    table.insertAdjacentHTML('beforeend', html)

    return table.rows[table.rows.length - 1]
  }

  // ✕: the row goes, but for the last one (a site has a language). The
  // default's gone, the first row is.
  table.addEventListener('click', event => {
    const button = event.target.closest('.wgl-remove')

    if (!button || table.rows.length < 2) {
      return
    }

    button.closest('tr').remove()

    if (!table.querySelector('input[name="default"]:checked')) {
      table.rows[0].querySelector('input[name="default"]').checked = true
    }

    sync()
    picker.querySelector('.wgl-picker__note').hidden = false
  })

  // The default's row, and its slug shown only with "in the URL too"; a
  // warning once another row is the default.
  const inUrl = document.getElementById('wgl-default-in-url')
  const warning = document.querySelector('.wgl-default-warning')
  const firstDefault = table.querySelector('input[name="default"]:checked')?.closest('tr')

  const sync = () => {
    ;[ ...table.rows ].forEach(row => row.classList.toggle('is-default', row.querySelector('input[name="default"]').checked))
    table.closest('table').classList.toggle('wgl-default-at-root', !inUrl?.checked)

    if (warning) {
      warning.hidden = table.querySelector('input[name="default"]:checked')?.closest('tr') === firstDefault
    }
  }

  table.addEventListener('change', event => {
    if (event.target.name === 'default') {
      sync()
      picker.querySelector('.wgl-picker__note').hidden = false
    }
  })
  inUrl?.addEventListener('change', sync)

  input.addEventListener('input', render)
  input.addEventListener('focus', render)
  input.addEventListener('blur', () => open(false))
  input.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()

      if (list.hidden) {
        render()
      } else {
        move(event.key === 'ArrowDown' ? 1 : -1)
      }
    } else if (event.key === 'Enter') {
      // never the form's submit
      event.preventDefault()

      if (!list.hidden && matches[active]) {
        pick(matches[active])
      }
    } else if (event.key === 'Escape') {
      open(false)
    }
  })
}

/**
 * AI translation's "Test the connection": the fields as they are now (the
 * saved key when the field is empty), translated by wgl/v1/ai/test.
 */
const test = document.querySelector('.wgl-ai__test')
const { rest = null } = window.wglSettings || {}

if (test && rest) {
  const result = document.querySelector('.wgl-ai__result')
  const value = name => document.querySelector(`[name="ai[${name}]"]`)?.value || ''

  test.addEventListener('click', async () => {
    test.disabled = true
    result.textContent = texts.testing
    result.className = 'wgl-ai__result'

    try {
      const response = await fetch(rest.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': rest.nonce },
        body: JSON.stringify({
          endpoint: value('endpoint'), model: value('model'), key: value('key'), instructions: value('instructions')
        })
      })
      const answer = await response.json()

      if (!response.ok) {
        throw new Error(answer.message || response.status)
      }

      result.textContent = texts.tested.replace('%1$s', answer.seconds).replace('%2$s', answer.source).replace('%3$s', answer.translation)
      result.classList.add('is-ok')
    } catch (error) {
      result.textContent = `${texts.testFailed} ${error.message}`
      result.classList.add('is-failed')
    } finally {
      test.disabled = false
    }
  })
}
