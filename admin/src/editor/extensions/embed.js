import { Node } from '@tiptap/core'
import { isolateFromProseMirror } from '../ui/dom-isolation.js'

function escapeHtml(str) {
  const d = document.createElement('div')
  d.textContent = String(str ?? '')
  return d.innerHTML
}

export const Embed = Node.create({
  name: 'embed',
  group: 'block',
  atom: true,
  selectable: true,
  draggable: true,

  addAttributes() {
    return {
      url: { default: '' },
      provider: { default: null },
      html: { default: null },
      width: { default: null },
      height: { default: null },
    }
  },

  parseHTML() {
    return [{ tag: 'div[data-embed]' }]
  },

  renderHTML({ node }) {
    return [
      'div',
      {
        'data-embed': '',
        'data-url': node.attrs.url,
        'data-provider': node.attrs.provider || '',
      },
    ]
  },

  addCommands() {
    return {
      setEmbed:
        (url = '') =>
        ({ chain }) =>
          chain()
            .insertContent({ type: 'embed', attrs: { url } })
            .run(),
    }
  },

  addNodeView() {
    return ({ node, editor, getPos }) => {
      const dom = document.createElement('div')
      dom.className = 'embed-block'
      dom.contentEditable = 'false'
      let lastAttrs = node.attrs

      const remove = () => {
        if (typeof getPos !== 'function') return
        const pos = getPos()
        editor
          .chain()
          .focus()
          .deleteRange({ from: pos, to: pos + node.nodeSize })
          .run()
      }

      // Delegated handlers on the NodeView root. Surviving across re-renders
      // matters because dom.innerHTML = '...' inside render() replaces every
      // child node — anything attached to a specific child would be lost.
      // Use capture phase + stopImmediatePropagation so the click never gets
      // a chance to bubble to the surrounding form (which would trip an
      // implicit submit).
      // Capture phase + stopImmediatePropagation so neither ProseMirror's
      // own DOM listeners nor the surrounding <form>'s implicit submit
      // pathway ever sees these events. Earlier we tried plain
      // stopPropagation, but PM has same-element click listeners that the
      // bubble path didn't shield us from, and clicks on the Confirm
      // button were ending up triggering an implicit form submit.
      dom.addEventListener(
        'click',
        (e) => {
          const t = e.target
          if (t.closest('[data-embed-confirm]')) {
            e.preventDefault()
            e.stopImmediatePropagation()
            const live = dom.querySelector('[data-embed-input]')
            if (live) submitUrl(live.value)
          } else if (t.closest('.embed-cancel') || t.closest('.embed-delete')) {
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
          if (!t.matches('[data-embed-input]')) return
          e.preventDefault()
          e.stopImmediatePropagation()
          submitUrl(t.value)
        },
        true,
      )

      const renderEmbed = (attrs) => {
        dom.innerHTML = `
          <div class="embed-wrapper">${attrs.html}</div>
          <button type="button" class="embed-delete" title="Remove">×</button>
        `
        dom.querySelector('.embed-delete').addEventListener('click', remove)
      }

      const submitUrl = async (rawUrl) => {
        const url = (rawUrl || '').trim()
        if (!url) return
        const liveInput = dom.querySelector('input')
        if (liveInput) {
          liveInput.disabled = true
          liveInput.placeholder = 'Fetching embed…'
        }
        try {
          const resp = await fetch(`/admin/api/oembed?url=${encodeURIComponent(url)}`, {
            credentials: 'same-origin',
          })
          const data = resp.ok ? await resp.json() : {}
          if (typeof getPos !== 'function') return
          editor
            .chain()
            .focus()
            .updateAttributes('embed', {
              url,
              provider: data.provider_name?.toLowerCase() || null,
              html: data.html || `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">${escapeHtml(url)}</a>`,
              width: data.width || null,
              height: data.height || null,
            })
            .run()
        } catch (err) {
          console.error('[embed] oEmbed fetch failed', err)
          if (typeof getPos !== 'function') return
          editor
            .chain()
            .focus()
            .updateAttributes('embed', {
              url,
              html: `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">${escapeHtml(url)}</a>`,
            })
            .run()
        }
      }

      const renderInput = (attrs) => {
        dom.innerHTML = `
          <div class="embed-input">
            <input type="url" placeholder="Paste a YouTube / Vimeo / Twitter URL and click Embed" value="${escapeHtml(attrs.url || '')}" data-embed-input>
            <button type="button" class="btn btn-primary embed-confirm" data-embed-confirm>Embed</button>
            <button type="button" class="embed-cancel" title="Remove">×</button>
          </div>
        `
        isolateFromProseMirror(dom.querySelector('[data-embed-input]'))
        // Focus after PM finishes its post-insert selection sync. setTimeout
        // sometimes fires inside that sync; double-RAF lands cleanly after.
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            dom.querySelector('[data-embed-input]')?.focus()
          })
        })
      }

      const render = (attrs) => {
        if (attrs.html) renderEmbed(attrs)
        else renderInput(attrs)
      }

      render(node.attrs)
      return {
        dom,
        // Tell PM to leave events that originate inside this NodeView alone.
        // Without this PM intercepts mousedown/keydown and creates a
        // NodeSelection that surfaces the floating toolbar and mis-routes
        // paste into the editor body.
        stopEvent: () => true,
        ignoreMutation: () => true,
        update: (updatedNode) => {
          if (updatedNode.type.name !== 'embed') return false
          // Skip re-render when the visible shape is unchanged. innerHTML
          // replacement would orphan the live <input> and break the
          // delegated handlers' targeting.
          const same =
            updatedNode.attrs.html === lastAttrs.html &&
            updatedNode.attrs.url === lastAttrs.url &&
            updatedNode.attrs.provider === lastAttrs.provider
          lastAttrs = updatedNode.attrs
          if (same) return true
          render(updatedNode.attrs)
          return true
        },
      }
    }
  },
})
