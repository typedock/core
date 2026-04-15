<?php
declare(strict_types=1);

namespace TypeDock\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use TypeDock\Content\MarkdownExtension\MarkExtension;
use TypeDock\Content\MarkdownExtension\CardLinkExtension;

class BlockRenderer
{
    private ?MarkdownConverter $markdown = null;

    public function __construct(
        private readonly \TypeDock\Component\ComponentRenderer $componentRenderer
    ) {}

    /**
     * Render page body JSON to HTML.
     */
    public function render(string|array|null $body): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                // Treat as plain markdown if JSON fails
                return $this->renderMarkdown($body);
            }
            $body = $decoded;
        }

        $blocks = $body['blocks'] ?? $body;
        if (!is_array($blocks)) {
            return '';
        }

        $html = '';
        foreach ($blocks as $block) {
            $html .= $this->renderBlock($block);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderBlock(array $block): string
    {
        $type = $block['type'] ?? 'markdown';

        return match ($type) {
            'markdown'  => $this->renderMarkdownBlock($block),
            'image'     => $this->renderImageBlock($block),
            'gallery'   => $this->renderGalleryBlock($block),
            'embed'     => $this->renderEmbedBlock($block),
            'bookmark'  => $this->renderBookmarkBlock($block),
            'html'      => $this->renderHtmlBlock($block),
            'separator' => '<hr class="block-separator">',
            'component' => $this->renderComponentBlock($block),
            default     => $this->renderFallbackBlock($block),
        };
    }

    private function renderMarkdownBlock(array $block): string
    {
        $content = (string) ($block['content'] ?? '');
        return '<div class="block block-markdown">' . $this->renderMarkdown($content) . '</div>';
    }

    private function renderMarkdown(string $markdown): string
    {
        if ($this->markdown === null) {
            $env = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
            $env->addExtension(new CommonMarkCoreExtension());
            $env->addExtension(new MarkExtension());
            $env->addExtension(new CardLinkExtension());
            $this->markdown = new MarkdownConverter($env);
        }
        return $this->markdown->convert($markdown)->getContent();
    }

    private function renderImageBlock(array $block): string
    {
        $attrs     = $block['attrs'] ?? [];
        $imageId   = $attrs['image_id'] ?? null;
        $alt       = htmlspecialchars((string) ($attrs['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption   = htmlspecialchars((string) ($attrs['caption'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alignment = htmlspecialchars((string) ($attrs['alignment'] ?? 'center'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link      = $attrs['link'] ?? null;

        if ($imageId === null) {
            return '';
        }

        try {
            $pdo  = \Flight::db();
            $stmt = $pdo->prepare('SELECT path, thumbnails, width, height FROM media WHERE id = ? LIMIT 1');
            $stmt->execute([$imageId]);
            $media = $stmt->fetch();
        } catch (\Throwable) {
            $media = null;
        }

        if ($media === false || $media === null) {
            return '';
        }

        $storageUrl = config('filesystems.' . config('filesystems.default', 'local') . '.url', '');
        $src        = htmlspecialchars($storageUrl . '/' . $media['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $img = "<img src=\"{$src}\" alt=\"{$alt}\"";
        if (!empty($media['width'])) {
            $img .= " width=\"{$media['width']}\" height=\"{$media['height']}\"";
        }
        $img .= ' loading="lazy">';

        if ($link !== null) {
            $linkHref = htmlspecialchars((string) $link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $img      = "<a href=\"{$linkHref}\">{$img}</a>";
        }

        $captionHtml = $caption !== '' ? "<figcaption>{$caption}</figcaption>" : '';
        return "<figure class=\"block block-image align-{$alignment}\">{$img}{$captionHtml}</figure>";
    }

    private function renderGalleryBlock(array $block): string
    {
        $attrs   = $block['attrs'] ?? [];
        $images  = $attrs['images'] ?? [];
        $columns = (int) ($attrs['columns'] ?? 3);

        if (empty($images)) {
            return '';
        }

        $html = "<div class=\"block block-gallery columns-{$columns}\">";
        foreach ($images as $item) {
            $imageId = $item['image_id'] ?? null;
            if ($imageId === null) {
                continue;
            }
            $html .= $this->renderImageBlock(['type' => 'image', 'attrs' => ['image_id' => $imageId, 'caption' => $item['caption'] ?? '']]);
        }
        $html .= '</div>';

        return $html;
    }

    private function renderEmbedBlock(array $block): string
    {
        $attrs = $block['attrs'] ?? [];
        $url   = htmlspecialchars((string) ($attrs['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html  = (string) ($attrs['html'] ?? '');

        if ($html !== '') {
            // oEmbed HTML (trusted, but sanitize)
            return '<div class="block block-embed">' . $html . '</div>';
        }

        return '<div class="block block-embed"><a href="' . $url . '">' . $url . '</a></div>';
    }

    private function renderBookmarkBlock(array $block): string
    {
        $attrs       = $block['attrs'] ?? [];
        $url         = htmlspecialchars((string) ($attrs['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title       = htmlspecialchars((string) ($attrs['title'] ?? $url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $description = htmlspecialchars((string) ($attrs['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $thumbnail   = htmlspecialchars((string) ($attrs['thumbnail'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $thumbHtml = $thumbnail !== '' ? "<img src=\"{$thumbnail}\" alt=\"\" class=\"bookmark-thumbnail\" loading=\"lazy\">" : '';
        $descHtml  = $description !== '' ? "<p class=\"bookmark-description\">{$description}</p>" : '';

        return <<<HTML
        <div class="block block-bookmark">
            <a href="{$url}" class="bookmark-inner" target="_blank" rel="noopener noreferrer">
                <div class="bookmark-content">
                    <strong class="bookmark-title">{$title}</strong>
                    {$descHtml}
                    <span class="bookmark-url">{$url}</span>
                </div>
                {$thumbHtml}
            </a>
        </div>
        HTML;
    }

    private function renderHtmlBlock(array $block): string
    {
        // Raw HTML block — only for trusted admin content
        $html = (string) ($block['content'] ?? '');
        return '<div class="block block-html">' . $html . '</div>';
    }

    private function renderComponentBlock(array $block): string
    {
        $attrs  = $block['attrs'] ?? [];
        $type   = (string) ($attrs['component'] ?? '');
        $params = (array) ($attrs['params'] ?? []);

        if ($type === '') {
            return '';
        }

        try {
            return $this->componentRenderer->render($type, $params);
        } catch (\Throwable $e) {
            return '<div class="block block-component-error" data-component="' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></div>';
        }
    }

    private function renderFallbackBlock(array $block): string
    {
        $type    = htmlspecialchars((string) ($block['type'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $content = is_array($block['content'] ?? null)
            ? json_encode($block['content'])
            : htmlspecialchars((string) ($block['content'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<div class=\"block block-unknown block-type-{$type}\" data-block-type=\"{$type}\">{$content}</div>";
    }
}
