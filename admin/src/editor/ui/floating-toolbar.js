const HIGHLIGHT_COLORS = [
  { color: 'yellow', swatch: '#fef08a' },
  { color: 'red', swatch: '#fecaca' },
  { color: 'green', swatch: '#bbf7d0' },
  { color: 'blue', swatch: '#bfdbfe' },
]

export const FloatingToolbar = {
  init(editor) {
    const toolbar = document.createElement('div')
    toolbar.className = 'floating-toolbar'
    toolbar.hidden = true

    const swatchHtml = HIGHLIGHT_COLORS.map(
      (c) =>
        `<button type="button" data-color="${c.color}" style="background:${c.swatch}" title="${c.color}"></button>`,
    ).join('')

    toolbar.innerHTML = `
      <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
      <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
      <button type="button" data-cmd="strike" title="Strikethrough"><s>S</s></button>
      <button type="button" data-cmd="code" title="Inline code">&lt;/&gt;</button>
      <span class="toolbar-sep"></span>
      <button type="button" data-cmd="link" title="Link">🔗</button>
      <span class="toolbar-sep"></span>
      <div class="toolbar-highlight-group">
        <button type="button" data-cmd="highlight" title="Highlight (Ctrl+Shift+H)">🖍</button>
        <div class="highlight-dropdown" hidden>${swatchHtml}</div>
      </div>
      <span class="toolbar-sep"></span>
      <select data-cmd="heading" title="Block type">
        <option value="paragraph">Paragraph</option>
        <option value="2">Heading 2</option>
        <option value="3">Heading 3</option>
        <option value="4">Heading 4</option>
      </select>
      <span class="toolbar-sep toolbar-extension-sep" hidden></span>
      <span class="toolbar-extension-actions"></span>
    `
    document.body.appendChild(toolbar)

    const dropdown = toolbar.querySelector('.highlight-dropdown')

    toolbar.addEventListener('mousedown', (e) => e.preventDefault())

    toolbar.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-cmd]')
      if (!btn) return
      const cmd = btn.dataset.cmd
      switch (cmd) {
        case 'bold':
          editor.chain().focus().toggleBold().run()
          break
        case 'italic':
          editor.chain().focus().toggleItalic().run()
          break
        case 'strike':
          editor.chain().focus().toggleStrike().run()
          break
        case 'code':
          editor.chain().focus().toggleCode().run()
          break
        case 'link':
          promptLink(editor)
          break
        case 'highlight':
          dropdown.hidden = !dropdown.hidden
          break
      }
    })

    toolbar.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-extension-action]')
      if (!btn) return
      const action = window.TypeDockEditorApi?.getInlineAction(btn.dataset.extensionAction)
      if (action) action.run(window.TypeDockEditorApi)
    })

    dropdown.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-color]')
      if (!btn) return
      editor.chain().focus().toggleHighlight({ color: btn.dataset.color }).run()
      dropdown.hidden = true
    })

    toolbar.querySelector('select[data-cmd="heading"]').addEventListener('change', (e) => {
      const v = e.target.value
      if (v === 'paragraph') editor.chain().focus().setParagraph().run()
      else editor.chain().focus().toggleHeading({ level: parseInt(v, 10) }).run()
      e.target.value = currentBlockValue(editor)
    })

    const renderActions = () => renderExtensionActions(toolbar)
    renderActions()
    document.addEventListener('typedock:editor-actions-changed', renderActions)

    const sync = () => updateToolbar(editor, toolbar)
    editor.on('selectionUpdate', sync)
    editor.on('transaction', sync)
    editor.on('blur', () => {
      // Defer so clicks on the toolbar can register before hiding.
      setTimeout(() => {
        if (!toolbar.contains(document.activeElement)) toolbar.hidden = true
      }, 100)
    })

    document.addEventListener('click', (e) => {
      if (!dropdown.contains(e.target) && !toolbar.querySelector('[data-cmd="highlight"]').contains(e.target)) {
        dropdown.hidden = true
      }
    })
  },
}

function renderExtensionActions(toolbar) {
  const wrap = toolbar.querySelector('.toolbar-extension-actions')
  const sep = toolbar.querySelector('.toolbar-extension-sep')
  if (!wrap || !sep) return

  const actions = window.TypeDockEditorApi?.getInlineActions?.() || []
  wrap.replaceChildren()
  sep.hidden = actions.length === 0

  actions.forEach((action) => {
    const button = document.createElement('button')
    button.type = 'button'
    button.dataset.extensionAction = action.id
    button.title = action.title || action.label
    button.textContent = action.label
    wrap.appendChild(button)
  })
}

function currentBlockValue(editor) {
  if (editor.isActive('heading', { level: 2 })) return '2'
  if (editor.isActive('heading', { level: 3 })) return '3'
  if (editor.isActive('heading', { level: 4 })) return '4'
  return 'paragraph'
}

function updateToolbar(editor, toolbar) {
  const sel = editor.state.selection
  const { from, to, empty } = sel
  // Hide on collapsed selection, on NodeSelection (atom block selected via
  // click), and when the editor doesn't actually have focus.
  if (empty || !editor.isFocused || sel.node) {
    toolbar.hidden = true
    return
  }

  toolbar.hidden = false
  toolbar.querySelector('select[data-cmd="heading"]').value = currentBlockValue(editor)

  const start = editor.view.coordsAtPos(from)
  const end = editor.view.coordsAtPos(to)
  const left = (start.left + end.left) / 2 - toolbar.offsetWidth / 2
  const top = start.top - toolbar.offsetHeight - 8

  toolbar.style.left = `${Math.max(8, left + window.scrollX)}px`
  toolbar.style.top = `${Math.max(8, top + window.scrollY)}px`
}

function promptLink(editor) {
  const existing = editor.getAttributes('link').href
  const url = window.prompt('Link URL:', existing || 'https://')
  if (url === null) return
  if (url === '') {
    editor.chain().focus().unsetLink().run()
  } else {
    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
  }
}
