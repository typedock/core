<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Social;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

final class SocialFollowProvider implements DataProvider
{
    private static ?\PDO $pdo = null;

    private const KEYS = [
        'x', 'twitter', 'facebook', 'instagram', 'youtube', 'linkedin',
        'bluesky', 'mastodon', 'github', 'tiktok', 'rss',
    ];

    public static function usePdo(\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public function resolve(array $params, RenderContext $context): array
    {
        $accounts = $this->readAccounts();

        foreach (self::KEYS as $key) {
            if (!empty($params[$key . '_url'])) {
                $accounts[$key] = (string) $params[$key . '_url'];
            }
        }

        if (!empty($accounts['twitter']) && empty($accounts['x'])) {
            $accounts['x'] = $accounts['twitter'];
        }
        unset($accounts['twitter']);

        $links = [];
        foreach (self::KEYS as $key) {
            if ($key === 'twitter') {
                continue;
            }
            if (!empty($accounts[$key])) {
                $links[] = [
                    'name'  => $key,
                    'label' => $this->label($key),
                    'url'   => (string) $accounts[$key],
                ];
            }
        }

        return ['links' => $links];
    }

    /** @return array<string, string> */
    private function readAccounts(): array
    {
        if (self::$pdo === null) {
            return [];
        }

        try {
            $stmt = self::$pdo->prepare(
                "SELECT key_name, value FROM site_options WHERE group_name = 'social'"
            );
            $stmt->execute();
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $key = (string) $row['key_name'];
                $val = json_decode((string) $row['value'], true);
                if (is_string($val) && $val !== '') {
                    $out[$this->strip($key)] = $val;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function strip(string $key): string
    {
        return str_starts_with($key, 'social.') ? substr($key, 7) : $key;
    }

    private function label(string $name): string
    {
        return match ($name) {
            'x'        => 'X',
            'rss'      => 'RSS',
            'github'   => 'GitHub',
            'linkedin' => 'LinkedIn',
            'tiktok'   => 'TikTok',
            default    => ucfirst($name),
        };
    }
}
