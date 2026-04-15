<?php
declare(strict_types=1);

namespace TypeDock\Seo;

class RssController
{
    public function index(): void
    {
        $generator = new RssGenerator(\Flight::db());
        $xml       = $generator->generate(20);

        header('Content-Type: application/rss+xml; charset=UTF-8');
        header('Cache-Control: public, max-age=1800');
        echo $xml;
    }
}
