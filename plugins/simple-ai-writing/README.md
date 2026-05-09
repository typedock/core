# Simple AI Writing

Simple AI Writing is a reference TypeDock plugin for lightweight AI-assisted editing.

It intentionally keeps AI provider configuration outside Core. The plugin connects to an OpenAI-compatible chat completions endpoint and adds:

- Selection rewrite actions in the editor toolbar.
- SEO title, meta description, and excerpt suggestions in the editor's AI Assist tray.
- Article draft generation from a prompt or brief.
- Markdown subset import through the editor public API.

The plugin sends only the selected text or current editor field context required for the requested action. It does not use site-wide analytics, Search Console data, or other content relationships; those belong to future TypeDock Cloud workflows.

## Editor integration

The plugin registers `assets/editor-extension.js` through `PluginContext::registerEditorScript()`. The script uses `window.TypeDockEditorApi` for:

- `registerInlineAction()` selection actions.
- `registerPanelAction()` draft and metadata buttons.
- `showPreviewDialog()` before applying generated text.
- `setAssistantStatus()` and `proposeSeoFields()` for the AI Assist tray.
- `setDocumentMarkdown()` for draft insertion.

Core provides the editor surface only. Provider endpoint, API key, model, prompts, request limits, and response sanitation stay inside this plugin.
