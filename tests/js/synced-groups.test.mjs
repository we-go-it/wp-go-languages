// A group's box and what a mark replaced (src/Admin/_synced.js): a group locks and
// shows its fields, a field its own mark or an inner group decides is left alone,
// and an untick puts back what the tick replaced, from the form or from what was
// saved. ACF is faked; the form is jsdom's.

import assert from 'node:assert/strict'
import { describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

import { initBoxes } from '../../src/Admin/_synced.js'

const attr = value => JSON.stringify(value).replace(/"/g, '&quot;')

function sync ({ mark, type = 'text', live = 1, value = null, checked = false, before, part = 'whole' }) {
  return `<span class="wgl-sync" data-type="${type}" data-live="${live}" data-block="0" data-value="${attr(value)}"><label class="wgl-sync__part"><input type="checkbox" class="wgl-sync__box" data-mark="${mark}" data-part="${part}"${checked ? ' checked' : ''}${before !== undefined ? ` data-before="${attr(before)}"` : ''}></label></span>`
}

function field (name, inner, box) {
  return `<div class="acf-field" data-name="${name}"><div class="acf-label"><label>${name}</label></div><div class="acf-input">${inner}${box}</div></div>`
}

function screen (html, values = {}) {
  const document = new JSDOM(`<form>${html}</form>`).window.document
  const store = { ...values }
  const env = {
    document,
    $: element => element,
    acf: {
      getField: element => ({
        val: value => {
          if (value === undefined) {
            return store[element.dataset.name]
          }

          store[element.dataset.name] = value
        }
      })
    }
  }

  initBoxes(document, env)

  const box = name => document.querySelector(`[data-name="${name}"] > .acf-label .wgl-sync__box`)
  const set = (name, on) => {
    box(name).checked = on
    box(name).dispatchEvent(new document.defaultView.Event('change'))
  }

  return { document, store, box, set }
}

const text = (name, options = {}) => field(name, '<input type="text">', sync({ mark: name, value: `${name} original`, ...options }))
const group = (name, inner, options = {}) => field(name, `<div class="acf-fields">${inner}</div>`, sync({ mark: `${name}#group:field_${name}`, type: 'group', live: 0, ...options }))
const unlocked = (document, name) => !document.querySelector(`[data-name="${name}"] > .acf-input > .acf-fields`).hasAttribute('inert')

describe('an untick', () => {
  test('puts back what the tick replaced, in the same form', () => {
    const { store, set } = screen(text('title'), { title: 'Own' })

    set('title', true)
    assert.equal(store.title, 'title original')
    set('title', false)
    assert.equal(store.title, 'Own')
  })

  test('a second tick and untick still end on the value it had', () => {
    const { store, set } = screen(text('title'), { title: 'Own' })

    set('title', true)
    set('title', false)
    set('title', true)
    set('title', false)
    assert.equal(store.title, 'Own')
  })

  test('puts back what was saved before it, when the form opened ticked', () => {
    const { store, set } = screen(text('title', { checked: true, before: 'Saved' }), { title: 'title original' })

    set('title', false)
    assert.equal(store.title, 'Saved')
  })

  test('with nothing remembered, leaves the value', () => {
    const { store, set } = screen(text('title', { checked: true }), { title: 'title original' })

    set('title', false)
    assert.equal(store.title, 'title original')
  })

  test('a link\'s page and label go back apart', () => {
    const own = { title: 'Label', url: '/own', target: '' }
    const link = field('cta', '<input type="text">', sync({ mark: 'cta', type: 'link', value: { title: 'Etichetta', url: '/it', target: '' }, part: 'page' }))
    const { store, set } = screen(link, { cta: own })

    set('cta', true)
    assert.deepEqual(store.cta, { title: 'Label', url: '/it', target: '' })
    set('cta', false)
    assert.deepEqual(store.cta, own)
  })
})

describe('a group\'s box', () => {
  const form = (options = {}) => group('media', text('alt') + text('caption', options))

  test('moves beside the group\'s title, not a field\'s', () => {
    const { document } = screen(form())

    assert.ok(document.querySelector('[data-name="media"] > .acf-label > .wgl-sync[data-type="group"]'))
  })

  test('ticked, locks what it holds, and shows the original\'s', () => {
    const { document, store, set } = screen(form(), { alt: 'Own alt', caption: 'Own caption' })

    set('media', true)
    assert.equal(unlocked(document, 'media'), false)
    assert.ok(document.querySelector('[data-name="media"]').classList.contains('wgl-synced--group'))
    assert.equal(store.alt, 'alt original')
    assert.equal(store.caption, 'caption original')
  })

  test('unticked, frees them, and puts back what they had', () => {
    const { document, store, set } = screen(form(), { alt: 'Own alt', caption: 'Own caption' })

    set('media', true)
    set('media', false)
    assert.equal(unlocked(document, 'media'), true)
    assert.equal(document.querySelector('[data-name="media"]').classList.contains('wgl-synced--group'), false)
    assert.equal(store.alt, 'Own alt')
    assert.equal(store.caption, 'Own caption')
  })

  test('its fields\' own boxes stay as they were', () => {
    const { box, set } = screen(form({ checked: true }), { alt: 'Own alt', caption: 'caption original' })

    set('media', true)
    assert.equal(box('alt').checked, false)
    assert.equal(box('caption').checked, true)
  })

  test('unticked, a field with its own mark keeps following', () => {
    const { store, set } = screen(form({ checked: true }), { alt: 'Own alt', caption: 'caption original' })

    set('media', true)
    set('media', false)
    assert.equal(store.caption, 'caption original')
    assert.equal(store.alt, 'Own alt')
  })

  test('opened ticked, unticking puts back what was saved', () => {
    const html = group('media', text('alt', { before: 'Saved alt' }), { checked: true })
    const { document, store, set } = screen(html, { alt: 'alt original' })

    assert.equal(unlocked(document, 'media'), false)
    set('media', false)
    assert.equal(store.alt, 'Saved alt')
  })

  test('an inner group\'s own tick keeps its fields following', () => {
    const html = group('outer', text('a') + group('inner', text('b'), { checked: true }))
    const { store, set } = screen(html, { a: 'Own a', b: 'b original' })

    set('outer', true)
    set('outer', false)
    assert.equal(store.a, 'Own a')
    assert.equal(store.b, 'b original')
  })

  test('what can\'t be shown isn\'t touched: it comes back on save', () => {
    const html = group('media', field('image', '<input type="hidden">', sync({ mark: 'image', type: 'image', live: 0 })))
    const { store, set } = screen(html, { image: '5' })

    set('media', true)
    assert.equal(store.image, '5')
  })

  test('its mark is posted with the rest', () => {
    const { document, set } = screen(form(), { alt: '', caption: '' })

    set('media', true)

    const posted = name => [ ...document.querySelectorAll(`input[name="${name}"]`) ].map(input => input.value)

    assert.deepEqual(posted('wgl_synced[]'), [ 'media#group:field_media' ])
    assert.deepEqual(posted('wgl_synced_shown[]').sort(), [ 'alt', 'caption', 'media#group:field_media' ])
  })
})
