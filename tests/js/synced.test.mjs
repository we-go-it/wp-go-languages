// Synced fields' boxes in ACF's forms (src/Admin/_synced.js): moved beside the
// title, the field locked while ticked, the original's value shown at once, a
// link's page and label apart, a block's marks in its metadata. ACF and wp.data
// are faked; the form is jsdom's.

import assert from 'node:assert/strict'
import { describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

import { formMarks, initBoxes } from '../../src/Admin/_synced.js'

function box (mark, part, checked = false) {
  return `<label><input type="checkbox" class="wgl-sync__box" data-mark="${mark}" data-part="${part}"${checked ? ' checked' : ''}></label>`
}

function sync ({ type = 'text', live = 1, block = 0, value = 'Titolo', boxes = box('title', 'whole') } = {}) {
  const json = JSON.stringify(value).replace(/"/g, '&quot;')

  return `<span class="wgl-sync" data-type="${type}" data-live="${live}" data-block="${block}" data-value="${json}" data-label="Label">${boxes}</span>`
}

function screen (html, { blocks = [], selected = null, fieldValue = '' } = {}) {
  const document = new JSDOM(`<!doctype html><body>${html}</body>`).window.document
  const done = []
  const set = { value: fieldValue }
  const field = {
    val: value => {
      if (value === undefined) {
        return set.value
      }

      set.value = value
      done.push([ 'val', value ])
    }
  }
  const env = {
    document,
    $: element => element,
    acf: { getField: () => field },
    wp: {
      data: {
        select: () => ({ getBlocks: () => blocks, getSelectedBlock: () => selected }),
        dispatch: () => ({ updateBlockAttributes: (id, attributes) => done.push([ 'attributes', id, attributes ]) })
      }
    }
  }

  initBoxes(document, env)

  return { document, done, field, set, env }
}

const fieldHtml = (inner, input = '<input type="text">') => `<div class="acf-field"><div class="acf-label"><label>Title</label></div><div class="acf-input">${input}${inner}<p class="wgl-original">Original</p></div></div>`

describe('a box', () => {
  test('moves beside the title', () => {
    const { document } = screen(fieldHtml(sync()))

    assert.ok(document.querySelector('.acf-label > .wgl-sync'))
  })

  test('in a table\'s cell, with no title of its own, stays at the top of its input', () => {
    const { document } = screen(`<div class="acf-field"><div class="acf-input"><input type="text">${sync()}</div></div>`)

    assert.equal(document.querySelector('.acf-input').firstElementChild.className, 'wgl-sync')
  })

  test('not ticked, the field is free', () => {
    const { document } = screen(fieldHtml(sync()))

    assert.equal(document.querySelector('.acf-input input').hasAttribute('inert'), false)
  })

  test('ticked as it\'s drawn, the field is locked, and its value not touched', () => {
    const { document, done } = screen(fieldHtml(sync({ boxes: box('title', 'whole', true) })))

    assert.equal(document.querySelector('.acf-input input').hasAttribute('inert'), true)
    assert.ok(document.querySelector('.acf-field').classList.contains('wgl-synced--all'))
    assert.deepEqual(done, [])
  })

  test('ticked, it locks the field and shows the original\'s; unticked, it frees it and keeps the value', () => {
    const { document, done, set } = screen(fieldHtml(sync()))
    const check = document.querySelector('.wgl-sync__box')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.equal(set.value, 'Titolo')
    assert.equal(document.querySelector('.acf-input input').hasAttribute('inert'), true)

    check.checked = false
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.equal(set.value, 'Titolo')
    assert.equal(document.querySelector('.acf-input input').hasAttribute('inert'), false)
    assert.equal(done.filter(([ what ]) => what === 'val').length, 1)
  })

  test('the original\'s line stays out of the lock', () => {
    const { document } = screen(fieldHtml(sync({ boxes: box('title', 'whole', true) })))

    assert.equal(document.querySelector('.wgl-original').hasAttribute('inert'), false)
  })

  test('a type it can\'t show at once locks all the same, its value left, with no text added', () => {
    const { document, done } = screen(fieldHtml(sync({ type: 'image', live: 0, value: null })))
    const check = document.querySelector('.wgl-sync__box')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.equal(document.querySelector('.wgl-sync').textContent, '')
    assert.equal(document.querySelector('.acf-input input').hasAttribute('inert'), true)
    assert.deepEqual(done, [])
  })

  test('isn\'t bound twice when a form is drawn again', () => {
    const { document, done, env } = screen(fieldHtml(sync()))

    initBoxes(document, env)

    const check = document.querySelector('.wgl-sync__box')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.equal(done.length, 1)
  })
})

describe('a link', () => {
  const boxes = (page = false, label = false) => box('cta', 'page', page) + box('cta#title', 'label', label)
  const link = { url: 'http://site/en/page/', title: 'Scopri', target: '' }
  const own = { url: 'http://own/', title: 'Discover', target: '_blank' }

  test('its page ticked: the address is the original\'s, translated; the label stays', () => {
    const { document, set } = screen(fieldHtml(sync({ type: 'link', value: link, boxes: boxes() }), '<div class="acf-link"></div>'), { fieldValue: own })
    const check = document.querySelector('[data-part="page"]')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.deepEqual(set.value, { url: 'http://site/en/page/', title: 'Discover', target: '' })
    assert.equal(document.querySelector('.acf-link').hasAttribute('inert'), true)
  })

  test('its page alone ticked: its label has a field of its own, writing the label only', () => {
    const { document, set } = screen(fieldHtml(sync({ type: 'link', value: link, boxes: boxes(true) }), '<div class="acf-link"></div>'), { fieldValue: own })
    const text = document.querySelector('.wgl-sync__label input')

    assert.equal(text.value, 'Discover')
    assert.equal(text.closest('[inert]'), null)

    text.value = 'Find out'
    text.dispatchEvent(new document.defaultView.Event('input'))

    assert.deepEqual(set.value, { ...own, title: 'Find out' })
  })

  test('its label ticked too: no such field, all locked', () => {
    const { document } = screen(fieldHtml(sync({ type: 'link', value: link, boxes: boxes(true, true) }), '<div class="acf-link"></div>'), { fieldValue: own })

    assert.equal(document.querySelector('.wgl-sync__label'), null)
    assert.equal(document.querySelector('.acf-link').hasAttribute('inert'), true)
    assert.ok(document.querySelector('.acf-field').classList.contains('wgl-synced--all'))
  })

  test('its label alone ticked: the link stays free, the label the original\'s', () => {
    const { document, set } = screen(fieldHtml(sync({ type: 'link', value: link, boxes: boxes() }), '<div class="acf-link"></div>'), { fieldValue: own })
    const check = document.querySelector('[data-part="label"]')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.deepEqual(set.value, { ...own, title: 'Scopri' })
    assert.equal(document.querySelector('.acf-link').hasAttribute('inert'), false)
  })
})

describe('a block\'s form', () => {
  const form = (id, inner) => `<div class="acf-block-fields" data-block-id="${id}">${inner}</div>`
  const block = { clientId: 'c1', attributes: { id: 'block_abc', metadata: { wgId: 'x', wgSynced: [ 'dormant' ] } }, innerBlocks: [] }

  test('writes its marks in the block\'s metadata, keeping what it doesn\'t show', () => {
    const { document, done } = screen(form('block_abc', fieldHtml(sync({ block: 1 }))), { blocks: [ block ] })
    const check = document.querySelector('.wgl-sync__box')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.deepEqual(done.at(-1), [ 'attributes', 'c1', { metadata: { wgId: 'x', wgSynced: [ 'dormant', 'title' ] } } ])
  })

  test('none left: the key goes', () => {
    const only = { clientId: 'c1', attributes: { id: 'block_abc', metadata: { wgId: 'x', wgSynced: [ 'title' ] } }, innerBlocks: [] }
    const { document, done } = screen(form('block_abc', fieldHtml(sync({ block: 1, boxes: box('title', 'whole', true) }))), { blocks: [ only ] })
    const check = document.querySelector('.wgl-sync__box')

    check.checked = false
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.deepEqual(done.at(-1), [ 'attributes', 'c1', { metadata: { wgId: 'x' } } ])
  })

  test('finds an inner block, and one known by its client ID', () => {
    const inner = { clientId: 'inner', attributes: { metadata: {} }, innerBlocks: [] }
    const parent = { clientId: 'parent', attributes: {}, innerBlocks: [ inner ] }
    const { document, done } = screen(form('block_inner', fieldHtml(sync({ block: 1 }))), { blocks: [ parent ] })
    const check = document.querySelector('.wgl-sync__box')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.equal(done.at(-1)[1], 'inner')
  })

  test('a page\'s form writes nothing: it\'s posted', () => {
    const { document, done } = screen(fieldHtml(sync({ value: 'x' })), { blocks: [ block ] })
    const check = document.querySelector('.wgl-sync__box')

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.equal(done.some(([ what ]) => what === 'attributes'), false)
  })

  test('formMarks: its boxes as they are', () => {
    const document = new JSDOM(`<!doctype html><body><div id="f">${box('a', 'whole', true)}${box('b', 'whole')}</div></body>`).window.document

    assert.deepEqual(formMarks(document.getElementById('f'), [ 'b', 'c' ]), [ 'c', 'a' ])
  })
})

describe('a page\'s form', () => {
  const form = inner => `<form id="f">${inner}</form>`
  const posted = (document, name) => [ ...new document.defaultView.FormData(document.getElementById('f')).entries() ].filter(([ key ]) => key === name).map(([ , value ]) => value)

  test('posts each box shown, and those ticked, from hidden inputs out of any field', () => {
    const { document } = screen(form(fieldHtml(sync({ boxes: box('title', 'whole', true) + box('other', 'whole') }))))

    assert.deepEqual(posted(document, 'wgl_synced_shown[]'), [ 'title', 'other' ])
    assert.deepEqual(posted(document, 'wgl_synced[]'), [ 'title' ])
    assert.equal(document.querySelector('.wgl-sync-posted').closest('.acf-field'), null)
  })

  test('as they change', () => {
    const { document } = screen(form(fieldHtml(sync())))
    const check = document.querySelector('.wgl-sync__box')

    assert.deepEqual(posted(document, 'wgl_synced[]'), [])

    check.checked = true
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.deepEqual(posted(document, 'wgl_synced[]'), [ 'title' ])

    check.checked = false
    check.dispatchEvent(new document.defaultView.Event('change'))

    assert.deepEqual(posted(document, 'wgl_synced[]'), [])
    assert.deepEqual(posted(document, 'wgl_synced_shown[]'), [ 'title' ])
  })

  test('a box has no name: ACF writes a field\'s value to its first named input', () => {
    const { document } = screen(form(fieldHtml(sync())))

    assert.equal(document.querySelector('.acf-field').querySelector('[name]'), null)
  })

  test('a block\'s boxes aren\'t posted', () => {
    const { document } = screen(form(fieldHtml(sync({ block: 1 }))))

    assert.equal(document.querySelector('.wgl-sync-posted'), null)
  })

  test('a click on a box doesn\'t reach the label, which may be a collapsible\'s', () => {
    const { document } = screen(form(fieldHtml(sync())))
    let reached = false

    document.querySelector('.acf-label').addEventListener('click', () => { reached = true })
    document.querySelector('.wgl-sync__box').click()

    assert.equal(reached, false)
    assert.equal(document.querySelector('.wgl-sync__box').checked, true)
  })
})
