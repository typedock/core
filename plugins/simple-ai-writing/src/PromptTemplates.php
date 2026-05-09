<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SimpleAiWriting;

final class PromptTemplates
{
    /**
     * @return array<int, array{role: string, content: string}>
     */
    public static function rewrite(string $action, string $selectedText, string $articleTitle = '', string $tone = ''): array
    {
        $instruction = match ($action) {
            'shorter' => 'Make the selected text shorter while preserving the important meaning.',
            'clearer' => 'Rewrite the selected text so it is clearer and easier to read.',
            'tone' => 'Rewrite the selected text in the requested tone. Keep facts intact.',
            default => 'Improve the selected text for clarity, flow, and usefulness.',
        };

        $context = $articleTitle !== '' ? "Article title: {$articleTitle}\n" : '';
        if ($tone !== '') {
            $context .= "Requested tone: {$tone}\n";
        }

        return [
            [
                'role' => 'system',
                'content' => 'You are an editorial assistant inside TypeDock CMS. Return plain text only. Do not return HTML, Markdown fences, explanations, or alternatives.',
            ],
            [
                'role' => 'user',
                'content' => $context . $instruction . "\n\nSelected text:\n" . $selectedText,
            ],
        ];
    }

    /**
     * @param array<string, string> $fields
     * @return array<int, array{role: string, content: string}>
     */
    public static function seo(array $fields): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'You are an SEO editor inside TypeDock CMS. Return only a JSON object with keys title, meta_description, and excerpt. Do not include Markdown fences.',
            ],
            [
                'role' => 'user',
                'content' =>
                    "Current post title: " . ($fields['post_title'] ?? '') . "\n" .
                    "Current SEO title: " . ($fields['seo_title'] ?? '') . "\n" .
                    "Current meta description: " . ($fields['meta_description'] ?? '') . "\n" .
                    "Current excerpt: " . ($fields['excerpt'] ?? '') . "\n\n" .
                    "Document text:\n" . ($fields['document_text'] ?? '') . "\n\n" .
                    "Create a concise SEO title, a meta description around 120 to 160 characters, and a short archive excerpt.",
            ],
        ];
    }

    /**
     * @param array<string, string> $fields
     * @return array<int, array{role: string, content: string}>
     */
    public static function draft(array $fields): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'You are an editorial assistant inside TypeDock CMS. Return only article body Markdown. Use this subset only: ##/###/#### headings, paragraphs, bullet or numbered lists, blockquotes, horizontal rules, and fenced code blocks. Do not include YAML front matter, HTML, images, links, tables, Markdown fences around the whole answer, or explanations.',
            ],
            [
                'role' => 'user',
                'content' =>
                    "Article title: " . ($fields['post_title'] ?? '') . "\n" .
                    "Draft brief:\n" . ($fields['brief'] ?? '') . "\n\n" .
                    "Current body text, if any:\n" . ($fields['document_text'] ?? '') . "\n\n" .
                    "Write a complete, structured article draft. Start with the body content, not the title.",
            ],
        ];
    }
}
