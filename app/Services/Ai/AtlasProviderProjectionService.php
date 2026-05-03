<?php

namespace App\Services\Ai;

use App\Models\AtlasMemoryEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasProviderProjectionService
{
    public const VERSION = 'atlas_provider_projection_v1';

    public const MANUAL_START = '<!-- atlas:manual:start -->';

    public const MANUAL_END = '<!-- atlas:manual:end -->';

    public function __construct(
        private readonly AtlasMemoryRegistryService $registry,
        private readonly AtlasMemoryPrivacyService $privacy,
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
        $maxLines = $this->boundedInt($options['max_lines'] ?? config('atlas.ai.provider_projection_max_lines', 80), 20, 240);
        $memoryLimit = $this->boundedInt($options['memory_limit'] ?? config('atlas.ai.provider_projection_memory_limit', 18), 1, 80);
        $entries = $this->providerSafeEntries($context + ['workspace' => $workspace], $memoryLimit);
        $manualContent = $this->manualContent($workspace.DIRECTORY_SEPARATOR.$this->filename($target), $options);
        $body = $this->body($target, $workspace, $entries, max(1, $maxLines - 1), $manualContent);
        $checksum = $this->checksum($body);
        $generatedAt = now()->toJSON();
        $content = $this->header($target, $generatedAt, $checksum)."\n".$body;

        return [
            'target' => $target,
            'filename' => $this->filename($target),
            'path' => $workspace.DIRECTORY_SEPARATOR.$this->filename($target),
            'workspace' => $workspace,
            'version' => self::VERSION,
            'generated_at' => $generatedAt,
            'checksum' => $checksum,
            'line_count' => substr_count($content, "\n") + 1,
            'memory_count' => $entries->count(),
            'content' => $content,
        ];
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
        $manualContent = $parsed['manual_content'] ?? trim($existing);

        return $this->write($target, $context, array_merge($options, [
            'workspace' => $workspace,
            'manual_content' => $manualContent,
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

        return array_values(array_unique($actions));
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

        return array_values(array_unique($actions));
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
    private function providerSafeEntries(array $context, int $limit): Collection
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
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
            ->filter(fn (AtlasMemoryEntry $entry): bool => $this->privacy->providerAllowed($entry))
            ->values();
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $entries
     */
    private function body(string $target, string $workspace, Collection $entries, int $maxLines, string $manualContent): string
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
            '- Do not expose Atlas internal IDs, traces, prompts or provider details unless the operator asks for audit.',
            '- If context is missing or stale, ask Atlas for fresh context instead of inventing facts.',
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

        $memoryLines = [];
        if ($entries->isEmpty()) {
            $memoryLines[] = '- No provider-safe Atlas memory was available for this workspace.';
        }

        foreach ($entries->take($availableMemoryLines) as $entry) {
            $line = $this->entryLine($entry);
            if ($line !== '') {
                $memoryLines[] = $line;
            }
        }
        if ($memoryLines === []) {
            $lines[] = '- No provider-safe Atlas memory was available for this workspace.';
        } else {
            $lines = array_merge($lines, array_slice($memoryLines, 0, $availableMemoryLines));
        }

        $manualContentBudget = max(1, $maxLines - count($lines) - $manualFrameLines - $pointerBudget);
        $manualLines = array_slice(array_map('strval', $manualSourceLines), 0, $manualContentBudget);
        if (count($manualSourceLines) > $manualContentBudget && $manualLines !== []) {
            $manualLines[count($manualLines) - 1] = Str::limit($manualLines[count($manualLines) - 1], 120, '...');
        }

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

        return implode("\n", array_slice($lines, 0, $maxLines));
    }

    private function entryLine(AtlasMemoryEntry $entry): string
    {
        $title = $this->privacy->providerTitle($entry) ?: $this->privacy->providerSummary($entry) ?: $entry->memory_type;
        $text = $this->privacy->providerSummary($entry) ?: $this->privacy->providerBody($entry);
        $text = Str::limit(trim($text), (int) config('atlas.ai.provider_projection_memory_chars', 220), '...');
        if ($text === '') {
            return '';
        }

        $scope = $entry->scope_id ? $entry->scope_type : ($entry->scope_type ?: 'global');

        return '- ['.$entry->memory_type.']['.$scope.'] '.Str::limit((string) $title, 80, '').': '.$text;
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

    private function extractManual(string $body): ?string
    {
        $pattern = '/'.preg_quote(self::MANUAL_START, '/')."\n?(.*?)\n?".preg_quote(self::MANUAL_END, '/').'/s';
        if (preg_match($pattern, $body, $matches) !== 1) {
            return null;
        }

        return trim((string) $matches[1]);
    }

    private function checksum(string $body): string
    {
        $pattern = '/'.preg_quote(self::MANUAL_START, '/')."\n?.*?\n?".preg_quote(self::MANUAL_END, '/').'/s';
        $canonical = preg_replace($pattern, self::MANUAL_START."\n".self::MANUAL_END, $body) ?? $body;

        return hash('sha256', $canonical);
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

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
