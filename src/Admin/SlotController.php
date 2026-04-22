<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Component\ComponentDefinition;
use TypeDock\Component\ParamOptionsResolver;
use TypeDock\Theme\ThemeLoader;

class SlotController extends BaseAdminController
{
    public function index(): void
    {
        $pdo         = \Flight::db();
        $loader      = new ThemeLoader();
        $activeTheme = $loader->resolveActiveTheme($pdo);
        $themeConfig = $loader->loadThemeConfig($activeTheme);

        $allComponents = \Flight::components()->list();
        // Expand dynamic select_* param types into normal select + options,
        // so the params editor template doesn't need to know about them.
        $optionsResolver = new ParamOptionsResolver();
        foreach ($allComponents as $key => $def) {
            $allComponents[$key] = $optionsResolver->enrich($def);
        }

        $slots = [];
        foreach ($themeConfig['slots'] ?? [] as $slotName => $slotDef) {
            $stmt = $pdo->prepare(
                'SELECT * FROM slot_placements WHERE slot_name = ? ORDER BY sort_order ASC'
            );
            $stmt->execute([$slotName]);
            $placements = $stmt->fetchAll();

            // Narrow the per-slot "Add Component" dropdown to only those
            // components declared compatible with this slot's context. The
            // full list is still passed as $available_components so we can
            // render existing placement rows even when they've become
            // incompatible (and flag them).
            $slotContexts                     = (array) ($slotDef['context'] ?? ['all']);
            $slotDef['placements']            = $this->annotatePlacements($placements, $allComponents, $slotContexts);
            $slotDef['compatible_components'] = $this->filterCompatible($allComponents, $slotContexts);
            $slots[$slotName]                 = $slotDef;
        }

        // Surface any placements that belong to slot names the active theme
        // doesn't declare. They're dead weight — the theme will never render
        // them — and seeing them in the UI is the only way to clean them up
        // after switching themes without running a full Activate.
        $known = array_keys($themeConfig['slots'] ?? []);
        if ($known !== []) {
            $in         = implode(',', array_fill(0, count($known), '?'));
            $orphanStmt = $pdo->prepare(
                "SELECT slot_name, COUNT(*) AS n FROM slot_placements
                 WHERE slot_name NOT IN ({$in})
                 GROUP BY slot_name"
            );
            $orphanStmt->execute($known);
            $orphans = $orphanStmt->fetchAll();
        } else {
            $orphans = [];
        }

        $this->render('pages/slots/index.latte', [
            'slots'                => $slots,
            'active_theme'         => $activeTheme,
            'active_theme_label'   => (string) ($themeConfig['name'] ?? $activeTheme),
            'orphan_placements'    => $orphans,
            'available_components' => $allComponents,
            'flash_success'        => $this->getFlash('success'),
            'flash_error'          => $this->getFlash('error'),
        ]);
    }

    /**
     * Filter the full component list down to those declared compatible with
     * the given slot contexts. A component with no `supportedContexts` is
     * treated as universal, and a slot declared as `['all']` accepts any
     * component.
     *
     * @param  array<string, ComponentDefinition> $components
     * @param  array<string>                      $slotContexts
     * @return array<string, ComponentDefinition>
     */
    private function filterCompatible(array $components, array $slotContexts): array
    {
        $out = [];
        foreach ($components as $type => $def) {
            if ($this->isCompatible($def, $slotContexts)) {
                $out[$type] = $def;
            }
        }
        return $out;
    }

    /**
     * @param  array<string> $slotContexts
     */
    private function isCompatible(ComponentDefinition $def, array $slotContexts): bool
    {
        if (empty($def->supportedContexts)) {
            return true;
        }
        if (in_array('all', $slotContexts, true)) {
            return true;
        }
        return count(array_intersect($def->supportedContexts, $slotContexts)) > 0;
    }

    /**
     * Tag each placement with `incompatible: true` when its component can't
     * render in any of the slot's declared contexts, so the UI can flag it
     * and point the operator at the problem.
     *
     * @param  array<int, array<string, mixed>>   $placements
     * @param  array<string, ComponentDefinition> $components
     * @param  array<string>                      $slotContexts
     * @return array<int, array<string, mixed>>
     */
    private function annotatePlacements(array $placements, array $components, array $slotContexts): array
    {
        foreach ($placements as &$p) {
            $type = (string) ($p['component_type'] ?? '');
            $def  = $components[$type] ?? null;
            if ($def === null) {
                $p['incompatible'] = false;
                continue;
            }
            $p['incompatible'] = !$this->isCompatible($def, $slotContexts);
        }
        return $placements;
    }

    public function update(): void
    {
        $pdo    = \Flight::db();
        $action = $_POST['action'] ?? '';
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($action === 'add') {
            $id            = \Ramsey\Uuid\Uuid::uuid7()->toString();
            $componentType = trim((string) ($_POST['component_type'] ?? ''));

            // Default params come from the component's declared schema so a
            // freshly added placement has sensible starting values the user can
            // then fine-tune via the Edit form.
            $compDef      = \Flight::components()->get($componentType);
            $initialJson  = $compDef !== null ? $this->defaultParamsJson($compDef) : null;

            $stmt = $pdo->prepare('SELECT MAX(sort_order) FROM slot_placements WHERE slot_name = ?');
            $stmt->execute([$_POST['slot_name'] ?? '']);
            $maxOrder = (int) $stmt->fetchColumn();

            $pdo->prepare(
                'INSERT INTO slot_placements (id, slot_name, component_type, params, sort_order, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                trim((string) ($_POST['slot_name'] ?? '')),
                $componentType,
                $initialJson,
                $maxOrder + 1,
                $now,
            ]);
        } elseif ($action === 'remove') {
            $pdo->prepare('DELETE FROM slot_placements WHERE id = ?')
                ->execute([$_POST['placement_id'] ?? '']);
        } elseif ($action === 'params') {
            $this->saveParams($pdo);
        }

        $this->redirect('/admin/slots', 'Slot placements updated successfully.');
    }

    /**
     * Persist the params form submitted from the inline editor. We coerce each
     * value against the component's declared schema so callers can't sneak in
     * keys the component doesn't know about, and so numeric fields round-trip
     * as ints rather than strings.
     */
    private function saveParams(\PDO $pdo): void
    {
        $placementId = trim((string) ($_POST['placement_id'] ?? ''));
        if ($placementId === '') {
            return;
        }

        $stmt = $pdo->prepare('SELECT component_type FROM slot_placements WHERE id = ? LIMIT 1');
        $stmt->execute([$placementId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $compDef = \Flight::components()->get((string) $row['component_type']);
        if ($compDef === null || empty($compDef->params)) {
            return;
        }

        $input   = $_POST['params'] ?? [];
        if (!is_array($input)) {
            $input = [];
        }

        $cleaned = [];
        foreach ($compDef->params as $spec) {
            $name = (string) ($spec['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = (string) ($spec['type'] ?? 'text');
            $raw  = $input[$name] ?? null;

            switch ($type) {
                case 'number':
                    if ($raw === null || $raw === '') {
                        $cleaned[$name] = isset($spec['default']) ? (int) $spec['default'] : 0;
                    } else {
                        $cleaned[$name] = (int) $raw;
                    }
                    break;
                case 'bool':
                case 'boolean':
                    $cleaned[$name] = (bool) $raw;
                    break;
                case 'repeater':
                    $cleaned[$name] = $this->cleanRepeater($raw, (array) ($spec['fields'] ?? []));
                    break;
                default:
                    $cleaned[$name] = $raw === null ? (string) ($spec['default'] ?? '') : (string) $raw;
            }
        }

        $pdo->prepare('UPDATE slot_placements SET params = ? WHERE id = ?')
            ->execute([json_encode($cleaned), $placementId]);
    }

    /**
     * Normalise a repeater submission.
     *
     * Rows are posted as `params[links][0][label]=…`, which PHP turns into a
     * nested array. We drop any row whose scalar fields are all empty, and
     * coerce each field to a string — repeater sub-fields currently only
     * support text/url/select, so a string projection is safe.
     *
     * @param  mixed                         $raw
     * @param  array<string, array<string, mixed>> $fieldSpec
     * @return array<int, array<string, string>>
     */
    private function cleanRepeater(mixed $raw, array $fieldSpec): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            $any   = false;
            foreach ($fieldSpec as $fieldKey => $_spec) {
                $v = isset($row[$fieldKey]) ? trim((string) $row[$fieldKey]) : '';
                $clean[(string) $fieldKey] = $v;
                if ($v !== '') {
                    $any = true;
                }
            }
            if ($any) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /**
     * Build the JSON to seed a newly-added placement's params column from the
     * component's declared defaults.
     */
    private function defaultParamsJson(\TypeDock\Component\ComponentDefinition $def): ?string
    {
        if (empty($def->params)) {
            return null;
        }
        $out = [];
        foreach ($def->params as $spec) {
            $name = (string) ($spec['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = $spec['default'] ?? null;
        }
        return $out === [] ? null : json_encode($out);
    }
}
