<?php
declare(strict_types=1);

namespace TypeDock\Theme;

use Latte\Extension;

class CmsLatteExtension extends Extension
{
    /**
     * @return array<string, callable>
     */
    public function getFunctions(): array
    {
        return [
            'component' => [$this, 'renderComponent'],
            'slot'      => [$this, 'renderSlot'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return [];
    }

    /**
     * Render a component by type.
     *
     * @param array<string, mixed> $params
     */
    public function renderComponent(string $type, array $params = []): string
    {
        try {
            return \Flight::component_renderer()->render($type, $params);
        } catch (\Throwable $e) {
            if ((bool) config('app.debug', false)) {
                return '<!-- component error [' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ']: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
            }
            return '';
        }
    }

    /**
     * Render a configurable slot (pulls components from slot_placements table).
     */
    public function renderSlot(string $slotName): string
    {
        try {
            $pdo  = \Flight::db();
            $stmt = $pdo->prepare(
                'SELECT component_type, params FROM slot_placements
                 WHERE slot_name = ?
                 ORDER BY sort_order ASC'
            );
            $stmt->execute([$slotName]);
            $placements = $stmt->fetchAll();

            $html = '';
            foreach ($placements as $placement) {
                $type   = (string) $placement['component_type'];
                $params = !empty($placement['params'])
                    ? (array) json_decode((string) $placement['params'], true)
                    : [];
                $html .= $this->renderComponent($type, $params);
            }

            return $html;
        } catch (\Throwable $e) {
            if ((bool) config('app.debug', false)) {
                return '<!-- slot error [' . htmlspecialchars($slotName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ']: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
            }
            return '';
        }
    }
}
