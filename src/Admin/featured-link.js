/**
 * The link on the editor's featured image, on a translation: the same box as a
 * field's (synced-fields.php), above it. It's saved with the post, in its `wgl` field
 * (Admin\rest_update()). While it's on, the image can't be changed here.
 */
import { linkEdits } from './_featured-link.js'

(function (wp) {
  const config = window.wglFeaturedLink

  if (!config) {
    return
  }

  const { createElement: h } = wp.element

  wp.hooks.addFilter('editor.PostFeaturedImage', 'wgl/featured-link', Original => props => {
    const { wgl, current } = wp.data.useSelect(select => ({
      wgl: select('core/editor').getEditedPostAttribute('wgl'),
      current: select('core/editor').getEditedPostAttribute('featured_media')
    }), [])
    const { editPost } = wp.data.useDispatch('core/editor')

    if (!wgl?.thumbnail) {
      return h(Original, props)
    }

    const linked = wgl.thumbnail.linked

    return h('div', { className: 'wgl-featured-link' },
      h('span', { className: 'wgl-sync wgl-sync--featured' },
        h('label', { className: 'wgl-sync__part', title: config.title },
          h('input', {
            'type': 'checkbox',
            'className': 'wgl-sync__box',
            'aria-label': config.title,
            'checked': linked,
            'onChange': event => editPost(linkEdits(wgl, event.target.checked, current))
          }),
          h('span', { className: 'wgl-sync__icon' },
            h('span', { 'className': 'wgl-sync__link dashicons dashicons-admin-links', 'aria-hidden': 'true' }),
            config.flag ? h('img', { className: 'wgl-flag', src: config.flag, alt: '', width: 20, height: 15 }) : null
          )
        )
      ),
      // through a ref: React 18 and 19 take the `inert` prop differently
      h('div', { ref: node => node?.toggleAttribute('inert', Boolean(linked)) }, h(Original, props))
    )
  })
}(window.wp))
