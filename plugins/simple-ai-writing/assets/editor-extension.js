(function () {
  let initialized = false

  function ready(api) {
    return api
      && typeof api.registerInlineAction === 'function'
      && typeof api.registerPanelAction === 'function'
  }

  function boot(api) {
    if (initialized || !ready(api)) return
    initialized = true
    register(api)
  }

  boot(window.TypeDockEditorApi)
  document.addEventListener('typedock:editor-ready', (event) => {
    boot(event.detail?.api || window.TypeDockEditorApi)
  })
})()

function register(api) {

  const baseUrl = '/admin/plugins/simple-ai-writing'
  const csrfToken = window.typedockEditorConfig?.csrfToken || ''

  api.registerInlineAction({
    id: 'simple-ai-writing.improve',
    label: 'Improve',
    title: 'Improve selected text with Simple AI Writing',
    run: () => rewriteSelection('improve'),
  })

  api.registerInlineAction({
    id: 'simple-ai-writing.shorter',
    label: 'Shorten',
    title: 'Shorten selected text with Simple AI Writing',
    run: () => rewriteSelection('shorter'),
  })

  api.registerInlineAction({
    id: 'simple-ai-writing.clearer',
    label: 'Clearer',
    title: 'Make selected text clearer with Simple AI Writing',
    run: () => rewriteSelection('clearer'),
  })

  api.registerInlineAction({
    id: 'simple-ai-writing.tone',
    label: 'Tone',
    title: 'Change selected text tone with Simple AI Writing',
    run: () => {
      const tone = window.prompt('Requested tone:', 'Professional')
      if (tone === null || tone.trim() === '') return
      rewriteSelection('tone', tone.trim())
    },
  })

  api.registerPanelAction({
    id: 'simple-ai-writing.seo',
    surface: 'seo',
    label: 'Suggest SEO fields',
    title: 'Suggest SEO title, meta description, and excerpt',
    run: suggestSeoFields,
  })

  api.registerPanelAction({
    id: 'simple-ai-writing.draft',
    surface: 'draft',
    label: 'Draft article',
    title: 'Generate a full article draft from a prompt',
    run: draftArticle,
  })

  async function rewriteSelection(action, tone = '') {
    const selectedText = api.getSelectionText()
    if (!selectedText.trim()) {
      api.showNotice('Select text before running AI writing.', 'warning')
      return
    }

    const range = api.getSelectionRange()
    const fields = api.getSeoFields()
    setStatus('Simple AI Writing is working...', 'loading')

    try {
      const response = await postJson('/rewrite-selection', {
        action,
        tone,
        selected_text: selectedText,
        article_title: fields.post_title || '',
      })
      if (!response.ok) {
        handleError(response)
        return
      }
      clearStatus()
      api.showPreviewDialog({
        title: 'AI rewrite preview',
        original: selectedText,
        suggestion: response.text || '',
        onApply: (text) => api.replaceRange(range, text),
      })
    } catch (error) {
      setStatus('AI request failed. Check Simple AI Writing settings.', 'error')
    }
  }

  async function suggestSeoFields() {
    const fields = api.getSeoFields()
    setStatus('Simple AI Writing is preparing SEO suggestions...', 'loading')

    try {
      const response = await postJson('/suggest-seo-fields', {
        ...fields,
        document_text: api.getDocumentText(),
      })
      if (!response.ok) {
        handleError(response)
        return
      }
      clearStatus()
      api.proposeSeoFields(response.fields || {})
    } catch (error) {
      setStatus('AI request failed. Check Simple AI Writing settings.', 'error')
    }
  }

  async function draftArticle() {
    const fields = api.getSeoFields()
    const brief = window.prompt('Draft brief:', fields.post_title || '')
    if (brief === null || brief.trim() === '') return

    setStatus('Simple AI Writing is drafting the article...', 'loading')

    try {
      const response = await postJson('/draft-article', {
        brief: brief.trim(),
        post_title: fields.post_title || '',
        document_text: api.getDocumentText(),
      })
      if (!response.ok) {
        handleError(response)
        return
      }
      const markdown = response.markdown || ''
      clearStatus()
      api.showPreviewDialog({
        title: 'Article draft preview',
        original: api.getDocumentText(),
        suggestion: markdown,
        onApply: (text) => api.setDocumentMarkdown(text),
      })
    } catch (error) {
      setStatus('AI request failed. Check Simple AI Writing settings.', 'error')
    }
  }

  async function postJson(path, payload) {
    const response = await window.fetch(baseUrl + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      body: JSON.stringify(payload),
    })
    const json = await response.json().catch(() => ({}))
    return { ok: response.ok, status: response.status, ...json }
  }

  function handleError(response) {
    if (response.code === 'not_configured') {
      clearStatus()
      if (window.confirm('Simple AI Writing is not configured. Open settings now?')) {
        window.location.href = baseUrl
      }
      return
    }
    setStatus(response.message || 'Simple AI Writing could not complete the request.', 'error')
  }

  function setStatus(message, type = 'info') {
    if (typeof api.setAssistantStatus === 'function') {
      api.setAssistantStatus(message, type)
      return
    }
    api.showNotice(message, type)
  }

  function clearStatus() {
    if (typeof api.clearAssistantStatus === 'function') {
      api.clearAssistantStatus()
    }
  }
}
