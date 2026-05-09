<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SimpleAiWriting;

use TypeDock\Core\PluginContext;

final class SimpleAiWritingController
{
    private const MAX_SELECTION = 5000;
    private const MAX_DOCUMENT = 12000;
    private const MAX_DRAFT_BRIEF = 3000;
    private const MAX_DRAFT_MARKDOWN = 30000;

    public function __construct(private readonly PluginContext $context) {}

    public function edit(): void
    {
        $settings = SimpleAiSettings::load($this->context);
        $this->context->view('templates/admin/settings.latte', [
            'settings'   => $settings,
            'configured' => SimpleAiSettings::configured($settings),
            'flash'      => $this->context->getFlash('success'),
            'error'      => $this->context->getFlash('error'),
        ]);
    }

    public function update(): void
    {
        try {
            SimpleAiSettings::save($this->context, $_POST);
            $this->context->redirect('', 'Simple AI Writing settings saved.');
        } catch (\Throwable $e) {
            $this->context->redirect('', $e->getMessage(), 'error');
        }
    }

    public function rewriteSelection(): void
    {
        $this->requireEditorPermission();
        $input = $this->jsonInput();
        $settings = SimpleAiSettings::load($this->context);
        if (!SimpleAiSettings::configured($settings)) {
            $this->json(['ok' => false, 'code' => 'not_configured', 'message' => 'Simple AI Writing is not configured.'], 422);
            return;
        }

        $selectedText = $this->limitText((string) ($input['selected_text'] ?? ''), self::MAX_SELECTION);
        if (trim($selectedText) === '') {
            $this->json(['ok' => false, 'message' => 'Select text before running AI writing.'], 422);
            return;
        }

        try {
            $client = new OpenAiCompatibleClient($this->context, $settings);
            $result = $client->complete(PromptTemplates::rewrite(
                (string) ($input['action'] ?? 'improve'),
                $selectedText,
                $this->sanitizeText((string) ($input['article_title'] ?? ''), 300),
                $this->sanitizeText((string) ($input['tone'] ?? ''), 80),
            ));
            $this->json([
                'ok' => true,
                'text' => $this->sanitizeText($result, self::MAX_SELECTION),
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => 'AI provider request failed. Check the plugin settings and try again.'], 502);
        }
    }

    public function suggestSeoFields(): void
    {
        $this->requireEditorPermission();
        $input = $this->jsonInput();
        $settings = SimpleAiSettings::load($this->context);
        if (!SimpleAiSettings::configured($settings)) {
            $this->json(['ok' => false, 'code' => 'not_configured', 'message' => 'Simple AI Writing is not configured.'], 422);
            return;
        }

        $fields = [
            'post_title'       => $this->sanitizeText((string) ($input['post_title'] ?? ''), 300),
            'seo_title'        => $this->sanitizeText((string) ($input['seo_title'] ?? ''), 300),
            'meta_description' => $this->sanitizeText((string) ($input['meta_description'] ?? ''), 500),
            'excerpt'          => $this->sanitizeText((string) ($input['excerpt'] ?? ''), 800),
            'document_text'    => $this->limitText((string) ($input['document_text'] ?? ''), self::MAX_DOCUMENT),
        ];

        if (trim($fields['document_text']) === '' && trim($fields['post_title']) === '') {
            $this->json(['ok' => false, 'message' => 'Add a title or body text before asking for SEO suggestions.'], 422);
            return;
        }

        try {
            $client = new OpenAiCompatibleClient($this->context, $settings);
            $raw = $client->complete(PromptTemplates::seo($fields));
            $suggestions = $this->parseSeoJson($raw);
            $this->json(['ok' => true, 'fields' => $suggestions]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => 'AI provider request failed. Check the plugin settings and try again.'], 502);
        }
    }

    public function draftArticle(): void
    {
        $this->requireEditorPermission();
        $input = $this->jsonInput();
        $settings = SimpleAiSettings::load($this->context);
        if (!SimpleAiSettings::configured($settings)) {
            $this->json(['ok' => false, 'code' => 'not_configured', 'message' => 'Simple AI Writing is not configured.'], 422);
            return;
        }

        $fields = [
            'brief' => $this->limitText((string) ($input['brief'] ?? ''), self::MAX_DRAFT_BRIEF),
            'post_title' => $this->sanitizeText((string) ($input['post_title'] ?? ''), 300),
            'document_text' => $this->limitText((string) ($input['document_text'] ?? ''), self::MAX_DOCUMENT),
        ];

        if (trim($fields['brief']) === '' && trim($fields['post_title']) === '') {
            $this->json(['ok' => false, 'message' => 'Add a title or draft brief before asking for an article draft.'], 422);
            return;
        }

        try {
            $client = new OpenAiCompatibleClient($this->context, $settings);
            $markdown = $client->complete(PromptTemplates::draft($fields));
            $this->json([
                'ok' => true,
                'markdown' => $this->sanitizeMarkdown($markdown, self::MAX_DRAFT_MARKDOWN),
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => 'AI provider request failed. Check the plugin settings and try again.'], 502);
        }
    }

    private function requireEditorPermission(): void
    {
        $user = $this->context->getCurrentUser();
        if (!is_array($user)) {
            $this->json(['ok' => false, 'message' => 'Not authenticated.'], 401);
            exit;
        }

        $permissions = \Flight::permissions();
        $allowed = $permissions->can($user, 'posts:edit_own')
            || $permissions->can($user, 'posts:edit_any')
            || $permissions->can($user, 'pages:edit_own')
            || $permissions->can($user, 'pages:edit_any')
            || $permissions->can($user, 'posts:create')
            || $permissions->can($user, 'pages:create');

        if (!$allowed) {
            $this->json(['ok' => false, 'message' => 'Insufficient permissions.'], 403);
            exit;
        }
    }

    /** @return array<string, mixed> */
    private function jsonInput(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{title: string, meta_description: string, excerpt: string} */
    private function parseSeoJson(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AI provider did not return valid SEO JSON.');
        }

        return [
            'title' => $this->sanitizeText((string) ($decoded['title'] ?? $decoded['seo_title'] ?? ''), 500),
            'meta_description' => $this->sanitizeText((string) ($decoded['meta_description'] ?? ''), 300),
            'excerpt' => $this->sanitizeText((string) ($decoded['excerpt'] ?? ''), 800),
        ];
    }

    private function limitText(string $text, int $maxBytes): string
    {
        $text = $this->sanitizeText($text, $maxBytes);
        return strlen($text) > $maxBytes ? substr($text, 0, $maxBytes) : $text;
    }

    private function sanitizeText(string $text, int $maxBytes): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = trim($text);
        return strlen($text) > $maxBytes ? substr($text, 0, $maxBytes) : $text;
    }

    private function sanitizeMarkdown(string $markdown, int $maxBytes): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = preg_replace('/^```(?:markdown|md)?\s*|\s*```$/i', '', trim($markdown)) ?? $markdown;
        $markdown = strip_tags($markdown);
        $markdown = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $markdown) ?? '';
        $markdown = trim($markdown);
        return strlen($markdown) > $maxBytes ? substr($markdown, 0, $maxBytes) : $markdown;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
