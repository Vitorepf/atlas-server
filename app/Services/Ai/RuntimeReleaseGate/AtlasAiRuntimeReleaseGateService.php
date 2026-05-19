<?php

namespace App\Services\Ai\RuntimeReleaseGate;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Illuminate\Support\Carbon;

/**
 * Atlas AI Runtime Release Gate Aggregator.
 *
 * Single source of truth para "o macro Atlas AI Hyperflow / Runtime Principal
 * está ready?". NÃO refaz nenhum check existente. Delega 100% para
 * {@see AtlasAiRuntimeReadinessService} e adiciona a moldura macro:
 *
 *   - `macro` canônico (`atlas_ai_hyperflow_runtime_principal`)
 *   - `next_macro_recommendation` honesto (sugere TEOS-I2 SOMENTE quando ready)
 *   - certification_hash determinístico próprio (incorpora o hash do readiness)
 *
 * Hard rules (paridade com o briefing):
 *   - status `ready` exige critical-failed=0 e warn-failed=0
 *   - status `partial` quando algo opcional está warn (UX cert, control plane warn)
 *   - status `blocked` quando algo crítico falhou (Product Cert, Hyperflow, Mission)
 *   - NUNCA invoca provider, executa rivals/benchmark, ou cria claim de superioridade
 *   - NUNCA declara ready sem evidência concreta — herda do readiness
 *   - `claim_policy.forbidden_claims` é hardcoded com fences anti-claim
 *   - macro NÃO inclui TEOS-I2 — recomendação só dispara quando ready
 *
 * Output schema: `atlas.ai.runtime_release_gate.v1`
 */
class AtlasAiRuntimeReleaseGateService
{
    public const SCHEMA_VERSION = 'atlas.ai.runtime_release_gate.v1';

    public const MACRO_ID = 'atlas_ai_hyperflow_runtime_principal';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_READY,
        self::STATUS_PARTIAL,
        self::STATUS_BLOCKED,
    ];

    public function __construct(
        private readonly AtlasAiRuntimeReadinessService $readiness,
    ) {}

    /**
     * Build the aggregated macro report. Pure read-only.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $upstream = $this->readiness->report();

        $upstreamStatus = (string) ($upstream['status'] ?? 'blocked');
        $status = $this->mapStatus($upstreamStatus);

        $summary = $this->summary($upstream);
        $checks = (array) ($upstream['checks'] ?? []);
        $blockers = $this->normalizeStringList((array) ($upstream['blockers'] ?? []));
        $warnings = $this->normalizeStringList((array) ($upstream['warnings'] ?? []));
        $evidenceRefs = $this->normalizeStringList((array) ($upstream['evidence_refs'] ?? []));
        $requiredCommands = $this->normalizeStringList(
            array_merge(
                ['php artisan atlas:ai:runtime-release-gate --json'],
                (array) ($upstream['required_commands'] ?? []),
            ),
        );
        $claimPolicy = $this->mergeClaimPolicy((array) ($upstream['claim_policy'] ?? []));
        $nextMacro = $this->nextMacroRecommendation($status, $blockers, $warnings);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'macro' => self::MACRO_ID,
            'summary' => $summary,
            'checks' => $checks,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'evidence_refs' => $evidenceRefs,
            'required_commands' => $requiredCommands,
            'claim_policy' => $claimPolicy,
            'next_macro_recommendation' => $nextMacro,
            'upstream_readiness' => [
                'schema_version' => (string) ($upstream['schema_version'] ?? ''),
                'status' => $upstreamStatus,
                'certification_hash' => (string) ($upstream['certification_hash'] ?? ''),
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * Map upstream readiness status to macro status. Defensive: unknown
     * upstream status maps to blocked (fail-safe).
     */
    private function mapStatus(string $upstream): string
    {
        return match ($upstream) {
            AtlasAiRuntimeReadinessService::STATUS_READY => self::STATUS_READY,
            AtlasAiRuntimeReadinessService::STATUS_PARTIAL => self::STATUS_PARTIAL,
            AtlasAiRuntimeReadinessService::STATUS_BLOCKED => self::STATUS_BLOCKED,
            default => self::STATUS_BLOCKED,
        };
    }

    /**
     * @param  array<string,mixed>  $upstream
     * @return array<string,int>
     */
    private function summary(array $upstream): array
    {
        $upSummary = (array) ($upstream['summary'] ?? []);

        return [
            'total' => (int) ($upSummary['total'] ?? 0),
            'passed' => (int) ($upSummary['passed'] ?? 0),
            'partial' => (int) ($upSummary['partial'] ?? 0),
            'failed' => (int) ($upSummary['failed'] ?? 0),
            'critical_failed' => (int) ($upSummary['critical_failed'] ?? 0),
            'warn_failed' => (int) ($upSummary['warn_failed'] ?? 0),
        ];
    }

    /**
     * @param  array<int|string,mixed>  $list
     * @return list<string>
     */
    private function normalizeStringList(array $list): array
    {
        $clean = [];
        foreach ($list as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $trim = trim($entry);
            if ($trim === '') {
                continue;
            }
            $clean[] = $trim;
        }

        return array_values(array_unique($clean));
    }

    /**
     * Anti-claim fences. Overlays an extra layer above the readiness policy
     * so even if downstream drops a field, the macro keeps its boundary.
     *
     * @param  array<string,mixed>  $upstream
     * @return array<string,mixed>
     */
    private function mergeClaimPolicy(array $upstream): array
    {
        $base = [
            'declares_benchmark' => false,
            'declares_rivals' => false,
            'declares_superiority' => false,
            'declares_teos_certification' => false,
            'invokes_provider' => false,
            'reads_external_apis' => false,
            'mutates_persistent_state' => false,
            'scope' => 'atlas_ai_hyperflow_runtime_principal_release_gate',
            'forbidden_claims' => [
                'better_than_claude_code',
                'better_than_codex',
                'better_than_cursor',
                'beats_benchmark_x',
                'wins_arena_y',
                'teos_certified_unless_explicitly_proven',
                'external_rivals_certified',
                'production_grade_unless_evidence_proven',
            ],
        ];

        foreach ($upstream as $key => $value) {
            if ($key === 'scope') {
                continue; // macro owns its scope label
            }
            if ($key === 'forbidden_claims') {
                $merged = array_values(array_unique(array_merge(
                    (array) ($base['forbidden_claims'] ?? []),
                    array_values((array) $value),
                )));
                $base['forbidden_claims'] = $merged;

                continue;
            }
            // Boolean fences: only override if upstream is true (strictly tighter).
            if (is_bool($value) && $value === true) {
                $base[$key] = true;

                continue;
            }
            if (! array_key_exists($key, $base)) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Honest next-macro recommendation. Sugere TEOS-I2 SÓ quando ready,
     * porque o briefing explicitamente diz que TEOS não entra neste macro.
     *
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function nextMacroRecommendation(string $status, array $blockers, array $warnings): array
    {
        return match ($status) {
            self::STATUS_READY => [
                'next_macro' => 'atlas_teos_i2_macro',
                'reason' => 'macro ready com evidências; safe para abrir TEOS-I2 em macro separado',
                'preconditions' => [
                    'open_teos_i2_in_a_separate_macro_certification',
                    'do_not_run_external_rivals_inside_this_macro',
                ],
                'forbidden_jumps' => [
                    'declare_external_rivals_certified_inside_this_macro',
                    'declare_teos_certified_without_separate_macro',
                ],
            ],
            self::STATUS_PARTIAL => [
                'next_macro' => 'stay_in_atlas_ai_hyperflow_runtime_principal',
                'reason' => 'macro com warnings; fechar gaps antes de abrir TEOS-I2',
                'gap_actions' => array_values(array_unique(array_merge($warnings, [
                    'audit_partial_checks',
                    'attach_evidence_to_partial_components',
                ]))),
                'forbidden_jumps' => [
                    'declare_macro_ready_with_warnings',
                    'open_teos_i2_before_closing_warnings',
                ],
            ],
            default => [
                'next_macro' => 'stay_in_atlas_ai_hyperflow_runtime_principal',
                'reason' => 'macro com blockers críticos; resolver antes de qualquer próximo passo',
                'blockers' => $blockers,
                'forbidden_jumps' => [
                    'declare_macro_ready_with_blockers',
                    'open_teos_i2_with_blocked_macro',
                    'declare_external_rivals_certified',
                ],
            ],
        };
    }
}
