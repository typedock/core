import { Node } from '@tiptap/core'

function escapeHtml(str) {
  const d = document.createElement('div')
  d.textContent = String(str ?? '')
  return d.innerHTML
}

function summarizeParams(params) {
  const entries = Object.entries(params || {})
  if (entries.length === 0) return ''
  return entries
    .slice(0, 4)
    .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
    .join(', ')
}

function normalizeOptions(options) {
  if (Array.isArray(options)) return options
  if (options && typeof options === 'object') {
    return Object.entries(options).map(([value, label]) => ({ value, label }))
  }
  return []
}

export const ComponentBlock = Node.create({
  name: 'componentBlock',
  group: 'block',
  atom: true,
  selectable: true,
  draggable: true,

  addAttributes() {
    return {
      component: { default: '' },
      params: { default: {} },
    }
  },

  parseHTML() {
    return [{ tag: 'div[data-component]' }]
  },

  renderHTML({ node }) {
    return [
      'div',
      {
        'data-component': node.attrs.component,
        'data-params': JSON.stringify(node.attrs.params || {}),
      },
    ]
  },

  addCommands() {
    return {
      setComponentBlock:
        (component, params = {}) =>
        ({ chain }) =>
          chain()
            .insertContent({
              type: 'componentBlock',
              attrs: { component, params },
            })
            .run(),
    }
  },

  addNodeView() {
    return ({ node, editor, getPos }) => {
      const dom = document.createElement('div')
      dom.className = 'component-block'
      dom.contentEditable = 'false'

      const def = window.typedockComponentDefs?.[node.attrs.component]

      const render = (attrs) => {
        const d = window.typedockComponentDefs?.[attrs.component]
        dom.innerHTML = `
          <div class="component-placeholder">
            <span class="component-icon">${escapeHtml(d?.icon || '🧩')}</span>
            <span class="component-name">${escapeHtml(d?.name || attrs.component)}</span>
            <span class="component-params">${escapeHtml(summarizeParams(attrs.params))}</span>
            <div class="component-actions">
              <button type="button" class="component-edit" title="Edit parameters">⚙</button>
              <button type="button" class="component-delete" title="Remove">×</button>
            </div>
          </div>
        `
        dom.querySelector('.component-edit').addEventListener('click', () => {
          openComponentParamsModal(attrs.component, attrs.params, (newParams) => {
            if (typeof getPos !== 'function') return
            editor.chain().focus().updateAttributes('componentBlock', { params: newParams }).run()
          })
        })
        dom.querySelector('.component-delete').addEventListener('click', () => {
          if (typeof getPos !== 'function') return
          const pos = getPos()
          editor.chain().focus().deleteRange({ from: pos, to: pos + node.nodeSize }).run()
        })
      }

      render(node.attrs)
      void def

      return {
        dom,
        stopEvent: () => true,
        ignoreMutation: () => true,
        update: (updatedNode) => {
          if (updatedNode.type.name !== 'componentBlock') return false
          render(updatedNode.attrs)
          return true
        },
      }
    }
  },
})

function openComponentParamsModal(componentType, currentParams, onSave) {
  const def = window.typedockComponentDefs?.[componentType]
  const params = def?.params || []

  const overlay = document.createElement('div')
  overlay.className = 'component-modal-overlay'

  const modal = document.createElement('div')
  modal.className = 'component-modal'

  const close = () => overlay.remove()

  let fields = ''
  for (const p of params) {
    const val = currentParams?.[p.name] ?? p.default ?? ''
    const label = escapeHtml(p.label || p.name)
    const hint = p.hint ? `<small>${escapeHtml(p.hint)}</small>` : ''
    if (p.type === 'number' || p.type === 'integer') {
      fields += `<label class="form-group"><span>${label}</span>
        <input type="number" name="${escapeHtml(p.name)}" value="${escapeHtml(val)}">${hint}</label>`
    } else if (p.type === 'boolean') {
      const checked = val === true || val === 'true' ? 'checked' : ''
      fields += `<label class="form-group"><span>${label}</span>
        <input type="checkbox" name="${escapeHtml(p.name)}" ${checked}>${hint}</label>`
    } else if (p.type === 'select') {
      const opts = normalizeOptions(p.options)
        .map((o) => {
          const v = typeof o === 'object' ? o.value : o
          const lbl = typeof o === 'object' ? o.label : o
          const sel = String(v) === String(val) ? 'selected' : ''
          return `<option value="${escapeHtml(v)}" ${sel}>${escapeHtml(lbl)}</option>`
        })
        .join('')
      fields += `<label class="form-group"><span>${label}</span>
        <select name="${escapeHtml(p.name)}">${opts}</select>${hint}</label>`
    } else {
      fields += `<label class="form-group"><span>${label}</span>
        <input type="text" name="${escapeHtml(p.name)}" value="${escapeHtml(val)}">${hint}</label>`
    }
  }

  modal.innerHTML = `
    <div class="component-modal-header">
      <h3>${escapeHtml(def?.name || componentType)}</h3>
      <button type="button" class="component-modal-close">×</button>
    </div>
    <form class="component-modal-body">
      ${fields || '<p class="meta-hint">This component has no configurable parameters.</p>'}
    </form>
    <div class="component-modal-footer">
      <button type="button" class="btn btn-secondary" data-action="cancel">Cancel</button>
      <button type="button" class="btn btn-primary" data-action="save">Save</button>
    </div>
  `
  overlay.appendChild(modal)
  document.body.appendChild(overlay)

  modal.querySelector('.component-modal-close').addEventListener('click', close)
  modal.querySelector('[data-action="cancel"]').addEventListener('click', close)
  modal.querySelector('[data-action="save"]').addEventListener('click', () => {
    const form = modal.querySelector('form')
    const out = {}
    for (const p of params) {
      const el = form.elements.namedItem(p.name)
      if (!el) continue
      if (p.type === 'boolean') out[p.name] = el.checked
      else if (p.type === 'number' || p.type === 'integer') {
        const n = el.value === '' ? null : Number(el.value)
        out[p.name] = Number.isFinite(n) ? n : null
      } else out[p.name] = el.value
    }
    onSave(out)
    close()
  })
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) close()
  })
}
