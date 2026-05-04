<?php

namespace App\Services\Semantic;

use InvalidArgumentException;

class AtlasVaultLinkService
{
    public const TYPES = [
        'memory' => 'Memory',
        'verbatim-memory' => 'Verbatim Memory',
        'semantic-note' => 'Semantic Note',
        'task' => 'Task',
        'project' => 'Project',
        'engineering-run' => 'Engineering Run',
        'open-brain/audit' => 'Open Brain Audit',
        'trace' => 'Trace',
    ];

    /**
     * @return array<int,string>
     */
    public function supportedTypes(): array
    {
        return array_keys(self::TYPES);
    }

    public function generate(string $type, string $id): string
    {
        $type = trim($type, '/');
        $id = $this->safeId($id);
        if (! array_key_exists($type, self::TYPES)) {
            throw new InvalidArgumentException("Unsupported atlas link type: {$type}");
        }

        return "atlas://{$type}/{$id}";
    }

    /**
     * @return array{type: string, id: string, label: string}|null
     */
    public function parse(string $uri): ?array
    {
        if (! str_starts_with($uri, 'atlas://')) {
            return null;
        }

        $path = substr($uri, strlen('atlas://'));
        foreach (array_keys(self::TYPES) as $type) {
            $prefix = $type.'/';
            if (str_starts_with($path, $prefix)) {
                $id = substr($path, strlen($prefix));
                try {
                    $id = $this->safeId($id);
                } catch (InvalidArgumentException) {
                    return null;
                }

                return ['type' => $type, 'id' => $id, 'label' => self::TYPES[$type]];
            }
        }

        return null;
    }

    /**
     * @param  array<int,array{label?: string, type?: string, id?: string, uri?: string, path?: string}>  $links
     */
    public function markdownBlock(array $links): string
    {
        $lines = ['## Links Atlas', ''];
        foreach ($links as $link) {
            $label = $this->safeLabel((string) ($link['label'] ?? $this->labelFor((string) ($link['type'] ?? ''))));
            $target = $this->targetFor($link);
            $lines[] = "- {$label}: {$target}";
        }

        return implode("\n", $lines)."\n";
    }

    public function labelFor(string $type): string
    {
        return self::TYPES[trim($type, '/')] ?? str($type)->replace('-', ' ')->title()->toString();
    }

    /**
     * @param  array{label?: string, type?: string, id?: string, uri?: string, path?: string}  $link
     */
    private function targetFor(array $link): string
    {
        if (isset($link['uri'])) {
            $uri = trim((string) $link['uri']);
            if ($this->parse($uri) === null) {
                throw new InvalidArgumentException('Invalid atlas:// link URI.');
            }

            return $uri;
        }

        if (isset($link['path'])) {
            return $this->safeRelativePath((string) $link['path']);
        }

        return $this->generate((string) ($link['type'] ?? ''), (string) ($link['id'] ?? ''));
    }

    private function safeId(string $id): string
    {
        $id = trim($id);
        if ($id === '' || str_contains($id, '/') || str_contains($id, '\\') || str_contains($id, '..')) {
            throw new InvalidArgumentException('Atlas link id must be a non-empty path segment.');
        }
        if (! preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            throw new InvalidArgumentException('Atlas link id contains unsupported characters.');
        }

        return $id;
    }

    private function safeLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($label === '') {
            throw new InvalidArgumentException('Atlas link label must be non-empty.');
        }
        if (str_contains($label, '[') || str_contains($label, ']')) {
            throw new InvalidArgumentException('Atlas link label contains unsupported characters.');
        }

        return $label;
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new InvalidArgumentException('Atlas link path must be vault/repo relative and cannot contain traversal.');
        }

        return $path;
    }
}
