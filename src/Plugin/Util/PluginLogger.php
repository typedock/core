<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Util;

/**
 * Per-plugin log file. Writes to storage/logs/plugins/<slug>.log so each
 * plugin's output is isolated — a noisy plugin doesn't drown out Core logs,
 * and operators can tail one file while debugging one integration.
 *
 * Intentionally not PSR-3: no context interpolation, no handlers, no levels
 * beyond info/warning/error. If a plugin needs Monolog it can pull it in.
 */
class PluginLogger
{
    public function __construct(private readonly string $logPath)
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = [], ?\Throwable $exception = null): void
    {
        if ($exception !== null) {
            $context['exception'] = $exception::class . ': ' . $exception->getMessage();
            $context['trace']     = $exception->getTraceAsString();
        }
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s %s%s\n",
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $level,
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
