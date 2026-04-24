import { Node } from '@tiptap/core'
import { isolateFromProseMirror } from '../ui/dom-isolation.js'

function escapeHtml(str) {
  const d = document.createElement('div')
  d.textContent = String(str ?? '')
  return d.innerHTML
}

export const Bookmark = Node.create({
  name: 'bookmark',
  group: 'block',
  atom: true,
  selectable: true,
  draggable: true,

  addAttributes() {
    return {
      url: { default: '' },
      title: { default: null },
      description: { default: null },
      thumbnail: { default: null },
      favicon: { default: null },
    }
  },

  parseHTML() {
    return [{ tag: 'div[data-bookmark]' }]
  },

  renderHTML({ node }) {
    return [
      'div',
      {
        'data-bookmark': '',
        'data-url': node.attrs.url,
      },
    ]
  },

  addCommands() {
    return {
      setBookmark:
        (url = '') =>
        ({ chain }) =>
          chain()
            .insertContent({ type: 'bookmark', attrs: { url } })
            .run(),
    }
  },

  addNodeView() {
    return ({ node, editor, getPos }) => {
      const dom = document.createElement('div')
      dom.className = 'bookmark-card'
      dom.contentEditable = 'false'
      let lastAttrs = node.attrs

      const fetchCsrf = () =>
        document.querySelector('input[name="_csrf_token"]')?.value || ''

      const remove = () => {
        if (typeof getPos !== 'function') return
        const pos = getPos()
        editor
          .chain()
          .focus()
          .deleteRange({ from: pos, to: pos + node.nodeSize })
          .run()
      }

      const submitUrl = async (rawUrl) => {
        const url = (rawUrl || '').trim()
        if (!url) return
        const liveInput = dom.querySelector('[data-bookmark-input]')
        if (liveInput) {
          liveInput.disabled = true
          liveInput.placeholder = 'Fetching preview…'
        }
        try {
          const resp = await fetch(`/admin/api/ogp?url=${encodeURIComponent(url)}`, {
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': fetchCsrf() },
          })
          const ogp = resp.ok ? await resp.json() : {}
          if (typeof getPos !== 'function') return
          editor
            .chain()
            .focus()
            .updateAttributes('bookmark', {
              url,
              title: ogp.title || url,
              description: ogp.description || null,
              thumbnail: ogp.image || null,
              favicon: ogp.favicon || null,
            })
            .run()
        } catch (err) {
          if (typeof getPos !== 'function') return
          editor.chain().focus().updateAttributes('bookmark', { url, title: url }).run()
        }
      }

      // Capture-phase + stopImmediatePropagation so the click is fully
      // isolated from the surrounding form's implicit submit pathway.
      dom.addEventListener(
        'click',
        (e) => {
          const t = e.target
          if (t.closest('[data-bookmark-confirm]')) {
            e.preventDefault()
            e.stopImmediatePropagation()
            const live = dom.querySelector('[data-bookmark-input]')
            if (live) submitUrl(live.value)
          } else if (t.closest('.bookmark-cancel') || t.closest('.bookmark-delete')) {
            e.preventDefault()
            e.stopImmediatePropagation()
            remove()
          }
        },
        true,
      )
      dom.addEventListener(
        'keydown',
        (e) => {
          if (e.key !== 'Enter') return
          const t = e.target
          if (!t.matches('[data-bookmark-input]')) return
          e.preventDefault()
          e.stopImmediatePropagation()
          submitUrl(t.value)
        },
        true,
      )

      const renderCard = (attrs) => {
        dom.innerHTML = ''
        const link = document.createElement('a')
        link.href = attrs.url
        link.target = '_blank'
        link.rel = 'noopener noreferrer'
        link.className = 'bookmark-link'

        let html = ''
        if (attrs.thumbnail) {
          html += `<img src="${escapeHtml(attrs.thumbnail)}" class="bookmark-thumb" loading="lazy" alt="">`
        }
        html += `<div class="bookmark-info">`
        html += `<div class="bookmark-title">${escapeHtml(attrs.title || attrs.url)}</div>`
        if (attrs.description) {
          html += `<div class="bookmark-desc">${escapeHtml(attrs.description)}</div>`
        }
        html += `<div class="bookmark-url">`
        if (attrs.favicon) {
          html += `<img src="${escapeHtml(attrs.favicon)}" class="bookmark-favicon" alt="">`
        }
        let host = attrs.url
        try {
          host = new URL(attrs.url).hostname
        } catch (_) {}
        html += escapeHtml(host)
        html += `</div></div>`
        link.innerHTML = html
        dom.appendChild(link)

        const del = document.createElement('button')
        del.type = 'button'
        del.className = 'bookmark-delete'
        del.textContent = '×'
        del.title = 'Remove'
        del.addEventListener('click', remove)
        dom.appendChild(del)
      }

      const renderInput = (attrs) => {
        dom.innerHTML = `
          <div class="bookmark-input">
            <input type="url" placeholder="Paste a URL and click Bookmark" value="${escapeHtml(attrs.url || '')}" data-bookmark-input>
            <button type="button" class="btn btn-primary bookmark-confirm" data-bookmark-confirm>Bookmark</button>
            <button type="button" class="bookmark-cancel" title="Remove">×</button>
          </div>
        `
        const input = dom.querySelector('[data-bookmark-input]')
        isolateFromProseMirror(input)
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            dom.querySelector('[data-bookmark-input]')?.focus()
          })
        })
      }

      const render = (attrs) => {
        if (attrs.title || attrs.thumbnail || attrs.description) {
          renderCard(attrs)
        } else {
          renderInput(attrs)
        }
      }

      render(node.attrs)

      return {
        dom,
        // Same rationale as Embed: PM must leave NodeView-internal events
        // alone, otherwise typing into the URL input loses focus and Enter
        // bubbles up to the surrounding form.
        stopEvent: () => true,
        ignoreMutation: () => true,
        update: (updatedNode) => {
          if (updatedNode.type.name !== 'bookmark') return false
          // Re-render only when the visible shape actually changes (input
          // mode ↔ resolved card). Stable identity preserves event
          // listeners on the live <input>.
          if (
            updatedNode.attrs.url === lastAttrs.url &&
            updatedNode.attrs.title === lastAttrs.title &&
            updatedNode.attrs.thumbnail === lastAttrs.thumbnail &&
            updatedNode.attrs.description === lastAttrs.description
          ) {
            lastAttrs = updatedNode.attrs
            return true
          }
          lastAttrs = updatedNode.attrs
          render(updatedNode.attrs)
          return true
        },
      }
    }
  },
})
