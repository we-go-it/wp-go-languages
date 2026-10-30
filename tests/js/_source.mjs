// A script of src/Admin as the build bundles it, for jsdom (which runs
// classic scripts, not modules): each `import … from './_x.js'` replaced with
// that module's code, its `export`s plain declarations.

import { readFileSync } from 'node:fs'

const dir = new URL('../../src/Admin/', import.meta.url)

export function adminScript (name) {
  return readFileSync(new URL(name, dir), 'utf8').replace(
    /^import \{[^}]*\} from '\.\/(_[a-z-]+\.js)'\n/gm,
    (line, module) => readFileSync(new URL(module, dir), 'utf8').replace(/^export /gm, '')
  )
}
