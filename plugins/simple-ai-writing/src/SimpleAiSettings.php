<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SimpleAiWriting;

use TypeDock\Core\PluginContext;
use TypeDock\ExternalSource\SourceCredentialCipher;

final class SimpleAiSettings
{
    private const OPTION = 'simple_ai_writing.settings';
    private const CREDENTIALS = 'simple_ai_writing.credentials';

    /** @return array<string, mixed> */
    public static function load(PluginContext $context): array
    {
        $settings = $context->getSiteOption(self::OPTION);
        $settings = is_array($settings) ? $settings : [];
        $credentials = self::decryptCredentials((string) ($context->getSiteOption(self::CREDENTIALS) ?? ''));

        return array_merge(self::defaults(), $settings, $credentials);
    }

    /** @param array<string, mixed> $input */
    public static function save(PluginContext $context, array $input): void
    {
        $current = self::load($context);
        $settings = [
            'endpoint'    => trim((string) ($input['endpoint'] ?? '')),
            'model'       => trim((string) ($input['model'] ?? '')),
            'temperature' => self::clampFloat((float) ($input['temperature'] ?? 0.4), 0.0, 2.0),
        ];

        $apiKey = trim((string) ($input['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = (string) ($current['api_key'] ?? '');
        }

        $context->setSiteOption(self::OPTION, $settings, 'plugin:simple-ai-writing');
        $context->setSiteOption(self::CREDENTIALS, self::encryptCredentials(['api_key' => $apiKey]), 'plugin:simple-ai-writing');
    }

    /** @param array<string, mixed> $settings */
    public static function configured(array $settings): bool
    {
        return trim((string) ($settings['endpoint'] ?? '')) !== ''
            && trim((string) ($settings['model'] ?? '')) !== ''
            && trim((string) ($settings['api_key'] ?? '')) !== '';
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'endpoint'    => 'https://api.openai.com/v1/chat/completions',
            'model'       => 'gpt-4o-mini',
            'temperature' => 0.4,
            'api_key'     => '',
        ];
    }

    private static function clampFloat(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /** @param array<string, string> $credentials */
    private static function encryptCredentials(array $credentials): string
    {
        try {
            return (new SourceCredentialCipher())->encrypt($credentials);
        } catch (\Throwable $e) {
            throw new \RuntimeException('APP_KEY must be configured before Simple AI Writing credentials can be saved.', 0, $e);
        }
    }

    /** @return array<string, string> */
    private static function decryptCredentials(string $payload): array
    {
        if ($payload === '') {
            return [];
        }
        try {
            return (new SourceCredentialCipher())->decrypt($payload);
        } catch (\Throwable) {
            return [];
        }
    }
}
