<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class OembedController
{
    public function resolve(): void
    {
        $url = trim($_GET['url'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid URL']);
            return;
        }

        // Simple oEmbed resolution: try YouTube, Twitter, etc.
        $oembedUrl = $this->getOembedEndpoint($url);
        if ($oembedUrl === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No oEmbed provider found']);
            return;
        }

        $response = @file_get_contents($oembedUrl);
        if ($response === false) {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'oEmbed request failed']);
            return;
        }

        header('Content-Type: application/json');
        echo $response;
    }

    private function getOembedEndpoint(string $url): ?string
    {
        $providers = [
            'youtube.com' => 'https://www.youtube.com/oembed?url=%s&format=json',
            'youtu.be'    => 'https://www.youtube.com/oembed?url=%s&format=json',
            'twitter.com' => 'https://publish.twitter.com/oembed?url=%s',
            'x.com'       => 'https://publish.twitter.com/oembed?url=%s',
            'vimeo.com'   => 'https://vimeo.com/api/oembed.json?url=%s',
        ];

        foreach ($providers as $domain => $endpointTemplate) {
            if (str_contains($url, $domain)) {
                return sprintf($endpointTemplate, urlencode($url));
            }
        }

        return null;
    }
}
