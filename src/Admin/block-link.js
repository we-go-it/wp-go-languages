/**
 * A block's link to its original, in the Advanced section of its settings
 * (block-link.php): on an independent translation, a select of the original's
 * blocks of the same type that takes the chosen one's ID, and the ID by hand; on
 * an original, its ID to read. It's saved with the page, as any attribute.
 */
import {
  idProblem, linkOptions, linkedTo, withId
} from './_block-link.js'

(function (wp) {
  const config = window.wglBlockLink

  if (!config) {
    return
  }

  const {
    createElement: h, Fragment, useEffect, useState
  } = wp.element
  const { InspectorAdvancedControls } = wp.blockEditor
  const { Button, SelectControl, TextControl } = wp.components
  const { texts } = config
  const format = (text, value) => (text || '').replace('%s', value)

  function OriginalId ({ id }) {
    return h(TextControl, {
      label: texts.blockId,
      value: id,
      readOnly: true,
      help: id ? texts.idOriginal : texts.idOnSave,
      onChange: () => {},
      __next40pxDefaultSize: true,
      __nextHasNoMarginBottom: true
    })
  }

  function TranslationLink ({
    clientId, name, id, metadata, setAttributes
  }) {
    // the IDs of the page's other blocks, as a string: useSelect compares it
    const taken = wp.data.useSelect(select => {
      const editor = select('core/block-editor')

      return editor.getClientIdsWithDescendants()
        .filter(other => other !== clientId)
        .map(other => editor.getBlockAttributes(other)?.metadata?.wgId)
        .filter(Boolean)
        .join(' ')
    }, [ clientId ]).split(' ')

    const blocks = config.blocks
    const [ draft, setDraft ] = useState(id)

    // what the select changes, the field shows
    useEffect(() => setDraft(id), [ id ])

    const context = { blocks, type: name, taken }
    const options = linkOptions({
      ...context,
      title: wp.blocks.getBlockType(name)?.title || name,
      notLinked: texts.notLinked,
      takenLabel: texts.taken
    })
    const value = linkedTo(id, blocks, name)
    const typed = draft.trim()
    const problem = typed && typed !== id ? idProblem(typed, context) : null
    const set = next => setAttributes({ metadata: withId(metadata, next) })
    const unknown = typed && typed !== id && !problem && !blocks.some(block => block.id === typed)
    const help = problem ? texts[problem] : unknown ? texts.unknownId : texts.idHelp

    return h(Fragment, null,
      h(SelectControl, {
        label: format(texts.linkedTo, config.language),
        value,
        options,
        help: options.length > 1 ? (metadata?.wgSynced?.length ? texts.marksGo : undefined) : texts.noneOfType,
        onChange: set,
        __next40pxDefaultSize: true,
        __nextHasNoMarginBottom: true
      }),
      h('div', { style: { margin: '16px 0' } },
        h(TextControl, {
          label: texts.blockId,
          value: draft,
          help,
          onChange: setDraft,
          __next40pxDefaultSize: true,
          __nextHasNoMarginBottom: true
        }),
        h(Button, {
          variant: 'secondary',
          size: 'small',
          style: { marginTop: '8px' },
          disabled: !typed || typed === id || Boolean(problem),
          onClick: () => set(typed)
        }, texts.useId)
      )
    )
  }

  // The section is the selected block's, the one Advanced shows. Keyed by its ID,
  // the block is drawn again when its link changes: ACF keeps a form it has drawn,
  // and it's the new form that has the synced fields (Core\sync_boxes()). A
  // synced mark isn't in the key: ticking one doesn't redraw the form.
  const withLink = wp.compose.createHigherOrderComponent(BlockEdit => props => {
    const { metadata } = props.attributes
    const id = metadata?.wgId || ''

    return h(Fragment, null,
      h(BlockEdit, { ...props, key: id }),
      props.isSelected && h(InspectorAdvancedControls, null, config.role === 'original'
        ? h(OriginalId, { id })
        : h(TranslationLink, {
          clientId: props.clientId, name: props.name, id, metadata, setAttributes: props.setAttributes
        }))
    )
  }, 'withWglBlockLink')

  wp.hooks.addFilter('editor.BlockEdit', 'wgl/block-link', withLink)
}(window.wp))
