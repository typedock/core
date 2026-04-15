<?php
declare(strict_types=1);

namespace TypeDock\Content\MarkdownExtension;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use League\CommonMark\Node\Inline\AbstractInline;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

class CardLinkNode extends AbstractInline
{
    public function __construct(public string $path) {}
}

class CardLinkParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('[card:');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $state  = $cursor->saveState();
        $cursor->advanceBy(6); // skip [card:

        $path = '';
        while (!$cursor->isAtEnd()) {
            $char = $cursor->getCurrentCharacter();
            if ($char === ']') {
                $cursor->advanceBy(1);
                $inlineContext->getContainer()->appendChild(new CardLinkNode($path));
                return true;
            }
            if ($char === "\n" || $char === "\r") {
                break;
            }
            $path .= $char;
            $cursor->advanceBy(1);
        }

        $cursor->restoreState($state);
        return false;
    }
}

class CardLinkRenderer implements NodeRendererInterface
{
    public function render(
        \League\CommonMark\Node\Node $node,
        ChildNodeRendererInterface $childRenderer
    ): \Stringable|string|null {
        assert($node instanceof CardLinkNode);
        $path = htmlspecialchars($node->path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Try to fetch page data for card
        try {
            $pdo  = \Flight::db();
            $stmt = $pdo->prepare('SELECT title, excerpt, slug FROM pages WHERE slug = ? AND status = ? LIMIT 1');
            $stmt->execute([ltrim($node->path, '/'), 'published']);
            $page = $stmt->fetch();
        } catch (\Throwable) {
            $page = null;
        }

        if ($page !== false && $page !== null) {
            $title       = htmlspecialchars((string) $page['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $excerptText = htmlspecialchars((string) ($page['excerpt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $url         = htmlspecialchars(config('app.url', '') . '/' . $page['slug'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $excerptHtml = $excerptText !== ''
                ? '<span class="card-link__excerpt">' . $excerptText . '</span>'
                : '';

            return <<<HTML
            <div class="card-link">
                <a href="{$url}" class="card-link__inner">
                    <span class="card-link__title">{$title}</span>
                    {$excerptHtml}
                </a>
            </div>
            HTML;
        }

        return '<a href="' . $path . '" class="card-link">' . $path . '</a>';
    }
}

class CardLinkExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new CardLinkParser(), 60);
        $environment->addRenderer(CardLinkNode::class, new CardLinkRenderer());
    }
}
