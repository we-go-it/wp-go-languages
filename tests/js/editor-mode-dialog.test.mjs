// Switching a translation's mode in the Language panel (src/Admin/
// editor-panel.js, ModeDialog), in jsdom, through its box on a screen without
// the block editor: the dialog comes first, says what goes, and nothing is
// written until Switch. wp.element is a small renderer with useState, enough
// for the panel; wp.components are plain elements.

import assert from 'node:assert/strict'
import { afterEach, describe, test } from 'node:test'

import { JSDOM, VirtualConsole } from 'jsdom'

import { adminScript } from './_source.mjs'

const script = adminScript('editor-panel.js').replace(/^import \{[^}]*\} from '[^']*'\n/gm, '')

const TEXTS = {
  independentTitle: 'Independent',
  independentShort: 'd',
  blocksToggle: 'Fixed blocks',
  blocksShort: 's',
  text: 'Fixed blocks, text only',
  textToggle: 'Fixed blocks, text only',
  textShort: 'f',
  confirmBlocks: 'Content can be deleted: blocks.',
  confirmText: 'Content can be deleted: blocks and images.',
  confirmIndependent: 'Nothing is deleted.',
  confirmUnlock: 'Unlocks. Nothing is deleted.',
  droppedCount: '%d block(s) will be deleted.',
  modeTitle: 'Switch to “%s”?',
  modeSwitch: 'Switch',
  modeCancel: 'Cancel',
  heldBack: 'Waiting for the original.',
  translationOf: 'Translation of',
  translations: 'Translations',
  draft: 'draft'
}

function panel (mode, dropped, held = false) {
  const dom = new JSDOM('<!doctype html><div id="wgl-panel-root"></div>', {
    runScripts: 'outside-only', url: 'http://base.test/wp/wp-admin/post.php', virtualConsole: new VirtualConsole()
  })
  const { window } = dom
  const writes = []

  // the renderer: a function component's hooks kept by its place in the tree
  let root = null
  let hooks = new Map()
  let path = ''
  let slot = 0
  const draw = (node, parent, at) => {
    if (node === null || node === undefined || node === false || node === '') {
      return
    }

    if (Array.isArray(node)) {
      node.forEach((child, i) => draw(child, parent, `${at}.${i}`))

      return
    }

    if (typeof node !== 'object') {
      parent.append(String(node))

      return
    }

    if (node.type === Fragment) {
      draw(node.children, parent, at)

      return
    }

    if (typeof node.type === 'function') {
      const [ outerPath, outerSlot ] = [ path, slot ]

      path = at
      slot = 0

      const out = node.type({ ...node.props, children: node.children })

      path = outerPath
      slot = outerSlot
      draw(out, parent, `${at}>`)

      return
    }

    const element = window.document.createElement(node.type)

    Object.entries(node.props || {}).forEach(([ name, value ]) => {
      if (name === 'onClick') {
        element.addEventListener('click', value)
      } else if (typeof value === 'string' || typeof value === 'boolean') {
        element.setAttribute(name, String(value))
      }
    })
    draw(node.children, element, `${at}/${node.type}`)
    parent.append(element)
  }
  const refresh = () => {
    const mount = window.document.getElementById('wgl-panel-root')

    mount.replaceChildren()
    draw(root, mount, 'r')
  }
  const Fragment = () => null
  const h = (type, props, ...children) => ({ type, props, children })
  const useState = initial => {
    const key = `${path}#${slot++}`

    if (!hooks.has(key)) {
      hooks.set(key, typeof initial === 'function' ? initial() : initial)
    }

    return [ hooks.get(key), value => {
      hooks.set(key, value)
      refresh()
    } ]
  }
  const plain = tag => ({ children }) => h(tag, {}, children)

  window.wp = {
    element: {
      createElement: h,
      Fragment,
      useState,
      useEffect: () => {},
      useRef: value => ({ current: value }),
      createRoot: () => ({
        render (element) {
          root = element
          refresh()
        }
      })
    },
    components: {
      SelectControl: () => null,
      Notice: ({ children }) => h('div', { class: 'notice' }, children),
      Spinner: () => null,
      SearchControl: () => null,
      CheckboxControl: () => null,
      Modal: ({ title, children }) => h('div', { role: 'dialog' }, h('h1', {}, title), children),
      Button: ({ onClick, children, isDestructive }) => h('button', { onClick, 'data-destructive': Boolean(isDestructive) }, children)
    },
    apiFetch: request => {
      writes.push(request)

      return new Promise(() => {})
    },
    url: { addQueryArgs: value => value }
  }
  window.wglEditor = {
    texts: TEXTS,
    post: {
      id: 7,
      data: {
        mode,
        dropped,
        held,
        can_text: true,
        can_blocks: true,
        original: { id: 1, title: 'Originale', edit: '#' },
        languages: [ { code: 'en', name: 'English', own: true }, { code: 'it', name: 'Italiano', id: 1, is_original: true } ]
      }
    }
  }
  dom.window.eval(script)

  const buttons = () => [ ...window.document.querySelectorAll('button') ]
  const card = name => buttons().find(button => button.textContent.includes(name) && button.hasAttribute('aria-pressed'))
  const inDialog = name => [ ...window.document.querySelectorAll('[role="dialog"] button') ].find(button => button.textContent === name)
  const dialog = () => window.document.querySelector('[role="dialog"]')
  const text = () => window.document.body.textContent

  return {
    card, inDialog, dialog, text, writes, window
  }
}

const open = []
const make = (...args) => {
  const made = panel(...args)

  open.push(made)

  return made
}

afterEach(() => open.splice(0).forEach(made => made.window.close()))

describe('switching a mode', () => {
  test('the dialog comes first, and nothing is written', () => {
    const { card, dialog, writes } = make('independent', 0)

    assert.equal(dialog(), null)
    card('Fixed blocks, text only').click()
    assert.ok(dialog(), 'the dialog is open')
    assert.equal(writes.length, 0)
    assert.match(dialog().textContent, /Switch to “Fixed blocks, text only”\?/)
    assert.match(dialog().textContent, /Content can be deleted/)
  })

  test('Cancel closes it, and writes nothing', () => {
    const {
      card, dialog, inDialog, writes
    } = make('independent', 0)

    card('Fixed blocks').click()
    inDialog('Cancel').click()
    assert.equal(dialog(), null)
    assert.equal(writes.length, 0)
  })

  test('Switch writes the mode, and closes it', () => {
    const {
      card, dialog, inDialog, writes
    } = make('independent', 0)

    card('Fixed blocks, text only').click()
    inDialog('Switch').click()
    assert.equal(dialog(), null)
    // through JSON: the data is an object of jsdom's window
    assert.deepEqual(JSON.parse(JSON.stringify(writes.map(({ path, data }) => [ path, data ]))), [ [ '/wgl/v1/posts/7/mode', { mode: 'text' } ] ])
  })

  test('the blocks that go are counted, on a button that warns', () => {
    const { card, dialog, inDialog } = make('independent', 2)

    card('Fixed blocks').click()
    assert.match(dialog().textContent, /2 block\(s\) will be deleted/)
    assert.equal(inDialog('Switch').getAttribute('data-destructive'), 'true')
  })

  test('no count when none go', () => {
    const { card, dialog } = make('independent', 0)

    card('Fixed blocks').click()
    assert.doesNotMatch(dialog().textContent, /will be deleted/)
  })

  test('into independent nothing is deleted, and the button says so', () => {
    const { card, dialog, inDialog } = make('text', null)

    card('Independent').click()
    assert.match(dialog().textContent, /Nothing is deleted/)
    assert.doesNotMatch(dialog().textContent, /can be deleted/)
    assert.equal(inDialog('Switch').getAttribute('data-destructive'), 'false')
  })

  test('from text only to fixed blocks, nothing is deleted', () => {
    const { card, dialog, inDialog } = make('text', null)

    card('Fixed blocks').click()
    assert.match(dialog().textContent, /Unlocks/)
    assert.equal(inDialog('Switch').getAttribute('data-destructive'), 'false')
  })

  test('from fixed blocks to text only, content can be deleted', () => {
    const { card, dialog, inDialog } = make('blocks', null)

    card('Fixed blocks, text only').click()
    assert.match(dialog().textContent, /Content can be deleted: blocks and images/)
    assert.equal(inDialog('Switch').getAttribute('data-destructive'), 'true')
  })

  test('a translation waiting for its original says so', () => {
    assert.match(make('text', null, true).text(), /Waiting for the original/)
    assert.doesNotMatch(make('text', null, false).text(), /Waiting for the original/)
  })

  test('the mode it is in asks nothing', () => {
    const { card, dialog } = make('text', null)

    card('Fixed blocks, text only').click()
    assert.equal(dialog(), null)
  })
})
