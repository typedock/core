<?php
declare(strict_types=1);

namespace TypeDock\Core;

/**
 * Renders a detailed, browser-friendly error page when APP_DEBUG is on.
 * Intended only for local development — never enable APP_DEBUG in production.
 */
final class DebugRenderer
{
    public static function render(\Throwable $e): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        $chain = [];
        $cur   = $e;
        while ($cur !== null) {
            $chain[] = $cur;
            $cur     = $cur->getPrevious();
        }

        $title = self::h($e::class . ': ' . $e->getMessage());
        echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$title}</title>";
        echo '<style>' . self::css() . '</style></head><body><div class="container">';

        foreach ($chain as $i => $ex) {
            self::renderException($ex, $i > 0);
        }

        echo self::requestInfo();
        echo '</div></body></html>';
    }

    private static function renderException(\Throwable $e, bool $isPrevious): void
    {
        $class  = self::h($e::class);
        $msg    = self::h($e->getMessage() !== '' ? $e->getMessage() : '(no message)');
        $file   = self::h($e->getFile());
        $line   = (int) $e->getLine();
        $code   = $e->getCode();
        $label  = $isPrevious ? 'Caused by' : 'Uncaught';

        echo '<section class="exception">';
        echo "<div class=\"label\">{$label}</div>";
        echo "<h1>{$class}</h1>";
        echo "<p class=\"message\">{$msg}</p>";
        echo "<p class=\"loc\"><strong>{$file}</strong>:{$line}";
        if ($code !== 0) {
            echo " &middot; code <code>" . self::h((string) $code) . '</code>';
        }
        echo '</p>';

        echo self::sourceSnippet($e->getFile(), $line);
        echo self::trace($e);
        echo '</section>';
    }

    private static function sourceSnippet(string $file, int $line, int $context = 6): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }
        $lines = @file($file);
        if ($lines === false) {
            return '';
        }
        $start = max(0, $line - $context - 1);
        $end   = min(count($lines), $line + $context);

        $html = '<div class="source"><table>';
        for ($i = $start; $i < $end; $i++) {
            $n        = $i + 1;
            $text     = rtrim($lines[$i], "\r\n");
            $isActive = $n === $line ? ' class="active"' : '';
            $html    .= "<tr{$isActive}><td class=\"ln\">{$n}</td><td class=\"code\">" . self::h($text) . '</td></tr>';
        }
        $html .= '</table></div>';
        return $html;
    }

    private static function trace(\Throwable $e): string
    {
        $frames = $e->getTrace();
        if ($frames === []) {
            return '';
        }
        $html = '<details class="trace" open><summary>Stack trace (' . count($frames) . ' frames)</summary><ol>';
        foreach ($frames as $f) {
            $file = isset($f['file']) ? self::h($f['file']) : '[internal]';
            $line = isset($f['line']) ? ':' . (int) $f['line'] : '';
            $call = (isset($f['class']) ? self::h($f['class'] . ($f['type'] ?? '::')) : '')
                  . self::h($f['function'] ?? '')
                  . '()';
            $html .= "<li><span class=\"call\">{$call}</span><br><span class=\"loc\">{$file}{$line}</span></li>";
        }
        $html .= '</ol></details>';
        return $html;
    }

    private static function requestInfo(): string
    {
        $rows = [
            'Method'   => $_SERVER['REQUEST_METHOD'] ?? '—',
            'URI'      => $_SERVER['REQUEST_URI']    ?? '—',
            'Host'     => $_SERVER['HTTP_HOST']      ?? '—',
            'PHP'      => PHP_VERSION,
            'SAPI'     => PHP_SAPI,
            'Memory'   => number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        ];
        $html = '<section class="meta"><h2>Request</h2><table>';
        foreach ($rows as $k => $v) {
            $html .= '<tr><th>' . self::h($k) . '</th><td>' . self::h((string) $v) . '</td></tr>';
        }
        $html .= '</table></section>';
        return $html;
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<CSS
            * { box-sizing: border-box; }
            body { margin: 0; font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #0f1115; color: #e6e6e6; }
            .container { max-width: 1100px; margin: 0 auto; padding: 24px; }
            section.exception { background: #181b22; border-radius: 8px; padding: 20px; margin-bottom: 20px; border-left: 4px solid #e5484d; }
            .label { color: #e5484d; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: 11px; margin-bottom: 6px; }
            h1 { margin: 0 0 8px; font-size: 18px; color: #fff; word-break: break-word; }
            p.message { margin: 0 0 12px; font-size: 15px; color: #f7b955; }
            p.loc { margin: 0 0 16px; color: #9ba3b4; }
            p.loc strong { color: #78c6ff; }
            code { background: #262a33; padding: 1px 6px; border-radius: 3px; }
            .source { background: #12151b; border-radius: 6px; overflow: hidden; margin-bottom: 16px; border: 1px solid #242833; }
            .source table { width: 100%; border-collapse: collapse; }
            .source td { padding: 2px 10px; vertical-align: top; }
            .source td.ln { color: #5a6272; text-align: right; user-select: none; width: 48px; }
            .source td.code { white-space: pre; font-family: inherit; }
            .source tr.active { background: #3a1f24; }
            .source tr.active td.ln { color: #e5484d; font-weight: 700; }
            details.trace { background: #12151b; border-radius: 6px; padding: 10px 14px; border: 1px solid #242833; }
            details.trace summary { cursor: pointer; color: #78c6ff; font-weight: 600; }
            details.trace ol { padding-left: 20px; margin: 10px 0 0; }
            details.trace li { margin-bottom: 10px; }
            .call { color: #e6e6e6; }
            .loc { color: #9ba3b4; font-size: 12px; }
            section.meta { background: #181b22; border-radius: 8px; padding: 16px 20px; margin-top: 20px; }
            section.meta h2 { margin: 0 0 10px; font-size: 13px; color: #9ba3b4; text-transform: uppercase; letter-spacing: .08em; }
            section.meta table { width: 100%; }
            section.meta th { text-align: left; color: #9ba3b4; font-weight: 500; width: 120px; padding: 3px 0; }
            section.meta td { color: #e6e6e6; padding: 3px 0; word-break: break-all; }
        CSS;
    }
}
