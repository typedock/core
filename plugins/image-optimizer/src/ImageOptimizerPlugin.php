<?php
declare(strict_types=1);

namespace TypeDock\Plugin\ImageOptimizer;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class ImageOptimizerPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $jpeg = $this->clamp((int) env('PLUGIN_IMAGEOPT_JPEG_QUALITY', 75), 40, 95);
        $webp = $this->clamp((int) env('PLUGIN_IMAGEOPT_WEBP_QUALITY', 72), 40, 95);
        $edge = $this->clamp((int) env('PLUGIN_IMAGEOPT_MAX_EDGE', 2048), 800, 4096);

        $context->configureImageProcessing($jpeg, $webp, $edge);
    }

    public function getName(): string
    {
        return 'Image Optimizer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return [];
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
