// "Translate" and "Translate…" on the lists (src/Admin/list.js,
// translateMany()), in jsdom: the dialog read from the rows' "+" links, what
// it asks wgl/v1/…/translate for each item, its notes, the bulk action
// intercepted only for itself, and what failed listed before the reload.
// apiFetch is faked; jsdom has no <dialog> methods, so they're stubbed.

import assert from 'node:assert/strict'
import { afterEach, describe, test } from 'node:test'

import { JSDOM, VirtualConsole } from 'jsdom'

import { adminScript } from './_source.mjs'

const script = adminScript('list.js')

const TEXTS = {
  translateOne: 'Translate “%s”',
  translateMany: 'Translate %s items',
  into: 'Into',
  lacking: '%1$s of %2$s lack it',
  as: 'As',
  independent: 'Duplicate',
  independentHelp: 'A copy',
  text: 'Fixed translation',
  textHelp: 'Follows it',
  intoDefault: 'In %s: a copy that becomes the original',
  noText: 'No blocks: a duplicate',
  copyOption: 'Start from the original',
  copyHelp: 'On or off',
  create: 'Create translations',
  noLanguage: 'Tick a language first.',
  creating: 'Creating %1$s of %2$s',
  done: '%1$s created, %2$s failed',
  close: 'Close',
  cancel: 'Cancel',
  failed: 'Failed',
  nothingToTranslate: 'Nothing to translate'
}

// a "+" for a language the item lacks, as languages_cell() prints it
const plus = (id, title, lang, name, { fixed = true, isDefault = false, canEmpty = true } = {}) => `
  <a class="wgl-lang wgl-lang--missing" href="#create" data-wgl-id="${id}" data-wgl-kind="posts" data-wgl-lang="${lang}"
    data-wgl-lang-name="${name}" data-wgl-title="${title}" data-wgl-text="${fixed ? '#fixed' : ''}" data-wgl-copy="${canEmpty ? 1 : 0}" data-wgl-default="${isDefault ? 1 : 0}"><img class="wgl-flag" alt="${lang.toUpperCase()}"></a>`

const row = (id, title, missing) => `
  <tr id="post-${id}">
    <th class="check-column"><input type="checkbox" name="post[]" value="${id}"></th>
    <td class="title">${title}<div class="row-actions">${missing ? `<a href="#fallback" class="wgl-translate" data-wgl-id="${id}">Translate</a>` : ''}</div></td>
    <td class="column-wgl_languages"><span class="wgl-langs">${missing}</span></td>
  </tr>`

const LIST = `
  <form id="posts-filter">
    <select id="bulk-action-selector-top"><option value="-1">Bulk actions</option><option value="trash">Trash</option><option value="wgl_translate">Translate…</option></select>
    <input type="submit" id="doaction" value="Apply">
    <table class="wp-list-table"><tbody id="the-list">
      ${row(10, 'Uno', plus(10, 'Uno', 'en', 'English') + plus(10, 'Uno', 'de', 'Deutsch'))}
      ${row(11, 'Due', plus(11, 'Due', 'de', 'Deutsch'))}
      ${row(12, 'Tre', '')}
      ${row(13, 'News', plus(13, 'News', 'en', 'English', { fixed: false }))}
      ${row(14, 'Hello', plus(14, 'Hello', 'it', 'Italiano', { isDefault: true }))}
    </tbody></table>
  </form>`

const windows = []

/**
 * The list with its script: `answer(path, data)` is what the fake apiFetch
 * answers for each request (a rejection for a failure).
 */
function page ({ answer = () => ({ created: { x: 1 }, failed: {} }) } = {}) {
  const errors = []
  const virtualConsole = new VirtualConsole()

  // jsdom can't navigate: what the reload would have been is noted
  virtualConsole.on('jsdomError', error => errors.push(error.message))

  const dom = new JSDOM(`<!doctype html><body>${LIST}</body>`, {
    url: 'https://example.test/wp-admin/edit.php?post_type=page&wgl_failed=9',
    runScripts: 'outside-only',
    virtualConsole
  })
  const w = dom.window
  const requests = []
  const alerts = []

  windows.push(w)
  w.HTMLDialogElement.prototype.showModal = function () {
    this.open = true
  }
  w.HTMLDialogElement.prototype.close = function () {
    this.open = false
    this.dispatchEvent(new w.Event('close'))
  }
  w.alert = message => alerts.push(message)
  w.wglList = { texts: TEXTS }
  w.wp = {
    apiFetch: async options => {
      // a plain copy: the page's arrays are another realm's
      requests.push(JSON.parse(JSON.stringify(options)))
      return answer(options.path, options.data)
    },
    url: { addQueryArgs: (path, args) => path + '?' + new URLSearchParams(args) }
  }
  w.eval(script)

  const doc = w.document
  const dialog = () => doc.querySelector('dialog.wgl-dialog')
  const click = node => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }))

  return {
    w,
    doc,
    requests,
    alerts,
    dialog,
    click,
    // a row's "Translate"
    translate: id => click(doc.querySelector(`a.wgl-translate[data-wgl-id="${id}"]`)),
    // "Translate…" (or another bulk action) applied to the rows ticked
    bulk: (ids, action = 'wgl_translate') => {
      ids.forEach(id => { doc.querySelector(`input[value="${id}"]`).checked = true })
      doc.getElementById('bulk-action-selector-top').value = action
      return click(doc.getElementById('doaction'))
    },
    langs: () => [ ...dialog().querySelectorAll('input[name="langs"]') ].map(box => [ box.value, box.checked ]),
    notes: () => [ ...dialog().querySelectorAll('.wgl-dialog__note') ].map(note => note.textContent),
    create: async () => {
      const button = [ ...dialog().querySelectorAll('button') ].find(b => b.value === 'create')
      dialog().querySelector('form').requestSubmit(button)
      // the requests, one after the other
      for (let i = 0; i < 20; i++) {
        await new Promise(resolve => setTimeout(resolve, 0))
      }
    },
    navigated: () => errors.some(message => message.includes('navigation'))
  }
}

afterEach(() => windows.splice(0).forEach(w => w.close()))

describe('a row\'s "Translate"', () => {
  test('opens the dialog for the item, its missing languages ticked, a duplicate chosen', () => {
    const p = page()

    p.translate(10)

    assert.equal(p.dialog().open, true)
    assert.equal(p.dialog().querySelector('h2').textContent, 'Translate “Uno”')
    assert.deepEqual(p.langs(), [ [ 'en', true ], [ 'de', true ] ])
    assert.ok(p.dialog().querySelector('.wgl-dialog__lang img.wgl-flag'), 'with the languages\' flags')
    assert.equal(p.dialog().querySelector('.wgl-dialog__count'), null, 'no counts for one item')
    assert.equal(p.dialog().querySelector('input[name="mode"]:checked').value, 'independent')
  })

  test('asks for the languages ticked, as the mode chosen, then reloads', async () => {
    const p = page()

    p.translate(10)
    p.dialog().querySelector('input[value="de"]').checked = false
    p.dialog().querySelector('input[value="text"]').checked = true
    await p.create()

    assert.deepEqual(p.requests, [ { path: '/wgl/v1/posts/10/translate', method: 'POST', data: { langs: [ 'en' ], mode: 'text', copy: true } } ])
    assert.ok(p.navigated(), 'the list reloaded')
  })

  test('asks nothing with no language ticked, and says so', async () => {
    const p = page()

    p.translate(10)
    p.dialog().querySelectorAll('input[name="langs"]').forEach(box => { box.checked = false })
    await p.create()

    assert.deepEqual(p.requests, [])
    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').textContent, 'Tick a language first.')
  })

  test('starts from the original, unless it\'s turned off', async () => {
    const p = page()

    p.translate(10)
    assert.equal(p.dialog().querySelector('input[name="copy"]').checked, true, 'on by default')
    p.dialog().querySelector('input[name="copy"]').checked = false
    await p.create()

    assert.equal(p.requests[0].data.copy, false)
  })

  test('offers no mode for a type without blocks', () => {
    const p = page()

    p.translate(13)
    assert.equal(p.dialog().querySelector('input[name="mode"]'), null)
  })

  test('says a copy into the default language becomes the original', () => {
    const p = page()

    p.translate(14)
    assert.deepEqual(p.notes(), [ 'In Italiano: a copy that becomes the original' ])
  })
})

describe('"Translate…", the bulk action', () => {
  test('opens one dialog for the rows ticked that lack a language, with how many lack each', () => {
    const p = page()

    assert.equal(p.bulk([ 10, 11, 12 ]), false, 'the form isn\'t submitted')
    assert.equal(p.dialog().querySelector('h2').textContent, 'Translate 2 items', 'Tre lacks nothing')
    assert.deepEqual(p.langs(), [ [ 'en', true ], [ 'de', true ] ])
    assert.deepEqual([ ...p.dialog().querySelectorAll('.wgl-dialog__count') ].map(count => count.textContent), [ '1 of 2 lack it', '2 of 2 lack it' ])
  })

  test('asks for each item in turn, only the languages it lacks', async () => {
    const p = page()

    p.bulk([ 10, 11 ])
    await p.create()

    assert.deepEqual(p.requests.map(request => [ request.path, request.data.langs ]), [
      [ '/wgl/v1/posts/10/translate', [ 'en', 'de' ] ],
      [ '/wgl/v1/posts/11/translate', [ 'de' ] ]
    ])
  })

  test('skips an item none of whose languages are ticked', async () => {
    const p = page()

    p.bulk([ 10, 11 ])
    p.dialog().querySelector('input[value="de"]').checked = false
    await p.create()

    assert.deepEqual(p.requests.map(request => request.path), [ '/wgl/v1/posts/10/translate' ])
  })

  test('notes that a type without blocks gets a duplicate, among others with blocks', () => {
    const p = page()

    p.bulk([ 10, 13 ])
    assert.ok(p.dialog().querySelector('input[value="text"]'))
    assert.deepEqual(p.notes(), [ 'No blocks: a duplicate' ])
  })

  test('says so when nothing ticked lacks a language', () => {
    const p = page()

    p.bulk([ 12 ])
    assert.equal(p.dialog(), null)
    assert.deepEqual(p.alerts, [ 'Nothing to translate' ])
  })

  test('leaves the other bulk actions to WordPress', () => {
    const p = page()

    assert.equal(p.bulk([ 10 ], 'trash'), true, 'not prevented')
    assert.equal(p.dialog(), null)
  })
})

describe('what failed', () => {
  test('stays listed, the list reloading only when closed', async () => {
    const p = page({
      answer: path => path.includes('/11/')
        ? Promise.reject(new Error('Not allowed'))
        : { created: { en: 101 }, failed: { de: 'Slug taken' } }
    })

    p.bulk([ 10, 11 ])
    await p.create()

    assert.deepEqual([ ...p.dialog().querySelectorAll('.wgl-dialog__errors li') ].map(li => li.textContent), [
      'Uno → Deutsch: Slug taken',
      'Due: Not allowed'
    ])
    assert.equal(p.dialog().querySelector('.wgl-dialog__progress').textContent, '1 created, 2 failed')
    assert.equal(p.navigated(), false, 'not yet')

    const close = [ ...p.dialog().querySelectorAll('button') ].find(b => b.textContent === 'Close')

    assert.equal(close.disabled, false)
    p.dialog().close()
    assert.ok(p.navigated(), 'closed: the list again')
  })
})
