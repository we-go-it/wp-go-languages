/**
 * The editor's Language panel: the page's language, a translation's mode (a
 * toggle), the list of its translations, and what this page alone can do:
 * on its own, join a group ("Link to a translation…", a search of the pages
 * in other languages it may join); in one, lead it or leave it
 * (wgl/v1/posts/<id>/…, wgl/v1/terms/<id>/… for a term, admin.php
 * rest_routes()). Each reloads the editor after. Translations are made from
 * the lists' "+" (list.js).
 *
 * In the block editor it reads the post's `wgl` REST field (src/Admin/
 * admin.php, panel_data()), and writes `wgl.language` while the page has no
 * translations, so a page can be moved to another language before it is
 * translated (the save moves its parent to the parent's translation, and
 * checks its slug again), and `wgl.mode`. On Contact Form 7's edit screen,
 * which has no block editor, the same panel is a box of CF7's sidebar
 * (`#wgl-cf7-box`), its data printed (`window.wglEditor.post`), and it writes
 * through wgl/v1/posts/<id>/mode and /language, reloading after. A classic
 * edit screen and a term's edit screen mount it the same way.
 *
 * With AI translation set up (`window.wglEditor.ai`), a translation's panel
 * has "Translate with AI…": a dialog of its fields, translated from the
 * original a chunk at a time and saved as each comes back (_ai.js,
 * admin/ai.php), then the editor again.
 */
import {
  AI_PATH, fieldsOf, translateFields
} from './_ai.js'
import { applier } from './_apply.js'

(function (wp) {
  const {
    createElement: h, Fragment, useState, useEffect, useRef
  } = wp.element
  const {
    SelectControl, Button, Notice, Modal, SearchControl, Spinner, CheckboxControl
  } = wp.components
  const apiFetch = wp.apiFetch
  const { addQueryArgs } = wp.url
  const texts = (window.wglEditor && window.wglEditor.texts) || {}

  // A link or unlink, then the editor again: the panel, the lock and the
  // content may all change. Not with unsaved changes, which the reload loses.
  function call (base, action, body, dirty, ask) {
    if (dirty) {
      window.alert(texts.saveFirst)

      return
    }

    if (!window.confirm(ask)) {
      return
    }

    apiFetch({
      path: `${base}/${action}`, method: 'POST', data: body
    })
      .then(() => window.location.reload())
      .catch(error => window.alert(error.message || texts.failed))
  }

  const muted = { color: '#757575', fontSize: '12px' }

  // `%s`, or `%1$s` and `%2$s`, filled in
  const format = (text, ...values) => values.reduce((out, value, i) => out.replace(`%${i + 1}$s`, value).replace('%s', value), text || '')

  // the AI's sparkle (_ai.js)
  const aiIcon = h('svg', {
    'viewBox': '0 0 24 24', 'width': 24, 'height': 24, 'aria-hidden': true, 'focusable': false
  }, h('path', { d: AI_PATH, fill: 'currentColor' }))

  // A field's text, short and plain, under its checkbox
  const preview = text => {
    const plain = (text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()

    return plain.length > 90 ? plain.slice(0, 89) + '…' : plain
  }

  /**
   * "Translate with AI…": the translation's fields by group, those not
   * translated yet ticked, translated a chunk at a time and put in, unsaved
   * (applier()): the page's save saves them. What couldn't be is listed.
   */
  function AiModal ({ base, onClose }) {
    const [ state, setState ] = useState({ loading: true, fields: [] })
    const [ picked, setPicked ] = useState(() => new Set())
    const [ progress, setProgress ] = useState('')
    const [ result, setResult ] = useState(null)
    // Stop: asked between parts (translateFields()'s `stopped`), shown at once
    const stop = useRef(false)
    const [ stopping, setStopping ] = useState(false)
    const running = progress !== '' && !result
    const fields = state.fields || []
    const untranslated = () => new Set(fields.filter(field => field.untranslated).map(field => field.key))

    useEffect(() => {
      fieldsOf(base)
        .then(answer => {
          setState({ ...answer, loading: false })
          setPicked(new Set((answer.fields || []).filter(field => field.untranslated).map(field => field.key)))
        })
        .catch(error => setState({
          loading: false, fields: [], error: error.message || texts.failed
        }))
    }, [ base ])

    const toggle = (key, on) => setPicked(before => {
      const after = new Set(before)

      on ? after.add(key) : after.delete(key)

      return after
    })

    const run = async () => {
      setProgress(' ')

      const done = await translateFields({
        base,
        fields: fields.filter(field => picked.has(field.key)),
        onProgress: (n, of) => setProgress(format(texts.aiRunning, n, of)),
        stopped: () => stop.current,
        apply: applier(state, {
          wp, texts, document, tinymce: window.tinymce, acf: window.acf
        })
      })

      setResult(done)
    }

    const labels = Object.fromEntries(fields.map(field => [ field.key, field.label ]))
    const groups = [
      [ 'page', texts.aiPage ],
      [ 'blocks', texts.aiBlocks ],
      [ 'fields', texts.aiFields ],
      [ 'seo', texts.aiSeo ],
      [ 'mail', texts.aiMail ],
      [ 'messages', texts.aiMessages ]
    ]
    const link = (label, keys) => h(Button, {
      variant: 'link', disabled: running, onClick: () => setPicked(keys())
    }, label)

    return h(Modal, {
      title: state.from_name ? format(texts.aiTitle, state.from_name, state.to_name) : texts.aiButton,
      onRequestClose: () => running || onClose(),
      isDismissible: !running,
      size: 'medium'
    },
    state.loading && h('p', null, h(Spinner), ' ', texts.aiLoading),
    state.error && h(Notice, { status: 'error', isDismissible: false }, state.error),
    !state.loading && !state.error && !fields.length && h('p', null, texts.aiNothing),

    fields.length > 0 && !result && h(Fragment, null,
      h('p', { style: { ...muted, marginTop: 0 } }, texts.aiNote),
      h('div', {
        style: {
          display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '8px'
        }
      },
      h('span', { style: muted }, texts.aiPick),
      link(texts.aiPickUntranslated, untranslated),
      link(texts.aiPickAll, () => new Set(fields.map(field => field.key))),
      link(texts.aiPickNone, () => new Set())
      ),
      h('div', {
        style: {
          maxHeight: '50vh', overflowY: 'auto', border: '1px solid #e0e0e0', borderRadius: '4px', padding: '0 12px 8px'
        }
      },
      groups.map(([ group, title ]) => {
        const items = fields.filter(field => field.group === group)

        return items.length > 0 && h('div', { key: group },
          h('h3', { style: { fontSize: '13px', margin: '14px 0 8px' } }, title),
          items.map(field => h('div', { key: field.key, style: { marginBottom: '10px' } }, h(CheckboxControl, {
            checked: picked.has(field.key),
            disabled: running,
            onChange: on => toggle(field.key, on),
            label: h('span', null, field.label, field.hint ? h('span', { style: { ...muted, marginLeft: '6px' } }, field.hint) : null),
            help: preview(field.source),
            __nextHasNoMarginBottom: true
          })))
        )
      })
      ),
      h('div', {
        style: {
          display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px', marginTop: '16px'
        }
      },
      h('span', {
        'aria-live': 'polite', 'style': {
          ...muted, display: 'flex', alignItems: 'center'
        }
      },
      running && h(Spinner, { style: { margin: '0 8px 0 0' } }),
      stopping ? texts.aiStopping : progress.trim()
      ),
      h('div', { style: { display: 'flex', gap: '8px' } },
        // Cancel; while it translates, Stop, after the part the model is on
        h(Button, {
          variant: 'tertiary',
          disabled: stopping,
          onClick: () => {
            if (!running) {
              onClose()
              return
            }

            stop.current = true
            setStopping(true)
          }
        }, running ? texts.aiStop : texts.aiCancel),
        h(Button, {
          variant: 'primary', icon: aiIcon, isBusy: running, disabled: running || !picked.size, onClick: run
        }, picked.size === 1 ? texts.aiOne : format(texts.aiMany, picked.size))
      )
      )
    ),

    result && h(Fragment, null,
      h('p', null, result.stopped
        ? format(texts.aiStoppedIn, result.written.length)
        : Object.keys(result.failed).length
          ? format(texts.aiDoneIn, result.written.length, Object.keys(result.failed).length)
          : format(texts.aiAllIn, result.written.length)),
      h('ul', {
        style: {
          color: '#b32d2e', listStyle: 'disc', paddingLeft: '18px'
        }
      },
      Object.entries(result.failed).map(([ key, why ]) => h('li', { key }, `${labels[key] || key}: ${why}`))
      ),
      h(Button, { variant: 'primary', onClick: onClose }, texts.aiClose)
    )
    )
  }
  const divider = () => h('hr', {
    style: {
      margin: '16px 0', border: 0, borderTop: '1px solid #e0e0e0'
    }
  })

  // A language's flag, 4:3, beside its name; nothing if it has none.
  const flag = language => language.flag && h('img', {
    src: language.flag,
    alt: '',
    width: 18,
    height: 13,
    style: {
      width: '18px', height: '13.5px', borderRadius: '2px', boxShadow: '0 0 0 1px rgba(0,0,0,.12)', objectFit: 'cover', verticalAlign: 'middle', marginRight: '6px'
    }
  })
  // the panel's own buttons: the sidebar's width, their label centred
  const wide = { width: '100%', justifyContent: 'center' }
  const actions = {
    display: 'flex', gap: '6px', flexWrap: 'wrap', marginTop: '8px'
  }

  // A dialog to search the pages this one may join, in any other language:
  // a click on one links it.
  function LinkModal ({
    base, dirty, onClose
  }) {
    const [ search, setSearch ] = useState('')
    const [ found, setFound ] = useState(null)

    useEffect(() => {
      let live = true
      const timer = setTimeout(() => {
        apiFetch({ path: addQueryArgs(`${base}/candidates`, { search }) })
          .then(pages => live && setFound(pages))
          .catch(() => live && setFound([]))
      }, 250)

      return () => {
        live = false
        clearTimeout(timer)
      }
    }, [ search ])

    const row = {
      display: 'flex', alignItems: 'center', gap: '8px', width: '100%', padding: '8px 12px', textAlign: 'left'
    }

    return h(Modal, {
      title: texts.link.replace('…', ''), onRequestClose: onClose, size: 'medium'
    },
    h(SearchControl, {
      label: texts.linkLabel, value: search, onChange: setSearch, __nextHasNoMarginBottom: true
    }),
    h('ul', {
      style: {
        margin: '12px 0 0', border: '1px solid #e0e0e0', borderRadius: '4px', maxHeight: '320px', overflowY: 'auto'
      }
    },
    found === null
      ? h('li', { style: { ...row, ...muted } }, h(Spinner))
      : found.length
        ? found.map(page => h('li', { key: page.id, style: { margin: 0, borderTop: '1px solid #f0f0f0' } },
          h(Button, {
            style: { ...row, height: 'auto' },
            onClick: () => call(base, 'link', { other: page.id }, dirty, (texts.confirmLink || '').replace('%1$s', page.title).replace('%2$s', page.languages.map(language => language.name).join(', ')))
          },
          h('span', { style: { flex: 1 } }, page.title, page.status !== 'publish' && h('span', { style: muted }, ` · ${texts.draft}`)),
          // the languages the group has: this page would add its own
          h('span', { style: { display: 'flex', alignItems: 'center' }, title: page.languages.map(language => language.name).join(', ') },
            page.languages.map(language => h('span', { key: language.code }, flag(language) || h('span', { style: muted }, language.code.toUpperCase() + ' '))))
          )))
        : h('li', { style: { ...row, ...muted } }, texts.noCandidates)
    ),
    h('p', { style: { ...muted, margin: '12px 0 0' } }, texts.linkHelp)
    )
  }

  // A row of the table: a language, and its page (or none yet), right
  // aligned: a star before the group's original, draft and fixed after.
  function Translation ({ language }) {
    const tags = [
      language.status && language.status !== 'publish' && texts.draft,
      language.mode === 'text' && (texts.text || '').toLowerCase(),
      language.mode === 'blocks' && texts.blocksToggle.toLowerCase() 
    ].filter(Boolean)
    const cell = {
      padding: '6px 0', borderTop: '1px solid #e0e0e0', verticalAlign: 'top'
    }

    return h('tr', null,
      h('th', {
        scope: 'row', style: {
          ...cell, paddingRight: '8px', textAlign: 'left', fontWeight: 600, whiteSpace: 'nowrap'
        }
      },
      flag(language),
      language.name,
      language.default && h('span', { style: { ...muted, fontWeight: 400 } }, ` ${texts.defaultTag}`)
      ),
      h('td', {
        style: {
          ...cell, width: '100%', textAlign: 'right', wordBreak: 'break-word'
        }
      },
      language.id
        ? h(Fragment, null,
          language.is_original && h('span', {
            'className': 'dashicons dashicons-star-filled',
            'title': texts.originalTag,
            'aria-label': texts.originalTag,
            'style': {
              fontSize: '14px', width: '14px', height: '14px', marginRight: '4px', verticalAlign: 'text-bottom', color: '#1e1e1e'
            }
          }),
          h('a', { href: language.edit }, language.title || '#' + language.id),
          tags.length > 0 && h('span', { style: muted }, ` · ${tags.join(' · ')}`)
        )
        : h('span', { style: muted, title: texts.noPage }, '—')
      )
    )
  }

  // Switching a translation's mode asks first, saying what goes: into a fixed
  // mode blocks the original doesn't have (and, text only, whatever isn't a
  // text). From text only to fixed blocks, or to independent, nothing does.
  function ModeDialog ({
    data, mode, title, onConfirm, onClose
  }) {
    const unlocks = data.mode === 'text' && mode === 'blocks'
    const safe = unlocks || mode === 'independent'
    const text = unlocks ? texts.confirmUnlock : {
      text: texts.confirmText,
      blocks: texts.confirmBlocks,
      independent: texts.confirmIndependent
    }[mode]

    return h(Modal, {
      title: format(texts.modeTitle, title), onRequestClose: onClose, size: 'small'
    },
    h('p', { style: { marginTop: 0 } }, text),
    !safe && data.dropped > 0 && h(Notice, { status: 'warning', isDismissible: false }, (texts.droppedCount || '').replace('%d', data.dropped)),
    h('div', {
      style: {
        display: 'flex', justifyContent: 'flex-end', gap: '8px', marginTop: '16px'
      }
    },
    h(Button, { variant: 'tertiary', onClick: onClose }, texts.modeCancel),
    h(Button, {
      variant: 'primary', isDestructive: !safe, onClick: onConfirm
    }, texts.modeSwitch)
    )
    )
  }

  /**
   * The panel's content, wherever it is: `onLanguage(code)` and
   * `onMode(mode)` write those, the rest goes through wgl/v1 (call()).
   * `saved`, the language saved, when it can differ from the one shown.
   */
  function LanguageBox ({
    data, saved, dirty, base, onLanguage, onMode
  }) {
    const [ linking, setLinking ] = useState(false)
    const [ translating, setTranslating ] = useState(false)
    // the mode being switched to, asked first: its key and its name
    const [ switching, setSwitching ] = useState(null)

    const own = data.languages.find(language => language.own)
    const others = data.languages.filter(language => !language.own)
    const grouped = others.some(language => language.id)

    return h(Fragment, null,
      data.can_relocate
        ? h(SelectControl, {
          label: texts.language,
          // the chosen language's flag, inside the field
          prefix: own && own.flag ? h('span', { style: { display: 'flex', paddingLeft: '8px' } }, flag({ ...own })) : undefined,
          value: data.language || '',
          options: data.languages.map(language => ({ label: language.name, value: language.code })),
          onChange: onLanguage,
          __nextHasNoMarginBottom: true
        })
        : h('p', { style: { fontSize: '14px' } }, own && flag(own), h('strong', { title: texts.language }, own ? own.name : '')),

      // a new language picked, not saved yet: what the save does to the address
      saved !== undefined && saved !== data.language && h(Notice, { status: 'warning', isDismissible: false }, texts.relocate),

      data.original && h('p', null, `${texts.translationOf} `, h('a', { href: data.original.edit }, data.original.title || '#' + data.original.id)),

      divider(),

      data.original && data.can_text && h(Fragment, null,
      // the mode: a card each, as the lists' dialog has; the current one
      // filled. Fixed blocks with free fields for a type with blocks only
        h('div', {
          style: {
            display: 'grid', gridTemplateColumns: data.can_blocks ? '1fr' : '1fr 1fr', gap: '8px'
          }
        },
        [
          [
            'independent',
            'dashicons-admin-page',
            texts.independentTitle,
            texts.independentShort
          ],
          data.can_blocks && [
            'blocks',
            'dashicons-layout',
            texts.blocksToggle,
            texts.blocksShort
          ],
          [
            'text',
            'dashicons-lock',
            texts.textToggle,
            texts.textShort
          ]
        ].filter(Boolean).map(([
          mode,
          icon,
          title,
          help
        ]) => {
          const on = data.mode === mode
          const color = 'var(--wp-admin-theme-color, #3858e9)'

          return h('button', {
            'key': mode,
            'type': 'button',
            'aria-pressed': on,
            'onClick': () => on || setSwitching({ mode, title }),
            'style': {
              display: 'flex',
              flexDirection: 'column',
              gap: '4px',
              padding: '10px',
              textAlign: 'left',
              cursor: on ? 'default' : 'pointer',
              border: `1px solid ${on ? color : '#ddd'}`,
              borderRadius: '6px',
              background: on ? color : '#fff',
              color: on ? '#fff' : '#1e1e1e',
              font: 'inherit'
            }
          },
          h('span', {
            'className': `dashicons ${icon}`, 'aria-hidden': 'true', 'style': { color: on ? '#fff' : color }
          }),
          h('strong', null, title),
          h('span', {
            style: {
              fontSize: '12px', lineHeight: 1.4, color: on ? 'rgba(255,255,255,.85)' : '#757575'
            }
          }, help)
          )
        })
        ),
        data.held && h('p', { style: { ...muted, margin: '6px 0 0' } }, texts.heldBack),
        switching && h(ModeDialog, {
          data,
          mode: switching.mode,
          title: switching.title,
          onClose: () => setSwitching(null),
          onConfirm: () => {
            setSwitching(null)
            onMode(switching.mode)
          }
        })
      ),

      // links that don't lead where they should in this language
      (data.link_warnings || []).map((warning, i) => h('div', { key: i, style: { marginTop: '8px' } }, h(Notice, { status: 'warning', isDismissible: false }, warning))),

      // a translation, with AI set up: its texts translated from the original
      data.original && window.wglEditor?.ai && h('div', { style: { marginTop: '12px' } }, h(Button, {
        'variant': 'secondary',
        'icon': aiIcon,
        'style': wide,
        'aria-haspopup': 'dialog',
        'onClick': () => setTranslating(true)
      }, texts.aiButton)),
      translating && h(AiModal, { base, onClose: () => setTranslating(false) }),

      // this page only: alone, it may join a group; in one, lead it or leave it
      h('div', { style: { ...actions, marginTop: '12px' } },
        !grouped && h(Button, {
          'variant': 'secondary', 'size': 'small', 'style': wide, 'onClick': () => setLinking(true), 'aria-haspopup': 'dialog'
        }, texts.link),
        grouped && data.can_original && h(Button, {
          variant: 'secondary',
          size: 'small',
          style: wide,
          onClick: () => call(base, 'original', {}, dirty, (texts.confirmOriginal || '').replace('%s', own?.title || ''))
        }, texts.makeThisOriginal),
        grouped && h(Button, {
          variant: 'secondary',
          isDestructive: true,
          size: 'small',
          style: wide,
          onClick: () => call(base, 'unlink', {}, dirty, texts.confirmLeave)
        }, texts.leave)
      ),
      !grouped && linking && h(LinkModal, {
        base, dirty, onClose: () => setLinking(false)
      }),

      divider(),

      h('p', { style: { margin: '0 0 6px' } }, h('strong', null, texts.translations)),
      h('table', {
        className: 'wgl-panel__table', style: {
          width: '100%', borderCollapse: 'collapse', borderBottom: '1px solid #e0e0e0'
        }
      },
      h('tbody', null, others.map(language => h(Translation, { key: language.code, language })))
      )
    )
  }

  // The block editor: a document panel, the edited post's `wgl`.
  if (wp.plugins && wp.data && (wp.editor?.PluginDocumentSettingPanel || wp.editPost?.PluginDocumentSettingPanel)) {
    const Panel = wp.editor?.PluginDocumentSettingPanel || wp.editPost.PluginDocumentSettingPanel

    wp.plugins.registerPlugin('wgl-language', {
      render () {
        const {
          data, saved, dirty, postId
        } = wp.data.useSelect(select => {
          const editor = select('core/editor')

          return {
            data: editor.getEditedPostAttribute('wgl'),
            saved: editor.getCurrentPostAttribute('wgl'),
            dirty: editor.isEditedPostDirty(),
            postId: editor.getCurrentPostId()
          }
        }, [])
        const { editPost, savePost } = wp.data.useDispatch('core/editor')

        if (!data || !data.languages) {
          return null
        }

        return h(Panel, {
          name: 'wgl-language', title: texts.panel, className: 'wgl-panel'
        }, h(LanguageBox, {
          data,
          saved: saved?.language,
          dirty,
          base: `/wgl/v1/posts/${postId}`,
          // the page moved on its save, as the warning says
          onLanguage: code => editPost({
            wgl: {
              ...data, language: code, languages: data.languages.map(language => ({ ...language, own: language.code === code }))
            }
          }),
          // saved with the page, then the editor again: the content and the lock change
          onMode: mode => {
            editPost({ wgl: { ...data, mode } })
            Promise.resolve(savePost()).then(() => window.location.reload())
          }
        }))
      }
    })
  }

  // Without the block editor (Contact Form 7's screen, a classic edit
  // screen): a box of the sidebar, its data printed with the script.
  const mount = document.getElementById('wgl-panel-root')
  const post = window.wglEditor && window.wglEditor.post

  if (mount && post) {
    // `posts`, or `terms` on a term's screen
    const base = `/wgl/v1/${post.kind || 'posts'}/${post.id}`
    const write = (key, value) => apiFetch({
      path: `${base}/${key}`, method: 'POST', data: { [key]: value }
    })
      .then(() => window.location.reload())
      .catch(error => window.alert(error.message || texts.failed))

    const element = h(LanguageBox, {
      data: post.data,
      dirty: false,
      base,
      onLanguage: code => window.confirm(texts.relocate) && write('language', code),
      onMode: mode => write('mode', mode)
    })
    const box = document.getElementById('wgl-cf7-box')

    // CF7's box comes after its form: into the sidebar, under Status
    if (box) {
      document.getElementById('submitdiv')?.after(box)
      box.hidden = false
    }

    wp.element.createRoot ? wp.element.createRoot(mount).render(element) : wp.element.render(element, mount)
  }
}(window.wp))
