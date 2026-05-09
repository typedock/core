<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SimpleAiWriting;

use TypeDock\Core\PluginContext;

final class OpenAiCompatibleClient
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly PluginContext $context,
        private readonly array $settings
    ) {}

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function complete(array $messages): string
    {
        $endpoint = trim((string) ($this->settings['endpoint'] ?? ''));
        $model = trim((string) ($this->settings['model'] ?? ''));
        $apiKey = trim((string) ($this->settings['api_key'] ?? ''));
        if ($endpoint === '' || $model === '' || $apiKey === '') {
            throw new \RuntimeException('Simple AI Writing is not configured.');
        }

        $response = $this->context->http()->post(
            $endpoint,
            [
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => (float) ($this->settings['temperature'] ?? 0.4),
            ],
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept'        => 'application/json',
            ],
            [
                'timeout'  => 20,
                'max_size' => 1024 * 1024,
            ]
        );

        if (!$response->ok()) {
            throw new \RuntimeException('AI provider returned HTTP ' . $response->status . '.');
        }

        $json = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('AI provider returned an empty response.');
        }

        return trim($content);
    }
}
