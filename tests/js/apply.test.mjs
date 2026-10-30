// Where the editor's AI dialog puts a translation, unsaved (src/Admin/_apply.js):
// the block editor's state (title and a draft's slug, excerpt, a block's HTML,
// an ACF block's field by name or by key, a link's title), Yoast's store or its
// hidden field, ACF's page fields (the nth of a key, a link, a WYSIWYG's editor),
// a form's fields where there's no block editor (a term, a classic screen), and
// a contact form's mails, MJML and messages in CF7's form.
// wp.data, wp.blocks, ACF and TinyMCE are faked; the form is jsdom's.

import assert from 'node:assert/strict'
import { describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

import { applier } from '../../src/Admin/_apply.js'

const texts = { aiNotHere: 'Not here.' }

/**
 * A screen: `blocks` (the block editor's, with their IDs) or none (a form);
 * `html` the page's form; `yoast`, `acf`, `tinymce` there or not. What was
 * done is noted in `done`.
 */
function screen ({ blocks = null, html = '', yoast = false, acfFields = null, editors = {} } = {}) {
  const document = new JSDOM(`<!doctype html><body>${html}</body>`).window.document
  const done = []
  const stores = {
    'core/block-editor': blocks && { getBlocks: () => blocks },
    'core/editor': blocks && { getCurrentPostId: () => 7 },
    'yoast-seo/editor': yoast && {}
  }
  const wp = {
    data: {
      select: name => stores[name],
      dispatch: name => ({
        editPost: edits => done.push([ 'editPost', edits ]),
        updateBlockAttributes: (id, attributes) => done.push([ 'update', id, attributes ]),
        updateData: data => done.push([ 'yoast', data ]),
        setFacebookPreviewTitle: text => done.push([ 'facebook', text ]),
        setFacebookPreviewDescription: text => done.push([ 'facebook', text ]),
        setTwitterPreviewTitle: text => done.push([ 'twitter', text ]),
        setTwitterPreviewDescription: text => done.push([ 'twitter', text ])
      })
    },
    blocks: {
      // a block's markup: its content between delimiters; parsing gives it back as an attribute
      serialize: block => `<!-- wp:${block.name} -->${block.html}<!-- /wp:${block.name} -->`,
      getBlockContent: block => block.html,
      parse: markup => [ { attributes: { content: markup.replace(/<!--[^>]*-->/g, '') } } ]
    },
    url: { cleanForSlug: text => text.toLowerCase().replace(/\W+/g, '-') }
  }
  const acf = acfFields && {
    getFields: ({ key }) => acfFields.filter(field => field.key === key)
  }
  const tinymce = {
    get: id => editors[id] && { setContent: text => done.push([ 'tinymce', id, text ]) }
  }

  return { document, done, wp, acf, tinymce }
}

const apply = (place, state = {}) => applier({ fields: [], ...state }, { wp: place.wp, texts, document: place.document, tinymce: place.tinymce, acf: place.acf })

// an ACF form field, as acf.getFields() gives them
const acfField = (key, value, id = '') => ({
  key,
  value,
  val (v) {
    if (v === undefined) {
      return this.value
    }

    this.value = v
  },
  $input: () => ({ attr: () => id })
})

describe('in the block editor', () => {
  const blocks = [
    { clientId: 'c1', name: 'paragraph', html: '<p>Ciao</p>', attributes: { metadata: { wgId: 'b1' } }, innerBlocks: [] },
    {
      clientId: 'c2',
      name: 'group',
      html: '',
      attributes: {},
      innerBlocks: [
        { clientId: 'c3', name: 'acf/hero', html: '', attributes: { metadata: { wgId: 'b2' }, data: { title: 'Ciao', _title: 'field_t', cta: { title: 'Vai', url: '/x' } } }, innerBlocks: [] },
        { clientId: 'c4', name: 'acf/edited', html: '', attributes: { metadata: { wgId: 'b3' }, data: { field_t: 'Ciao' } }, innerBlocks: [] }
      ]
    }
  ]

  test('the title, and a draft\'s slug while it\'s the original\'s', () => {
    const place = screen({ blocks })

    assert.equal(apply(place, { slug_follows: true })('post:title', 'About us'), '')
    assert.deepEqual(place.done, [ [ 'editPost', { title: 'About us' } ], [ 'editPost', { slug: 'about-us' } ] ])

    const other = screen({ blocks })

    apply(other)('post:title', 'About us')
    assert.deepEqual(other.done, [ [ 'editPost', { title: 'About us' } ] ], 'a slug of its own stays')
  })

  test('the excerpt', () => {
    const place = screen({ blocks })

    apply(place)('post:excerpt', 'In short')
    assert.deepEqual(place.done, [ [ 'editPost', { excerpt: 'In short' } ] ])
  })

  test('a block\'s HTML, parsed into its attributes; a block it can\'t find, said so', () => {
    const place = screen({ blocks })

    assert.equal(apply(place)('block:b1', '<p>Hello</p>'), '')
    assert.deepEqual(place.done, [ [ 'update', 'c1', { content: '<p>Hello</p>' } ] ])
    assert.equal(apply(place)('block:nope', '<p>x</p>'), 'Not here.')
  })

  test('an ACF block\'s field, by name, by its key once edited, a link\'s title; inner blocks too', () => {
    const place = screen({ blocks })
    const state = { fields: [ { key: 'acf:b3/title', field: 'field_t' } ] }

    apply(place)('acf:b2/title', 'Hello')
    apply(place)('acf:b2/cta#title', 'Go')
    apply(place, state)('acf:b3/title', 'Hello')

    assert.deepEqual(place.done.map(([ , id, attributes ]) => [ id, attributes.data.title ?? attributes.data.field_t, attributes.data.cta?.title ]), [
      [ 'c3', 'Hello', 'Vai' ],
      [ 'c3', 'Ciao', 'Go' ],
      [ 'c4', 'Hello', undefined ]
    ])
    assert.equal(place.done[1][2].data.cta.url, '/x', 'the link\'s address kept')
    assert.equal(apply(place)('acf:b2/missing', 'x'), 'Not here.')
  })

  test('an ACF block\'s field its form has rewritten by key: in its clone, in a repeater\'s rows', () => {
    // the hero's data once its form is drawn: the title clone's fields under
    // it, with its key before theirs; a repeater's rows by id
    const hero = {
      clientId: 'c9',
      name: 'acf/hero',
      attributes: {
        metadata: { wgId: 'h1' },
        data: {
          field_hero_title: { field_hero_title_field_text_rich: 'Ciao', field_hero_title_field_tag: 'h1' },
          field_items: { 'row-0': { field_item_title: 'A' }, 'row-1': { field_item_title: 'B' } }
        }
      },
      innerBlocks: []
    }
    const place = screen({ blocks: [ hero ] })
    const state = {
      fields: [
        { key: 'acf:h1/title_text_rich', field: 'field_text_rich' },
        { key: 'acf:h1/items_1_title', field: 'field_item_title' },
        { key: 'acf:h1/nope', field: 'field_nope' }
      ]
    }

    assert.equal(apply(place, state)('acf:h1/title_text_rich', '<p>Hello</p>'), '')
    assert.deepEqual(place.done[0][2].data.field_hero_title, { field_hero_title_field_text_rich: '<p>Hello</p>', field_hero_title_field_tag: 'h1' }, 'the clone\'s field set, its other kept')

    assert.equal(apply(place, state)('acf:h1/items_1_title', 'Bee'), '')
    assert.deepEqual(place.done[1][2].data.field_items, { 'row-0': { field_item_title: 'A' }, 'row-1': { field_item_title: 'Bee' } }, 'the row its name says')

    assert.equal(apply(place, state)('acf:h1/nope', 'x'), 'Not here.')
  })

  test('a block with no ID yet, by its place: `@1.0`, the first in the second', () => {
    const place = screen({ blocks })

    assert.equal(apply(place)('acf:@1.0/title', 'Hello'), '')
    assert.equal(apply(place)('block:@0', '<p>Hello</p>'), '')
    assert.deepEqual(place.done.map(([ , id ]) => id), [ 'c3', 'c1' ])
    assert.equal(apply(place)('block:@5', '<p>x</p>'), 'Not here.', 'a place with no block')
    assert.equal(apply(place)('acf:@0.3/title', 'x'), 'Not here.')
  })
})

describe('page fields', () => {
  test('Yoast\'s, through its store', () => {
    const place = screen({ blocks: [], yoast: true })

    apply(place)('meta:_yoast_wpseo_title', 'Title')
    apply(place)('meta:_yoast_wpseo_metadesc', 'Description')
    apply(place)('meta:_yoast_wpseo_opengraph-title', 'Social')
    assert.deepEqual(place.done, [ [ 'yoast', { title: 'Title' } ], [ 'yoast', { description: 'Description' } ], [ 'facebook', 'Social' ] ])
  })

  test('Yoast\'s, without its store or for one it has no action for: its hidden field', () => {
    const place = screen({ html: '<input id="yoast_wpseo_title"><input id="yoast_wpseo_bctitle">' })

    assert.equal(apply(place)('meta:_yoast_wpseo_title', 'Title'), '')
    assert.equal(apply(place)('meta:_yoast_wpseo_bctitle', 'Crumb'), '')
    assert.deepEqual([ place.document.getElementById('yoast_wpseo_title').value, place.document.getElementById('yoast_wpseo_bctitle').value ], [ 'Title', 'Crumb' ])
  })

  test('ACF\'s: the nth with its key, a link\'s title, a WYSIWYG\'s editor told', () => {
    const rows = [ acfField('field_row', 'Uno'), acfField('field_row', 'Due') ]
    const link = acfField('field_link', { title: 'Vai', url: '/x' })
    const wysiwyg = acfField('field_text', '<p>Ciao</p>', 'acf-editor-1')
    const place = screen({ acfFields: [ ...rows, link, wysiwyg ], editors: { 'acf-editor-1': true } })
    const state = {
      fields: [
        { key: 'meta:rows_1_title', field: 'field_row', nth: 1 },
        { key: 'meta:link#title', field: 'field_link', nth: 0 },
        { key: 'meta:text', field: 'field_text', nth: 0 }
      ]
    }

    apply(place, state)('meta:rows_1_title', 'Two')
    apply(place, state)('meta:link#title', 'Go')
    apply(place, state)('meta:text', '<p>Hello</p>')

    assert.deepEqual([ rows[0].value, rows[1].value ], [ 'Uno', 'Two' ], 'the second row\'s')
    assert.deepEqual(link.value, { title: 'Go', url: '/x' })
    assert.equal(wysiwyg.value, '<p>Hello</p>')
    assert.deepEqual(place.done, [ [ 'tinymce', 'acf-editor-1', '<p>Hello</p>' ] ])
  })

  test('a field ACF\'s form doesn\'t have, or no ACF: said so', () => {
    const place = screen({ acfFields: [] })

    assert.equal(apply(place, { fields: [ { key: 'meta:x', field: 'field_x', nth: 0 } ] })('meta:x', 'y'), 'Not here.')
    assert.equal(apply(screen())('meta:x', 'y'), 'Not here.')
  })
})

describe('without the block editor', () => {
  test('a term\'s name and description, in its form', () => {
    const place = screen({ html: '<input id="name" value="Notizie"><textarea id="description"></textarea>' })
    let changed = 0

    place.document.getElementById('name').addEventListener('change', () => changed++)

    apply(place)('term:name', 'News')
    apply(place)('term:description', 'The latest.')
    assert.deepEqual([ place.document.getElementById('name').value, place.document.getElementById('description').value ], [ 'News', 'The latest.' ])
    assert.equal(changed, 1, 'as typing would: input and change')
  })

  test('a classic screen\'s title, and its content in its editor or textarea', () => {
    const place = screen({ html: '<input id="title"><textarea id="content"></textarea>', editors: { content: true } })

    apply(place)('post:title', 'About us')
    apply(place)('post:content', '<p>Hello</p>')
    assert.equal(place.document.getElementById('title').value, 'About us')
    assert.deepEqual(place.done, [ [ 'tinymce', 'content', '<p>Hello</p>' ] ])

    const bare = screen({ html: '<textarea id="content"></textarea>' })

    apply(bare)('post:content', '<p>Hello</p>')
    assert.equal(bare.document.getElementById('content').value, '<p>Hello</p>')
  })

  test('a field the screen doesn\'t have: said so', () => {
    assert.equal(apply(screen())('term:name', 'x'), 'Not here.')
    assert.equal(apply(screen())('block:b1', 'x'), 'Not here.', 'no block editor, no blocks')
  })
})

describe('a contact form, in CF7\'s form', () => {
  const FORM = `
    <input name="wpcf7-mail[subject]"><textarea name="wpcf7-mail[body]"></textarea>
    <input name="wpcf7-mail-2[subject]"><textarea name="wpcf7-mail-2[body]"></textarea>
    <input name="wpcf7-messages[mail_sent_ok]"><input name="wpcf7-messages[wg_required]">`
  const value = (place, name) => place.document.querySelector(`[name="${name}"]`).value

  test('each mail\'s subject and body, and the messages, as typing would', () => {
    const place = screen({ html: FORM })
    const events = []

    place.document.querySelector('[name="wpcf7-mail[body]"]').addEventListener('input', () => events.push('input'))
    place.document.querySelector('[name="wpcf7-mail[body]"]').addEventListener('change', () => events.push('change'))

    assert.equal(apply(place)('cf7:mail/subject', 'New contact: [your-name]'), '')
    assert.equal(apply(place)('cf7:mail/body', 'From: [your-name]'), '')
    assert.equal(apply(place)('cf7:mail_2/subject', 'Thanks, [your-name]'), '')
    assert.equal(apply(place)('cf7:mail_2/body', '<html>thanks</html>'), '')
    assert.equal(apply(place)('cf7:messages/wg_required', 'Required.'), '')
    assert.deepEqual([
      value(place, 'wpcf7-mail[subject]'),
      value(place, 'wpcf7-mail[body]'),
      value(place, 'wpcf7-mail-2[subject]'),
      value(place, 'wpcf7-mail-2[body]'),
      value(place, 'wpcf7-messages[wg_required]'),
      value(place, 'wpcf7-messages[mail_sent_ok]')
    ], [ 'New contact: [your-name]', 'From: [your-name]', 'Thanks, [your-name]', '<html>thanks</html>', 'Required.', '' ])
    assert.deepEqual(events, [ 'input', 'change' ])
  })

  test('a mail\'s MJML through cf7-mjml\'s window.wgMjml, which compiles it', () => {
    const place = screen({ html: FORM })
    const set = []

    place.document.defaultView.wgMjml = { setSource: (which, source) => set.push([ which, source ]) && Promise.resolve() }

    assert.equal(apply(place)('cf7:mail_2/mjml', '<mjml>en</mjml>'), '')
    assert.deepEqual(set, [ [ 'mail_2', '<mjml>en</mjml>' ] ])
    assert.equal(value(place, 'wpcf7-mail-2[body]'), '', 'the body is the compile\'s, not written here')
  })

  test('MJML off, or no cf7-mjml: said so', () => {
    const place = screen({ html: FORM })

    assert.equal(apply(place)('cf7:mail/mjml', '<mjml>en</mjml>'), 'Not here.', 'no window.wgMjml')

    place.document.defaultView.wgMjml = { setSource: () => false }
    assert.equal(apply(place)('cf7:mail/mjml', '<mjml>en</mjml>'), 'Not here.', 'its MJML off')
  })

  test('a field the form doesn\'t have: said so', () => {
    assert.equal(apply(screen())('cf7:mail/subject', 'x'), 'Not here.')
    assert.equal(apply(screen({ html: FORM }))('cf7:messages/unknown', 'x'), 'Not here.')
  })
})
