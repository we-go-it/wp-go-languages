/**
 * Synced fields' boxes, on ACF's forms as they're drawn: the screen's on `ready`,
 * a block's on `remount`.
 */

import { initBoxes } from './_synced.js'

(function () {
  const { acf, jQuery: $, wp, tinymce } = window

  if (!acf) {
    return
  }

  const run = $el => initBoxes($el?.[0] || document, { acf, $, wp, tinymce: window.tinymce || tinymce, document })

  acf.addAction('ready', run)
  // after ACF's own: a block's form is drawn on `remount`, a row's or an older
  // block's on `append`, at 50
  acf.addAction('remount', run, 60)
  acf.addAction('append', run, 60)
})()
