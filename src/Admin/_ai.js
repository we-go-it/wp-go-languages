/**
 * AI translation's run, shared by the "+" dialog (list.js) and the Language
 * panel's dialog (editor-panel.js), bundled into each: a translation's
 * fields sent a chunk at a time to `<base>/ai-translate` (base
 * `/wgl/v1/posts/<id>` or `/wgl/v1/terms/<id>`, admin/ai.php), each chunk
 * written as it comes back. A local model writes some 20 words a second, so
 * a chunk is kept small, and a long page is several.
 */

// what one request carries at most: at ~20 words a second, a chunk is
// answered well within a web server's usual 60 s for PHP (nginx's
// fastcgi_read_timeout)
export const CHUNK_CHARS = 1200
export const CHUNK_TEXTS = 15

/**
 * Fields in chunks, in their order: each up to CHUNK_TEXTS fields and
 * CHUNK_CHARS of source text, a longer field alone.
 */
export function chunks (fields) {
  const out = []
  let chunk = []
  let size = 0

  fields.forEach(field => {
    const length = (field.source || '').length

    if (chunk.length && (chunk.length >= CHUNK_TEXTS || size + length > CHUNK_CHARS)) {
      out.push(chunk)
      chunk = []
      size = 0
    }

    chunk.push(field)
    size += length
  })

  if (chunk.length) {
    out.push(chunk)
  }

  return out
}

/** A translation's fields, its languages' names with them: `<base>/ai-fields`. */
export const fieldsOf = base => window.wp.apiFetch({ path: `${base}/ai-fields` })

/**
 * Translate fields, a chunk after the other: `onProgress(n, of)` before each.
 * `stopped()`, asked before each chunk, stops the run there: the chunk the
 * model is on is let finish, the rest isn't sent.
 *
 * Saved on the server as each chunk comes back, unless `apply(key, text)` is
 * given: then the route only translates (`write: false`), and apply() puts
 * each translation in (the editor's own state, for its own save), answering
 * '' when it did, else why it couldn't.
 *
 * Answers `{ written: [key…], failed: { key: why }, error, stopped }`, `error`
 * when the endpoint couldn't be reached: the run stops there, as every chunk
 * would fail the same.
 */
export async function translateFields ({
  base, fields, onProgress, stopped = () => false, apply = null
}) {
  const parts = chunks(fields)
  const done = {
    written: [], failed: {}, error: '', stopped: false
  }

  for (const [ index, part ] of parts.entries()) {
    if (stopped()) {
      done.stopped = true
      break
    }

    onProgress?.(index + 1, parts.length)

    try {
      const keys = part.map(field => field.key)
      const answer = await window.wp.apiFetch({
        path: `${base}/ai-translate`, method: 'POST', data: apply ? { keys, write: false } : { keys }
      })

      if (apply) {
        Object.entries(answer.translated || {}).forEach(([ key, text ]) => {
          const why = apply(key, text)

          why ? (done.failed[key] = why) : done.written.push(key)
        })
      } else {
        done.written.push(...(answer.written || []))
      }

      Object.assign(done.failed, answer.failed || {})
    } catch (error) {
      done.error = error.message || String(error)
      part.forEach(field => { done.failed[field.key] = done.error })
      break
    }
  }

  return done
}

/** The AI's icon, a sparkle, as SVG markup (the lists) and its path (the editor's components). */
export const AI_PATH = 'M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9L12 2zm7 11l.9 2.6 2.6.9-2.6.9L19 20l-.9-2.6-2.6-.9 2.6-.9L19 13zM5 15l.7 1.8 1.8.7-1.8.7L5 20l-.7-1.8-1.8-.7 1.8-.7L5 15z'
export const AI_ICON = `<svg class="wgl-ai-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="${AI_PATH}"/></svg>`
