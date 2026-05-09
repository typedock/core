import './styles/editor.css'

import { Editor } from '@tiptap/core'
import { StarterKit } from '@tiptap/starter-kit'
import { Link } from '@tiptap/extension-link'
import { Placeholder } from '@tiptap/extension-placeholder'
import { CharacterCount } from '@tiptap/extension-character-count'
import { Typography } from '@tiptap/extension-typography'

import { Highlight } from './extensions/highlight.js'
import { CustomImage } from './extensions/custom-image.js'
import { Bookmark } from './extensions/bookmark.js'
import { Embed } from './extensions/embed.js'
import { ComponentBlock } from './extensions/component-block.js'

import { FloatingToolbar } from './ui/floating-toolbar.js'
import { SlashMenu } from './ui/slash-menu.js'
import { uploadImage } from './ui/image-upload.js'
import { EditorPublicApi } from './ui/public-api.js'

const EMPTY_DOC = { type: 'doc', content: [{ type: 'paragraph' }] }

function parseInitialContent(raw) {
  if (!raw) return EMPTY_DOC
  try {
    const doc = JSON.parse(raw)
    if (doc && typeof doc === 'object' && doc.type === 'doc') return doc
  } catch (_) {
    // fall through
  }
  return EMPTY_DOC
}

export function createEditor(element, { content, onUpdate }) {
  const editor = new Editor({
    element,
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3, 4] },
        codeBlock: { HTMLAttributes: { class: 'editor-code-block' } },
        // StarterKit 3 ships Link by default; we want our own configured
        // copy below (openOnClick: false, restricted protocols).
        link: false,
      }),
      Link.configure({
        openOnClick: false,
        HTMLAttributes: { rel: 'noopener noreferrer' },
        protocols: ['http', 'https', 'mailto'],
      }),
      Placeholder.configure({
        placeholder: 'Write something… (press / for blocks)',
      }),
      CharacterCount,
      Typography,
      Highlight,
      CustomImage,
      Bookmark,
      Embed,
      ComponentBlock,
    ],
    content: content || EMPTY_DOC,
    onUpdate: ({ editor }) => {
      if (onUpdate) onUpdate(editor.getJSON())
    },
    editorProps: {
      attributes: { class: 'typedock-editor' },
      handleDrop: (view, event, _slice, moved) => {
        if (moved) return false
        const files = event.dataTransfer?.files
        if (!files?.length) return false
        const imageFile = Array.from(files).find((f) => f.type.startsWith('image/'))
        if (!imageFile) return false
        event.preventDefault()
        uploadImage(imageFile)
          .then((attrs) => editor.chain().focus().setImage(attrs).run())
          .catch((err) => console.error('Image upload failed', err))
        return true
      },
      handlePaste: (view, event) => {
        const files = event.clipboardData?.files
        if (files?.length) {
          const imageFile = Array.from(files).find((f) => f.type.startsWith('image/'))
          if (imageFile) {
            event.preventDefault()
            uploadImage(imageFile)
              .then((attrs) => editor.chain().focus().setImage(attrs).run())
              .catch((err) => console.error('Image upload failed', err))
            return true
          }
        }
        return false
      },
    },
  })

  FloatingToolbar.init(editor)
  SlashMenu.init(editor)
  return editor
}

function mount() {
  const el = document.getElementById('editor')
  if (!el) return
  const bodyInput = document.getElementById('body-field')
  const initial = parseInitialContent(bodyInput?.value)

  const editor = createEditor(el, {
    content: initial,
    onUpdate: (json) => {
      if (bodyInput) bodyInput.value = JSON.stringify(json)
    },
  })

  EditorPublicApi.attach(editor, bodyInput)

  // Sync once on mount so a brand-new doc is also persisted on save.
  if (bodyInput) bodyInput.value = JSON.stringify(editor.getJSON())

  // Final sync on submit.
  const form = el.closest('form')
  if (form) {
    form.addEventListener('submit', () => {
      if (bodyInput) bodyInput.value = JSON.stringify(editor.getJSON())
    })
  }

  // Expose for debugging / extensions.
  window.typedockEditor = editor
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount)
} else {
  mount()
}
