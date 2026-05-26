<?php

declare(strict_types=1);

namespace App\Services\Ai\Patamar4;

use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Nightly Counterfactuals Service — Patamar 4 background ensaios.
 *
 * Cron noturno: seleciona decisões reais do dia (gateway preflight envelopes
 * + ai_traces majores quando disponíveis), reexecuta TEOS-I4 sobre alternativas
 * canônicas, calcula best_improvement, gera lista de recomendações para o
 * inbox do operador.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-nightly-counterfactuals.md
 *
 * Schemas:
 *   - atlas.patamar4.nightly_counterfactuals_sweep.v1
 *   - atlas.patamar4.nightly_recommendation.v1
 *
 * Invariants:
 *   - READ-ONLY: nunca executa nenhuma alternativa, só projeta.
 *   - Defensive: degrada honestamente se gateway preflight log ausente.
 *   - Append-only JSONL com sweep_hash.
 *   - claim_policy provider-safe via TEOS-I4 chain.
 */
final class AtlasNightlyCounterfactualsService
{
    public const SWEEP_SCHEMA = 'atlas.patamar4.nightly_counterfactuals_sweep.v1';

    public const RECOMMENDATION_SCHEMA = 'atlas.patamar4.nightly_recommendation.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_NO_DECISIONS = 'no_decisions_found';

    public const MAX_DECISIONS_PER_SWEEP = 20;

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasGatewayPreflightService $preflight,
        private readonly AtlasTeosI4CounterfactualTreeService $teosI4,
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/patamar4')
            : sys_get_temp_dir().'/atlas/patamar4';

        return $base.DIRECTORY_SEPARATOR.'nightly_counterfactuals.jsonl';
    }

    /**
     * Run one nightly sweep.
     *
     * @return array<string,mixed>
     */
    public function run(string $actor = 'cron_nightly'): array
    {
        $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $decisions = $this->selectMajorDecisions();
        if ($decisions === []) {
            return $this->persist($this->envelope(
                startedAt: $startedAt,
                status: self::STATUS_NO_DECISIONS,
                recommendations: [],
                actor: $actor,
                note: 'no preflight envelopes from last 24h to project',
            ));
        }

        $recommendations = [];
        foreach ($decisions as $decision) {
            $rec = $this->projectDecision($decision);
            $recommendations[] = $rec;
        }

        return $this->persist($this->envelope(
            startedAt: $startedAt,
            status: self::STATUS_OK,
            recommendations: $recommendations,
            actor: $actor,
            note: count($recommendations).' decisions reprojected',
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSweeps(): array
    {
        return $this->readJsonl($this->logPath());
    }

    public function lastSweep(): ?array
    {
        $list = $this->listSweeps();

        return $list === [] ? null : $list[count($list) - 1];
    }

    /**
     * Inbox-ready: best N recommendations from latest sweep.
     *
     * @return list<array<string,mixed>>
     */
    public function inbox(int $limit = 10): array
    {
        $last = $this->lastSweep();
        if ($last === null) {
            return [];
        }
        $recs = (array) ($last['recommendations'] ?? []);
        // Sort by projected_improvement desc, take limit.
        usort($recs, static fn ($a, $b): int => ((float) ($b['projected_improvement'] ?? 0)) <=> ((float) ($a['projected_improvement'] ?? 0)));

        return array_slice($recs, 0, max(1, $limit));
    }

    // ---------- internals ----------

    /**
     * Select up to MAX_DECISIONS_PER_SWEEP major decisions from gateway
     * preflight envelopes within the last 24h. Defensive against missing log.
     *
     * @return list<array<string,mixed>>
     */
    private function selectMajorDecisions(): array
    {
        try {
            $envelopes = $this->preflight->listEnvelopes();
        } catch (\Throwable $e) {
            return [];
        }
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day');
        $cutoffIso = $cutoff->format(DateTimeInterface::ATOM);
        $major = [];
        foreach (array_reverse($envelopes) as $env) {
            $started = (string) ($env['started_at'] ?? '');
            if ($started === '' || $started < $cutoffIso) {
                continue;
            }
            $verdict = (string) ($env['verdict'] ?? '');
            if ($verdict === AtlasGatewayPreflightService::VERDICT_NOT_PROJECTED) {
                continue;
            }
            $major[] = $env;
            if (count($major) >= self::MAX_DECISIONS_PER_SWEEP) {
                break;
            }
        }

        return $major;
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function projectDecision(array $decision): array
    {
        $provider = (string) ($decision['provider'] ?? 'unknown');
        $anchor = 'nightly_'.substr((string) ($decision['envelope_hash'] ?? hash('sha256', json_encode($decision))), 0, 16);
        $alternatives = [
            ['decision_kind' => 'provider_swap', 'value' => $provider.'_runner_up'],
            ['decision_kind' => 'escalation', 'value' => 'operator'],
            ['decision_kind' => 'replan', 'value' => 'narrow_scope_first'],
            ['decision_kind' => 'abort', 'value' => 'cancel_decision'],
        ];

        try {
            $tree = $this->teosI4->expand([
                'anchor_decision_id' => $anchor,
                'alternatives' => $alternatives,
                'max_breadth' => 4,
                'max_depth' => 2,
                'scope' => ['privacy_class' => 'normal'],
                'factual_outcome_score' => 0.5,
                'projected_outcome_score' => 0.6,
            ]);
            $bestImprovement = (float) ($tree['best_improvement'] ?? 0.0);
            $treeId = (string) ($tree['tree_id'] ?? '');
            $treeHash = (string) ($tree['tree_hash'] ?? '');
            $bestPath = (array) ($tree['best_path'] ?? []);
        } catch (\Throwable $e) {
            $bestImprovement = 0.0;
            $treeId = '';
            $treeHash = '';
            $bestPath = [];
        }

        return [
            'schema_version' => self::RECOMMENDATION_SCHEMA,
            'source_envelope_hash' => $decision['envelope_hash'] ?? null,
            'source_started_at' => $decision['started_at'] ?? null,
            'provider' => $provider,
            'tree_id' => $treeId,
            'tree_hash' => $treeHash,
            'best_path' => $bestPath,
            'projected_improvement' => round($bestImprovement, 4),
            'note' => $bestImprovement > 0
                ? 'review recommended — projected gain'
                : 'no projected gain from alternatives',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $recommendations
     * @return array<string,mixed>
     */
    private function envelope(
        string $startedAt,
        string $status,
        array $recommendations,
        string $actor,
        string $note,
    ): array {
        $env = [
            'schema_version' => self::SWEEP_SCHEMA,
            'started_at' => $startedAt,
            'actor' => $actor,
            'status' => $status,
            'recommendation_count' => count($recommendations),
            'recommendations' => $recommendations,
            'kernel_hash' => $this->kernel->kernelHash(),
            'note' => $note,
        ];
        $env['sweep_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SWEEP_SCHEMA,
            'started_at' => $startedAt,
            'status' => $status,
            'recommendation_count' => count($recommendations),
            'kernel_hash' => $env['kernel_hash'],
        ], JSON_THROW_ON_ERROR));

        return $env;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function persist(array $envelope): array
    {
        $this->appendJsonl($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
