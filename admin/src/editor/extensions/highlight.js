import { Mark, mergeAttributes } from '@tiptap/core'

const COLORS = ['yellow', 'red', 'green', 'blue']

export const Highlight = Mark.create({
  name: 'highlight',

  addOptions() {
    return { HTMLAttributes: {} }
  },

  addAttributes() {
    return {
      color: {
        default: 'yellow',
        parseHTML: (el) => el.getAttribute('data-color') || 'yellow',
        renderHTML: (attrs) => ({ 'data-color': attrs.color || 'yellow' }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'mark' }]
  },

  renderHTML({ HTMLAttributes }) {
    const color = COLORS.includes(HTMLAttributes['data-color']) ? HTMLAttributes['data-color'] : 'yellow'
    return [
      'mark',
      mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
        class: `highlight highlight--${color}`,
      }),
      0,
    ]
  },

  addCommands() {
    return {
      setHighlight:
        (attrs) =>
        ({ commands }) =>
          commands.setMark(this.name, attrs),
      toggleHighlight:
        (attrs) =>
        ({ commands }) =>
          commands.toggleMark(this.name, attrs),
      unsetHighlight:
        () =>
        ({ commands }) =>
          commands.unsetMark(this.name),
    }
  },

  addKeyboardShortcuts() {
    return {
      'Mod-Shift-h': () => this.editor.commands.toggleHighlight(),
    }
  },
})
