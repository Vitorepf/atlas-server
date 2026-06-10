<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Read-only compounding layer for the unified loop backlog.
 *
 * It does not execute providers, run domain scanners, apply feedback, or change source.
 * It ranks what already exists, records human review signals, and exposes provider/domain
 * slots so the operator can decide what to run next with evidence.
 */
final class AtlasLoopIntelligenceOverlay
{
    public const SCHEMA = 'atlas.loop.intelligence_overlay.v1';

    private const FEEDBACK_SCHEMA = 'atlas.loop.review_feedback.v1';

    private const FEEDBACK_FILE = 'review_feedback.jsonl';

    /**
     * @param  array<string,mixed>  $backlog
     * @param  array<string,mixed>  $state
     * @return array<string,mixed>
     */
    public function overlay(string $repoRoot, string $runDir, array $backlog, array $state = []): array
    {
        $flags = $this->flags($backlog['flags'] ?? []);
        $feedback = $this->feedbackRecords($runDir);
        $prioritized = array_map(
            fn (array $flag): array => $this->withPriority($flag, $feedback),
            $flags,
        );
        usort($prioritized, static function (array $a, array $b): int {
            $score = ((int) ($b['impact_score'] ?? 0)) <=> ((int) ($a['impact_score'] ?? 0));

            return $score !== 0 ? $score : strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
        });

        $provider = trim((string) ($state['provider'] ?? '')) ?: trim((string) config('atlas.loop.default_provider', ''));

        return [
            'schema_version' => self::SCHEMA,
            'mode' => 'read_only_priority_and_learning_overlay',
            'repo_root' => rtrim($repoRoot, '/'),
            'run_dir' => rtrim($runDir, '/'),
            'routing_effect' => [
                'changes_provider' => false,
                'changes_source' => false,
                'auto_applies_feedback' => false,
                'auto_executes_cross_domain' => false,
            ],
            'summary' => [
                'flag_count' => count($prioritized),
                'feedback_count' => count($feedback),
                'top_impact_score' => (int) ($prioritized[0]['impact_score'] ?? 0),
                'provider_candidates' => count($this->providerCandidates()),
                'cross_domain_slots' => count($this->crossDomainSlots()),
            ],
            'prioritized_flags' => $prioritized,
            'meta_clusters' => $this->metaClusters($prioritized),
            'learning' => $this->learningSummary($feedback),
            'provider_matrix' => $this->providerMatrix($provider),
            'cross_domain_slots' => $this->crossDomainSlots(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordFeedback(string $runDir, array $input): array
    {
        $runDir = rtrim($runDir, '/');
        if (! is_dir($runDir)) {
            @mkdir($runDir, 0o755, true);
        }

        $path = trim((string) ($input['path'] ?? ''));
        $mode = trim((string) ($input['mode'] ?? ''));
        $action = $this->normalizeAction((string) ($input['action'] ?? ''));
        $record = [
            'schema_version' => self::FEEDBACK_SCHEMA,
            'at' => time(),
            'item' => trim((string) ($input['item'] ?? '')) ?: 'loop-review:'.hash('crc32b', $mode.'|'.$path.'|'.microtime(true)),
            'path' => $path,
            'mode' => $mode,
            'action' => $action,
            'reason' => trim((string) ($input['reason'] ?? '')),
            'operator' => trim((string) ($input['operator'] ?? 'operator')) ?: 'operator',
            'source' => 'operator_review',
        ];

        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $runDir.'/'.self::FEEDBACK_FILE,
            $record,
            JSON_UNESCAPED_SLASHES,
            FILE_APPEND | LOCK_EX,
            0o755,
        );

        $feedback = $this->feedbackRecords($runDir);

        return [
            'schema_version' => self::FEEDBACK_SCHEMA.'.write',
            'status' => 'recorded',
            'run_dir' => $runDir,
            'record' => $record,
            'summary' => $this->learningSummary($feedback),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string,mixed>>
     */
    private function flags(mixed $raw): array
    {
        $flags = [];
        foreach ((array) $raw as $flag) {
            if (is_array($flag)) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @param  list<array<string,mixed>>  $feedback
     * @return array<string,mixed>
     */
    private function withPriority(array $flag, array $feedback): array
    {
        $path = (string) ($flag['path'] ?? '');
        $mode = (string) ($flag['mode'] ?? 'unknown');
        $count = max(1, (int) ($flag['count'] ?? 1));
        $base = $this->baseScore($mode);
        $volume = min(25, $count * 4);
        [$pathScore, $pathFactors] = $this->pathScore($path);
        [$learningScore, $learningFactors] = $this->learningScore($path, $mode, $feedback);
        $score = max(0, min(100, $base + $volume + $pathScore + $learningScore));

        $flag['impact_score'] = $score;
        $flag['priority_factors'] = array_values(array_filter([
            'mode_base='.$base,
            'volume='.$volume,
            ...$pathFactors,
            ...$learningFactors,
        ]));
        $flag['review_contract'] = [
            'requires_human_or_forge_review' => true,
            'auto_apply_allowed' => false,
            'reason' => 'priority_only_overlay',
        ];

        return $flag;
    }

    private function baseScore(string $mode): int
    {
        return match ($mode) {
            'fake_implemented' => 58,
            'coverage_gap' => 54,
            'complexity_hotspot' => 50,
            'code_clone' => 46,
            'doc_drift' => 44,
            'doc_duplicate' => 34,
            'docs_structure' => 28,
            'deadcode' => 24,
            default => 30,
        };
    }

    /**
     * @return array{0:int,1:list<string>}
     */
    private function pathScore(string $path): array
    {
        $lower = strtolower($path);
        $score = 0;
        $factors = [];
        foreach ([
            'security' => 18,
            'auth' => 16,
            'policy' => 14,
            'kernel' => 12,
            'decision' => 12,
            'runtime' => 10,
            'provider' => 10,
            'routes/' => 10,
            'config/' => 8,
            'database/migrations/' => 8,
            'console/commands' => 6,
        ] as $needle => $points) {
            if (str_contains($lower, $needle)) {
                $score += $points;
                $factors[] = 'path:'.$needle.'='.$points;
            }
        }
        if (str_contains($lower, '/archive/')) {
            $score -= 25;
            $factors[] = 'archive=-25';
        }
        if (str_starts_with($lower, 'tests/')) {
            $score -= 6;
            $factors[] = 'tests=-6';
        }

        return [$score, $factors];
    }

    /**
     * @param  list<array<string,mixed>>  $feedback
     * @return array{0:int,1:list<string>}
     */
    private function learningScore(string $path, string $mode, array $feedback): array
    {
        $score = 0;
        $factors = [];
        foreach ($feedback as $record) {
            $samePath = $path !== '' && (string) ($record['path'] ?? '') === $path;
            $sameMode = $mode !== '' && (string) ($record['mode'] ?? '') === $mode;
            if (! $samePath && ! $sameMode) {
                continue;
            }
            $action = (string) ($record['action'] ?? '');
            $weight = in_array($action, ['approved', 'applied'], true) ? 1 : (in_array($action, ['rejected', 'invalid', 'stale'], true) ? -1 : 0);
            if ($weight === 0) {
                continue;
            }
            $delta = $weight * ($samePath && $sameMode ? 18 : 5);
            $score += $delta;
            $factors[] = 'learning:'.$action.'='.$delta;
        }

        return [$score, $factors];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function feedbackRecords(string $runDir): array
    {
        $path = rtrim($runDir, '/').'/'.self::FEEDBACK_FILE;
        if (! is_file($path)) {
            return [];
        }
        $records = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /**
     * @param  list<array<string,mixed>>  $feedback
     * @return array<string,mixed>
     */
    private function learningSummary(array $feedback): array
    {
        $byAction = [];
        $byMode = [];
        foreach ($feedback as $record) {
            $action = (string) ($record['action'] ?? 'unknown');
            $mode = (string) ($record['mode'] ?? 'unknown');
            $byAction[$action] = ($byAction[$action] ?? 0) + 1;
            $byMode[$mode] = ($byMode[$mode] ?? 0) + 1;
        }

        return [
            'schema_version' => 'atlas.loop.learning_feedback_summary.v1',
            'source' => self::FEEDBACK_FILE,
            'feedback_count' => count($feedback),
            'by_action' => $byAction,
            'by_mode' => $byMode,
            'effect' => 'priority_weight_only_no_auto_apply',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerMatrix(string $selectedProvider): array
    {
        $candidates = $this->providerCandidates();

        return [
            'schema_version' => 'atlas.loop.provider_matrix.v1',
            'mode' => 'advisory_no_provider_invocation',
            'selected_provider' => $selectedProvider !== '' ? $selectedProvider : 'atlas_decide',
            'known_count' => count($candidates),
            'auto_allowed_count' => count(array_filter($candidates, static fn (array $p): bool => (bool) ($p['allow_auto'] ?? false))),
            'manual_allowed_count' => count(array_filter($candidates, static fn (array $p): bool => (bool) ($p['allow_manual'] ?? false))),
            'candidates' => array_slice($candidates, 0, 12),
            'routing_control' => [
                'changes_provider' => false,
                'provider_change_requires' => ['human_review', 'policy_update', 'fresh_decision_receipt'],
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function providerCandidates(): array
    {
        $providers = (array) config('atlas.ai.providers', []);
        $rows = [];
        foreach ($providers as $id => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }
            $rows[] = [
                'provider' => (string) $id,
                'enabled' => ! array_key_exists('enabled', $cfg) || (bool) $cfg['enabled'],
                'allow_auto' => (bool) ($cfg['allow_auto'] ?? false),
                'allow_manual' => (bool) ($cfg['allow_manual'] ?? false),
                'model' => (string) ($cfg['model'] ?? $cfg['model_identity'] ?? 'selected-by-atlas-decide'),
                'tier' => (string) ($cfg['model_tier'] ?? 'unknown'),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $auto = ((int) ($b['allow_auto'] ?? false)) <=> ((int) ($a['allow_auto'] ?? false));

            return $auto !== 0 ? $auto : strcmp((string) $a['provider'], (string) $b['provider']);
        });

        return $rows;
    }

    /**
     * @return list<array<string,string|bool>>
     */
    private function crossDomainSlots(): array
    {
        return [
            [
                'domain' => 'engineering_security',
                'verifier' => 'security_scan',
                'command' => 'php artisan atlas:engineering:security-scan --profile=standard --json',
                'auto_execute' => false,
                'route' => 'operator_runs_scanner_then_loop_surfaces_findings',
            ],
            [
                'domain' => 'cyber_security',
                'verifier' => 'cyber_readiness',
                'command' => 'php artisan atlas:ai:cyber-domain --action=readiness --json',
                'auto_execute' => false,
                'route' => 'authorized_defensive_review_only',
            ],
            [
                'domain' => 'finance',
                'verifier' => 'finance_readiness',
                'command' => 'php artisan atlas:ai:finance-domain --action=readiness --json',
                'auto_execute' => false,
                'route' => 'research_or_paper_trading_only',
            ],
            [
                'domain' => 'marketing',
                'verifier' => 'marketing_readiness',
                'command' => 'php artisan atlas:ai:marketing-domain --action=readiness --json',
                'auto_execute' => false,
                'route' => 'approval_required_before_publish_or_spend',
            ],
            [
                'domain' => 'research',
                'verifier' => 'research_readiness',
                'command' => 'php artisan atlas:ai:research-domain --action=readiness --json',
                'auto_execute' => false,
                'route' => 'evidence_attribution_required',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $flags
     * @return list<array<string,mixed>>
     */
    private function metaClusters(array $flags): array
    {
        $clusters = [];
        foreach ($flags as $flag) {
            $mode = (string) ($flag['mode'] ?? 'unknown');
            $path = (string) ($flag['path'] ?? '');
            $dir = $path !== '' ? dirname($path) : '.';
            $key = $mode.'|'.$dir;
            $clusters[$key] ??= [
                'mode' => $mode,
                'path_prefix' => $dir,
                'items' => 0,
                'max_impact_score' => 0,
                'route' => (string) ($flag['route'] ?? 'human_review'),
            ];
            $clusters[$key]['items']++;
            $clusters[$key]['max_impact_score'] = max((int) $clusters[$key]['max_impact_score'], (int) ($flag['impact_score'] ?? 0));
        }
        $rows = array_values($clusters);
        usort($rows, static fn (array $a, array $b): int => ((int) $b['max_impact_score']) <=> ((int) $a['max_impact_score']));

        return array_slice($rows, 0, 20);
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));

        return in_array($action, ['approved', 'applied', 'rejected', 'invalid', 'stale'], true) ? $action : 'approved';
    }
}
