// The Translation strings screen's fields (src/SiteTexts/site-texts.js, editing() and
// suggesting()), in jsdom: every field can be typed in, what's changed is marked and
// counted, and only that is posted, a long list passing PHP's `max_input_vars` otherwise;
// a suggestion translates what's under the field.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

const script = readFileSync(new URL('../../src/SiteTexts/site-texts.js', import.meta.url), 'utf8')

const row = (key, value, source = '') => `
  <tr id="${key}">
    <td class="wg-site-texts__key"><code>${key}</code>
      <label class="wg-site-texts__domain"><select name="domains[${key}]"><option value="general" selected>General</option><option value="shop">Shop</option></select>
      <input type="hidden" name="domains_was[${key}]" value="general"></label></td>
    <td class="wg-site-texts__cell" data-lang="en" data-value="${value}" data-from="it" data-source="${source}">
      <div class="wg-site-texts__field has-suggest">
        <input type="text" name="texts[${key}][en]" value="${value}">
        <button type="button" class="wg-site-texts__suggest" data-from="it" data-to="en" hidden></button>
      </div>
      <span class="wg-site-texts__note"></span>
    </td>
  </tr>`

const windows = []

function page ({ translator = false } = {}) {
  const dom = new JSDOM(`<!doctype html><body>
    <div class="wrap wg-site-texts">
      <form class="wg-site-texts__form">
        <input type="hidden" name="action" value="wp_go_site_texts_save">
        <table class="widefat wg-site-texts__table"><thead><tr><th data-column="key">Key</th><th data-column="lang-en">English</th></tr></thead>
          <tbody>${row('a.one', 'One', 'Uno')}${row('a.two', '', 'Due')}${row('a.three', 'Three', '')}</tbody></table>
        <div class="wg-site-texts__submit"><input type="submit" name="submit"><span class="wg-site-texts__changed"></span></div>
      </form>
    </div></body>`, { url: 'https://example.test/wp-admin/admin.php?page=wg-site-texts', runScripts: 'outside-only' })
  const w = dom.window

  windows.push(w)
  w.wgSiteTexts = { texts: { changed: { one: '1 changed', many: '%s changed' }, noSource: 'Write it first.', cannot: 'No.', download: '%d%%' } }

  if (translator) {
    w.Translator = {
      create: async () => ({ translate: async text => `en:${text}` }),
      availability: async () => 'available'
    }
    w.document.execCommand = () => false
  }

  w.eval(script)

  const doc = w.document
  const input = key => doc.querySelector(`#${key.replace('.', '\\.')} input[type="text"]`)
  const type = (key, value) => {
    input(key).value = value
    input(key).dispatchEvent(new w.Event('input', { bubbles: true }))
  }
  // what the browser would post, as the form is submitted
  const posted = () => {
    doc.querySelector('form').dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }))

    return [ ...new w.FormData(doc.querySelector('form')).keys() ]
  }

  return { w, doc, input, type, posted, changed: () => doc.querySelector('.wg-site-texts__changed').textContent }
}

afterEach(() => windows.splice(0).forEach(w => w.close()))

describe('a field', () => {
  test('can be typed in as the screen opens', () => {
    const p = page()

    assert.equal(p.input('a.one').disabled, false)
    assert.equal(p.input('a.one').value, 'One')
  })

  test('is marked and counted while it isn\'t what\'s saved', () => {
    const p = page()

    p.type('a.one', 'Uno più')
    assert.ok(p.input('a.one').closest('td').classList.contains('is-edited'))
    assert.equal(p.changed(), '1 changed')

    p.type('a.one', 'One')
    assert.equal(p.input('a.one').closest('td').classList.contains('is-edited'), false)
    assert.equal(p.changed(), '')
  })

  test('goes back to what\'s saved with Escape', () => {
    const p = page()

    p.type('a.one', 'Changed')
    p.input('a.one').dispatchEvent(new p.w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))

    assert.equal(p.input('a.one').value, 'One')
    assert.equal(p.changed(), '')
  })
})

describe('a save', () => {
  test('with nothing changed posts no text, and no domain', () => {
    const p = page()

    assert.deepEqual(p.posted(), [ 'action' ])
  })

  test('posts only the texts changed', () => {
    const p = page()

    p.type('a.two', 'Two')
    assert.deepEqual(p.posted(), [ 'action', 'texts[a.two][en]' ])
  })

  test('a text emptied is posted, as that removes it', () => {
    const p = page()

    p.type('a.one', '')
    assert.deepEqual(p.posted(), [ 'action', 'texts[a.one][en]' ])
  })

  test('posts a domain changed, with the one it showed on load', () => {
    const p = page()
    const select = p.doc.querySelector('select[name="domains[a.one]"]')

    select.value = 'shop'
    select.dispatchEvent(new p.w.Event('change', { bubbles: true }))

    assert.deepEqual(p.posted(), [ 'action', 'domains[a.one]', 'domains_was[a.one]' ])
  })

  test('back on the page, the fields can be typed in again', () => {
    const p = page()

    p.posted()
    assert.equal(p.input('a.one').disabled, true)

    p.w.dispatchEvent(new p.w.Event('pageshow'))
    assert.equal(p.input('a.one').disabled, false)
    assert.equal(p.doc.querySelector('select[name="domains[a.one]"]').disabled, false)
  })
})

describe('a suggestion', () => {
  const press = async (p, key) => {
    p.doc.querySelector(`#${key.replace('.', '\\.')} .wg-site-texts__suggest`).click()

    for (let i = 0; i < 10; i++) {
      await new Promise(resolve => setTimeout(resolve, 0))
    }
  }

  test('translates the text under the field, marked until it\'s edited', async () => {
    const p = page({ translator: true })

    await press(p, 'a.two')

    assert.equal(p.input('a.two').value, 'en:Due')
    assert.ok(p.input('a.two').classList.contains('is-suggested'))
    assert.equal(p.changed(), '1 changed')
  })

  test('with nothing under the field, says so', async () => {
    const p = page({ translator: true })

    await press(p, 'a.three')

    assert.equal(p.input('a.three').value, 'Three')
    assert.equal(p.doc.querySelector('#a\\.three .wg-site-texts__note').textContent, 'Write it first.')
  })
})
