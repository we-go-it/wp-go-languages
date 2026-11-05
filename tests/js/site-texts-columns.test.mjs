// The Translation strings screen's columns (src/SiteTexts/site-texts.js,
// columns()), in jsdom: a header's click sorts the rows within each group,
// empty texts last; the order kept per tab and brought back.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

const script = readFileSync(new URL('../../src/SiteTexts/site-texts.js', import.meta.url), 'utf8')

// a row: its key, and its text in the language picked ('' for none)
const row = (key, value) => `
  <tr id="${key}">
    <td class="wg-site-texts__key"><div class="wg-site-texts__key-inner"><div class="wg-site-texts__key-body"><code>${key}</code></div></div></td>
    <td class="wg-site-texts__cell" data-lang="en" data-value="${value}"><div class="wg-site-texts__field"><input type="text" value="${value}"></div></td>
  </tr>`

const group = (name, rows) => `
  <tbody>
    <tr class="wg-site-texts__group"><th colspan="2"><span class="wg-site-texts__group-name">${name}</span></th></tr>
    ${rows.join('')}
  </tbody>`

const SITE = `
  <table class="widefat striped wg-site-texts__table">
    <thead><tr>
      <th class="wg-site-texts__col-key" data-column="key">Key</th>
      <th class="wg-site-texts__col-lang" data-column="lang-en" data-name="English">English</th>
    </tr></thead>
    ${group('Footer', [ row('footer.b', ''), row('footer.a', 'Zulu'), row('footer.c', 'Alpha') ])}
    ${group('Common', [ row('common.z', 'Z'), row('common.y', 'Y') ])}
  </table>`

// the Admin strings tab: the language alone
const ADMIN = `
  <table class="widefat striped wg-site-texts__table">
    <thead><tr><th class="wg-site-texts__col-lang" data-column="lang-it" data-name="Italiano">Italiano <code>it_IT</code></th></tr></thead>
    <tbody>
      <tr class="wg-site-texts__group"><th><code class="wg-site-texts__group-name">wp-go · a.php</code></th></tr>
      <tr id="save"><td class="wg-site-texts__cell" data-lang="it_IT" data-code="it" data-value="Salva"><div class="wg-site-texts__field"><input type="text" value="Salva"></div></td></tr>
      <tr id="add"><td class="wg-site-texts__cell" data-lang="it_IT" data-code="it" data-value="Aggiungi"><div class="wg-site-texts__field"><input type="text" value="Aggiungi"></div></td></tr>
    </tbody>
  </table>`

const windows = []

/**
 * The screen with its script run: `stored` is what this browser kept, set
 * before the script reads it; `hash` the address's.
 */
function page ({ table = SITE, tab = '', stored = {}, hash = '' } = {}) {
  const dom = new JSDOM(`<!doctype html><html lang="it"><body>
    <div class="wrap wg-site-texts">
      <form class="wg-site-texts__form">
        ${table}
        <div class="wg-site-texts__submit"><input type="submit" name="submit"><span class="wg-site-texts__changed"></span></div>
      </form>
    </div>
  </body></html>`, {
    url: 'https://example.test/wp-admin/admin.php?page=wg-site-texts' + (tab ? '&tab=' + tab : '') + hash,
    runScripts: 'outside-only'
  })
  const w = dom.window

  windows.push(w)
  Object.entries(stored).forEach(([ key, value ]) => w.localStorage.setItem(key, JSON.stringify(value)))
  w.HTMLElement.prototype.scrollIntoView = function () {
    w.scrolledTo = this.id
  }
  w.eval(script)

  const doc = w.document

  return {
    w,
    doc,
    header: column => doc.querySelector(`th[data-column="${column}"]`),
    click: column => doc.querySelector(`th[data-column="${column}"] a`).dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true })),
    // each group's rows, by their IDs
    order: () => [ ...doc.querySelectorAll('tbody') ].map(body => [ ...body.rows ]
      .filter(tr => tr.id)
      .map(tr => tr.id)),
    stored: key => JSON.parse(w.localStorage.getItem(key))
  }
}

afterEach(() => windows.splice(0).forEach(w => w.close()))

describe('sorting by a column', () => {
  test('makes each header but none other a link, as WordPress\'s sortable columns', () => {
    const p = page()

    for (const column of [ 'key', 'lang-en' ]) {
      const th = p.header(column)

      assert.ok(th.querySelector('a .sorting-indicators'), column)
      assert.ok(th.classList.contains('sortable') && th.classList.contains('desc'), column)
      assert.equal(th.getAttribute('aria-sort'), 'none')
    }

    assert.equal(p.header('key').querySelector('a span').textContent, 'Key', 'the header\'s text is in the link')
  })

  test('sorts by the key, A→Z then Z→A, each group\'s rows staying in it', () => {
    const p = page()

    p.click('key')
    assert.deepEqual(p.order(), [ [ 'footer.a', 'footer.b', 'footer.c' ], [ 'common.y', 'common.z' ] ])
    assert.ok(p.header('key').classList.contains('sorted') && p.header('key').classList.contains('asc'))
    assert.equal(p.header('key').getAttribute('aria-sort'), 'ascending')

    p.click('key')
    assert.deepEqual(p.order(), [ [ 'footer.c', 'footer.b', 'footer.a' ], [ 'common.z', 'common.y' ] ])
    assert.equal(p.header('key').getAttribute('aria-sort'), 'descending')
    assert.ok(p.doc.querySelector('.wg-site-texts__group').closest('tbody').rows[0].classList.contains('wg-site-texts__group'), 'a group\'s heading stays its first row')
  })

  test('sorts by the language\'s texts, an empty text last either way', () => {
    const p = page()

    p.click('lang-en')
    assert.deepEqual(p.order()[0], [ 'footer.c', 'footer.a', 'footer.b' ], 'Alpha, Zulu, then the empty one')
    assert.ok(p.header('key').classList.contains('sortable'), 'the other column is sortable')

    p.click('lang-en')
    assert.deepEqual(p.order()[0], [ 'footer.a', 'footer.c', 'footer.b' ], 'Zulu, Alpha, still the empty one last')
  })

  test('keeps the order per tab, and brings it back', () => {
    const p = page()

    p.click('lang-en')
    p.click('lang-en')
    assert.deepEqual(p.stored('wgSiteTexts.sort.site'), { column: 'lang-en', direction: 'desc' })

    const again = page({ stored: { 'wgSiteTexts.sort.site': { column: 'lang-en', direction: 'desc' } } })

    assert.deepEqual(again.order()[0], [ 'footer.a', 'footer.c', 'footer.b' ])
    assert.ok(again.header('lang-en').classList.contains('sorted') && again.header('lang-en').classList.contains('desc'))
  })

  test('leaves the rows as they are when the order kept names no column', () => {
    const p = page({ stored: { 'wgSiteTexts.sort.site': { column: 'lang-de', direction: 'asc' } } })

    assert.deepEqual(p.order()[0], [ 'footer.b', 'footer.a', 'footer.c' ])
  })

  test('scrolls back to the row the address points to, once sorted', () => {
    const p = page({ stored: { 'wgSiteTexts.sort.site': { column: 'key', direction: 'desc' } }, hash: '#footer.b' })

    assert.equal(p.w.scrolledTo, 'footer.b')
  })

  test('on the Admin strings tab, sorts by its language, its order kept apart', () => {
    const p = page({ table: ADMIN, tab: 'admin' })

    p.click('lang-it')
    assert.deepEqual(p.order(), [ [ 'add', 'save' ] ])
    assert.deepEqual(p.stored('wgSiteTexts.sort.admin'), { column: 'lang-it', direction: 'asc' })
    assert.equal(p.w.localStorage.getItem('wgSiteTexts.sort.site'), null)
  })
})
