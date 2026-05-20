<?php

declare(strict_types=1);

namespace App\Services\Ai\ConversationOps;

use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;

final class AtlasConversationOperationsService
{
    public const HEALTH_SCHEMA_VERSION = 'atlas.conversation_ops.health_report.v1';

    public const HANDOFF_SCHEMA_VERSION = 'atlas.conversation_ops.handoff_packet.v1';

    public const RETURN_AUDIT_SCHEMA_VERSION = 'atlas.conversation_ops.subagent_return_audit.v1';

    public const JANITOR_SCHEMA_VERSION = 'atlas.conversation_ops.context_janitor_receipt.v1';

    public const MEMORY_CURATOR_SCHEMA_VERSION = 'atlas.conversation_ops.memory_curator_receipt.v1';

    public const COMPRESSION_CRITIC_SCHEMA_VERSION = 'atlas.conversation_ops.compression_critic_report.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasContextIntelligenceService $contextIntelligence,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function healthReport(array $input): array
    {
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();
        $turns = array_values((array) ($input['turns'] ?? []));
        $goal = trim((string) ($input['goal'] ?? ''));
        $decisions = $this->extractSignals($turns, ['decid', 'decision', 'decisão', 'decisao', 'vamos fazer', 'canon']);
        $blockers = $this->extractSignals($turns, ['blocker', 'bloque', 'falha', 'erro', 'pendente', 'não funciona', 'nao funciona']);
        $noise = $this->extractSignals($turns, ['talvez', 'acho', 'rascunho', 'hipótese', 'hipotese']);
        $goalDrift = $this->goalDrift($goal, $turns);
        $handoffNeed = count($turns) >= 12 || $blockers !== [] || $goalDrift !== [];
        $status = $blockers !== [] || $goalDrift !== [] ? self::STATUS_WATCH : self::STATUS_HEALTHY;

        if ($goal !== '' && $turns === []) {
            $status = self::STATUS_BLOCKED;
            $blockers[] = [
                'turn_index' => -1,
                'excerpt' => 'goal_without_turns',
                'reason' => 'goal exists but no conversation turns were provided',
            ];
        }

        $context = $this->contextIntelligence->assess([
            'prompt' => $goal !== '' ? $goal : 'conversation operations health report',
            'task_type' => 'conversation_ops',
            'domain' => (string) ($input['domain'] ?? 'atlas'),
            'risk_level' => $handoffNeed ? 'medium' : 'low',
            'context_refs' => array_values((array) ($input['context_refs'] ?? [])),
            'recent_turns' => array_slice($turns, -8),
            'must_keep_items' => array_values(array_merge(
                array_map(static fn (array $signal): array => ['kind' => 'decision', 'digest' => $signal['excerpt']], $decisions),
                array_map(static fn (array $signal): array => ['kind' => 'blocker', 'digest' => $signal['excerpt']], $blockers),
            )),
        ]);

        $payload = [
            'schema_version' => self::HEALTH_SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => $now->toJSON(),
            'summary' => [
                'turn_count' => count($turns),
                'decision_count' => count($decisions),
                'blocker_count' => count($blockers),
                'noise_count' => count($noise),
                'goal_drift_count' => count($goalDrift),
                'handoff_recommended' => $handoffNeed,
            ],
            'goal_guardian' => [
                'goal_hash' => $goal !== '' ? MissionCanonicalHash::sha256(['goal' => $goal]) : null,
                'drift' => $goalDrift,
            ],
            'context_janitor' => [
                'noise' => $noise,
                'recommended_action' => $noise !== [] ? 'compress_or_exclude_low_signal_turns' : 'none',
            ],
            'decision_ledger' => [
                'decisions' => $decisions,
                'blockers' => $blockers,
            ],
            'context_intelligence' => [
                'status' => $context['status'] ?? 'unknown',
                'context_certification_hash' => $context['context_certification_hash'] ?? null,
                'blockers' => $context['blockers'] ?? [],
            ],
            'next_action' => $this->nextAction($status, $handoffNeed),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
            ],
            'writes' => false,
        ];
        $payload['conversation_health_hash'] = $this->hash($payload, 'conversation_health_hash');

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function handoffPacket(array $input): array
    {
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();
        $role = $this->nonEmpty((string) ($input['role'] ?? ''), 'explorer');
        $task = $this->nonEmpty((string) ($input['task'] ?? ''), 'investigate bounded subtask');
        $allowedScope = array_values(array_filter((array) ($input['allowed_scope'] ?? []), 'is_string'));
        $evidenceRefs = array_values(array_filter((array) ($input['evidence_refs'] ?? []), 'is_string'));

        $payload = [
            'schema_version' => self::HANDOFF_SCHEMA_VERSION,
            'generated_at' => $now->toJSON(),
            'role' => $role,
            'task' => $task,
            'allowed_scope' => $allowedScope,
            'forbidden_actions' => array_values(array_unique(array_merge([
                'run_provider',
                'run_benchmark',
                'edit_files_outside_scope',
                'declare_completion_without_evidence',
            ], array_filter((array) ($input['forbidden_actions'] ?? []), 'is_string')))),
            'must_return' => array_values(array_unique(array_merge([
                'files_read',
                'findings',
                'evidence_refs',
                'gaps',
                'confidence',
            ], array_filter((array) ($input['must_return'] ?? []), 'is_string')))),
            'context_budget' => $this->nonEmpty((string) ($input['context_budget'] ?? ''), 'small'),
            'ttl_minutes' => max(5, min(240, (int) ($input['ttl_minutes'] ?? 30))),
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
            ],
        ];
        $payload['handoff_packet_hash'] = $this->hash($payload, 'handoff_packet_hash');

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $returnPacket
     * @return array<string,mixed>
     */
    public function auditSubagentReturn(array $returnPacket): array
    {
        $evidenceRefs = array_values(array_filter((array) ($returnPacket['evidence_refs'] ?? []), 'is_string'));
        $findings = array_values((array) ($returnPacket['findings'] ?? []));
        $status = $evidenceRefs === [] || $findings === [] ? self::STATUS_BLOCKED : self::STATUS_HEALTHY;
        $payload = [
            'schema_version' => self::RETURN_AUDIT_SCHEMA_VERSION,
            'status' => $status,
            'checks' => [
                ['id' => 'has_findings', 'status' => $findings !== [] ? 'pass' : 'fail'],
                ['id' => 'has_evidence_refs', 'status' => $evidenceRefs !== [] ? 'pass' : 'fail'],
            ],
            'integration_allowed' => $status === self::STATUS_HEALTHY,
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
            ],
        ];
        $payload['subagent_return_audit_hash'] = $this->hash($payload, 'subagent_return_audit_hash');

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function contextJanitorReceipt(array $input): array
    {
        $turns = array_values((array) ($input['turns'] ?? []));
        $noise = $this->extractSignals($turns, ['talvez', 'acho', 'rascunho', 'hipótese', 'hipotese', 'ignore isso']);
        $mustKeep = $this->extractSignals($turns, ['decid', 'blocker', 'bloque', 'evidencia', 'evidence', 'critico', 'crítico']);
        $payload = [
            'schema_version' => self::JANITOR_SCHEMA_VERSION,
            'status' => self::STATUS_HEALTHY,
            'turn_count' => count($turns),
            'noise_candidates' => $noise,
            'must_keep_candidates' => $mustKeep,
            'removal_allowed' => false,
            'recommended_action' => $noise !== [] ? 'exclude_noise_from_next_context_pack' : 'none',
            'claim_policy' => [
                'benchmark_not_run' => true,
                'provider_calls_made' => false,
            ],
        ];
        $payload['context_janitor_hash'] = $this->hash($payload, 'context_janitor_hash');

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function memoryCuratorReceipt(array $input): array
    {
        $decisions = array_values((array) ($input['decisions'] ?? []));
        $blockers = array_values((array) ($input['blockers'] ?? []));
        $candidates = array_values(array_map(
            static fn (mixed $item): array => [
                'kind' => is_array($item) ? (string) ($item['kind'] ?? 'memory_candidate') : 'memory_candidate',
                'digest_hash' => MissionCanonicalHash::sha256(['value' => is_scalar($item) ? (string) $item : $item]),
                'requires_human_review' => true,
            ],
            array_merge($decisions, $blockers),
        ));

        $payload = [
            'schema_version' => self::MEMORY_CURATOR_SCHEMA_VERSION,
            'status' => self::STATUS_HEALTHY,
            'candidate_count' => count($candidates),
            'promotion_allowed' => false,
            'reason' => 'ACOL can propose memory candidates but durable memory promotion remains governed.',
            'candidates' => $candidates,
            'claim_policy' => [
                'benchmark_not_run' => true,
                'provider_calls_made' => false,
            ],
        ];
        $payload['memory_curator_hash'] = $this->hash($payload, 'memory_curator_hash');

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compressionCriticReport(array $input): array
    {
        $mustKeepCoverage = (float) ($input['must_keep_coverage'] ?? 0.0);
        $missingMustKeep = array_values((array) ($input['missing_must_keep_ids'] ?? []));
        $status = $mustKeepCoverage >= 1.0 && $missingMustKeep === [] ? self::STATUS_HEALTHY : self::STATUS_BLOCKED;
        $payload = [
            'schema_version' => self::COMPRESSION_CRITIC_SCHEMA_VERSION,
            'status' => $status,
            'must_keep_coverage' => $mustKeepCoverage,
            'missing_must_keep_ids' => $missingMustKeep,
            'integration_allowed' => $status === self::STATUS_HEALTHY,
            'blockers' => $status === self::STATUS_HEALTHY ? [] : [[
                'id' => 'compression_lost_must_keep',
                'reason' => 'critical compaction cannot be integrated until must_keep coverage is 1.0',
            ]],
            'claim_policy' => [
                'benchmark_not_run' => true,
                'provider_calls_made' => false,
            ],
        ];
        $payload['compression_critic_hash'] = $this->hash($payload, 'compression_critic_hash');

        return $payload;
    }

    /**
     * @param  list<mixed>  $turns
     * @param  list<string>  $needles
     * @return list<array<string,mixed>>
     */
    private function extractSignals(array $turns, array $needles): array
    {
        $signals = [];
        foreach ($turns as $index => $turn) {
            $text = mb_strtolower($this->turnText($turn));
            foreach ($needles as $needle) {
                if (str_contains($text, mb_strtolower($needle))) {
                    $signals[] = [
                        'turn_index' => $index,
                        'excerpt' => mb_substr($this->turnText($turn), 0, 220),
                        'reason' => 'matched:'.$needle,
                    ];
                    break;
                }
            }
        }

        return $signals;
    }

    /**
     * @param  list<mixed>  $turns
     * @return list<array<string,string>>
     */
    private function goalDrift(string $goal, array $turns): array
    {
        if ($goal === '' || $turns === []) {
            return [];
        }

        $last = mb_strtolower($this->turnText(end($turns)));
        $goalWords = array_values(array_filter(preg_split('/\s+/', mb_strtolower($goal)) ?: [], static fn (string $word): bool => mb_strlen($word) >= 5));
        $matches = 0;
        foreach (array_slice($goalWords, 0, 12) as $word) {
            if (str_contains($last, $word)) {
                $matches++;
            }
        }

        if ($goalWords !== [] && $matches === 0 && mb_strlen($last) > 80) {
            return [[
                'reason' => 'latest_turn_has_no_overlap_with_goal_keywords',
                'latest_turn_hash' => MissionCanonicalHash::sha256(['turn' => $last]),
            ]];
        }

        return [];
    }

    private function turnText(mixed $turn): string
    {
        if (is_array($turn)) {
            return (string) ($turn['content'] ?? $turn['text'] ?? json_encode($turn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return is_scalar($turn) ? (string) $turn : '';
    }

    private function nextAction(string $status, bool $handoffNeed): string
    {
        if ($status === self::STATUS_BLOCKED) {
            return 'ask_human_or_restore_missing_context';
        }

        if ($handoffNeed) {
            return 'prepare_handoff_or_compact_context';
        }

        return 'continue_main_flow';
    }

    private function nonEmpty(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload, string $key): string
    {
        unset($payload['generated_at'], $payload[$key]);

        return MissionCanonicalHash::sha256($payload);
    }
}
