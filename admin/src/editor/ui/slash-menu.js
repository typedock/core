import { uploadImage } from './image-upload.js'

const STATIC_ITEMS = [
  {
    group: 'Text',
    items: [
      { label: 'Heading 2', icon: 'H2', cmd: (e) => e.chain().focus().toggleHeading({ level: 2 }).run() },
      { label: 'Heading 3', icon: 'H3', cmd: (e) => e.chain().focus().toggleHeading({ level: 3 }).run() },
      { label: 'Heading 4', icon: 'H4', cmd: (e) => e.chain().focus().toggleHeading({ level: 4 }).run() },
      { label: 'Quote', icon: '❝', cmd: (e) => e.chain().focus().toggleBlockquote().run() },
      { label: 'Bullet list', icon: '•', cmd: (e) => e.chain().focus().toggleBulletList().run() },
      { label: 'Numbered list', icon: '1.', cmd: (e) => e.chain().focus().toggleOrderedList().run() },
      { label: 'Code block', icon: '</>', cmd: (e) => e.chain().focus().toggleCodeBlock().run() },
      { label: 'Divider', icon: '—', cmd: (e) => e.chain().focus().setHorizontalRule().run() },
    ],
  },
  {
    group: 'Media',
    items: [
      { label: 'Image', icon: '🖼', cmd: (e) => triggerImageUpload(e) },
      { label: 'Bookmark', icon: '🔗', cmd: (e) => e.chain().focus().setBookmark('').run() },
      { label: 'Embed', icon: '📺', cmd: (e) => e.chain().focus().setEmbed('').run() },
    ],
  },
]

export const SlashMenu = {
  init(editor) {
    const menu = document.createElement('div')
    menu.className = 'slash-menu'
    menu.hidden = true
    document.body.appendChild(menu)

    const state = { activeIndex: 0, items: [], slashFrom: null }

    const close = () => {
      menu.hidden = true
      state.items = []
      state.slashFrom = null
    }

    const detect = () => {
      const { $from, empty } = editor.state.selection
      if (!empty || !editor.isFocused) return close()
      const textBefore = $from.parent.textBetween(0, $from.parentOffset, undefined, '\ufffc')
      const m = /(?:^|\s)\/(\S*)$/.exec(textBefore)
      if (!m) return close()

      const query = m[1] || ''
      state.items = filterItems(query)
      if (state.items.length === 0) return close()

      state.activeIndex = 0
      // Position of the "/" itself = cursor pos - query length - 1.
      state.slashFrom = $from.pos - query.length - 1

      renderMenu(menu, state.items, state.activeIndex)
      positionMenu(editor, menu)
      menu.hidden = false
    }

    editor.on('update', detect)
    editor.on('selectionUpdate', detect)

    // Capture phase + stopImmediatePropagation so ProseMirror's keymap (which
    // would turn Enter into a hard break / split block) never sees these
    // keys while the slash menu is open.
    editor.view.dom.addEventListener(
      'keydown',
      (e) => {
        if (menu.hidden) return
        if (e.key === 'ArrowDown') {
          e.preventDefault()
          e.stopImmediatePropagation()
          state.activeIndex = (state.activeIndex + 1) % state.items.length
          renderMenu(menu, state.items, state.activeIndex)
        } else if (e.key === 'ArrowUp') {
          e.preventDefault()
          e.stopImmediatePropagation()
          state.activeIndex = (state.activeIndex - 1 + state.items.length) % state.items.length
          renderMenu(menu, state.items, state.activeIndex)
        } else if (e.key === 'Enter') {
          e.preventDefault()
          e.stopImmediatePropagation()
          const item = state.items[state.activeIndex]
          if (item) executeItem(editor, item, state.slashFrom)
          close()
        } else if (e.key === 'Escape') {
          e.preventDefault()
          e.stopImmediatePropagation()
          close()
        }
      },
      true,
    )

    menu.addEventListener('mousedown', (e) => e.preventDefault())
    menu.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-index]')
      if (!btn) return
      const item = state.items[Number(btn.dataset.index)]
      if (item) executeItem(editor, item, state.slashFrom)
      close()
    })

    editor.on('blur', () => setTimeout(close, 100))
  },
}

function filterItems(query) {
  const all = []
  for (const group of STATIC_ITEMS) {
    for (const item of group.items) all.push({ ...item, group: group.group })
  }
  const defs = window.typedockComponentDefs || {}
  for (const [type, def] of Object.entries(defs)) {
    if (Array.isArray(def.placeable) && !def.placeable.includes('block')) continue
    all.push({
      label: def.name || type,
      icon: def.icon || '🧩',
      group: 'Components',
      cmd: (editor) =>
        editor.chain().focus().setComponentBlock(type, def.defaultParams || {}).run(),
    })
  }
  if (!query) return all
  const q = query.toLowerCase()
  return all.filter((it) => it.label.toLowerCase().includes(q))
}

function renderMenu(menu, items, activeIndex) {
  let html = ''
  let lastGroup = ''
  items.forEach((item, i) => {
    if (item.group !== lastGroup) {
      html += `<div class="slash-menu-group">${escapeHtml(item.group)}</div>`
      lastGroup = item.group
    }
    const active = i === activeIndex ? ' is-active' : ''
    html += `
      <button type="button" data-index="${i}" class="slash-menu-item${active}">
        <span class="slash-menu-icon">${escapeHtml(item.icon || '')}</span>
        <span class="slash-menu-label">${escapeHtml(item.label)}</span>
      </button>
    `
  })
  menu.innerHTML = html
}

function positionMenu(editor, menu) {
  const { from } = editor.state.selection
  const coords = editor.view.coordsAtPos(from)
  menu.style.left = `${coords.left + window.scrollX}px`
  menu.style.top = `${coords.bottom + window.scrollY + 4}px`
}

function executeItem(editor, item, slashFrom) {
  const { $from } = editor.state.selection
  if (typeof slashFrom === 'number' && slashFrom >= 0 && slashFrom < $from.pos) {
    editor.chain().deleteRange({ from: slashFrom, to: $from.pos }).run()
  }
  item.cmd(editor)
}

function triggerImageUpload(editor) {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/*'
  input.addEventListener('change', async () => {
    const file = input.files?.[0]
    if (!file) return
    try {
      const attrs = await uploadImage(file)
      editor.chain().focus().setImage(attrs).run()
    } catch (err) {
      console.error('Image upload failed', err)
    }
  })
  input.click()
}

function escapeHtml(str) {
  const d = document.createElement('div')
  d.textContent = String(str ?? '')
  return d.innerHTML
}
