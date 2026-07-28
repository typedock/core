<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Update\PreflightChecker;
use TypeDock\Update\AgentUpdateContext;
use TypeDock\Update\ReleaseMetadata;
use TypeDock\Update\Trust;
use TypeDock\Update\UpdateChecker;
use TypeDock\Update\UpdateManager;

final class SystemUpdateController extends BaseAdminController
{
    public function index(): void
    {
        $report = PreflightChecker::fromRuntime()->check();
        $agentContext = new AgentUpdateContext($report);
        $checker = UpdateChecker::fromRuntime();
        $release = $checker->cached();
        $manager = UpdateManager::fromRuntime(\Flight::db());
        $version = (string) config('app.version', defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.8.0');

        $this->render('pages/system/update.latte', [
            'report' => $report,
            'agent_context_json' => $agentContext->toJson(),
            'agent_prompt' => $agentContext->prompt(),
            'version' => $version,
            'channel' => (string) config('update.channel', 'stable'),
            'channel_url' => (string) (config('update.channels', [])[$report->profile->mode === 'zip' ? (string) config('update.channel', 'stable') : 'stable'] ?? ''),
            'release' => $release,
            'update_available' => $release instanceof ReleaseMetadata && version_compare($release->version, $version, '>'),
            'current_revoked' => $release instanceof ReleaseMetadata && in_array($version, $release->revokedVersions, true),
            'update_state' => $manager->state(),
            'signing_key_configured' => Trust::publicKeys() !== [],
            'flash_success' => $this->getFlash('success'),
            'flash_error' => $this->getFlash('error'),
        ]);
    }

    public function check(): void
    {
        try {
            $release = UpdateChecker::fromRuntime()->check();
            $this->redirect('/admin/system/update', "Latest {$release->channel} release: {$release->version}.");
        } catch (\Throwable $e) {
            $this->redirect('/admin/system/update', 'Update check failed: ' . $e->getMessage(), 'error');
        }
    }

    public function prepare(): void
    {
        try {
            $release = UpdateChecker::fromRuntime()->check();
            $state = UpdateManager::fromRuntime(\Flight::db())->prepare($release);
            $this->redirect(
                '/admin/system/update',
                "TypeDock {$state['target_version']} was downloaded, signature-verified, and staged. Review ownership before applying.",
            );
        } catch (\Throwable $e) {
            $this->redirect('/admin/system/update', 'Update preparation failed: ' . $e->getMessage(), 'error');
        }
    }

    public function apply(): void
    {
        if ((string) ($_POST['confirm_update'] ?? '') !== 'yes') {
            $this->redirect('/admin/system/update', 'Confirm the backup and maintenance notice before applying.', 'error');
        }
        $token = (string) ($_POST['update_token'] ?? '');
        try {
            $state = UpdateManager::fromRuntime(\Flight::db())->apply($token);
            $this->redirect(
                '/admin/system/update',
                "Update completed. TypeDock {$state['target_version']} is now installed.",
            );
        } catch (\Throwable $e) {
            $path = '/admin/system/update';
            if (is_file(TYPEDOCK_ROOT . '/storage/.maintenance') && $token !== '') {
                $path .= '?_maintenance_admin=' . rawurlencode($token);
            }
            $this->redirect($path, 'Update failed: ' . $e->getMessage(), 'error');
        }
    }

    public function rollback(): void
    {
        $token = (string) ($_POST['update_token'] ?? '');
        try {
            UpdateManager::fromRuntime(\Flight::db())->rollback($token);
            $this->redirect('/admin/system/update', 'The interrupted update was rolled back.');
        } catch (\Throwable $e) {
            $path = '/admin/system/update';
            if ($token !== '') {
                $path .= '?_maintenance_admin=' . rawurlencode($token);
            }
            $this->redirect($path, 'Rollback failed: ' . $e->getMessage(), 'error');
        }
    }
}
