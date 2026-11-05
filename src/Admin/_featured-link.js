/**
 * What a tick on the featured image's link edits in the post (featured-link.js),
 * apart from the editor, so a test can run it. Ticked, it shows the original's image,
 * and keeps the one it had. Unticked, it goes back to that, or, with nothing kept,
 * stays as it is. The save does the same whatever's shown (Core\set_thumbnail_link()).
 */
export function linkEdits (wgl, on, current) {
  const link = wgl.thumbnail
  const before = link.before ?? current

  if (on) {
    return { wgl: { ...wgl, thumbnail: { ...link, linked: true, before } }, featured_media: link.original }
  }

  const back = link.before ?? null
  const edits = { wgl: { ...wgl, thumbnail: { ...link, linked: false, before: null } } }

  return back === null ? edits : { ...edits, featured_media: back }
}
