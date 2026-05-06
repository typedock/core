<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Update\PreflightChecker;
use TypeDock\Update\AgentUpdateContext;

final class SystemUpdateController extends BaseAdminController
{
    public function index(): void
    {
        $report = PreflightChecker::fromRuntime()->check();
        $agentContext = new AgentUpdateContext($report);

        $this->render('pages/system/update.latte', [
            'report' => $report,
            'agent_context_json' => $agentContext->toJson(),
            'agent_prompt' => $agentContext->prompt(),
            'version' => (string) config('app.version', defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.1.0'),
            'channel' => (string) config('update.channel', 'stable'),
            'channel_url' => (string) (config('update.channels', [])[$report->profile->mode === 'zip' ? (string) config('update.channel', 'stable') : 'stable'] ?? ''),
            'flash_success' => $this->getFlash('success'),
            'flash_error' => $this->getFlash('error'),
        ]);
    }
}
