/**
 * Stop ProseMirror from intercepting events that originate inside a form
 * control rendered by a NodeView. Without this, clicking a <input> inside
 * an atom NodeView creates a NodeSelection on the surrounding node, the
 * floating toolbar pops up, and a paste event ends up replacing the whole
 * node instead of filling the input.
 *
 * The capture-phase listener stops both immediate propagation (so PM's own
 * DOM listeners on the editor don't fire) and the event's default flow
 * isn't touched — the browser still handles the input natively.
 */
const ISOLATED_EVENTS = [
  'mousedown', 'mouseup', 'click', 'dblclick',
  'keydown', 'keyup', 'keypress',
  'input', 'change', 'beforeinput',
  'paste', 'copy', 'cut',
  'drop', 'dragstart', 'dragover',
  'compositionstart', 'compositionupdate', 'compositionend',
  'focus', 'blur',
]

export function isolateFromProseMirror(el) {
  for (const type of ISOLATED_EVENTS) {
    el.addEventListener(type, stop, true)
  }
}

function stop(e) {
  e.stopPropagation()
}
