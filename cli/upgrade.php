<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

typedock_load_config(TYPEDOCK_ROOT);

$args = array_values(array_slice($argv ?? [], 1));
$command = $args[0] ?? '--check';

if (!in_array($command, ['--check', 'check', '--agent-context', 'agent-context', '--agent-prompt', 'agent-prompt'], true)) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php cli/upgrade.php --check\n");
    fwrite(STDERR, "  php cli/upgrade.php --agent-context\n");
    fwrite(STDERR, "  php cli/upgrade.php --agent-prompt\n");
    exit(2);
}

$report = \TypeDock\Update\PreflightChecker::fromRuntime()->check();
$context = new \TypeDock\Update\AgentUpdateContext($report);

if (in_array($command, ['--agent-context', 'agent-context'], true)) {
    echo $context->toJson() . "\n";
    exit($report->canApplyUpdates() ? 0 : 1);
}

if (in_array($command, ['--agent-prompt', 'agent-prompt'], true)) {
    echo $context->prompt() . "\n";
    exit($report->canApplyUpdates() ? 0 : 1);
}

echo "TypeDock agent-assisted update preflight\n";
echo "  Version: " . (string) config('app.version', defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.1.0') . "\n";
echo "  Mode:    {$report->profile->mode}\n";
echo "  Public:  " . ($report->profile->isSplitPublic() ? 'split' : 'standard') . "\n\n";

echo "Checks:\n";
foreach ($report->issues as $issue) {
    $flag = match ($issue->severity) {
        'ok' => 'OK',
        'warning' => 'WARN',
        default => 'ERR',
    };
    printf("  %-4s  %-22s  %s\n", $flag, $issue->label, $issue->message);
}

echo "\nTheme/plugin ownership:\n";
foreach ($report->ownership as $row) {
    printf("  %-6s  %-24s  %-18s  %s\n", $row['type'], $row['slug'], $row['status'], $row['message']);
}

echo "\nAgent handoff:\n";
echo "  php cli/upgrade.php --agent-context\n";
echo "  php cli/upgrade.php --agent-prompt\n";

exit($report->canApplyUpdates() ? 0 : 1);
