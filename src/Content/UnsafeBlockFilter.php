<?php
declare(strict_types=1);

namespace TypeDock\Content;

use TypeDock\Component\ComponentRegistry;

/**
 * Strip component blocks the current user is not authorised to insert.
 *
 * The Tiptap body is whatever the editor POSTs back. Most nodes are safe by
 * construction (TiptapRenderer escapes text and only emits its known tag
 * set), but `componentBlock` nodes delegate to ComponentRenderer which can
 * include templates that emit raw HTML — `custom_html` is the obvious one.
 * Components that need the privilege declare `requiresCapability` on their
 * ComponentDefinition; this filter walks the body and drops nodes whose
 * required capability the saver lacks. Drops are reported via getRemoved()
 * so the controller can flash a warning.
 */
final class UnsafeBlockFilter
{
    /** @var array<string> */
    private array $removed = [];

    public function __construct(
        private readonly ComponentRegistry $components,
        /** @var callable(string): bool */
        private $can
    ) {}

    /**
     * Filter a Tiptap doc. Accepts the wire form (string|array) the controller
     * received from $_POST['body'] and returns the same shape back so callers
     * don't have to think about JSON encoding. Returns the input untouched on
     * decode failure — validation of body shape happens elsewhere.
     */
    public function filter(string|array|null $body): string|array|null
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $wasString = is_string($body);
        $doc = $wasString ? json_decode($body, true) : $body;
        if (!is_array($doc)) {
            return $body;
        }

        $doc['content'] = $this->walk($doc['content'] ?? []);

        return $wasString ? json_encode($doc) : $doc;
    }

    /**
     * @param array<int, mixed> $nodes
     * @return array<int, mixed>
     */
    private function walk(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'componentBlock') {
                $type = (string) ($node['attrs']['component'] ?? '');
                if ($type !== '' && $this->isBlocked($type)) {
                    $this->removed[] = $type;
                    continue;
                }
            }
            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = $this->walk($node['content']);
            }
            $out[] = $node;
        }
        return $out;
    }

    private function isBlocked(string $componentType): bool
    {
        $def = $this->components->get($componentType);
        if ($def === null || $def->requiresCapability === null) {
            return false;
        }
        return !($this->can)($def->requiresCapability);
    }

    /**
     * Component types that were removed during the last filter() call.
     *
     * @return array<string>
     */
    public function getRemoved(): array
    {
        return array_values(array_unique($this->removed));
    }
}
