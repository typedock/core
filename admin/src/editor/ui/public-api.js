const state = {
  editor: null,
  bodyInput: null,
  inlineActions: [],
  panelActions: [],
  assistantTray: null,
  assistantStatus: null,
  assistantStatusTimer: null,
  assistantResults: [],
  assistantResultSeq: 0,
}

const api = window.TypeDockEditorApi || {}

Object.assign(api, {
  attach(editor, bodyInput) {
    state.editor = editor
    state.bodyInput = bodyInput || null
    emit('typedock:editor-ready', { api })
    renderPanelActions()
    renderAssistantTray()
  },

  registerInlineAction(action) {
    const normalized = normalizeAction(action)
    if (!normalized) return () => {}
    state.inlineActions = state.inlineActions.filter((item) => item.id !== normalized.id)
    state.inlineActions.push(normalized)
    emit('typedock:editor-actions-changed', { type: 'inline' })
    return () => {
      state.inlineActions = state.inlineActions.filter((item) => item.id !== normalized.id)
      emit('typedock:editor-actions-changed', { type: 'inline' })
    }
  },

  registerPanelAction(action) {
    const normalized = normalizeAction(action)
    if (!normalized) return () => {}
    normalized.surface = typeof action.surface === 'string' ? action.surface : 'seo'
    state.panelActions = state.panelActions.filter((item) => item.id !== normalized.id)
    state.panelActions.push(normalized)
    renderPanelActions()
    return () => {
      state.panelActions = state.panelActions.filter((item) => item.id !== normalized.id)
      renderPanelActions()
    }
  },

  getInlineActions() {
    return [...state.inlineActions]
  },

  getInlineAction(id) {
    return state.inlineActions.find((action) => action.id === id) || null
  },

  getSelectionText() {
    const editor = requireEditor()
    const { from, to } = editor.state.selection
    if (from === to) return ''
    return editor.state.doc.textBetween(from, to, '\n\n')
  },

  getSelectionRange() {
    const editor = requireEditor()
    const { from, to } = editor.state.selection
    return { from, to }
  },

  getDocumentText() {
    const editor = requireEditor()
    return editor.state.doc.textBetween(0, editor.state.doc.content.size, '\n\n')
  },

  getDocumentJSON() {
    return requireEditor().getJSON()
  },

  markdownToDocumentJSON(markdown) {
    return markdownToDocumentJSON(markdown)
  },

  setDocumentMarkdown(markdown) {
    const editor = requireEditor()
    editor.commands.setContent(markdownToDocumentJSON(markdown))
    editor.commands.focus()
    syncBodyInput()
    return true
  },

  insertMarkdown(markdown) {
    const editor = requireEditor()
    const doc = markdownToDocumentJSON(markdown)
    editor.chain().focus().insertContent(doc.content || []).run()
    syncBodyInput()
    return true
  },

  replaceSelection(text) {
    const range = this.getSelectionRange()
    return this.replaceRange(range, text)
  },

  replaceRange(range, text) {
    const editor = requireEditor()
    const from = Number(range?.from)
    const to = Number(range?.to)
    if (!Number.isFinite(from) || !Number.isFinite(to) || from >= to) return false
    const tr = editor.state.tr.insertText(toPlainText(text), from, to)
    editor.view.dispatch(tr)
    editor.commands.focus()
    syncBodyInput()
    return true
  },

  getSeoFields() {
    return {
      post_title: readField('#post-title'),
      seo_title: readField('[name="seo[seo_title]"]'),
      meta_description: readField('[name="seo[meta_description]"]'),
      excerpt: readField('[name="excerpt"]'),
      focus_keyword: readField('[name="seo[focus_keyword]"]'),
    }
  },

  proposeSeoFields(payload) {
    renderSeoProposals(payload || {})
  },

  setAssistantStatus(message, type = 'info') {
    setAssistantStatus(String(message || ''), type)
  },

  clearAssistantStatus() {
    setAssistantStatus('', 'info')
  },

  addAssistantResult(result) {
    return addAssistantResult(result || {})
  },

  clearAssistantResults() {
    state.assistantResults = []
    renderAssistantTray()
  },

  openAssistantTray() {
    ensureAssistantTray()
    if (state.assistantTray) state.assistantTray.classList.remove('is-collapsed')
  },

  showPreviewDialog(options) {
    showPreviewDialog(options || {})
  },

  showNotice(message, type = 'info') {
    showNotice(String(message || ''), type)
  },
})

window.TypeDockEditorApi = api

export const EditorPublicApi = api

function normalizeAction(action) {
  if (!action || typeof action !== 'object') return null
  const id = String(action.id || '').trim()
  const label = String(action.label || '').trim()
  if (id === '' || label === '' || typeof action.run !== 'function') return null
  return {
    id,
    label,
    title: String(action.title || label),
    surface: String(action.surface || ''),
    run: action.run,
  }
}

function requireEditor() {
  if (!state.editor) {
    throw new Error('TypeDock editor is not ready.')
  }
  return state.editor
}

function syncBodyInput() {
  if (!state.editor || !state.bodyInput) return
  state.bodyInput.value = JSON.stringify(state.editor.getJSON())
  state.bodyInput.dispatchEvent(new Event('input', { bubbles: true }))
  state.bodyInput.dispatchEvent(new Event('change', { bubbles: true }))
}

function renderPanelActions() {
  document.querySelectorAll('[data-editor-panel-actions]').forEach((container) => {
    const surface = container.dataset.editorPanelActions || 'seo'
    const actions = state.panelActions.filter((action) => action.surface === surface)
    container.replaceChildren()
    container.hidden = actions.length === 0
    if (actions.length === 0) return
    const wrap = document.createElement('div')
    wrap.className = 'editor-panel-action-row'
    actions.forEach((action) => {
      const button = document.createElement('button')
      button.type = 'button'
      button.className = 'btn btn-secondary btn-sm'
      button.textContent = action.label
      button.title = action.title
      button.addEventListener('click', () => action.run(api))
      wrap.appendChild(button)
    })
    container.appendChild(wrap)
  })
}

function renderSeoProposals(payload) {
  const container = document.querySelector('[data-editor-panel-actions="seo"]')
  const details = container?.closest('details') || null
  if (details) details.open = true

  const fields = [
    { key: 'title', label: 'SEO Title', selector: '[name="seo[seo_title]"]' },
    { key: 'seo_title', label: 'SEO Title', selector: '[name="seo[seo_title]"]' },
    { key: 'meta_description', label: 'Meta Description', selector: '[name="seo[meta_description]"]' },
    { key: 'excerpt', label: 'Excerpt', selector: '[name="excerpt"]' },
  ]

  const seen = new Set()
  const proposals = fields
    .filter((field) => {
      if (seen.has(field.selector)) return false
      const value = toPlainText(payload[field.key] || '')
      if (value === '') return false
      seen.add(field.selector)
      field.value = value
      return true
    })

  if (proposals.length === 0) {
    showNotice('No SEO suggestions were returned.', 'warning')
    return
  }

  addAssistantResult({
    title: 'Suggested metadata',
    message: 'Review each suggestion before applying it.',
    type: 'success',
    items: proposals.map((field) => ({
      label: field.label,
      value: field.value,
      applyLabel: 'Apply',
      apply: () => writeField(field.selector, field.value),
    })),
  })
}

function setAssistantStatus(message, type) {
  if (state.assistantStatusTimer) {
    clearTimeout(state.assistantStatusTimer)
    state.assistantStatusTimer = null
  }

  const text = toPlainText(message)
  state.assistantStatus = text === '' ? null : {
    message: text,
    type: normalizeAssistantType(type),
  }
  renderAssistantTray()

  if (state.assistantStatus && state.assistantStatus.type !== 'loading') {
    state.assistantStatusTimer = setTimeout(() => {
      state.assistantStatus = null
      state.assistantStatusTimer = null
      renderAssistantTray()
    }, 7000)
  }
}

function addAssistantResult(result) {
  const title = toPlainText(result.title || 'AI suggestion')
  const normalized = {
    id: String(result.id || `assistant-result-${++state.assistantResultSeq}`),
    title: title || 'AI suggestion',
    message: toPlainText(result.message || ''),
    type: normalizeAssistantType(result.type || 'info'),
    items: Array.isArray(result.items) ? result.items.map(normalizeAssistantItem).filter(Boolean) : [],
    actions: Array.isArray(result.actions) ? result.actions.map(normalizeAssistantAction).filter(Boolean) : [],
  }
  state.assistantResults = [
    normalized,
    ...state.assistantResults.filter((item) => item.id !== normalized.id),
  ].slice(0, 8)
  renderAssistantTray()
  return normalized.id
}

function normalizeAssistantItem(item) {
  if (!item || typeof item !== 'object') return null
  const value = toPlainText(item.value || '')
  if (value === '') return null
  return {
    label: toPlainText(item.label || 'Suggestion'),
    value,
    applyLabel: toPlainText(item.applyLabel || 'Apply'),
    apply: typeof item.apply === 'function' ? item.apply : null,
  }
}

function normalizeAssistantAction(action) {
  if (!action || typeof action !== 'object' || typeof action.run !== 'function') return null
  const label = toPlainText(action.label || '')
  if (label === '') return null
  return {
    label,
    title: toPlainText(action.title || label),
    variant: action.variant === 'primary' ? 'primary' : 'secondary',
    run: action.run,
  }
}

function normalizeAssistantType(type) {
  return ['info', 'success', 'warning', 'error', 'loading'].includes(type) ? type : 'info'
}

function ensureAssistantTray() {
  if (state.assistantTray) return state.assistantTray
  const tray = document.createElement('aside')
  tray.className = 'editor-assistant-tray'
  tray.setAttribute('aria-live', 'polite')
  tray.hidden = true
  document.body.appendChild(tray)
  state.assistantTray = tray
  return tray
}

function renderAssistantTray() {
  const tray = ensureAssistantTray()
  const hasStatus = Boolean(state.assistantStatus)
  const hasResults = state.assistantResults.length > 0
  tray.hidden = !hasStatus && !hasResults
  if (tray.hidden) {
    tray.replaceChildren()
    return
  }

  const header = document.createElement('div')
  header.className = 'editor-assistant-tray__header'
  const title = document.createElement('div')
  title.className = 'editor-assistant-tray__title'
  title.textContent = 'AI Assist'
  const tools = document.createElement('div')
  tools.className = 'editor-assistant-tray__tools'
  if (hasResults) {
    const clear = document.createElement('button')
    clear.type = 'button'
    clear.className = 'editor-assistant-tray__clear'
    clear.textContent = 'Clear'
    clear.addEventListener('click', () => {
      state.assistantResults = []
      renderAssistantTray()
    })
    tools.appendChild(clear)
  }
  header.append(title, tools)

  const body = document.createElement('div')
  body.className = 'editor-assistant-tray__body'
  if (state.assistantStatus) body.appendChild(assistantStatusNode(state.assistantStatus))
  state.assistantResults.forEach((result) => body.appendChild(assistantResultNode(result)))

  tray.replaceChildren(header, body)
}

function assistantStatusNode(status) {
  const node = document.createElement('div')
  node.className = `editor-assistant-status editor-assistant-status--${status.type}`
  if (status.type === 'loading') {
    const spinner = document.createElement('span')
    spinner.className = 'editor-assistant-status__spinner'
    spinner.setAttribute('aria-hidden', 'true')
    node.appendChild(spinner)
  }
  const message = document.createElement('span')
  message.textContent = status.message
  node.appendChild(message)
  return node
}

function assistantResultNode(result) {
  const card = document.createElement('section')
  card.className = `editor-assistant-result editor-assistant-result--${result.type}`

  const heading = document.createElement('h3')
  heading.textContent = result.title
  card.appendChild(heading)

  if (result.message !== '') {
    const message = document.createElement('p')
    message.className = 'editor-assistant-result__message'
    message.textContent = result.message
    card.appendChild(message)
  }

  result.items.forEach((item) => card.appendChild(assistantItemNode(item)))
  if (result.actions.length > 0) {
    const actions = document.createElement('div')
    actions.className = 'editor-assistant-result__actions'
    result.actions.forEach((action) => {
      const button = document.createElement('button')
      button.type = 'button'
      button.className = action.variant === 'primary' ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm'
      button.textContent = action.label
      button.title = action.title
      button.addEventListener('click', () => action.run(api, result))
      actions.appendChild(button)
    })
    card.appendChild(actions)
  }

  return card
}

function assistantItemNode(item) {
  const row = document.createElement('div')
  row.className = 'editor-assistant-item'

  const text = document.createElement('div')
  text.className = 'editor-assistant-item__text'
  const label = document.createElement('strong')
  label.textContent = item.label
  const value = document.createElement('p')
  value.textContent = item.value
  text.append(label, value)

  row.appendChild(text)
  if (item.apply) {
    const apply = document.createElement('button')
    apply.type = 'button'
    apply.className = 'btn btn-secondary btn-sm'
    apply.textContent = item.applyLabel
    apply.addEventListener('click', () => {
      item.apply(api, item)
      row.classList.add('is-applied')
    })
    row.appendChild(apply)
  }
  return row
}

function showPreviewDialog({ title = 'Preview', original = '', suggestion = '', onApply = null }) {
  const overlay = document.createElement('div')
  overlay.className = 'editor-preview-modal'
  overlay.setAttribute('role', 'dialog')
  overlay.setAttribute('aria-modal', 'true')

  const dialog = document.createElement('div')
  dialog.className = 'editor-preview-modal__dialog'

  const header = document.createElement('div')
  header.className = 'editor-preview-modal__header'
  const heading = document.createElement('h2')
  heading.textContent = title
  const close = document.createElement('button')
  close.type = 'button'
  close.className = 'editor-preview-modal__close'
  close.textContent = 'Close'
  close.addEventListener('click', () => overlay.remove())
  header.append(heading, close)

  const body = document.createElement('div')
  body.className = 'editor-preview-modal__body'
  body.append(
    previewColumn('Original', original),
    previewColumn('Suggestion', suggestion),
  )

  const footer = document.createElement('div')
  footer.className = 'editor-preview-modal__footer'
  const cancel = document.createElement('button')
  cancel.type = 'button'
  cancel.className = 'btn btn-ghost'
  cancel.textContent = 'Cancel'
  cancel.addEventListener('click', () => overlay.remove())
  const apply = document.createElement('button')
  apply.type = 'button'
  apply.className = 'btn btn-primary'
  apply.textContent = 'Apply'
  apply.addEventListener('click', () => {
    if (typeof onApply === 'function') onApply(toPlainText(suggestion))
    overlay.remove()
  })
  footer.append(cancel, apply)

  dialog.append(header, body, footer)
  overlay.appendChild(dialog)
  document.body.appendChild(overlay)
  apply.focus()
}

function previewColumn(label, text) {
  const column = document.createElement('section')
  column.className = 'editor-preview-modal__column'
  const heading = document.createElement('h3')
  heading.textContent = label
  const pre = document.createElement('pre')
  pre.textContent = toPlainText(text)
  column.append(heading, pre)
  return column
}

function showNotice(message, type) {
  if (message === '') return
  setAssistantStatus(message, type)
}

function readField(selector) {
  const field = document.querySelector(selector)
  return field ? String(field.value || '') : ''
}

function writeField(selector, value) {
  const field = document.querySelector(selector)
  if (!field) return
  field.value = toPlainText(value)
  field.dispatchEvent(new Event('input', { bubbles: true }))
  field.dispatchEvent(new Event('change', { bubbles: true }))
}

function markdownToDocumentJSON(markdown) {
  const lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n')
  const content = []
  let paragraph = []
  let i = 0

  const flushParagraph = () => {
    const text = paragraph.join(' ').trim()
    paragraph = []
    if (text !== '') content.push(paragraphNode(text))
  }

  while (i < lines.length) {
    const raw = lines[i]
    const line = raw.trimEnd()

    const fence = line.match(/^```/)
    if (fence) {
      flushParagraph()
      const code = []
      i += 1
      while (i < lines.length && !lines[i].trimEnd().match(/^```/)) {
        code.push(lines[i])
        i += 1
      }
      if (i < lines.length) i += 1
      content.push(codeBlockNode(code.join('\n')))
      continue
    }

    if (line.trim() === '') {
      flushParagraph()
      i += 1
      continue
    }

    const heading = line.match(/^(#{1,6})\s+(.+)$/)
    if (heading) {
      flushParagraph()
      const level = Math.min(4, Math.max(2, heading[1].length))
      content.push({
        type: 'heading',
        attrs: { level },
        content: textContent(stripInlineMarkdown(heading[2])),
      })
      i += 1
      continue
    }

    if (/^(-{3,}|\*{3,}|_{3,})\s*$/.test(line.trim())) {
      flushParagraph()
      content.push({ type: 'horizontalRule' })
      i += 1
      continue
    }

    if (/^\s*[-*+]\s+/.test(line)) {
      flushParagraph()
      const items = []
      while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) {
        items.push(listItemNode(lines[i].replace(/^\s*[-*+]\s+/, '')))
        i += 1
      }
      content.push({ type: 'bulletList', content: items })
      continue
    }

    if (/^\s*\d+[.)]\s+/.test(line)) {
      flushParagraph()
      const items = []
      while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) {
        items.push(listItemNode(lines[i].replace(/^\s*\d+[.)]\s+/, '')))
        i += 1
      }
      content.push({ type: 'orderedList', attrs: { start: 1 }, content: items })
      continue
    }

    if (/^\s*>\s?/.test(line)) {
      flushParagraph()
      const parts = []
      while (i < lines.length && /^\s*>\s?/.test(lines[i])) {
        parts.push(lines[i].replace(/^\s*>\s?/, ''))
        i += 1
      }
      content.push({ type: 'blockquote', content: [paragraphNode(parts.join(' '))] })
      continue
    }

    paragraph.push(line.trim())
    i += 1
  }

  flushParagraph()
  return { type: 'doc', content: content.length ? content : [{ type: 'paragraph' }] }
}

function paragraphNode(text) {
  const content = textContent(stripInlineMarkdown(text))
  return content.length ? { type: 'paragraph', content } : { type: 'paragraph' }
}

function listItemNode(text) {
  return { type: 'listItem', content: [paragraphNode(text)] }
}

function codeBlockNode(text) {
  return text === ''
    ? { type: 'codeBlock' }
    : { type: 'codeBlock', content: [{ type: 'text', text }] }
}

function textContent(text) {
  const plain = toPlainText(text)
  return plain === '' ? [] : [{ type: 'text', text: plain }]
}

function stripInlineMarkdown(text) {
  return String(text || '')
    .replace(/!\[([^\]]*)]\([^)]+\)/g, '$1')
    .replace(/\[([^\]]+)]\([^)]+\)/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/(\*\*|__)(.*?)\1/g, '$2')
    .replace(/(\*|_)(.*?)\1/g, '$2')
    .replace(/~~(.*?)~~/g, '$1')
}

function toPlainText(value) {
  return String(value || '')
    .replace(/\r\n?/g, '\n')
    .replace(/<[^>]*>/g, '')
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
    .trim()
}

function emit(name, detail) {
  document.dispatchEvent(new CustomEvent(name, { detail }))
}
