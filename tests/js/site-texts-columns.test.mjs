// The Translation strings screen's columns (src/SiteTexts/site-texts.js,
// columns()), in jsdom: a header's click sorts the rows within each group,
// empty texts last; the order kept per tab and brought back; and the
// table's width as Screen Options hides and shows languages.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

const script = readFileSync(new URL('../../src/SiteTexts/site-texts.js', import.meta.url), 'utf8')

// a row: its key, and its texts by language ('' for none)
const row = (key, texts) => `
  <tr id="${key}">
    <td class="wg-site-texts__key"><div class="wg-site-texts__key-inner"><div class="wg-site-texts__key-body"><code>${key}</code></div></div></td>
    ${Object.entries(texts).map(([ lang, value ]) => `<td class="wg-site-texts__cell column-lang-${lang}" data-lang="${lang}" data-value="${value}"></td>`).join('')}
  </tr>`

const group = (name, rows) => `
  <tbody>
    <tr class="wg-site-texts__group"><th colspan="4" class="colspanchange"><span class="wg-site-texts__group-name">${name}</span></th></tr>
    ${rows.join('')}
  </tbody>`

const SITE = `
  <div class="wg-site-texts__scroll is-wide" style="--wg-site-texts-langs: 3">
    <table class="widefat striped wg-site-texts__table">
      <thead><tr>
        <th class="wg-site-texts__col-key" data-column="key">Key</th>
        <th class="wg-site-texts__col-lang column-lang-it" data-column="lang-it">Italiano</th>
        <th class="wg-site-texts__col-lang column-lang-en" data-column="lang-en">English</th>
        <th class="wg-site-texts__col-lang column-lang-de" data-column="lang-de">Deutsch</th>
      </tr></thead>
      ${group('Footer', [
        row('footer.b', { it: 'Bravo', en: '', de: 'b' }),
        row('footer.a', { it: 'alfa', en: 'Zulu', de: 'a' }),
        row('footer.c', { it: 'Charlie', en: 'Alpha', de: 'c' })
      ])}
      ${group('Common', [
        row('common.z', { it: 'Zeta', en: 'Z', de: 'z' }),
        row('common.y', { it: 'Ypsilon', en: 'Y', de: 'y' })
      ])}
    </table>
  </div>`

// the Admin strings tab: the English text is the first column
const ADMIN = `
  <div class="wg-site-texts__scroll" style="--wg-site-texts-langs: 2">
    <table class="widefat striped wg-site-texts__table">
      <thead><tr>
        <th class="wg-site-texts__col-key" data-column="source">English</th>
        <th class="wg-site-texts__col-lang column-lang-it" data-column="lang-it">Italiano <code>it_IT</code></th>
      </tr></thead>
      <tbody>
        <tr class="wg-site-texts__group"><th colspan="2" class="colspanchange"><code class="wg-site-texts__group-name">wp-go · a.php</code></th></tr>
        <tr><td class="wg-site-texts__cell wg-site-texts__source" data-lang="en" data-value="Save">Save</td><td class="wg-site-texts__cell column-lang-it" data-lang="it_IT" data-value="Salva"></td></tr>
        <tr><td class="wg-site-texts__cell wg-site-texts__source" data-lang="en" data-value="Add">Add</td><td class="wg-site-texts__cell column-lang-it" data-lang="it_IT" data-value=""></td></tr>
      </tbody>
    </table>
  </div>`

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
    <div id="adv-settings"><input type="checkbox" class="hide-column-tog" value="lang-de" checked></div>
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
    // each group's rows, by key (or English text)
    order: () => [ ...doc.querySelectorAll('tbody') ].map(body => [ ...body.rows ]
      .filter(tr => tr.cells.length > 1)
      .map(tr => tr.id || tr.cells[0].dataset.value)),
    stored: key => JSON.parse(w.localStorage.getItem(key))
  }
}

afterEach(() => windows.splice(0).forEach(w => w.close()))

describe('sorting by a column', () => {
  test('makes each header but none other a link, as WordPress\'s sortable columns', () => {
    const p = page()

    for (const column of [ 'key', 'lang-it', 'lang-en', 'lang-de' ]) {
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

  test('sorts by a language\'s texts, ignoring case, an empty text last either way', () => {
    const p = page()

    p.click('lang-it')
    assert.deepEqual(p.order()[0], [ 'footer.a', 'footer.b', 'footer.c' ], 'alfa, Bravo, Charlie')

    p.click('lang-en')
    assert.deepEqual(p.order()[0], [ 'footer.c', 'footer.a', 'footer.b' ], 'Alpha, Zulu, then the empty one')
    assert.ok(p.header('lang-it').classList.contains('sortable'), 'the column sorted before is sortable again')

    p.click('lang-en')
    assert.deepEqual(p.order()[0], [ 'footer.a', 'footer.c', 'footer.b' ], 'Zulu, Alpha, still the empty one last')
  })

  test('keeps the order per tab, and brings it back', () => {
    const p = page()

    p.click('lang-de')
    p.click('lang-de')
    assert.deepEqual(p.stored('wgSiteTexts.sort.site'), { column: 'lang-de', direction: 'desc' })

    const again = page({ stored: { 'wgSiteTexts.sort.site': { column: 'lang-de', direction: 'desc' } } })

    assert.deepEqual(again.order()[0], [ 'footer.c', 'footer.b', 'footer.a' ])
    assert.ok(again.header('lang-de').classList.contains('sorted') && again.header('lang-de').classList.contains('desc'))
  })

  test('leaves the rows as they are when the order kept names no column', () => {
    const p = page({ stored: { 'wgSiteTexts.sort.site': { column: 'lang-fr', direction: 'asc' } } })

    assert.deepEqual(p.order()[0], [ 'footer.b', 'footer.a', 'footer.c' ])
  })

  test('scrolls back to the row the address points to, once sorted', () => {
    const p = page({ stored: { 'wgSiteTexts.sort.site': { column: 'key', direction: 'desc' } }, hash: '#footer.b' })

    assert.equal(p.w.scrolledTo, 'footer.b')
  })

  test('on the Admin strings tab, sorts by the English text, its order kept apart', () => {
    const p = page({ table: ADMIN, tab: 'admin' })

    p.click('source')
    assert.deepEqual(p.order(), [ [ 'Add', 'Save' ] ])
    assert.deepEqual(p.stored('wgSiteTexts.sort.admin'), { column: 'source', direction: 'asc' })
    assert.equal(p.w.localStorage.getItem('wgSiteTexts.sort.site'), null)

    p.click('lang-it')
    assert.deepEqual(p.order(), [ [ 'Save', 'Add' ] ], 'Salva, then the untranslated one')
  })
})

describe('the width, as Screen Options hides languages', () => {
  // WordPress's own handler first: it hides the column's cells
  const toggle = (p, column, shown) => {
    const box = p.doc.querySelector(`.hide-column-tog[value="${column}"]`)

    box.checked = shown
    p.doc.querySelectorAll('.column-' + column).forEach(cell => cell.classList.toggle('hidden', !shown))
    box.dispatchEvent(new p.w.Event('change', { bubbles: true }))
  }

  test('stops scrolling sideways at two languages shown, and scrolls again past two', () => {
    const p = page()
    const scroll = p.doc.querySelector('.wg-site-texts__scroll')

    toggle(p, 'lang-de', false)
    assert.equal(scroll.classList.contains('is-wide'), false)
    assert.equal(scroll.style.getPropertyValue('--wg-site-texts-langs'), '2')

    toggle(p, 'lang-de', true)
    assert.equal(scroll.classList.contains('is-wide'), true)
    assert.equal(scroll.style.getPropertyValue('--wg-site-texts-langs'), '3')
  })
})
