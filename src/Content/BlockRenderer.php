<?php
declare(strict_types=1);

namespace TypeDock\Content;

use TypeDock\Component\ComponentRenderer;
use TypeDock\Component\RenderContext;

/**
 * Thin facade kept so existing callers (FrontendController) don't need to
 * change. Internally this is just TiptapRenderer. Pages now save Tiptap /
 * ProseMirror JSON; any non-JSON body (from a pre-25 migration, if any)
 * renders as empty so we never accidentally expose legacy Markdown HTML.
 */
class BlockRenderer
{
    public function __construct(
        private readonly ComponentRenderer $componentRenderer
    ) {}

    /**
     * Render page body JSON to HTML.
     *
     * @param string|array<string, mixed>|null $body
     */
    public function render(string|array|null $body, ?RenderContext $context = null): string
    {
        $renderer = new TiptapRenderer($this->componentRenderer, $context);
        return $renderer->render($body);
    }
}
