<?php
declare(strict_types=1);

namespace TypeDock\Seo;

/**
 * One handler per sitemap, because the index links to them by path
 * (`/sitemap-posts.xml`) and a crawler will not invent a query string.
 */
class SitemapController
{
    public function index(): void
    {
        $this->emit($this->generator()->generateIndex());
    }

    public function pages(): void
    {
        $this->emit($this->generator()->generatePages());
    }

    public function posts(): void
    {
        $this->emit($this->generator()->generatePosts());
    }

    public function categories(): void
    {
        $this->emit($this->generator()->generateCategories());
    }

    private function generator(): SitemapGenerator
    {
        return new SitemapGenerator(\Flight::db());
    }

    private function emit(string $xml): void
    {
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        echo $xml;
    }
}
