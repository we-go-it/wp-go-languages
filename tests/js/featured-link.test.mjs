// What the featured image's link edits in the post (src/Admin/_featured-link.js).

import assert from 'node:assert/strict'
import { test } from 'node:test'

import { linkEdits } from '../../src/Admin/_featured-link.js'

const wgl = (thumbnail, rest = {}) => ({ mode: 'blocks', languages: [], ...rest, thumbnail })

test('ticked: shows the original\'s, and keeps what it had', () => {
  const { wgl: edited, featured_media: media } = linkEdits(wgl({ linked: false, original: 5, before: null }), true, 9)

  assert.equal(media, 5)
  assert.equal(edited.thumbnail.linked, true)
  assert.equal(edited.thumbnail.before, 9)
})

test('unticked in the same form: goes back', () => {
  const on = linkEdits(wgl({ linked: false, original: 5, before: null }), true, 9)
  const off = linkEdits(on.wgl, false, 5)

  assert.equal(off.featured_media, 9)
  assert.equal(off.wgl.thumbnail.linked, false)
})

test('unticked after a save: goes back to what the server kept', () => {
  assert.equal(linkEdits(wgl({ linked: true, original: 5, before: 9 }), false, 5).featured_media, 9)
})

test('it had none: goes back to none', () => {
  assert.equal(linkEdits(wgl({ linked: true, original: 5, before: 0 }), false, 5).featured_media, 0)
})

test('nothing kept: the image stays', () => {
  assert.equal('featured_media' in linkEdits(wgl({ linked: true, original: 5, before: null }), false, 5), false)
})

test('the rest of the post\'s data goes back as it came', () => {
  assert.equal(linkEdits(wgl({ linked: false, original: 5, before: null }, { mode: 'independent' }), true, 9).wgl.mode, 'independent')
})
