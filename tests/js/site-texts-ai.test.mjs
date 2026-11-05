// "Translate empty with AI" on the Translation strings screen
// (src/SiteTexts/site-texts-ai.js), in jsdom, after the screen's own script
// (site-texts.js): the empty cells of the columns shown sent a target
// language at a time, in chunks, to wgl/v1/ai/texts (a faked fetch), each
// translation put in its cell's field as a suggestion and counted as a
// change, and what failed listed.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

const screen = readFileSync(new URL('../../src/SiteTexts/site-texts.js', import.meta.url), 'utf8')
const script = readFileSync(new URL('../../src/SiteTexts/site-texts-ai.js', import.meta.url), 'utf8')

const TEXTS = {
  nothing: 'Nothing to fill in.',
  running: 'Translating %1$s of %2$s…',
  done: { one: '1 filled in.', many: '%s filled in.' },
  failed: { one: '1 failed:', many: '%s failed:' },
  error: 'No answer: %s',
  missing: 'Missing.',
  confirm: { one: 'Fill 1 (%2$s)?', many: 'Fill %1$s (%2$s)?' },
  title: 'Translate empty with AI',
  translate: 'Translate',
  cancel: 'Cancel',
  stop: 'Stop',
  stopping: 'Stopping…',
  stopped: { one: 'Stopped: 1 filled in.', many: 'Stopped: %s filled in.' }
}

// a cell as the screen prints it: its field, and what it's written from
const cell = (lang, value, source, { code = '', from = 'it' } = {}) => `
  <td class="wg-site-texts__cell" data-lang="${lang}"${code ? ` data-code="${code}"` : ''} data-value="${value}"${source !== null ? ` data-from="${from}" data-source="${source}"` : ''}>
    <div class="wg-site-texts__field"><input type="text" value="${value}"></div>
    <span class="wg-site-texts__note"></span>
  </td>`

// Site strings: a row per key, in English, from the Italian
const siteRow = (key, it, en = '') => `
  <tr id="${key}"><td class="wg-site-texts__key"><code>${key}</code></td>${cell('en', en, it)}</tr>`

// Site strings in the default language: nothing to write it from
const defaultRow = (key, it = '') => `
  <tr id="${key}"><td class="wg-site-texts__key"><code>${key}</code></td>${cell('it', it, null)}</tr>`

// Admin strings: a field for the locale, the English its source
const adminRow = (english, it = '') => `<tr>${cell('it_IT', it, english, { code: 'it', from: 'en' })}</tr>`

const page = (rows, { answer, tab = '', confirm = true } = {}) => {
  const dom = new JSDOM(`<!doctype html><body>
    <div class="wrap wg-site-texts">
      <button type="button" class="page-title-action wg-site-texts__ai">AI</button>
      <div class="wg-site-texts__ai-box"><p class="wg-site-texts__ai-status" aria-live="polite" hidden></p><ul class="wg-site-texts__ai-errors"></ul></div>
      <form class="wg-site-texts__form">
        <table class="widefat wg-site-texts__table"><thead><tr><th data-column="key">Key</th><th data-column="lang-en" data-name="English">English</th></tr></thead>
          <tbody><tr class="wg-site-texts__group"><th colspan="4">Group</th></tr>${rows.join('')}</tbody></table>
        <div class="wg-site-texts__submit"><input type="submit" name="submit"><span class="wg-site-texts__changed"></span></div>
      </form>
    </div></body>`, {
    url: 'https://example.test/wp-admin/admin.php?page=wg-site-texts' + (tab ? '&tab=' + tab : ''),
    runScripts: 'outside-only'
  })
  const w = dom.window
  const requests = []

  windows.push(w)
  w.wgSiteTexts = { texts: { changed: { one: '1 changed', many: '%s changed' } } }
  w.wgSiteTextsAi = { texts: TEXTS, route: 'https://example.test/wp-json/wgl/v1/ai/texts', nonce: 'n0nce' }

  // the route: by default, each text back with its target language before it
  w.fetch = async (url, options) => {
    const body = JSON.parse(options.body)

    requests.push({ url, headers: options.headers, body })

    const reply = answer ? await answer(body, requests.length) : {
      translated: Object.fromEntries(Object.entries(body.texts).map(([ key, text ]) => [ key, `${body.to}:${text}` ]))
    }

    return { ok: !reply.status, status: reply.status || 200, statusText: '', json: async () => reply }
  }

  // jsdom has no <dialog> methods
  w.HTMLDialogElement.prototype.showModal = function () {
    this.open = true
  }
  w.HTMLDialogElement.prototype.close = function () {
    this.open = false
    this.dispatchEvent(new w.Event('close'))
  }

  w.eval(screen)
  w.eval(script)

  const doc = w.document
  // the dialog's question, noted; answered Translate, or Cancel (`confirm`)
  const asked = []
  const dialog = () => doc.querySelector('dialog.wg-site-texts__ai-dialog')
  const settle = async () => {
    for (let i = 0; i < 30; i++) {
      await new Promise(resolve => setTimeout(resolve, 0))
    }
  }
  const press = label => [ ...dialog().querySelectorAll('button') ].find(b => b.textContent === label).click()

  return {
    doc,
    requests,
    asked,
    dialog,
    press,
    settle,
    run: async () => {
      doc.querySelector('.wg-site-texts__ai').click()

      if (dialog()) {
        asked.push(dialog().querySelector('p').textContent)
        press(confirm ? TEXTS.translate : TEXTS.cancel)
      }

      await settle()
    },
    field: (row, lang) => doc.querySelector(`${row} .wg-site-texts__cell[data-lang="${lang}"]`),
    status: () => doc.querySelector('.wg-site-texts__ai-status').textContent,
    errors: () => [ ...doc.querySelectorAll('.wg-site-texts__ai-errors li') ].map(li => li.textContent)
  }
}

const windows = []

afterEach(() => windows.splice(0).forEach(w => w.close()))

describe('the question first', () => {
  test('says how many, into which language, by its name', async () => {
    const p = page([ siteRow('a.one', 'Uno'), siteRow('a.two', 'Due', 'Two'), siteRow('a.three', 'Tre') ])

    await p.run()

    assert.deepEqual(p.asked, [ 'Fill 2 (English)?' ])
    assert.equal(p.requests.length, 1)
  })

  test('names a language with no header by its code', async () => {
    const p = page([ adminRow('Save') ], { tab: 'admin' })

    await p.run()

    assert.deepEqual(p.asked, [ 'Fill 1 (it)?' ])
  })

  test('answered no, asks the endpoint nothing and fills nothing', async () => {
    const p = page([ siteRow('a.one', 'Uno') ], { confirm: false })

    await p.run()

    assert.equal(p.requests.length, 0)
    assert.equal(p.field('#a\\.one', 'en').classList.contains('is-edited'), false)
    assert.equal(p.doc.querySelector('.wg-site-texts__ai-status').hidden, true)
  })

  test('isn\'t asked when there\'s nothing to fill in', async () => {
    const p = page([ siteRow('a.one', 'Uno', 'One') ])

    await p.run()

    assert.deepEqual(p.asked, [])
    assert.equal(p.dialog(), null)
  })

  test('stays open while it translates: a spinner, how far it got, and Stop for Cancel', async () => {
    let release
    const long = 'a'.repeat(700)
    const p = page([ siteRow('a.one', long), siteRow('a.two', long) ], { answer: () => new Promise(resolve => { release = resolve }) })

    p.doc.querySelector('.wg-site-texts__ai').click()
    p.press('Translate')
    await p.settle()

    const progress = p.dialog().querySelector('.wg-site-texts__ai-progress')

    assert.equal(p.dialog().open, true)
    assert.equal(progress.classList.contains('is-running'), true)
    assert.equal(progress.textContent, 'Translating 1 of 2…')
    assert.ok([ ...p.dialog().querySelectorAll('button') ].some(b => b.textContent === 'Stop'))
    assert.equal([ ...p.dialog().querySelectorAll('button') ].find(b => b.textContent === 'Translate').hidden, true)

    release({ translated: { t0: 'One' } })
    await p.settle()
    release({ translated: { t0: 'Two' } })
    await p.settle()

    assert.equal(p.dialog(), null, 'done: closed')
    assert.equal(p.status(), '2 filled in.')
  })

  test('Stop lets the request the model is on finish, and asks nothing more', async () => {
    let release
    const long = 'a'.repeat(700)
    const p = page([ siteRow('a.one', long), siteRow('a.two', long) ], { answer: () => new Promise(resolve => { release = resolve }) })

    p.doc.querySelector('.wg-site-texts__ai').click()
    p.press('Translate')
    await p.settle()
    p.press('Stop')

    assert.equal(p.dialog().querySelector('.wg-site-texts__ai-progress').textContent, 'Stopping…')

    release({ translated: { t0: 'One' } })
    await p.settle()

    assert.equal(p.requests.length, 1, 'the second part not asked')
    assert.equal(p.field('#a\\.one', 'en').querySelector('input').value, 'One', 'what came back is kept')
    assert.equal(p.dialog(), null)
    assert.equal(p.status(), 'Stopped: 1 filled in.')
  })
})

describe('Site strings', () => {
  test('fills the empty fields, from the text under them', async () => {
    const p = page([
      siteRow('a.one', 'Uno'),
      siteRow('a.two', 'Due', 'Two'),
      siteRow('a.none', '', '')
    ])

    await p.run()

    assert.deepEqual(p.requests.map(request => [ request.body.from, request.body.to, Object.values(request.body.texts) ]), [
      [ 'it', 'en', [ 'Uno' ] ]
    ], 'a.two is there already; a.none has no Italian to translate')
    assert.equal(p.requests[0].headers['X-WP-Nonce'], 'n0nce')
    assert.equal(p.requests[0].url, 'https://example.test/wp-json/wgl/v1/ai/texts')
  })

  test('counts as empty what\'s empty now, a text just typed left alone', async () => {
    const p = page([ siteRow('a.one', 'Uno'), siteRow('a.two', 'Due') ])

    p.field('#a\\.one', 'en').querySelector('input').value = 'Typed'
    await p.run()

    assert.deepEqual(p.requests.map(request => Object.values(request.body.texts)), [ [ 'Due' ] ])
    assert.equal(p.field('#a\\.one', 'en').querySelector('input').value, 'Typed')
  })

  test('puts each translation in its field, as a suggestion and a change not saved yet', async () => {
    const p = page([ siteRow('a.one', 'Uno') ])

    await p.run()

    const en = p.field('#a\\.one', 'en')
    const input = en.querySelector('input')

    assert.equal(input.value, 'en:Uno')
    assert.ok(input.classList.contains('is-suggested'))
    assert.ok(en.classList.contains('is-edited'), 'site-texts.js marked it')
    assert.equal(p.doc.querySelector('.wg-site-texts__changed').textContent, '1 changed', 'and counted it')
    assert.equal(p.status(), '1 filled in.')
  })

  test('in the default language there\'s nothing to translate from', async () => {
    const p = page([ defaultRow('a.one'), defaultRow('a.two', 'Due') ])

    await p.run()

    assert.equal(p.requests.length, 0)
    assert.equal(p.status(), 'Nothing to fill in.')
  })

  test('sends 15 texts a request at most', async () => {
    const p = page(Array.from({ length: 30 }, (_, i) => siteRow(`k.r${i}`, `Testo ${i}`)))

    await p.run()

    assert.deepEqual(p.requests.map(request => Object.keys(request.body.texts).length), [ 15, 15 ])
  })

  test('and about 1200 characters of source', async () => {
    const long = 'a'.repeat(500)
    const p = page([ siteRow('k.a', long), siteRow('k.b', long), siteRow('k.c', long) ])

    await p.run()

    assert.deepEqual(p.requests.map(request => Object.keys(request.body.texts).length), [ 2, 1 ])
  })

  test('says so when there\'s nothing to fill in, asking nothing', async () => {
    const p = page([ siteRow('a.one', 'Uno', 'One') ])

    await p.run()

    assert.equal(p.requests.length, 0)
    assert.equal(p.status(), 'Nothing to fill in.')
  })

  test('lists what failed, filling in the rest', async () => {
    const p = page([ siteRow('a.one', 'Uno'), siteRow('a.two', 'Due') ], {
      answer: body => ({ translated: { t0: 'One' }, failed: { t1: 'Its HTML changed.' } })
    })

    await p.run()

    assert.equal(p.field('#a\\.one', 'en').querySelector('input').value, 'One')
    assert.equal(p.field('#a\\.two', 'en').querySelector('input').value, '', 'left empty')
    assert.deepEqual(p.errors(), [ 'a.two (en): Its HTML changed.' ])
    assert.equal(p.status(), '1 filled in. 1 failed:')
  })

  test('stops at an endpoint that doesn\'t answer, counting what wasn\'t asked as failed', async () => {
    const long = 'a'.repeat(700)
    const p = page([ siteRow('a.one', long), siteRow('a.two', long) ], {
      answer: (body, n) => n === 1 ? { status: 502, message: 'Connection refused' } : { translated: {} }
    })

    await p.run()

    assert.equal(p.requests.length, 1, 'the rest not asked')
    assert.deepEqual(p.errors(), [ 'No answer: Connection refused' ])
    assert.equal(p.status(), '2 failed:')
    assert.equal(p.doc.querySelector('.wg-site-texts__ai').disabled, false, 'the button back')
  })
})

describe('Admin strings', () => {
  test('translates the English into the language, by its code, not its locale', async () => {
    const p = page([ adminRow('Save'), adminRow('Add', 'Aggiungi') ], { tab: 'admin' })

    await p.run()

    assert.deepEqual(p.requests.map(request => request.body), [ { texts: { t0: 'Save' }, from: 'en', to: 'it' } ])

    const field = p.doc.querySelector('.wg-site-texts__cell[data-lang="it_IT"] input')

    assert.equal(field.value, 'it:Save')
    assert.ok(field.classList.contains('is-suggested'))
  })
})
