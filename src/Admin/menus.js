/**
 * The Menus screen's panel (src/Admin/menus.php): moved into the menu's form, so
 * core's save posts it. A menu following its original has its items hidden, still
 * posted: core deletes those that aren't. Only the default language's menus have
 * locations. With all languages picked, the menu select says each menu's.
 */
const panel = document.getElementById('wgl-menu-panel')
const header = document.getElementById('nav-menu-header')

if (panel && header) {
  header.after(panel)
  panel.hidden = false

  const follow = document.getElementById('wgl-menu-follow')
  const note = document.getElementById('wgl-menu-follow-note')
  const sync = () => {
    document.body.classList.toggle('wgl-menu-follows', Boolean(follow?.checked))

    if (note) {
      note.hidden = !follow.checked
    }
  }

  follow?.addEventListener('change', sync)
  sync()

  document.body.classList.toggle('wgl-menu-not-default', panel.dataset.default !== '1')

  const names = JSON.parse(panel.dataset.names || '{}')

  document.querySelectorAll('#select-menu-to-edit option').forEach(option => {
    if (names[option.value]) {
      option.textContent = `${option.textContent.trim()} (${names[option.value]})`
    }
  })
}
