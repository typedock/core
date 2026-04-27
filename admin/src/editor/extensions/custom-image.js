import { Image } from '@tiptap/extension-image'
import { uploadImage } from '../ui/image-upload.js'

export const CustomImage = Image.extend({
  name: 'image',
  draggable: true,

  addAttributes() {
    return {
      ...this.parent?.(),
      width: {
        default: null,
        parseHTML: (el) => {
          const w = el.getAttribute('width') || el.style.width
          if (!w) return null
          const n = parseInt(String(w), 10)
          return Number.isFinite(n) ? n : null
        },
        renderHTML: (attrs) => (attrs.width ? { width: attrs.width } : {}),
      },
      align: {
        default: 'center',
        parseHTML: (el) => el.getAttribute('data-align') || 'center',
        renderHTML: (attrs) => ({ 'data-align': attrs.align || 'center' }),
      },
      caption: {
        default: '',
        parseHTML: (el) => {
          const fig = el.closest('figure')
          return fig?.querySelector('figcaption')?.textContent || ''
        },
        renderHTML: () => ({}),
      },
      mediaId: {
        default: null,
        parseHTML: (el) => el.getAttribute('data-media-id'),
        renderHTML: (attrs) => (attrs.mediaId ? { 'data-media-id': attrs.mediaId } : {}),
      },
    }
  },

  addNodeView() {
    return ({ node, editor, getPos }) => {
      const figure = document.createElement('figure')
      figure.className = `editor-image editor-image--${node.attrs.align || 'center'}`
      figure.contentEditable = 'false'

      const alignBar = document.createElement('div')
      alignBar.className = 'image-align-bar'
      const labels = { left: '◀', center: '◆', right: '▶' }
      const titles = { left: 'Align left', center: 'Center', right: 'Align right' }
      ;['left', 'center', 'right'].forEach((a) => {
        const btn = document.createElement('button')
        btn.type = 'button'
        btn.dataset.align = a
        btn.textContent = labels[a]
        btn.title = titles[a]
        if (a === node.attrs.align) btn.classList.add('is-active')
        btn.addEventListener('click', (e) => {
          e.preventDefault()
          if (typeof getPos === 'function') {
            editor.chain().focus().updateAttributes('image', { align: a }).run()
          }
        })
        alignBar.appendChild(btn)
      })

      const wrapper = document.createElement('div')
      wrapper.className = 'image-wrapper'

      const img = document.createElement('img')
      img.src = node.attrs.src
      img.alt = node.attrs.alt || ''
      if (node.attrs.width) img.style.width = `${node.attrs.width}px`
      img.draggable = false
      wrapper.appendChild(img)

      const handle = document.createElement('div')
      handle.className = 'image-resize-handle'
      handle.title = 'Drag to resize'
      wrapper.appendChild(handle)

      attachResize(handle, img, (newWidth) => {
        if (typeof getPos === 'function') {
          editor.chain().focus().updateAttributes('image', { width: newWidth }).run()
        }
      })

      const caption = document.createElement('figcaption')
      caption.className = 'image-caption'
      caption.contentEditable = 'true'
      caption.dataset.placeholder = 'Add caption…'
      caption.textContent = node.attrs.caption || ''
      caption.addEventListener('blur', () => {
        if (typeof getPos === 'function') {
          editor.chain().updateAttributes('image', { caption: caption.textContent.trim() }).run()
        }
      })
      caption.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault()
          caption.blur()
          editor.commands.focus()
        }
      })

      figure.append(alignBar, wrapper, caption)

      return {
        dom: figure,
        update: (updatedNode) => {
          if (updatedNode.type.name !== 'image') return false
          if (img.getAttribute('src') !== updatedNode.attrs.src) {
            img.src = updatedNode.attrs.src
          }
          const nextAlt = updatedNode.attrs.alt || ''
          if (img.alt !== nextAlt) img.alt = nextAlt
          const nextWidth = updatedNode.attrs.width ? `${updatedNode.attrs.width}px` : ''
          if (img.style.width !== nextWidth) img.style.width = nextWidth
          figure.className = `editor-image editor-image--${updatedNode.attrs.align || 'center'}`
          alignBar.querySelectorAll('button').forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.align === updatedNode.attrs.align)
          })
          if (caption.textContent !== (updatedNode.attrs.caption || '')) {
            caption.textContent = updatedNode.attrs.caption || ''
          }
          return true
        },
        ignoreMutation: (mutation) => {
          // Allow contenteditable caption to mutate without reinit.
          if (mutation.type === 'selection') return true
          if (mutation.target === caption || caption.contains(mutation.target)) return true
          return false
        },
      }
    }
  },
})

function attachResize(handle, img, onCommit) {
  let startX = 0
  let startW = 0
  let activeW = 0
  const MIN = 100
  const MAX = 1600

  const onMove = (ev) => {
    const dx = ev.clientX - startX
    activeW = Math.min(MAX, Math.max(MIN, startW + dx))
    img.style.width = `${activeW}px`
  }

  const onUp = () => {
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
    if (activeW && activeW !== startW) onCommit(Math.round(activeW))
  }

  handle.addEventListener('mousedown', (ev) => {
    ev.preventDefault()
    ev.stopPropagation()
    startX = ev.clientX
    startW = img.getBoundingClientRect().width
    activeW = startW
    document.addEventListener('mousemove', onMove)
    document.addEventListener('mouseup', onUp)
  })
}

export { uploadImage }
