// The Trash's question on the lists (src/Admin/list.js, askTrash()), in
// jsdom: a row's "Trash" and "Move to Trash" for the rows ticked ask first
// when an item has translations (not those in the Trash, nor those ticked
// too), and go on with WordPress's own request, `wgl_all=1` added for "With
// its translations": a row's choices are links, the bulk's submit the
// form. jsdom has no <dialog> methods and can't submit: they're stubbed.

import assert from 'node:assert/strict'
import { afterEach, describe, test } from 'node:test'

import { adminScript } from './_source.mjs'
import { JSDOM } from 'jsdom'

const script = adminScript('list.js')

const TEXTS = {
  trashOne: 'Move “%s” to the Trash',
  trashMany: 'Move %s items to the Trash',
  trashOnly: 'Only this one',
  trashOnlyThese: 'Only these',
  trashOnlyHelp: 'Its translations stay.',
  trashAll: 'With its translations',
  trashAllHelp: '%1$s more: %2$s.',
  cancel: 'Cancel'
}

// a translation's flag in the Languages cell, as languages_cell() prints it
const translation = (id, name, status = 'publish') => `<a class="wgl-lang" href="#edit-${id}" data-wgl-translation="${id}" data-wgl-lang-name="${name}" data-wgl-status="${status}"></a>`

const row = (id, title, translations) => `
  <tr id="post-${id}">
    <th class="check-column"><input type="checkbox" name="post[]" value="${id}"></th>
    <td class="title"><a class="row-title" href="#">${title}</a>
      <div class="row-actions"><a href="post.php?post=${id}&action=trash&_wpnonce=n" class="submitdelete">Trash</a></div></td>
    <td class="column-wgl_languages"><span class="wgl-langs">${translations}</span></td>
  </tr>`

const LIST = `
  <form id="posts-filter" action="edit.php" method="get">
    <select id="bulk-action-selector-top" name="action"><option value="-1">Bulk actions</option><option value="edit">Edit</option><option value="trash">Move to Trash</option></select>
    <input type="submit" id="doaction" value="Apply">
    <table class="wp-list-table"><tbody id="the-list">
      ${row(10, 'Uno', translation(11, 'English') + translation(12, 'Deutsch'))}
      ${row(11, 'One', translation(10, 'Italiano') + translation(12, 'Deutsch'))}
      ${row(20, 'Due', translation(21, 'English', 'trash'))}
      ${row(30, 'Tre', '')}
    </tbody></table>
  </form>
  <table><tbody><tr><td class="row-actions"><a href="post.php?post=40&action=trash" class="submitdelete">Not a row</a></td></tr></tbody></table>`

const windows = []

function page () {
  const dom = new JSDOM(`<!doctype html><body>${LIST}</body>`, { url: 'https://example.test/wp-admin/edit.php?post_type=page', runScripts: 'outside-only' })
  const w = dom.window
  const went = []

  windows.push(w)
  w.HTMLDialogElement.prototype.showModal = function () {
    this.open = true
  }
  w.HTMLDialogElement.prototype.close = function () {
    this.open = false
  }
  w.wglList = { texts: TEXTS }
  w.wp = { apiFetch: async () => ({}), url: { addQueryArgs: path => path } }
  w.eval(script)

  const doc = w.document
  const form = doc.getElementById('posts-filter')

  // where WordPress's form would have gone, with its fields
  w.HTMLFormElement.prototype.submit = function () {
    went.push('submit ' + new w.URLSearchParams(new w.FormData(this)).toString())
  }

  const click = node => node.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }))
  const dialog = () => doc.querySelector('dialog.wgl-dialog')

  return {
    doc,
    went,
    dialog,
    click,
    form,
    trashRow: id => click(doc.querySelector(`#post-${id} a.submitdelete`)),
    bulk: (ids, action = 'trash') => {
      ids.forEach(id => { doc.querySelector(`input[value="${id}"]`).checked = true })
      doc.getElementById('bulk-action-selector-top').value = action
      return click(doc.getElementById('doaction'))
    }
  }
}

afterEach(() => windows.splice(0).forEach(w => w.close()))

describe('a row\'s "Trash"', () => {
  test('asks, for an item with translations: only it, or with them', () => {
    const p = page()

    assert.equal(p.trashRow(10), false, 'the link isn\'t followed yet')
    assert.equal(p.dialog().querySelector('h2').textContent, 'Move “Uno” to the Trash')

    const choices = [ ...p.dialog().querySelectorAll('.wgl-choice') ]

    assert.deepEqual(choices.map(choice => choice.querySelector('strong').textContent), [ 'Only this one', 'With its translations' ])
    assert.equal(choices[1].querySelector('.wgl-choice__help').textContent, '2 more: English, Deutsch.')
    assert.equal(choices[0].href, 'https://example.test/wp-admin/post.php?post=10&action=trash&_wpnonce=n', 'only it: WordPress\'s own link')
    assert.equal(choices[1].href, 'https://example.test/wp-admin/post.php?post=10&action=trash&_wpnonce=n&wgl_all=1', 'with them: wgl_all=1 added')
  })

  test('leaves a translation in the Trash out, and asks nothing when none is left', () => {
    const p = page()

    assert.equal(p.trashRow(20), true, 'followed: WordPress trashes it')
    assert.equal(p.dialog(), null)
  })

  test('asks nothing for an item without translations', () => {
    const p = page()

    assert.equal(p.trashRow(30), true)
    assert.equal(p.dialog(), null)
  })

  test('isn\'t caught outside the list\'s rows', () => {
    const p = page()

    assert.equal(p.click(p.doc.querySelector('a[href*="post=40"]')), true)
    assert.equal(p.dialog(), null)
  })
})

describe('"Move to Trash" for the rows ticked', () => {
  test('asks, with their translations not ticked', () => {
    const p = page()

    assert.equal(p.bulk([ 10, 30 ]), false, 'not submitted yet')
    assert.equal(p.dialog().querySelector('h2').textContent, 'Move 2 items to the Trash')
    assert.equal(p.dialog().querySelectorAll('.wgl-choice')[1].querySelector('.wgl-choice__help').textContent, '2 more: English, Deutsch.')
  })

  test('counts each translation once, those ticked left out', () => {
    const p = page()

    p.bulk([ 10, 11 ])
    assert.equal(p.dialog().querySelectorAll('.wgl-choice')[1].querySelector('.wgl-choice__help').textContent, '1 more: Deutsch.')
  })

  test('submits WordPress\'s form as it was for "Only these", with wgl_all=1 for the other', () => {
    const p = page()

    p.bulk([ 10 ])
    p.click(p.dialog().querySelectorAll('.wgl-choice')[0])
    assert.deepEqual(p.went, [ 'submit action=trash&post%5B%5D=10' ])

    const q = page()

    q.bulk([ 10 ])
    q.click(q.dialog().querySelectorAll('.wgl-choice')[1])
    assert.deepEqual(q.went, [ 'submit action=trash&post%5B%5D=10&wgl_all=1' ])
  })

  test('asks nothing when no row ticked has a translation left to take', () => {
    const p = page()

    assert.equal(p.bulk([ 20, 30 ]), true)
    assert.equal(p.dialog(), null)
  })

  test('leaves the other bulk actions alone', () => {
    const p = page()

    assert.equal(p.bulk([ 10 ], 'edit'), true)
    assert.equal(p.dialog(), null)
  })
})
