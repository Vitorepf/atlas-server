<?php

namespace App\Services\Ai\Instrumentation;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\AtlasOpenBrainMemoryProjectionSafetyGate;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Provider\ProviderProjectionInput;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AtlasProviderProjectionService
{
    public const VERSION = 'atlas_provider_projection_v1';

    public const MANUAL_START = '<!-- atlas:manual:start -->';

    public const MANUAL_END = '<!-- atlas:manual:end -->';

    public const AOBG_MANAGED_START = '<!-- atlas:aobg:auto-bootstrap:start -->';

    public const AOBG_MANAGED_END = '<!-- atlas:aobg:auto-bootstrap:end -->';

    public function __construct(
        private readonly AtlasMemoryRegistryService $registry,
        private readonly AtlasMemoryPrivacyService $privacy,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasOpenBrainMemoryProjectionSafetyGate $memoryProjectionSafetyGate,
        private readonly ?ProviderProjectionInput $input = null,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function generate(string $target, array $context = [], array $options = []): array
    {
        $target = $this->target($target);
        $workspace = $this->workspace($context['workspace'] ?? ($options['workspace'] ?? null));
        $maxLines = $this->projectionInput()->maxLines($options['max_lines'] ?? null);
        $memoryLimit = $this->projectionInput()->memoryLimit($options['memory_limit'] ?? null);
        $entries = $this->providerSafeEntries($target, $context + ['workspace' => $workspace], $memoryLimit);
        $canonicalMemoryLines = $this->canonicalProviderMemoryLines();
        $path = $workspace.DIRECTORY_SEPARATOR.$this->filename($target);
        $manualContent = $this->manualContent($path, $options);
        $managedAppendix = $this->managedAppendix($path, $options);
        $lean = $this->leanNested($target, $workspace);
        $body = $lean
            ? $this->leanBody($target, $manualContent)
            : $this->body($target, $workspace, $entries, $canonicalMemoryLines, max(1, $maxLines - 1), $manualContent);
        $checksum = $this->checksum($body);
        $generatedAt = now()->toJSON();
        $content = $this->header($target, $generatedAt, $checksum)."\n".$body;
        if ($managedAppendix !== '') {
            $content = rtrim($content, "\r\n")."\n\n".$managedAppendix;
        }

        return [
            'target' => $target,
            'filename' => $this->filename($target),
            'path' => $path,
            'workspace' => $workspace,
            'version' => self::VERSION,
            'generated_at' => $generatedAt,
            'checksum' => $checksum,
            'line_count' => substr_count($content, "\n") + 1,
            'memory_count' => $entries->count() + count($canonicalMemoryLines),
            'redaction_receipts' => $entries
                ->map(fn (AtlasMemoryEntry $entry): array => $this->redactionReceipt($entry))
                ->values()
                ->all(),
            'lean' => $lean,
            'content' => $content,
        ];
    }

    /**
     * Lean projection for a nested workspace: when the canonical context already
     * loads from a managed parent projection (an ancestor CLAUDE.md, always read
     * by Claude Code), the child only needs its Manual Notes. Scoped to the claude
     * target — AGENTS.md consumers (Codex/Cursor) are not guaranteed to read
     * ancestor files, so they keep the full self-contained projection. Off by
     * default; enable with atlas.ai.provider_projection_lean_nested.
     */
    private function leanNested(string $target, string $workspace): bool
    {
        if ($target !== 'claude') {
            return false;
        }

        if (! (bool) config('atlas.ai.provider_projection_lean_nested', false)) {
            return false;
        }

        return $this->hasManagedAncestorProjection($workspace, $target);
    }

    private function hasManagedAncestorProjection(string $workspace, string $target): bool
    {
        $filename = $this->filename($target);
        $dir = rtrim($workspace, DIRECTORY_SEPARATOR);

        for ($depth = 0; $depth < 8; $depth++) {
            $parent = dirname($dir);
            if ($parent === $dir || $parent === '' || $parent === '.') {
                break;
            }

            $candidate = $parent.DIRECTORY_SEPARATOR.$filename;
            if (is_file($candidate) && $this->parse((string) file_get_contents($candidate)) !== null) {
                return true;
            }

            $dir = $parent;
        }

        return false;
    }

    private function leanBody(string $target, string $manualContent): string
    {
        $title = $this->filename($target);
        $manualSourceLines = preg_split('/\R/', $manualContent !== '' ? $manualContent : 'Keep local provider notes here. Atlas preserves this block and ignores it for checksum drift.') ?: [];
        $manualLines = array_map('strval', $manualSourceLines);

        return implode("\n", [
            '# '.$title.' generated by Atlas',
            '',
            'Atlas memory is canonical. This file is a short provider projection, not the source of truth.',
            'Regenerate with `atlas memory projection --target='.$target.' --write` after Atlas memory changes.',
            '',
            '## Nested Workspace (lean projection)',
            '- This workspace is nested under an Atlas-managed parent projection.',
            '- Operating Contract, Canonical Knowledge Governance, Provider-Safe Memory and Atlas Pointers load from the parent '.$title.' (always read as an ancestor) and are not duplicated here.',
            '- Edit canonical context in the parent projection; this file carries only workspace-specific Manual Notes.',
            '',
            '## Manual Notes',
            self::MANUAL_START,
            ...$manualLines,
            self::MANUAL_END,
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function write(string $target, array $context = [], array $options = []): array
    {
        $projection = $this->generate($target, $context, $options);
        $workspace = (string) $projection['workspace'];
        if (! is_dir($workspace)) {
            return $projection + [
                'written' => false,
                'error' => 'workspace_not_found',
            ];
        }

        if (empty($options['force']) && is_file((string) $projection['path'])) {
            $inspection = $this->inspect($target, $context, $options);
            if (($inspection['managed'] ?? false) !== true || ($inspection['manual_drift'] ?? false) === true) {
                return $projection + [
                    'written' => false,
                    'error' => (string) ($inspection['reason'] ?? 'existing_file_not_safe_to_overwrite'),
                    'inspection' => $inspection,
                ];
            }
        }

        file_put_contents((string) $projection['path'], (string) $projection['content'], LOCK_EX);

        return $projection + [
            'written' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function adopt(string $target, array $context = [], array $options = []): array
    {
        $target = $this->target($target);
        $workspace = $this->workspace($context['workspace'] ?? ($options['workspace'] ?? null));
        $path = $workspace.DIRECTORY_SEPARATOR.$this->filename($target);
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $parsed = $existing !== '' ? $this->parse($existing) : null;
        $manualContent = $parsed['manual_content'] ?? $this->manualContentFromUnmanaged($existing);
        $managedAppendix = $this->extractAobgManagedBlocks($existing);

        return $this->write($target, $context, array_merge($options, [
            'workspace' => $workspace,
            'manual_content' => $manualContent,
            'managed_appendix' => $managedAppendix,
            'force' => true,
        ])) + [
            'adopted' => $existing !== '',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function inspect(string $target, array $context = [], array $options = []): array
    {
        $projection = $this->generate($target, $context, $options);
        $path = (string) $projection['path'];
        if (! is_file($path)) {
            return [
                'target' => $projection['target'],
                'path' => $path,
                'memory_count' => $projection['memory_count'],
                'exists' => false,
                'managed' => false,
                'manual_drift' => false,
                'stale' => false,
                'reason' => 'missing',
            ];
        }

        $content = (string) file_get_contents($path);
        $parsed = $this->parse($content);
        if ($parsed === null) {
            return [
                'target' => $projection['target'],
                'path' => $path,
                'memory_count' => $projection['memory_count'],
                'exists' => true,
                'managed' => false,
                'manual_drift' => true,
                'stale' => false,
                'reason' => 'atlas_header_missing',
            ];
        }

        $actualChecksum = $this->checksum($parsed['body']);
        $manualDrift = $actualChecksum !== $parsed['checksum'];

        return [
            'target' => $projection['target'],
            'path' => $path,
            'memory_count' => $projection['memory_count'],
            'exists' => true,
            'managed' => true,
            'manual_drift' => $manualDrift,
            'stale' => ! $manualDrift && $parsed['checksum'] !== $projection['checksum'],
            'checksum' => $parsed['checksum'],
            'actual_checksum' => $actualChecksum,
            'expected_checksum' => $projection['checksum'],
            'generated_at' => $parsed['generated_at'],
            'manual_section_present' => $parsed['manual_section_present'],
            'reason' => $manualDrift ? 'checksum_mismatch' : ($parsed['checksum'] !== $projection['checksum'] ? 'atlas_memory_changed' : 'ok'),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function status(string $target = 'all', array $context = [], array $options = []): array
    {
        $workspace = $this->workspace($context['workspace'] ?? ($options['workspace'] ?? null));
        $targets = $this->targets($target === '' ? 'all' : $target);
        $projections = collect($targets)
            ->map(fn (string $target): array => $this->inspect($target, array_merge($context, ['workspace' => $workspace]), array_merge($options, ['workspace' => $workspace])))
            ->values();
        $summary = [
            'total' => $projections->count(),
            'ready' => $projections->filter(fn (array $projection): bool => $this->projectionReady($projection))->count(),
            'missing' => $projections->filter(fn (array $projection): bool => ($projection['exists'] ?? false) === false)->count(),
            'unmanaged' => $projections->filter(fn (array $projection): bool => ($projection['exists'] ?? false) === true && ($projection['managed'] ?? false) !== true)->count(),
            'manual_drift' => $projections->filter(fn (array $projection): bool => ($projection['manual_drift'] ?? false) === true)->count(),
            'stale' => $projections->filter(fn (array $projection): bool => ($projection['stale'] ?? false) === true)->count(),
            'empty_memory' => $projections->filter(fn (array $projection): bool => (int) ($projection['memory_count'] ?? 0) < 1)->count(),
            'provider_safe_memory_count' => $projections->sum(fn (array $projection): int => (int) ($projection['memory_count'] ?? 0)),
        ];
        $status = $summary['ready'] === $summary['total'] && $summary['total'] > 0 && $summary['empty_memory'] === 0
            ? 'passed'
            : 'needs_review';

        return [
            'status' => $status,
            'workspace' => $workspace,
            'workspace_exists' => is_dir($workspace),
            'targets' => $targets,
            'summary' => $summary,
            'detail' => $this->statusDetail($summary),
            'next_actions' => $this->statusNextActions($workspace, $projections->all()),
            'projections' => $projections->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function review(string $target = 'all', array $context = [], array $options = []): array
    {
        $workspace = $this->workspace($context['workspace'] ?? ($options['workspace'] ?? null));
        $targets = $this->targets($target === '' ? 'all' : $target);
        $projections = collect($targets)
            ->map(fn (string $target): array => $this->reviewTarget($target, array_merge($context, ['workspace' => $workspace]), array_merge($options, ['workspace' => $workspace])))
            ->values();
        $summary = [
            'total' => $projections->count(),
            'changed' => $projections->filter(fn (array $projection): bool => (bool) ($projection['changed'] ?? false))->count(),
            'create' => $projections->where('change_type', 'create')->count(),
            'adopt' => $projections->where('change_type', 'adopt')->count(),
            'update' => $projections->where('change_type', 'update')->count(),
            'manual_drift' => $projections->where('change_type', 'manual_drift')->count(),
            'noop' => $projections->where('change_type', 'none')->count(),
            'empty_memory' => $projections->filter(fn (array $projection): bool => (int) ($projection['memory_count'] ?? 0) < 1)->count(),
            'provider_safe_memory_count' => $projections->sum(fn (array $projection): int => (int) ($projection['memory_count'] ?? 0)),
        ];

        return [
            'status' => $summary['changed'] > 0 || $summary['empty_memory'] > 0 ? 'needs_review' : 'passed',
            'workspace' => $workspace,
            'workspace_exists' => is_dir($workspace),
            'targets' => $targets,
            'summary' => $summary,
            'detail' => $this->reviewDetail($summary),
            'next_actions' => $this->reviewNextActions($workspace, $projections->all()),
            'projections' => $projections->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function applyReviewed(string $target = 'all', array $context = [], array $options = []): array
    {
        $workspace = $this->workspace($context['workspace'] ?? ($options['workspace'] ?? null));
        $context = array_merge($context, ['workspace' => $workspace]);
        $options = array_merge($options, ['workspace' => $workspace]);
        $review = $this->review($target, $context, $options);
        $projections = collect((array) ($review['projections'] ?? []));
        $blocked = $projections
            ->filter(fn (array $projection): bool => ($projection['change_type'] ?? null) === 'manual_drift')
            ->values()
            ->all();
        $applicable = $projections
            ->filter(fn (array $projection): bool => in_array($projection['change_type'] ?? null, ['create', 'update', 'adopt'], true))
            ->values();
        $applied = $applicable
            ->map(function (array $projection) use ($context, $options): array {
                $target = (string) ($projection['target'] ?? 'claude');
                $changeType = (string) ($projection['change_type'] ?? 'none');
                $result = $changeType === 'adopt'
                    ? $this->adopt($target, $context, $options)
                    : $this->write($target, $context, $options);

                return [
                    'target' => $target,
                    'change_type' => $changeType,
                    'path' => $result['path'] ?? $projection['path'] ?? null,
                    'written' => (bool) ($result['written'] ?? false),
                    'error' => $result['error'] ?? null,
                    'result' => $result,
                ];
            })
            ->values()
            ->all();
        $failed = collect($applied)->filter(fn (array $result): bool => (bool) ($result['written'] ?? false) !== true)->values()->all();
        $ok = $blocked === [] && $failed === [];

        return [
            'ok' => $ok,
            'status' => $ok ? 'passed' : 'needs_review',
            'workspace' => $workspace,
            'target' => $target,
            'review' => $review,
            'applied' => $applied,
            'blocked' => $blocked,
            'failed' => $failed,
            'summary' => [
                'reviewed' => $projections->count(),
                'applicable' => $applicable->count(),
                'applied' => collect($applied)->where('written', true)->count(),
                'blocked' => count($blocked),
                'failed' => count($failed),
            ],
            'detail' => $ok
                ? 'Provider projections aplicadas a partir de review confirmado.'
                : 'Provider projection apply precisa de revisao antes de concluir.',
            'next_actions' => $ok ? [] : (array) ($review['next_actions'] ?? []),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function targets(string $target): array
    {
        $target = strtolower(trim($target));

        return $target === 'all' ? ['claude', 'agents'] : [$this->target($target)];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function reviewTarget(string $target, array $context, array $options): array
    {
        $target = $this->target($target);
        $workspace = $this->workspace($context['workspace'] ?? ($options['workspace'] ?? null));
        $path = $workspace.DIRECTORY_SEPARATOR.$this->filename($target);
        $exists = is_file($path);
        $current = $exists ? (string) file_get_contents($path) : '';
        $parsed = $current !== '' ? $this->parse($current) : null;
        $options = $parsed === null && $current !== ''
            ? array_merge($options, ['manual_content' => trim($current)])
            : $options;
        $projection = $this->generate($target, $context, $options);
        $inspection = $this->inspect($target, $context, $options);
        $changeType = $this->reviewChangeType($inspection);
        $changed = $changeType !== 'none';
        $diff = $changed
            ? $this->unifiedDiff((string) $projection['path'], $current, (string) $projection['content'])
            : '';

        return [
            'target' => $target,
            'path' => $projection['path'],
            'exists' => $exists,
            'managed' => (bool) ($inspection['managed'] ?? false),
            'changed' => $changed,
            'change_type' => $changeType,
            'reason' => (string) ($inspection['reason'] ?? 'ok'),
            'current_line_count' => $current === '' ? 0 : substr_count($current, "\n") + 1,
            'proposed_line_count' => $projection['line_count'],
            'memory_count' => $projection['memory_count'],
            'diff_line_count' => $diff === '' ? 0 : substr_count($diff, "\n") + 1,
            'diff' => $diff,
            'inspection' => $inspection,
        ];
    }

    /**
     * @param  array<string,mixed>  $inspection
     */
    private function reviewChangeType(array $inspection): string
    {
        if (($inspection['exists'] ?? false) === false) {
            return 'create';
        }
        if (($inspection['managed'] ?? false) !== true) {
            return 'adopt';
        }
        if (($inspection['manual_drift'] ?? false) === true) {
            return 'manual_drift';
        }
        if (($inspection['stale'] ?? false) === true) {
            return 'update';
        }

        return 'none';
    }

    /**
     * @param  array<string,mixed>  $projection
     */
    private function projectionReady(array $projection): bool
    {
        return ($projection['exists'] ?? false) === true
            && ($projection['managed'] ?? false) === true
            && ($projection['manual_drift'] ?? false) !== true
            && ($projection['stale'] ?? false) !== true
            && (int) ($projection['memory_count'] ?? 0) > 0;
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function statusDetail(array $summary): string
    {
        if (($summary['ready'] ?? 0) === ($summary['total'] ?? 0) && ($summary['total'] ?? 0) > 0) {
            if (($summary['empty_memory'] ?? 0) > 0) {
                return 'Provider projections gerenciadas, mas sem memoria Atlas provider-safe.';
            }

            return 'Provider projections gerenciadas e atualizadas.';
        }

        $parts = [];
        foreach (['missing' => 'ausente(s)', 'unmanaged' => 'nao gerenciado(s)', 'manual_drift' => 'com drift manual', 'stale' => 'stale', 'empty_memory' => 'sem memoria provider-safe'] as $key => $label) {
            $count = (int) ($summary[$key] ?? 0);
            if ($count > 0) {
                $parts[] = $count.' '.$label;
            }
        }

        return $parts === []
            ? 'Provider projections precisam de revisao.'
            : 'Provider projections precisam de revisao: '.implode(', ', $parts).'.';
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function reviewDetail(array $summary): string
    {
        if (($summary['changed'] ?? 0) === 0 && ($summary['empty_memory'] ?? 0) === 0) {
            return 'Provider projections sem alteracoes pendentes.';
        }

        $parts = [];
        foreach (['create' => 'criacao', 'adopt' => 'adocao', 'update' => 'atualizacao', 'manual_drift' => 'drift manual', 'empty_memory' => 'sem memoria provider-safe'] as $key => $label) {
            $count = (int) ($summary[$key] ?? 0);
            if ($count > 0) {
                $parts[] = $count.' '.$label;
            }
        }

        return 'Provider projection review: '.implode(', ', $parts).'.';
    }

    /**
     * @param  array<int,array<string,mixed>>  $projections
     * @return array<int,string>
     */
    private function reviewNextActions(string $workspace, array $projections): array
    {
        $actions = [];
        $workspaceOption = ' --workspace="'.str_replace('"', '\"', $workspace).'"';

        $applicable = collect($projections)
            ->filter(fn (array $projection): bool => in_array($projection['change_type'] ?? null, ['create', 'update', 'adopt'], true))
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($applicable !== []) {
            $actions[] = 'Aplicar review confirmada: atlas memory projection apply --target='.$this->targetOption($applicable).$workspaceOption.' --yes';
        }

        $emptyMemory = collect($projections)
            ->filter(fn (array $projection): bool => (int) ($projection['memory_count'] ?? 0) < 1)
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($emptyMemory !== []) {
            $actions[] = 'Sem memoria provider-safe: rode atlas memory seed-core --json ou revise memorias em /memory antes de aplicar projection.';
        }

        $manualDrift = collect($projections)
            ->filter(fn (array $projection): bool => ($projection['change_type'] ?? null) === 'manual_drift')
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($manualDrift !== []) {
            $actions[] = 'Resolver drift fora do bloco manual antes de gravar: atlas memory projection inspect --target='.($manualDrift === ['claude', 'agents'] ? 'all' : implode(',', $manualDrift)).$workspaceOption.' --json';
        }

        return AiStringListNormalizer::uniqueStrings($actions);
    }

    /**
     * @param  array<int,array<string,mixed>>  $projections
     * @return array<int,string>
     */
    private function statusNextActions(string $workspace, array $projections): array
    {
        $actions = [];
        $workspaceOption = ' --workspace="'.str_replace('"', '\"', $workspace).'"';

        $missing = collect($projections)
            ->filter(fn (array $projection): bool => ($projection['exists'] ?? false) === false)
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($missing !== []) {
            $actions[] = 'Criar projeções ausentes com review confirmada: atlas memory projection apply --target='.$this->targetOption($missing).$workspaceOption.' --yes';
        }

        $unmanaged = collect($projections)
            ->filter(fn (array $projection): bool => ($projection['exists'] ?? false) === true && ($projection['managed'] ?? false) !== true)
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($unmanaged !== []) {
            $actions[] = 'Adotar arquivos humanos com review confirmada: atlas memory projection apply --target='.$this->targetOption($unmanaged).$workspaceOption.' --yes';
        }

        $stale = collect($projections)
            ->filter(fn (array $projection): bool => ($projection['stale'] ?? false) === true)
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($stale !== []) {
            $actions[] = 'Regenerar projeções stale com review confirmada: atlas memory projection apply --target='.$this->targetOption($stale).$workspaceOption.' --yes';
        }

        $emptyMemory = collect($projections)
            ->filter(fn (array $projection): bool => (int) ($projection['memory_count'] ?? 0) < 1)
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($emptyMemory !== []) {
            $actions[] = 'Sem memoria provider-safe: rode atlas memory seed-core --json ou revise memorias em /memory antes de aplicar projection.';
        }

        $manualDrift = collect($projections)
            ->filter(fn (array $projection): bool => ($projection['manual_drift'] ?? false) === true)
            ->pluck('target')
            ->filter()
            ->values()
            ->all();
        if ($manualDrift !== []) {
            $actions[] = 'Revisar drift fora do bloco manual antes de gravar: atlas memory projection inspect --target='.($manualDrift === ['claude', 'agents'] ? 'all' : implode(',', $manualDrift)).$workspaceOption.' --json';
        }

        return AiStringListNormalizer::uniqueStrings($actions);
    }

    /**
     * @param  array<int,string>  $targets
     */
    private function targetOption(array $targets): string
    {
        $targets = collect($targets)
            ->map(fn (string $target): string => $this->target($target))
            ->unique()
            ->values()
            ->all();

        return count($targets) > 1 ? 'all' : (string) ($targets[0] ?? 'all');
    }

    private function unifiedDiff(string $path, string $current, string $proposed): string
    {
        if ($current === $proposed) {
            return '';
        }

        $currentLines = $current === '' ? [] : preg_split('/\R/', str_replace("\r", '', $current));
        $proposedLines = $proposed === '' ? [] : preg_split('/\R/', str_replace("\r", '', $proposed));
        $currentLines = is_array($currentLines) ? array_map('strval', $currentLines) : [];
        $proposedLines = is_array($proposedLines) ? array_map('strval', $proposedLines) : [];
        $ops = $this->diffOps($currentLines, $proposedLines);
        $lines = [
            '--- '.$path,
            '+++ '.$path.' (Atlas projection)',
            '@@ -1,'.count($currentLines).' +1,'.count($proposedLines).' @@',
        ];

        foreach ($ops as $op) {
            $lines[] = $op[0].$op[1];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,string>  $current
     * @param  array<int,string>  $proposed
     * @return array<int,array{0:string,1:string}>
     */
    private function diffOps(array $current, array $proposed): array
    {
        $oldCount = count($current);
        $newCount = count($proposed);
        $table = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $table[$i][$j] = $current[$i] === $proposed[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $oldCount && $j < $newCount) {
            if ($current[$i] === $proposed[$j]) {
                $ops[] = [' ', $current[$i]];
                $i++;
                $j++;

                continue;
            }

            if ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['-', $current[$i]];
                $i++;
            } else {
                $ops[] = ['+', $proposed[$j]];
                $j++;
            }
        }

        while ($i < $oldCount) {
            $ops[] = ['-', $current[$i]];
            $i++;
        }
        while ($j < $newCount) {
            $ops[] = ['+', $proposed[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return Collection<int,AtlasMemoryEntry>
     */
    private function providerSafeEntries(string $target, array $context, int $limit): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return collect();
        }

        return $this->registry
            ->relevantForContext($context, [
                'types' => [
                    'decision',
                    'preference',
                    'technical_context',
                    'resolution',
                    'harness_learning',
                ],
            ], $limit)
            ->filter(function (AtlasMemoryEntry $entry) use ($target, $context): bool {
                $decision = $this->privacy->providerDecision($entry);
                if ((bool) $decision['allowed']) {
                    return true;
                }

                $this->ledger->recordProviderMemoryBlocked($entry, $decision, $target, [
                    'tenant_id' => $context['tenant_id'] ?? null,
                    'operator_id' => $context['operator_id'] ?? null,
                    'envelope_id' => $context['envelope_id'] ?? null,
                    'receipt_id' => $context['receipt_id'] ?? null,
                    'trace_id' => $context['trace_id'] ?? null,
                    'correlation_id' => $context['correlation_id'] ?? null,
                ]);

                return false;
            })
            ->values();
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $entries
     * @param  array<int,string>  $canonicalMemoryLines
     */
    private function body(string $target, string $workspace, Collection $entries, array $canonicalMemoryLines, int $maxLines, string $manualContent): string
    {
        $title = $target === 'claude' ? 'CLAUDE.md' : 'AGENTS.md';
        $lines = [
            '# '.$title.' generated by Atlas',
            '',
            'Atlas memory is canonical. This file is a short provider projection, not the source of truth.',
            'Regenerate with `atlas memory projection --target='.$target.' --write` after Atlas memory changes.',
            '',
            '## Operating Contract',
            '- Use the Atlas memory registry and Context Pack as canonical context.',
            '- Treat this file as a compact bootstrap for external tools.',
            '- Read `docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md` before trusting Obsidian, Postgres KB, Code Intelligence, provider projections or chat as implementation context.',
            '- Do not expose Atlas internal IDs, traces, prompts or provider details unless the operator asks for audit.',
            '- If context is missing or stale, ask Atlas for fresh context instead of inventing facts.',
            '',
            '## Canonical Knowledge Governance',
            '- Repo docs in `docs/engineering-knowledge-base` are the authoring source of truth.',
            '- Postgres KB and Code Intelligence are read models, not authoring sources.',
            '- Evidence Ledger proves runtime events; Obsidian/AtlasVault is a Human Knowledge Surface.',
            '- This file is generated provider projection; it never overrides canonical docs.',
            '- Before implementation, run or emulate `php artisan atlas:ai:session-bootstrap --task="..." --json` and `php artisan atlas:ai:place-feature "..." --json`.',
            '',
            '## Provider-Safe Memory',
        ];

        $pointers = [
            '',
            '## Atlas Pointers',
            '- Full memory list: `atlas memory:list`',
            '- Runtime search: `atlas search "<query>"`',
            '- Open Brain MCP: `atlas open-brain mcp --describe`',
            '- This projection should stay short; detailed recall belongs in Atlas Context Packs.',
        ];
        $manualSourceLines = preg_split('/\R/', $manualContent !== '' ? $manualContent : 'Keep local provider notes here. Atlas preserves this block and ignores it for checksum drift.') ?: [];
        $manualFrameLines = 4;
        $baseBudget = count($lines) + $manualFrameLines + 1;
        $includePointers = $maxLines - $baseBudget >= count($pointers) + 1;
        $pointerBudget = $includePointers ? count($pointers) : 0;
        $availableMemoryLines = max(1, $maxLines - count($lines) - $manualFrameLines - $pointerBudget - 1);

        $entryLines = $entries
            ->map(fn (AtlasMemoryEntry $entry): string => $this->entryLine($entry, $target))
            ->filter(fn (string $line): bool => $line !== '')
            ->values();
        $entryBudget = $entryLines->isNotEmpty()
            ? max(1, $availableMemoryLines - min(count($canonicalMemoryLines), max(0, $availableMemoryLines - 1)))
            : 0;
        $canonicalBudget = max(0, $availableMemoryLines - $entryBudget);
        $memoryLines = array_merge(
            array_slice($canonicalMemoryLines, 0, $canonicalBudget),
            $entryLines->take($entryBudget)->all(),
        );

        if ($memoryLines === []) {
            $lines[] = '- No provider-safe Atlas memory was available for this workspace.';
        } else {
            $lines = array_merge($lines, array_slice($memoryLines, 0, $availableMemoryLines));
        }

        $manualLines = array_map('strval', $manualSourceLines);

        $lines = array_merge($lines, [
            '',
            '## Manual Notes',
            self::MANUAL_START,
            ...$manualLines,
            self::MANUAL_END,
        ]);

        if ($includePointers) {
            $lines = array_merge($lines, $pointers);
        }

        return implode("\n", $lines);
    }

    private function entryLine(AtlasMemoryEntry $entry, string $target): string
    {
        $title = $this->privacy->providerTitle($entry) ?: $this->privacy->providerSummary($entry) ?: $entry->memory_type;
        $text = $this->privacy->providerSummary($entry) ?: $this->privacy->providerBody($entry);
        $safeText = trim((string) data_get($entry->metadata, 'provider_projection.safe_text', ''));
        $classification = data_get($entry->metadata, 'provider_projection.classification');
        $gate = $this->memoryProjectionSafetyGate->evaluate([
            'summary' => (string) ($entry->summary ?? ''),
            'excerpt' => (string) $entry->body,
            'title' => (string) $title,
            'source' => (string) ($entry->source_type ?: $entry->source_id ?: $entry->id),
            'recorded_at' => (string) ($entry->recorded_at ?? ''),
            'safe_text' => $safeText,
            'classification' => $classification,
        ]);
        if (($gate['accepted'] ?? false) !== true) {
            $this->ledger->recordProviderMemoryBlocked($entry, [
                'allowed' => false,
                'privacy_class' => (string) ($entry->privacy_class ?? 'normal'),
                'external_ai_allowed' => (bool) ($entry->external_ai_allowed ?? true),
                'metadata_external_ai_allowed' => data_get($entry->metadata ?? [], 'privacy.external_ai_allowed'),
                'reason' => 'projection_safety_gate:'.implode(',', (array) ($gate['violations'] ?? [])),
            ], $target, []);

            return '';
        }
        if ($safeText !== '' && ! empty($classification)) {
            $title = 'sanitized:'.$this->classificationLabel($classification);
            $text = $safeText;
        }
        $text = Str::limit(trim($text), $this->projectionInput()->memoryChars(), '...');
        if ($text === '') {
            $text = 'corpo omitido; ref '.$this->memoryRef($entry);
        }

        $scope = $entry->scope_id ? $entry->scope_type : ($entry->scope_type ?: 'global');

        return '- ['.$entry->memory_type.']['.$scope.'] '.Str::limit((string) $title, 80, '').': '.$text;
    }

    private function memoryRef(AtlasMemoryEntry $entry): string
    {
        $hash = trim((string) ($entry->content_hash ?? ''));
        if ($hash === '') {
            $hash = hash('sha256', (string) $entry->id);
        }

        return 'memory:'.substr($hash, 0, 16);
    }

    /**
     * @return array<string,mixed>
     */
    private function redactionReceipt(AtlasMemoryEntry $entry): array
    {
        $summary = (string) ($this->privacy->providerSummary($entry) ?? '');
        $body = (string) $this->privacy->providerBody($entry);
        $verified = data_get($entry->metadata, 'privacy.provider_body_verified') === true
            || data_get($entry->metadata, 'provider_projection.provider_body_verified') === true;
        $classGate = $this->privacy->providerDecision($entry);

        return [
            'schema' => 'atlas.provider_bound_redaction_receipt.v1',
            'memory_ref' => $this->memoryRef($entry),
            'redaction_status' => (string) ($entry->redaction_status ?? 'unknown'),
            'patterns_fired' => (string) ($entry->redaction_status ?? '') === 'redacted' ? ['atlas_security_redaction'] : [],
            'class_gate' => [
                'allowed' => (bool) ($classGate['allowed'] ?? false),
                'privacy_class' => (string) ($classGate['privacy_class'] ?? 'normal'),
                'reason' => (string) ($classGate['reason'] ?? 'unknown'),
            ],
            'verified_by' => match (true) {
                $verified => 'provider_body_verified',
                $summary !== '' || $body !== '' => 'redacted_projection_field',
                default => 'omitted_unverified_raw',
            },
        ];
    }

    private function classificationLabel(mixed $classification): string
    {
        if (is_scalar($classification)) {
            $label = trim((string) $classification);

            return $label !== '' ? Str::limit($label, 80, '') : 'memory_projection';
        }

        return 'memory_projection';
    }

    /**
     * @return array<int,string>
     */
    private function canonicalProviderMemoryLines(): array
    {
        return [
            '- [decision][global] Docs canônicos do repo governam implementação: leia `atlas-ai-knowledge-governance-system.md` antes de confiar em Postgres, Obsidian, projections ou chat.',
            '- [decision][global] Feature nova precisa de placement: rode `php artisan atlas:ai:place-feature "..." --json` antes de criar fluxo, domain, surface, runtime ou AP.',
            '- [decision][global] Sessão nova precisa de bootstrap: rode `php artisan atlas:ai:session-bootstrap --task="..." --json` e leia os owner docs antes de programar.',
            '- [harness_learning][global] Depois de docs/código, sincronize KB e Code Intelligence: `atlas engineering knowledge sync --prune` e `atlas engineering knowledge index-code --prune`.',
        ];
    }

    private function header(string $target, string $generatedAt, string $checksum): string
    {
        return '<!-- '.self::VERSION.' target='.$target.' generated_at='.$generatedAt.' checksum='.$checksum.' -->';
    }

    /**
     * @return array{checksum:string,generated_at:?string,body:string,manual_section_present:bool,manual_content:string}|null
     */
    private function parse(string $content): ?array
    {
        $parts = explode("\n", $content, 2);
        $header = $parts[0] ?? '';
        if (! str_contains($header, self::VERSION)) {
            return null;
        }

        if (preg_match('/checksum=([a-f0-9]{64})/', $header, $checksum) !== 1) {
            return null;
        }

        preg_match('/generated_at=([^ ]+)/', $header, $generatedAt);

        return [
            'checksum' => $checksum[1],
            'generated_at' => $generatedAt[1] ?? null,
            'body' => $parts[1] ?? '',
            'manual_section_present' => $this->extractManual($parts[1] ?? '') !== null,
            'manual_content' => $this->extractManual($parts[1] ?? '') ?? '',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function manualContent(string $path, array $options): string
    {
        if (is_scalar($options['manual_content'] ?? null)) {
            return trim((string) $options['manual_content']);
        }

        if (! is_file($path)) {
            return '';
        }

        $parsed = $this->parse((string) file_get_contents($path));

        return $parsed['manual_content'] ?? '';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function managedAppendix(string $path, array $options): string
    {
        if (is_scalar($options['managed_appendix'] ?? null)) {
            return trim((string) $options['managed_appendix']);
        }

        if (! is_file($path)) {
            return '';
        }

        return $this->extractAobgManagedBlocks((string) file_get_contents($path));
    }

    private function extractManual(string $body): ?string
    {
        $pattern = '/'.preg_quote(self::MANUAL_START, '/')."\n?(.*?)\n?".preg_quote(self::MANUAL_END, '/').'/s';
        if (preg_match($pattern, $body, $matches) !== 1) {
            return null;
        }

        return trim((string) $matches[1]);
    }

    private function extractAobgManagedBlocks(string $content): string
    {
        $pattern = '/'.preg_quote(self::AOBG_MANAGED_START, '/').'.*?'.preg_quote(self::AOBG_MANAGED_END, '/').'/s';
        if (preg_match_all($pattern, $content, $matches) !== false && ($matches[0] ?? []) !== []) {
            return implode("\n\n", AiStringListNormalizer::uniqueTrimmedStrings($matches[0]));
        }

        return '';
    }

    private function manualContentFromUnmanaged(string $content): string
    {
        $content = trim($this->stripAobgManagedBlocks($content));
        $content = preg_replace('/\A#\s+(?:AGENTS|CLAUDE)\.md generated by Atlas AOBG activation\R+/i', '', $content) ?? $content;

        return trim($content);
    }

    private function stripAobgManagedBlocks(string $body): string
    {
        $pattern = '/(?:\R){0,2}'.preg_quote(self::AOBG_MANAGED_START, '/').'(?:\R)?.*?(?:\R)?'.preg_quote(self::AOBG_MANAGED_END, '/').'(?:\R)?/s';

        return preg_replace($pattern, '', $body) ?? $body;
    }

    private function checksum(string $body): string
    {
        $body = $this->stripAobgManagedBlocks($body);
        $pattern = '/'.preg_quote(self::MANUAL_START, '/')."\n?.*?\n?".preg_quote(self::MANUAL_END, '/').'/s';
        $canonical = preg_replace($pattern, self::MANUAL_START."\n".self::MANUAL_END, $body) ?? $body;

        return hash('sha256', rtrim($canonical, "\r\n"));
    }

    private function target(string $target): string
    {
        $target = strtolower(trim($target));

        return in_array($target, ['claude', 'agents'], true) ? $target : 'claude';
    }

    private function filename(string $target): string
    {
        return $target === 'agents' ? 'AGENTS.md' : 'CLAUDE.md';
    }

    private function workspace(mixed $workspace): string
    {
        if (is_scalar($workspace) && trim((string) $workspace) !== '') {
            return rtrim((string) $workspace, DIRECTORY_SEPARATOR);
        }

        return rtrim((string) config('atlas.ai.workdir', dirname(base_path())), DIRECTORY_SEPARATOR);
    }

    private function projectionInput(): ProviderProjectionInput
    {
        return $this->input ?? app(ProviderProjectionInput::class);
    }
}
