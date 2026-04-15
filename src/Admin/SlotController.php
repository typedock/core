<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Theme\ThemeLoader;

class SlotController extends BaseAdminController
{
    public function index(): void
    {
        $loader      = new ThemeLoader();
        $themeConfig = $loader->loadThemeConfig();
        $pdo         = \Flight::db();

        $slots = [];
        foreach ($themeConfig['slots'] ?? [] as $slotName => $slotDef) {
            $stmt = $pdo->prepare(
                'SELECT * FROM slot_placements WHERE slot_name = ? ORDER BY sort_order ASC'
            );
            $stmt->execute([$slotName]);
            $placements            = $stmt->fetchAll();
            $slotDef['placements'] = $placements;
            $slots[$slotName]      = $slotDef;
        }

        $this->render('pages/slots/index.latte', [
            'slots'                => $slots,
            'available_components' => \Flight::components()->list(),
            'flash_success'        => $this->getFlash('success'),
            'flash_error'          => $this->getFlash('error'),
        ]);
    }

    public function update(): void
    {
        $pdo    = \Flight::db();
        $action = $_POST['action'] ?? '';
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($action === 'add') {
            $id = \Ramsey\Uuid\Uuid::uuid7()->toString();

            // Get max sort_order for this slot
            $stmt = $pdo->prepare('SELECT MAX(sort_order) FROM slot_placements WHERE slot_name = ?');
            $stmt->execute([$_POST['slot_name'] ?? '']);
            $maxOrder = (int) $stmt->fetchColumn();

            $pdo->prepare(
                'INSERT INTO slot_placements (id, slot_name, component_type, params, sort_order, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                trim($_POST['slot_name'] ?? ''),
                trim($_POST['component_type'] ?? ''),
                null,
                $maxOrder + 1,
                $now,
            ]);
        } elseif ($action === 'remove') {
            $pdo->prepare('DELETE FROM slot_placements WHERE id = ?')
                ->execute([$_POST['placement_id'] ?? '']);
        }

        $this->redirect('/admin/slots', 'Slot placements updated successfully.');
    }
}
