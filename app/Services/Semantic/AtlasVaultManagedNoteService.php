<?php

namespace App\Services\Semantic;

use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\ValueObjects\AtlasVaultNote;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class AtlasVaultManagedNoteService
{
    public const MANAGED_START = '<!-- ATLAS:MANAGED:START -->';
    public const MANAGED_END = '<!-- ATLAS:MANAGED:END -->';
    public const SUPPORTED_ATLAS_TYPES = [
        'memory_entry' => [
            'link_type' => 'memory',
            'directory' => 'Atlas/Memory/memory_entry',
            'source_type' => 'atlas_memory_entry',
        ],
        'verbatim_memory' => [
            'link_type' => 'verbatim-memory',
            'directory' => 'Atlas/Memory/verbatim_memory',
            'source_type' => 'atlas_verbatim_memory',
        ],
        'semantic_note' => [
            'link_type' => 'semantic-note',
            'directory' => 'Atlas/SemanticNotes',
            'source_type' => 'semantic_note',
        ],
        'task' => [
            'link_type' => 'task',
            'directory' => 'Atlas/Tasks/managed',
            'source_type' => 'atlas_task',
        ],
        'project' => [
            'link_type' => 'project',
            'directory' => 'Atlas/Projects',
            'source_type' => 'atlas_project',
        ],
        'engineering_run' => [
            'link_type' => 'engineering-run',
            'directory' => 'Atlas/Engineering/Runs',
            'source_type' => 'engineering_run',
        ],
        'open_brain_audit' => [
            'link_type' => 'open-brain/audit',
            'directory' => 'Atlas/OpenBrain/Audits',
            'source_type' => 'atlas_open_brain_access_log',
        ],
        'trace' => [
            'link_type' => 'trace',
            'directory' => 'Atlas/Traces',
            'source_type' => 'ai_trace',
        ],
    ];
    private const MANUAL_HEADING = '## Manual Notes';

    public function __construct(
        private readonly VaultFileStore $vault,
        private readonly AtlasVaultFrontmatterService $frontmatter,
        private readonly AtlasVaultLinkService $links,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public function preview(array $input): AtlasVaultNote
    {
        return $this->build($input, write: false);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function write(array $input): AtlasVaultNote
    {
        return $this->build($input, write: true);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $configuredPath = $this->vault->configuredPath();
        $exists = $configuredPath !== '' && File::isDirectory($configuredPath);
        $root = $exists ? (realpath($configuredPath) ?: $configuredPath) : $configuredPath;
        $managed = 0;
        $conflicts = 0;
        $invalid = 0;
        $inspected = 0;
        if ($exists) {
            foreach ($this->vault->listMarkdownFiles() as $path) {
                $inspected++;
                try {
                    $markdown = $this->vault->read($path);
                    $parsed = $this->frontmatter->parse($markdown);
                } catch (\Throwable) {
                    continue;
                }
                $managedNote = $this->frontmatter->isManaged($parsed['frontmatter']);
                if ($managedNote) {
                    $managed++;
                }
                $noteConflicts = $managedNote ? $this->existingNoteConflicts($parsed, $markdown) : [];
                if ($managedNote && ($noteConflicts !== [] || $this->frontmatter->syncStatus($parsed['frontmatter']) === 'conflict')) {
                    $conflicts++;
                }
                if ($managedNote && ($parsed['errors'] !== [] || $noteConflicts !== [])) {
                    $invalid++;
                }
            }
        }

        return [
            'vault_path' => $root,
            'exists' => $exists,
            'writable' => $exists && File::isWritable($root),
            'inspected_markdown_count' => $inspected,
            'managed_notes_count' => $managed,
            'conflicts_count' => $conflicts,
            'invalid_notes_count' => $invalid,
            'last_indexed_info' => $this->lastIndexedInfo(),
            'supported_note_types' => array_keys(self::SUPPORTED_ATLAS_TYPES),
            'safety_status' => [
                'configured' => $configuredPath !== '',
                'confined_by' => VaultFileStore::class,
                'write_policy' => 'managed_notes_only',
                'supported_link_types' => $this->links->supportedTypes(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function build(array $input, bool $write): AtlasVaultNote
    {
        $atlasId = $this->required($input, 'id');
        $this->assertSafeAtlasId($atlasId);
        $atlasType = $this->required($input, 'type');
        $this->assertSupportedAtlasType($atlasType);
        $title = $this->singleLine($this->required($input, 'title'));
        $summary = trim((string) ($input['summary'] ?? ''));
        $content = (string) ($input['content'] ?? '');
        $this->assertManagedContentSafe($content);
        $path = $this->pathFor($atlasType, $atlasId, $title, $input['path'] ?? null, $write);
        $sourceType = (string) ($input['source_type'] ?? $this->sourceTypeFor($atlasType));

        $frontmatter = $this->frontmatter->build([
            'atlas_id' => $atlasId,
            'atlas_type' => $atlasType,
            'source_type' => $sourceType,
            'source_id' => (string) ($input['source_id'] ?? $atlasId),
            'privacy_class' => (string) ($input['privacy_class'] ?? 'normal'),
            'provider_safe' => $input['provider_safe'] ?? true,
            'redaction_status' => (string) ($input['redaction_status'] ?? 'clean'),
            'updated_at' => (string) ($input['updated_at'] ?? ''),
        ]);
        $atlasLinks = $this->linksFor($atlasType, $atlasId, (array) ($input['links'] ?? []));
        $manual = "## Manual Notes\n\nEspaco humano preservado.\n";
        $conflicts = [];
        $existingMarkdown = null;
        $absolute = $this->vault->absolutePath($path, ensureRoot: $write);

        if (File::exists($absolute)) {
            $existingMarkdown = $this->vault->read($path);
            $parsed = $this->frontmatter->parse($existingMarkdown);
            $existingFrontmatter = $parsed['frontmatter'];
            $conflicts = array_values(array_unique([
                ...$conflicts,
                ...$parsed['errors'],
                ...$this->existingNoteConflicts($parsed, $existingMarkdown),
            ]));
            if (! $this->frontmatter->isManaged($existingFrontmatter)) {
                $conflicts[] = 'existing_file_not_atlas_managed';
            }
            $conflicts = array_values(array_unique([
                ...$conflicts,
                ...$this->frontmatterDriftConflicts($existingFrontmatter, $frontmatter),
            ]));
            $manual = $this->manualSection($parsed['body']) ?? $manual;
            if ($conflicts === [] && $this->shellChanged($existingMarkdown, $title, $summary, $atlasLinks)) {
                $conflicts[] = 'human_changes_outside_manual_or_managed_block';
            }
        }

        if ($conflicts !== []) {
            $frontmatter['sync_status'] = 'conflict';
        }

        $markdown = $this->compose($frontmatter, $title, $summary, $atlasLinks, $content, $manual);
        $metadata = [
            'dry_run' => ! $write,
            'write_requested' => $write,
            'exists' => $existingMarkdown !== null,
            'managed_markers' => [self::MANAGED_START, self::MANAGED_END],
            'manual_preserved' => $existingMarkdown !== null && $manual !== "## Manual Notes\n\nEspaco humano preservado.\n",
            'conflict_marked_in_payload' => $conflicts !== [],
            'operation' => $existingMarkdown === null ? 'create' : ($conflicts === [] ? 'update' : 'conflict'),
            'existing_content_hash' => $existingMarkdown === null ? null : hash('sha256', $existingMarkdown),
            'proposed_content_hash' => hash('sha256', $markdown),
        ];

        if ($write && $conflicts === []) {
            File::ensureDirectoryExists(dirname($absolute));
            $tmp = $absolute.'.tmp.'.bin2hex(random_bytes(4));
            try {
                File::put($tmp, $markdown);
                File::move($tmp, $absolute);
            } finally {
                if (File::exists($tmp)) {
                    File::delete($tmp);
                }
            }
            $metadata['written'] = true;
        } else {
            $metadata['written'] = false;
            if ($write && $conflicts !== []) {
                $metadata['write_blocked_reason'] = 'conflict_detected';
            }
        }

        return new AtlasVaultNote($path, $markdown, $frontmatter, $atlasLinks, $metadata, $conflicts);
    }

    /**
     * @param  array<int,mixed>  $extra
     * @return array<int,array<string,string>>
     */
    private function linksFor(string $atlasType, string $atlasId, array $extra): array
    {
        $linkType = (string) self::SUPPORTED_ATLAS_TYPES[$atlasType]['link_type'];

        $links = [[
            'label' => $this->links->labelFor($linkType),
            'type' => $linkType,
            'id' => $atlasId,
            'uri' => $this->links->generate($linkType, $atlasId),
        ]];

        foreach ($extra as $link) {
            if (! is_array($link)) {
                throw new \InvalidArgumentException('Extra Atlas vault links must be arrays.');
            }

            $links[] = $link;
        }

        return $links;
    }

    /**
     * @param  array<int,array<string,string>>  $links
     */
    private function compose(array $frontmatter, string $title, string $summary, array $links, string $content, string $manual): string
    {
        $content = trim($content) !== '' ? trim($content) : 'Conteudo gerado pelo Atlas.';

        return $this->frontmatter->render($frontmatter)."\n"
            .'# '.$title."\n\n"
            .trim($summary)."\n\n"
            .$this->links->markdownBlock($links)."\n"
            .self::MANAGED_START."\n"
            .$content."\n"
            .self::MANAGED_END."\n\n"
            .rtrim($manual)."\n";
    }

    /**
     * @param  array<int,array<string,string>>  $links
     */
    private function shellChanged(string $existingMarkdown, string $title, string $summary, array $links): bool
    {
        $parsed = $this->frontmatter->parse($existingMarkdown);
        $body = $this->withoutManagedBlock($parsed['body']);
        $body = $this->withoutManualSection($body);
        $expected = "# {$title}\n\n".trim($summary)."\n\n".$this->links->markdownBlock($links);

        return $this->normalize($body) !== $this->normalize($expected);
    }

    private function withoutManagedBlock(string $body): string
    {
        return (string) preg_replace(
            '/'.preg_quote(self::MANAGED_START, '/').'.*?'.preg_quote(self::MANAGED_END, '/').'/s',
            '',
            $body,
        );
    }

    private function manualSection(string $body): ?string
    {
        $position = strpos($body, self::MANUAL_HEADING);
        if ($position === false) {
            return null;
        }

        return trim(substr($body, $position))."\n";
    }

    private function withoutManualSection(string $body): string
    {
        $position = strpos($body, self::MANUAL_HEADING);
        if ($position === false) {
            return $body;
        }

        return substr($body, 0, $position);
    }

    private function normalize(string $markdown): string
    {
        $markdown = preg_replace("/[ \t]+/", ' ', $markdown) ?? $markdown;
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function pathFor(string $type, string $id, string $title, mixed $path, bool $write): string
    {
        $this->assertSupportedAtlasType($type);

        if (is_string($path) && trim($path) !== '') {
            $candidate = trim($path);
            if (str_starts_with($candidate, '/') || str_starts_with($candidate, '\\')) {
                throw new RuntimeException('Atlas vault note path must be vault-relative.');
            }
            $this->vault->absolutePath($candidate, ensureRoot: $write);
            if (! str_ends_with(strtolower($candidate), '.md')) {
                throw new RuntimeException('Atlas vault note path must end with .md.');
            }

            return $this->normalizeVaultRelativePath($candidate);
        }

        $directory = (string) self::SUPPORTED_ATLAS_TYPES[$type]['directory'];
        $slug = Str::slug($title) ?: Str::slug($id);

        return "{$directory}/{$slug}-{$id}.md";
    }

    private function normalizeVaultRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return ltrim($path, '/');
    }

    private function sourceTypeFor(string $type): string
    {
        $this->assertSupportedAtlasType($type);

        return (string) self::SUPPORTED_ATLAS_TYPES[$type]['source_type'];
    }

    private function assertSupportedAtlasType(string $type): void
    {
        if (! array_key_exists($type, self::SUPPORTED_ATLAS_TYPES)) {
            throw new RuntimeException('Unsupported Atlas vault note type: '.$type);
        }
    }

    private function assertSafeAtlasId(string $id): void
    {
        if (str_contains($id, '/') || str_contains($id, '\\') || str_contains($id, '..')) {
            throw new RuntimeException('Atlas vault note id must be a safe path segment.');
        }
        if (! preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            throw new RuntimeException('Atlas vault note id contains unsupported characters.');
        }
    }

    private function assertManagedContentSafe(string $content): void
    {
        if (str_contains($content, self::MANAGED_START) || str_contains($content, self::MANAGED_END)) {
            throw new RuntimeException('Atlas managed content cannot contain reserved managed block markers.');
        }
    }

    private function singleLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * @param  array{frontmatter: array<string,mixed>, body: string, errors: array<int,string>}  $parsed
     * @return array<int,string>
     */
    private function existingNoteConflicts(array $parsed, string $markdown): array
    {
        if (! $this->frontmatter->isManaged($parsed['frontmatter'])) {
            return [];
        }

        $conflicts = $this->frontmatter->validateManaged($parsed['frontmatter']);
        if ($this->frontmatter->syncStatus($parsed['frontmatter']) === 'conflict') {
            $conflicts[] = 'existing_sync_status_conflict';
        }
        if (! str_contains($markdown, self::MANAGED_START) || ! str_contains($markdown, self::MANAGED_END)) {
            $conflicts[] = 'missing_managed_block_markers';
        }
        if (substr_count($markdown, self::MANAGED_START) !== 1 || substr_count($markdown, self::MANAGED_END) !== 1) {
            $conflicts[] = 'invalid_managed_block_marker_count';
        }
        if (
            str_contains($markdown, self::MANAGED_START)
            && str_contains($markdown, self::MANAGED_END)
            && strpos($markdown, self::MANAGED_START) > strpos($markdown, self::MANAGED_END)
        ) {
            $conflicts[] = 'invalid_managed_block_marker_order';
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $expected
     * @return array<int,string>
     */
    private function frontmatterDriftConflicts(array $existing, array $expected): array
    {
        $conflicts = [];
        foreach ([
            'atlas_id',
            'atlas_type',
            'source',
            'source_type',
            'source_id',
            'privacy_class',
            'provider_safe',
            'redaction_status',
            'canonical',
            'created_by',
        ] as $field) {
            if (($existing[$field] ?? null) !== ($expected[$field] ?? null)) {
                $conflicts[] = "{$field}_changed";
            }
        }

        return $conflicts;
    }

    private function required(array $input, string $key): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("Missing required note field: {$key}");
        }

        return $value;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastIndexedInfo(): ?array
    {
        if (! class_exists(\App\Models\SemanticNote::class)) {
            return null;
        }

        try {
            if (! DatabaseTableAvailability::has('semantic_notes')) {
                return null;
            }

            return [
                'semantic_notes_count' => \App\Models\SemanticNote::query()->count(),
                'last_indexed_at' => \App\Models\SemanticNote::query()->max('indexed_at'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
