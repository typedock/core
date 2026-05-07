<?php
declare(strict_types=1);

namespace TypeDock\Plugin\CloudStorage;

use TypeDock\Core\PluginContext;
use TypeDock\ExternalSource\SourceCredentialCipher;

final class CloudStorageSettings
{
    private const OPTION = 'cloud_storage.settings';
    private const CREDENTIALS = 'cloud_storage.credentials';

    /** @return array<string, mixed> */
    public static function load(PluginContext $context): array
    {
        $settings = $context->getSiteOption(self::OPTION);
        $settings = is_array($settings) ? $settings : [];
        $credentials = self::decryptCredentials((string) ($context->getSiteOption(self::CREDENTIALS) ?? ''));

        return array_merge(self::defaults(), $settings, $credentials);
    }

    /** @param array<string, mixed> $settings */
    public static function canProvide(array $settings): bool
    {
        return (bool) ($settings['active'] ?? false)
            && class_exists(\AsyncAws\S3\S3Client::class)
            && trim((string) ($settings['bucket'] ?? '')) !== ''
            && trim((string) ($settings['access_key_id'] ?? '')) !== ''
            && trim((string) ($settings['secret_access_key'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $input */
    public static function save(PluginContext $context, array $input): void
    {
        $current = self::load($context);
        $settings = [
            'active'                  => (bool) ($input['active'] ?? false),
            'bucket'                  => trim((string) ($input['bucket'] ?? '')),
            'region'                  => trim((string) ($input['region'] ?? '')) ?: 'us-east-1',
            'endpoint'                => trim((string) ($input['endpoint'] ?? '')),
            'public_url'              => rtrim(trim((string) ($input['public_url'] ?? '')), '/'),
            'key_prefix'              => trim((string) ($input['key_prefix'] ?? ''), '/'),
            'use_path_style_endpoint' => (bool) ($input['use_path_style_endpoint'] ?? false),
            'send_chunked_body'        => (bool) ($input['send_chunked_body'] ?? false),
        ];

        $credentials = [
            'access_key_id'     => trim((string) ($input['access_key_id'] ?? '')),
            'secret_access_key' => trim((string) ($input['secret_access_key'] ?? '')),
        ];
        if ($credentials['access_key_id'] === '') {
            $credentials['access_key_id'] = (string) ($current['access_key_id'] ?? '');
        }
        if ($credentials['secret_access_key'] === '') {
            $credentials['secret_access_key'] = (string) ($current['secret_access_key'] ?? '');
        }

        $context->setSiteOption(self::OPTION, $settings, 'plugin:cloud-storage');
        $context->setSiteOption(self::CREDENTIALS, self::encryptCredentials($credentials), 'plugin:cloud-storage');
    }

    /** @return array<string, mixed> */
    public static function diagnostics(array $settings): array
    {
        return [
            'sdk'          => class_exists(\AsyncAws\S3\S3Client::class),
            'has_bucket'   => trim((string) ($settings['bucket'] ?? '')) !== '',
            'has_key'      => trim((string) ($settings['access_key_id'] ?? '')) !== '',
            'has_secret'   => trim((string) ($settings['secret_access_key'] ?? '')) !== '',
            'will_provide' => self::canProvide($settings),
        ];
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'active'                  => false,
            'bucket'                  => '',
            'region'                  => 'us-east-1',
            'endpoint'                => '',
            'public_url'              => '',
            'key_prefix'              => '',
            'use_path_style_endpoint' => false,
            'send_chunked_body'        => false,
            'access_key_id'           => '',
            'secret_access_key'       => '',
        ];
    }

    /** @param array<string, string> $credentials */
    private static function encryptCredentials(array $credentials): string
    {
        return (new SourceCredentialCipher())->encrypt($credentials);
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
