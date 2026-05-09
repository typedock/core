# Simple AI Writing

Simple AI Writing is a reference TypeDock plugin for lightweight AI-assisted editing.

It intentionally keeps AI provider configuration outside Core. The plugin connects to an OpenAI-compatible chat completions endpoint and adds:

- Selection rewrite actions in the editor toolbar.
- SEO title, meta description, and excerpt suggestions in the SEO panel.

The plugin sends only the selected text or current editor field context required for the requested action. It does not use site-wide analytics, Search Console data, or other content relationships; those belong to future TypeDock Cloud workflows.
