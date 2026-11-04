// AI translation from the lists' "+" dialog (src/Admin/list.js, with
// _ai.js), in jsdom: its checkbox (on, remembered, not into the default
// language), a choice with it ticked making the translation then translating
// its untranslated fields a chunk at a time, and what failed listed with a
// link to it. And _ai.js's chunks and run, on their own. apiFetch is faked;
// jsdom has no <dialog> methods and can't navigate: stubbed, and noted.

import assert from 'node:assert/strict'
import { afterEach, describe, test } from 'node:test'

import { JSDOM, VirtualConsole } from 'jsdom'

import { chunks, translateFields } from '../../src/Admin/_ai.js'
import { adminScript } from './_source.mjs'

const script = adminScript('list.js')

const TEXTS = {
  title: '%1$s translation of "%2$s"',
  independent: 'Duplicate',
  independentHelp: 'A copy',
  copyOption: 'Start from the original',
  copyHelp: 'On or off',
  text: 'Fixed translation',
  textHelp: 'Follows it',
  original: 'Create it in %s',
  originalHelp: 'Becomes the original',
  originalKept: 'The chosen original stays',
  linkCard: 'Link one in %s',
  linkHelp: 'Joins these translations',
  search: 'Search…',
  noCandidates: 'Nothing to link',
  draft: 'draft',
  aiOption: 'Translate its texts with AI',
  aiOptionHelp: 'From the original',
  aiCreating: 'Creating…',
  aiRunning: 'Translating %1$s of %2$s',
  aiDone: '%1$s translated, %2$s failed:',
  aiOpen: 'Open it',
  createOne: 'Create',
  aiStop: 'Stop',
  aiStopping: 'Stopping…',
  aiStopped: 'Stopped: %s translated.',
  aiClose: 'Close',
  cancel: 'Cancel',
  failed: 'Failed'
}

const plus = (lang, name, { isDefault = false, structure = true, chosen = false } = {}) => `
  <a class="wgl-lang wgl-lang--missing" href="admin-post.php?action=wgl_create_translation&post=10&lang=${lang}&mode=independent"
    data-wgl-id="10" data-wgl-kind="posts" data-wgl-lang="${lang}" data-wgl-lang-name="${name}" data-wgl-title="Uno"
    data-wgl-text="admin-post.php?action=wgl_create_translation&post=10&lang=${lang}&mode=text"
    data-wgl-blocks="${structure ? `admin-post.php?action=wgl_create_translation&post=10&lang=${lang}&mode=blocks` : ''}" data-wgl-copy="${startsEmpty ? 1 : 0}" data-wgl-default="${isDefault ? 1 : 0}" data-wgl-chosen="${chosen ? 1 : 0}">+</a>`

const FIELDS = [
  { key: 'post:title', label: 'Title', source: 'Uno', untranslated: true },
  { key: 'block:a', label: 'Paragraph', source: '<p>' + 'x'.repeat(2600) + '</p>', untranslated: true },
  { key: 'block:b', label: 'Heading', source: '<h2>Due</h2>', untranslated: false }
]

const windows = []

// whether the "+" links built next offer the toggle
let startsEmpty = true

function page ({ ai = true, stored = null, answer = {}, structure = true, canEmpty = true, chosen = false } = {}) {
  startsEmpty = canEmpty

  const errors = []
  const virtualConsole = new VirtualConsole()

  virtualConsole.on('jsdomError', error => errors.push(error.message))

  const dom = new JSDOM(`<!doctype html><body><table><tbody id="the-list"><tr><td class="column-wgl_languages">${plus('en', 'English', { structure })}${plus('it', 'Italiano', { isDefault: true, chosen })}</td></tr></tbody></table></body>`, {
    url: 'https://example.test/wp-admin/edit.php?post_type=page',
    runScripts: 'outside-only',
    virtualConsole
  })
  const w = dom.window
  const requests = []

  windows.push(w)
  w.HTMLDialogElement.prototype.showModal = function () {
    this.open = true
  }
  w.HTMLDialogElement.prototype.close = function () {
    this.open = false
    this.dispatchEvent(new w.Event('close'))
  }

  if (stored !== null) {
    w.localStorage.setItem('wglAi', stored)
  }

  w.wglList = { texts: TEXTS, ai }
  w.wp = {
    apiFetch: async options => {
      requests.push(JSON.parse(JSON.stringify(options)))

      const reply = Object.entries(answer).find(([ path ]) => options.path.endsWith(path))?.[1]

      if (reply instanceof Error) {
        throw reply
      }

      return typeof reply === 'function' ? reply(options) : reply
    },
    url: { addQueryArgs: path => path }
  }
  w.eval(script)

  const doc = w.document
  const click = node => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }))
  const dialog = () => doc.querySelector('dialog.wgl-dialog')

  return {
    w,
    doc,
    // what the dialog asked, but its search for a page to link
    requests: () => requests.filter(request => !request.path.includes('/candidates')),
    dialog,
    click,
    open: lang => click(doc.querySelector(`a.wgl-lang--missing[data-wgl-lang="${lang}"]`)),
    box: () => dialog().querySelector('.wgl-dialog__ai input'),
    choose: mode => click([ ...dialog().querySelectorAll('button.wgl-choice') ].find(choice => choice.dataset.mode === mode)),
    picked: () => dialog().querySelector('.wgl-choice.is-selected')?.dataset.mode,
    create: () => click(dialog().querySelector('.wgl-dialog__create')),
    settle: async () => {
      for (let i = 0; i < 20; i++) {
        await new Promise(resolve => setTimeout(resolve, 0))
      }
    },
    navigated: () => errors.some(message => message.includes('navigation')),
    cards: () => [ ...dialog().querySelectorAll('button.wgl-choice') ].map(card => card.dataset.mode),
    results: () => [ ...dialog().querySelectorAll('.wgl-dialog__result') ],
    all: requests
  }
}

afterEach(() => windows.splice(0).forEach(w => w.close()))

// the routes' answers, for a translation made with id 99
const MADE = {
  '/posts/10/translate': { created: { en: 99 }, failed: {}, edit: { en: 'https://example.test/wp-admin/post.php?post=99&action=edit' } },
  '/posts/99/ai-fields': { fields: FIELDS },
  '/posts/99/ai-translate': options => ({ written: options.data.keys, failed: {} })
}


describe('starting from the original, or empty', () => {
  const toggle = p => p.dialog().querySelector('.wgl-dialog__copy')
  const aiCard = p => p.dialog().querySelector('.wgl-dialog__ai')

  test('the dialog offers it, on', () => {
    const p = page()

    p.open('en')
    assert.ok(toggle(p), 'it is in the dialog')
    assert.equal(toggle(p).querySelector('input').checked, true)
    assert.equal(toggle(p).hidden, false)
  })

  test('not where a translation can\'t start empty: a form, a term, the default language', () => {
    const p = page({ canEmpty: false })

    p.open('en')
    assert.equal(toggle(p), null)
  })

  test('off, AI has nothing to start from: its card goes, and comes back', () => {
    const p = page()

    p.open('en')
    assert.equal(aiCard(p).hidden, false)
    toggle(p).querySelector('input').click()
    assert.equal(aiCard(p).hidden, true)
    toggle(p).querySelector('input').click()
    assert.equal(aiCard(p).hidden, false)
  })

  test('a link keeps the page\'s content: no toggle while it\'s picked', () => {
    const p = page()

    p.open('en')
    p.dialog().querySelector('.wgl-choice--link').click()
    assert.equal(toggle(p).hidden, true)
    p.dialog().querySelector('button.wgl-choice').click()
    assert.equal(toggle(p).hidden, false)
  })
})

describe('the "+" dialog\'s AI checkbox', () => {
  test('is there, ticked the first time', () => {
    const p = page()

    p.open('en')
    assert.equal(p.box().checked, true)
    assert.ok(p.dialog().querySelector('.wgl-dialog__ai svg'), 'with the AI\'s icon')
    assert.ok(p.dialog().querySelector('label.wgl-choice.wgl-dialog__ai'), 'a card as the choices are')
    assert.equal(p.dialog().querySelector('.wgl-choices').contains(p.box()), false, 'under them, not in their grid')
  })

  test('is toggled by a click on its card, and never picked as a choice', () => {
    const p = page()

    p.open('en')
    p.choose('text')
    p.click(p.dialog().querySelector('.wgl-dialog__ai strong'))
    assert.equal(p.box().checked, false, 'a click on the card unticks it')
    assert.equal(p.picked(), 'text', 'the choice picked stays')
    assert.equal(p.dialog().querySelector('.wgl-dialog__ai').classList.contains('is-selected'), false)
  })

  test('is ticked as it was last left, and remembers', () => {
    const p = page({ stored: '0' })

    p.open('en')
    assert.equal(p.box().checked, false)

    p.box().checked = true
    p.box().dispatchEvent(new p.w.Event('change'))
    assert.equal(p.w.localStorage.getItem('wglAi'), '1')
  })

  test('isn\'t there without AI set up, nor into the default language', () => {
    const p = page({ ai: false })

    p.open('en')
    assert.equal(p.box(), null)

    const q = page()

    q.open('it')
    assert.equal(q.box(), null, 'the default language\'s copy becomes the original: nothing to translate it from')
  })

  test('the default language\'s card says what its copy will be', () => {
    const p = page()

    p.open('it')
    assert.match(p.dialog().textContent, /Becomes the original/)

    const q = page({ chosen: true })

    q.open('it')
    assert.match(q.dialog().textContent, /The chosen original stays/, 'an original chosen by hand stays')
    assert.doesNotMatch(q.dialog().textContent, /Becomes the original/)
  })
})

describe('a choice', () => {
  test('only picks what Create makes: the first to start with, a click another', async () => {
    const p = page({ answer: MADE })

    p.open('en')
    assert.equal(p.picked(), 'independent')
    assert.ok(p.dialog().querySelector('.wgl-dialog__create'), 'Create, beside Cancel')

    p.choose('text')
    await p.settle()
    assert.equal(p.picked(), 'text')
    assert.equal(p.dialog().querySelector('.wgl-choice.is-selected').getAttribute('aria-pressed'), 'true')
    assert.deepEqual(p.requests(), [], 'nothing made yet')
    assert.equal(p.navigated(), false)
  })

  test('a form\'s "+" (no structure link) has no fixed blocks card', () => {
    const p = page({ structure: false })

    p.open('en')
    assert.deepEqual(p.cards(), [ 'independent', 'text', 'link' ])
  })

  test('fixed blocks picked, with AI, makes it in that mode', async () => {
    const p = page({ answer: MADE })

    p.open('en')
    p.choose('blocks')
    p.create()
    await p.settle()
    assert.deepEqual(p.requests()[0], { path: '/wgl/v1/posts/10/translate', method: 'POST', data: { langs: [ 'en' ], mode: 'blocks' } })
  })

  test('picked, without AI, Create opens the "+"\'s own link for it', () => {
    const p = page({ stored: '0' })

    p.open('en')
    p.choose('text')
    p.create()
    assert.deepEqual(p.requests(), [])
    assert.ok(p.navigated(), 'the fixed translation\'s link (jsdom can\'t follow it)')
  })
})

describe('Create, with AI ticked', () => {
  test('makes the translation, translates its untranslated fields a chunk at a time, then opens it', async () => {
    const p = page({ answer: MADE })

    p.open('en')
    p.choose('text')
    p.create()
    await p.settle()

    assert.deepEqual(p.requests().map(request => [ request.path, request.data ]), [
      [ '/wgl/v1/posts/10/translate', { langs: [ 'en' ], mode: 'text' } ],
      [ '/wgl/v1/posts/99/ai-fields', undefined ],
      // the long one in a chunk of its own; the heading, translated already, isn't sent
      [ '/wgl/v1/posts/99/ai-translate', { keys: [ 'post:title' ] } ],
      [ '/wgl/v1/posts/99/ai-translate', { keys: [ 'block:a' ] } ]
    ])
    assert.ok(p.navigated(), 'then its editor')
  })

  test('lists what failed, with a link to the translation', async () => {
    const p = page({ answer: { ...MADE, '/posts/99/ai-translate': options => ({ written: [], failed: Object.fromEntries(options.data.keys.map(key => [ key, 'Its HTML changed.' ])) }) } })

    p.open('en')
    p.create()
    await p.settle()

    assert.deepEqual([ ...p.dialog().querySelectorAll('.wgl-dialog__errors li') ].map(li => li.textContent), [ 'Title: Its HTML changed.', 'Paragraph: Its HTML changed.' ])
    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').textContent, '0 translated, 2 failed:')

    const open = p.dialog().querySelector('.wgl-dialog__actions a.button-primary')

    assert.equal(open.href, 'https://example.test/wp-admin/post.php?post=99&action=edit&wgl_created=1')
    assert.equal(p.navigated(), false)
    assert.equal(p.dialog().querySelector('.wgl-dialog__create').hidden, true, 'Create gone: it\'s made')
  })

  test('says why when the translation can\'t be made, and lets the dialog be used again', async () => {
    const p = page({ answer: { '/posts/10/translate': { created: {}, failed: { en: 'Already there.' }, edit: {} } } })

    p.open('en')
    p.create()
    await p.settle()

    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').textContent, 'Already there.')
    assert.equal(p.dialog().querySelector('form').classList.contains('is-busy'), false)
    assert.equal(p.dialog().querySelector('.wgl-dialog__create').disabled, false)
  })
})

describe('_ai.js', () => {
  test('chunks fields by count and by size, a long one alone', () => {
    const field = (key, length) => ({ key, source: 'x'.repeat(length) })

    assert.deepEqual(chunks([ field('a', 100), field('b', 2450), field('c', 3000), field('d', 10) ]).map(chunk => chunk.map(f => f.key)), [ [ 'a' ], [ 'b' ], [ 'c' ], [ 'd' ] ])
    assert.deepEqual(chunks(Array.from({ length: 45 }, (_, i) => field(`f${i}`, 10))).map(chunk => chunk.length), [ 15, 15, 15 ])
    assert.deepEqual(chunks([]), [])
  })

  test('stops at the first chunk the endpoint can\'t answer, every field of it failed', async () => {
    const asked = []

    globalThis.window = {
      wp: {
        apiFetch: async ({ data }) => {
          asked.push(data.keys)

          if (asked.length === 2) {
            throw new Error('The AI endpoint doesn\'t answer')
          }

          return { written: data.keys, failed: {} }
        }
      }
    }

    const progress = []
    const fields = Array.from({ length: 50 }, (_, i) => ({ key: `f${i}`, source: 'x' }))
    const done = await translateFields({ base: '/wgl/v1/posts/1', fields, onProgress: (n, of) => progress.push(`${n}/${of}`) })

    assert.deepEqual(progress, [ '1/4', '2/4' ])
    assert.equal(done.written.length, 15)
    assert.equal(Object.keys(done.failed).length, 15)
    assert.equal(done.error, 'The AI endpoint doesn\'t answer')

    delete globalThis.window
  })
})

describe('_ai.js\'s Stop', () => {
  test('asks stopped() before each part: none sent after, what was written kept', async () => {
    let stop = false

    globalThis.window = { wp: { apiFetch: async ({ data }) => { stop = true; return { written: data.keys, failed: {} } } } }

    const fields = Array.from({ length: 40 }, (_, i) => ({ key: `f${i}`, source: 'x' }))
    const done = await translateFields({ base: '/wgl/v1/posts/1', fields, stopped: () => stop })

    assert.equal(done.stopped, true)
    assert.equal(done.written.length, 15, 'the first part only')
    assert.deepEqual(done.failed, {})

    delete globalThis.window
  })
})

describe('Link, the third card', () => {
  const PAGES = { '/posts/10/candidates': [ { id: 31, title: 'One', status: 'publish' }, { id: 32, title: 'Two', status: 'draft' } ], '/posts/10/link': {} }

  test('sits beside the copies: two cards for the default language', () => {
    const p = page()

    p.open('en')
    assert.deepEqual(p.cards(), [ 'independent', 'blocks', 'text', 'link' ], 'from the freest to the strictest')
    assert.equal(p.dialog().querySelector('.wgl-choices').classList.contains('wgl-choices--3'), true, 'the three copies on a row')
    assert.ok(p.dialog().querySelector('button.wgl-choice[data-mode="link"]').classList.contains('wgl-choice--link'), 'Link on its own, under them')
    assert.equal(p.dialog().querySelector('.wgl-dialog__link').hidden, true, 'its search hidden until picked')

    const q = page()

    q.open('it')
    assert.deepEqual(q.cards(), [ 'independent', 'link' ])
  })

  test('picked, shows its search and hides AI; Create waits for a page', async () => {
    const p = page({ answer: PAGES })

    p.open('en')
    p.choose('link')
    await p.settle()

    assert.equal(p.dialog().querySelector('.wgl-dialog__link').hidden, false)
    assert.equal(p.dialog().querySelector('.wgl-dialog__ai').hidden, true, 'a link has nothing to translate')
    assert.equal(p.dialog().querySelector('.wgl-dialog__create').disabled, true)
    assert.deepEqual(p.results().map(button => button.textContent), [ 'One', 'Two (draft)' ])

    p.choose('independent')
    assert.equal(p.dialog().querySelector('.wgl-dialog__link').hidden, true, 'back to a copy: the search hidden again')
    assert.equal(p.dialog().querySelector('.wgl-dialog__ai').hidden, false)
    assert.equal(p.dialog().querySelector('.wgl-dialog__create').disabled, false)

    p.choose('link')
    await p.settle()
    assert.equal(p.all.filter(request => request.path.includes('/candidates')).length, 1, 'searched once, on the first pick')
  })

  test('a page picked, Create links it', async () => {
    const p = page({ answer: PAGES })

    p.open('en')
    p.choose('link')
    await p.settle()
    p.click(p.results()[1])

    assert.equal(p.results()[1].classList.contains('is-selected'), true)
    assert.equal(p.results()[1].getAttribute('aria-pressed'), 'true')
    assert.deepEqual(p.requests(), [], 'nothing linked by the pick alone')

    p.create()
    await p.settle()
    assert.deepEqual(p.requests(), [ { path: '/wgl/v1/posts/10/link', method: 'POST', data: { other: 32 } } ])
  })

  test('a new search drops the page picked', async () => {
    const p = page({ answer: PAGES })

    p.open('en')
    p.choose('link')
    await p.settle()
    p.click(p.results()[0])

    const input = p.dialog().querySelector('.wgl-dialog__search')

    input.value = 'Tw'
    input.dispatchEvent(new p.w.Event('input'))
    await new Promise(resolve => setTimeout(resolve, 300))
    await p.settle()

    assert.equal(p.dialog().querySelector('.wgl-dialog__create').disabled, true)
    assert.equal(p.results().some(button => button.classList.contains('is-selected')), false)
  })

  test('says why a link failed, and lets Create be pressed again', async () => {
    const p = page({ answer: { ...PAGES, '/posts/10/link': new Error('Already in a group with one in English.') } })

    p.open('en')
    p.choose('link')
    await p.settle()
    p.click(p.results()[0])
    p.create()
    await p.settle()

    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').textContent, 'Already in a group with one in English.')
    assert.equal(p.dialog().querySelector('.wgl-dialog__create').disabled, false)
  })
})

describe('Stop', () => {
  test('Cancel while AI translates is Stop: the part on is let finish, the dialog says what was done', async () => {
    let release
    const p = page({ answer: { ...MADE, '/posts/99/ai-translate': options => new Promise(resolve => { release = () => resolve({ written: options.data.keys, failed: {} }) }) } })

    p.open('en')
    p.create()
    await p.settle()

    const cancel = p.dialog().querySelector('.wgl-dialog__actions button[value="cancel"]')

    assert.equal(cancel.textContent, 'Stop')
    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').classList.contains('is-running'), true, 'a spinner')
    assert.equal(p.click(cancel), false, 'the dialog isn\'t closed')
    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').textContent, 'Stopping…')

    release()
    await p.settle()

    assert.equal(p.requests().filter(request => request.path.endsWith('/ai-translate')).length, 1, 'the second part never asked')
    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').textContent, 'Stopped: 1 translated.')
    assert.equal(cancel.textContent, 'Close')
    assert.ok(p.dialog().querySelector('.wgl-dialog__actions a.button-primary'), 'Open it')
    assert.equal(p.navigated(), false)

    p.dialog().close()
    assert.ok(p.navigated(), 'closed: the list again, its row showing the translation')
  })

  test('Cancel before anything runs only closes', () => {
    const p = page()

    p.open('en')

    const cancel = p.dialog().querySelector('.wgl-dialog__actions button[value="cancel"]')

    assert.equal(cancel.textContent, 'Cancel')
    p.dialog().close()
    assert.equal(p.navigated(), false, 'nothing made: no reload')
  })
})
