<?php
declare(strict_types=1);

namespace TypeDock\Seo;

class SitemapController
{
    public function index(): void
    {
        $generator = new SitemapGenerator(\Flight::db());
        $type      = $_GET['type'] ?? 'index';

        $xml = match ($type) {
            'pages'      => $generator->generatePages('page'),
            'posts'      => $generator->generatePosts(),
            'categories' => $generator->generateCategories(),
            default      => $generator->generateIndex(),
        };

        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        echo $xml;
    }
}
