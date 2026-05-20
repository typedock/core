import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { FloatingToolbar } from '../../admin/src/editor/ui/floating-toolbar.js'

function createEditorStub() {
  const handlers = {}
  return {
    isFocused: true,
    state: { selection: { from: 1, to: 5, empty: false } },
    view: {
      coordsAtPos: () => ({ left: 100, top: 100 }),
    },
    on: vi.fn((event, handler) => {
      handlers[event] = handler
    }),
    isActive: vi.fn(() => false),
    chain: vi.fn(() => ({
      focus: () => ({
        toggleBold: () => ({ run: vi.fn() }),
        toggleItalic: () => ({ run: vi.fn() }),
        toggleStrike: () => ({ run: vi.fn() }),
        toggleCode: () => ({ run: vi.fn() }),
        toggleHighlight: () => ({ run: vi.fn() }),
        setParagraph: () => ({ run: vi.fn() }),
        toggleHeading: () => ({ run: vi.fn() }),
      }),
    })),
    getAttributes: vi.fn(() => ({})),
    __handlers: handlers,
  }
}

describe('FloatingToolbar', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    delete window.TypeDockEditorApi
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps toolbar buttons from stealing the editor selection', () => {
    FloatingToolbar.init(createEditorStub())

    const button = document.querySelector('.floating-toolbar button[data-cmd="bold"]')
    const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    button.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
  })

  it('allows the block type select to open normally', () => {
    FloatingToolbar.init(createEditorStub())

    const select = document.querySelector('.floating-toolbar select[data-cmd="heading"]')
    const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    select.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
  })

  it('keeps the toolbar open while the block type select is being used', () => {
    vi.useFakeTimers()
    const editor = createEditorStub()
    FloatingToolbar.init(editor)

    const toolbar = document.querySelector('.floating-toolbar')
    const select = toolbar.querySelector('select[data-cmd="heading"]')
    editor.__handlers.selectionUpdate()
    expect(toolbar.hidden).toBe(false)

    select.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }))
    editor.isFocused = false
    editor.__handlers.transaction()
    editor.__handlers.blur()
    vi.advanceTimersByTime(100)

    expect(toolbar.hidden).toBe(false)
  })
})
