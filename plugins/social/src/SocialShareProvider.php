<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Social;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

final class SocialShareProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $base  = rtrim((string) config('app.url', ''), '/');
        $url   = $base . $context->currentUrl;
        $title = trim((string) ($params['title'] ?? ($context->page['title'] ?? '')));

        $networks = $this->parseNetworks((string) ($params['networks'] ?? 'x,facebook,linkedin,hatena,line,email'));
        $links    = [];
        foreach ($networks as $name) {
            $link = $this->link($name, $url, $title);
            if ($link !== null) {
                $links[] = ['name' => $name, 'label' => $this->label($name), 'url' => $link];
            }
        }

        return [
            'page_url'   => $url,
            'page_title' => $title,
            'links'      => $links,
            'show_copy'  => in_array('copy', $networks, true),
        ];
    }

    /** @return array<int, string> */
    private function parseNetworks(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $name = strtolower(trim($piece));
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    private function link(string $network, string $url, string $title): ?string
    {
        $u = rawurlencode($url);
        $t = rawurlencode($title);
        return match ($network) {
            'x', 'twitter' => "https://twitter.com/intent/tweet?url={$u}&text={$t}",
            'facebook'     => "https://www.facebook.com/sharer/sharer.php?u={$u}",
            'linkedin'     => "https://www.linkedin.com/sharing/share-offsite/?url={$u}",
            'bluesky'      => "https://bsky.app/intent/compose?text={$t}%20{$u}",
            'mastodon'     => "https://mastodon.social/share?text={$t}%20{$u}",
            'hatena'       => "https://b.hatena.ne.jp/entry/panel/?url={$u}&title={$t}",
            'line'         => "https://social-plugins.line.me/lineit/share?url={$u}",
            'email'        => "mailto:?subject={$t}&body={$u}",
            default        => null,
        };
    }

    private function label(string $network): string
    {
        return match ($network) {
            'x', 'twitter' => 'X',
            'facebook'     => 'Facebook',
            'linkedin'     => 'LinkedIn',
            'bluesky'      => 'Bluesky',
            'mastodon'     => 'Mastodon',
            'hatena'       => 'Hatena',
            'line'         => 'LINE',
            'email'        => 'Email',
            default        => ucfirst($network),
        };
    }
}
