<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class SearchFormProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        return [
            'action'      => '/search',
            'query'       => htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'placeholder' => $params['placeholder'] ?? 'Search...',
        ];
    }
}
