// A block's link to its original (src/Admin/_block-link.js): the select's options,
// which IDs typed by hand are refused, and what a change does to the metadata.

import assert from 'node:assert/strict'
import { describe, test } from 'node:test'

import {
  idProblem, linkOptions, linkedTo, withId
} from '../../src/Admin/_block-link.js'

const blocks = [
  { id: 'a', type: 'acf/hero', name: '', position: 1, preview: 'Una luce' },
  { id: 'b', type: 'acf/text', name: 'Focus', position: 2, preview: '' },
  { id: 'c', type: 'acf/text', name: '', position: 3, preview: 'Altro testo' }
]

const options = (type, taken = []) => linkOptions({
  blocks, type, title: 'Text', taken, notLinked: 'Not linked', takenLabel: 'already linked'
})

describe('the select', () => {
  test('offers only the blocks of the same type, after "not linked"', () => {
    assert.deepEqual(options('acf/text').map(option => option.value), [ '', 'b', 'c' ])
  })

  test('tells them apart by position, name or type, and words', () => {
    assert.deepEqual(options('acf/text').map(option => option.label), [ 'Not linked', '2. Focus', '3. Text · Altro testo' ])
  })

  test('a block another one links to is shown, but can\'t be picked', () => {
    const [ , focus, other ] = options('acf/text', [ 'b' ])

    assert.equal(focus.disabled, true)
    assert.equal(focus.label, '2. Focus (already linked)')
    assert.equal(other.disabled, false)
  })

  test('a type the original doesn\'t have leaves "not linked" alone', () => {
    assert.equal(options('acf/other').length, 1)
  })

  test('shows the block linked to, not an ID of its own or another type\'s', () => {
    assert.equal(linkedTo('b', blocks, 'acf/text'), 'b')
    assert.equal(linkedTo('own-random-id', blocks, 'acf/text'), '')
    assert.equal(linkedTo('a', blocks, 'acf/text'), '')
  })
})

describe('an ID by hand', () => {
  const context = { blocks, type: 'acf/text', taken: [ 'c' ] }

  test('one of the original\'s, of this type', () => {
    assert.equal(idProblem('b', context), null)
  })

  test('refused: what can\'t be an ID', () => {
    assert.equal(idProblem('has space', context), 'badId')
    assert.equal(idProblem('quo"te', context), 'badId')
  })

  test('refused: one another block has here, which a save would replace', () => {
    assert.equal(idProblem('c', context), 'usedId')
  })

  test('refused: another type\'s, whose fields aren\'t these', () => {
    assert.equal(idProblem('a', context), 'otherType')
  })

  test('allowed: one the original doesn\'t have', () => {
    assert.equal(idProblem('e82c39dc-c101-444b-99a6-c46666a6f817', context), null)
  })
})

describe('the metadata', () => {
  test('takes the ID and keeps the rest, the ID it had beside it', () => {
    assert.deepEqual(withId({ name: 'Focus', wgId: 'old' }, 'b'), { name: 'Focus', wgId: 'b', wgFrom: 'old' })
  })

  test('without an ID, for the save to give one', () => {
    assert.deepEqual(withId({ name: 'Focus', wgId: 'b' }, ''), { name: 'Focus', wgFrom: 'b' })
  })

  test('a new link drops the marks, which followed another block', () => {
    assert.deepEqual(withId({ wgId: 'old', wgSynced: [ 'title' ] }, 'b'), { wgId: 'b', wgFrom: 'old' })
    assert.deepEqual(withId({ wgId: 'old', wgSynced: [ 'title' ] }, ''), { wgFrom: 'old' })
  })

  test('changed twice before a save, the ID it\'s saved with is the one kept', () => {
    assert.deepEqual(withId({ wgId: 'b', wgFrom: 'old' }, 'c'), { wgId: 'c', wgFrom: 'old' })
  })

  test('back to the ID it\'s saved with, nothing to find', () => {
    assert.deepEqual(withId({ wgId: 'b', wgFrom: 'old' }, 'old'), { wgId: 'old' })
  })

  test('the same ID keeps them', () => {
    assert.deepEqual(withId({ wgId: 'b', wgSynced: [ 'title' ] }, 'b'), { wgId: 'b', wgSynced: [ 'title' ] })
  })

  test('a block with no metadata yet', () => {
    assert.deepEqual(withId(undefined, 'b'), { wgId: 'b' })
  })
})
