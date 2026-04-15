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
use League\CommonMark\Xml\XmlNodeRendererInterface;

class MarkNode extends AbstractInline
{
    public function __construct(public string $content) {}
}

class MarkParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('==');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $state  = $cursor->saveState();
        $cursor->advanceBy(2); // skip ==

        $text = '';
        while (!$cursor->isAtEnd()) {
            $char = $cursor->getCurrentCharacter();
            if ($char === '=' && $cursor->peek(1) === '=') {
                $cursor->advanceBy(2);
                $inlineContext->getContainer()->appendChild(new MarkNode($text));
                return true;
            }
            $text .= $char;
            $cursor->advanceBy(1);
        }

        $cursor->restoreState($state);
        return false;
    }
}

class MarkRenderer implements NodeRendererInterface
{
    public function render(
        \League\CommonMark\Node\Node $node,
        ChildNodeRendererInterface $childRenderer
    ): \Stringable|string|null {
        assert($node instanceof MarkNode);
        return '<mark>' . htmlspecialchars($node->content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</mark>';
    }
}

class MarkExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new MarkParser(), 50);
        $environment->addRenderer(MarkNode::class, new MarkRenderer());
    }
}
