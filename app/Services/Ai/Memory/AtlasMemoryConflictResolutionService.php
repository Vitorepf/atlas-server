<?php

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Atlas Cognition Operating System — Absorcao 2 (Conflict Verbs).
 *
 * Schema canon: `atlas.memory.relation_verdict.v1`.
 * Doc canon: `atlas-cognition-operating-system.md`.
 * Doc absorcao: `atlas-external-memory-pattern-absorptions-v1.md` (Absorcao 2).
 *
 * Responsabilidades:
 *  - Validar e persistir relacoes semanticas entre `atlas_memory_entries`
 *    usando os SEIS VERBOS CANONICOS de conflito (dissecacao engram):
 *      related | compatible | scoped | conflicts_with | supersedes | not_conflict
 *  - Aplicar heuristica "ask vs silent" para decidir escalation humana antes
 *    de promover verdicts em memorias de alto risco (decision/architecture/policy).
 *  - Reverse lookup para search composer:
 *      - `relatedConflicts(memoryId)` retorna pares com flag de display
 *      - `latestVerdict(sourceId, targetId)` retorna verdict canonico atual
 *
 * Phase 1 (esta versao):
 *  - judge() persiste verdict via `atlas_memory_entry_relations`.
 *  - validateVerdict() enforce enum dos seis verbos.
 *  - shouldEscalate() implementa heuristica engram.
 *  - Multi-actor disagreement ainda nao habilitado (UNIQUE constraint da
 *    tabela bloqueia). Phase 2 droppa UNIQUE.
 *
 * Phase 2 (proximo AP):
 *  - Drop UNIQUE em atlas_memory_entry_relations para suportar multi-actor.
 *  - Composer integration: ACRS suprime memoria superseded em display.
 *  - CLI `atlas:memory:judge` com modos plan/dry-run/apply (Absorcao 3).
 *  - Integracao com TEOS-I2 causal_graph_lite edge_kind enum.
 */
class AtlasMemoryConflictResolutionService
{
    /**
     * Os seis verbos canonicos. Ordem importa para humanidade da exibicao em search.
     */
    public const VERDICT_RELATED = 'related';
    public const VERDICT_COMPATIBLE = 'compatible';
    public const VERDICT_SCOPED = 'scoped';
    public const VERDICT_CONFLICTS_WITH = 'conflicts_with';
    public const VERDICT_SUPERSEDES = 'supersedes';
    public const VERDICT_NOT_CONFLICT = 'not_conflict';

    public const ALLOWED_VERDICTS = [
        self::VERDICT_RELATED,
        self::VERDICT_COMPATIBLE,
        self::VERDICT_SCOPED,
        self::VERDICT_CONFLICTS_WITH,
        self::VERDICT_SUPERSEDES,
        self::VERDICT_NOT_CONFLICT,
    ];

    /**
     * Verdicts que mudam display em search.
     */
    public const VISIBLE_VERDICTS = [
        self::VERDICT_CONFLICTS_WITH,
        self::VERDICT_SUPERSEDES,
    ];

    /**
     * Memory types que disparam escalation humana em verdicts visiveis.
     */
    public const HIGH_RISK_MEMORY_TYPES = [
        'decision',
        'architecture',
        'policy',
    ];

    /**
     * Actor types canonicos.
     */
    public const ACTOR_AGENT = 'agent';
    public const ACTOR_ATLAS = 'atlas';
    public const ACTOR_HUMAN = 'human';
    public const ACTOR_ENGRAM = 'engram';
    public const ACTOR_UNKNOWN = 'unknown';

    public const ALLOWED_ACTORS = [
        self::ACTOR_AGENT,
        self::ACTOR_ATLAS,
        self::ACTOR_HUMAN,
        self::ACTOR_ENGRAM,
        self::ACTOR_UNKNOWN,
    ];

    /**
     * Judgment status canonicos.
     */
    public const JUDGMENT_PENDING = 'pending';
    public const JUDGMENT_JUDGED = 'judged';
    public const JUDGMENT_ORPHANED = 'orphaned';
    public const JUDGMENT_IGNORED = 'ignored';

    public const ALLOWED_JUDGMENT_STATUS = [
        self::JUDGMENT_PENDING,
        self::JUDGMENT_JUDGED,
        self::JUDGMENT_ORPHANED,
        self::JUDGMENT_IGNORED,
    ];

    /**
     * Persiste verdict entre dois memory entries.
     *
     * @param  array<string,mixed>  $options  Opcoes adicionais:
     *   - actor: string (default unknown)
     *   - model: string|null
     *   - confidence: float|null (0.0-1.0)
     *   - reason: string|null
     *   - evidence_refs: array
     *   - judgment_status: string (default judged)
     *   - allow_escalation_bypass: bool (default false; em fluxos automaticos, true escala mesmo)
     *
     * @return array{
     *   ok:bool,
     *   status:string,
     *   verdict:string,
     *   relation_id:string|null,
     *   should_escalate:bool,
     *   escalation_reasons:array<int,string>,
     *   schema_version:string,
     *   reason:string|null
     * }
     */
    public function judge(string $sourceId, string $targetId, string $verdict, array $options = []): array
    {
        if ($sourceId === '' || $targetId === '') {
            return $this->envelope(false, 'invalid_ids', $verdict, null, false, ['empty_id']);
        }

        if ($sourceId === $targetId) {
            return $this->envelope(false, 'self_reference', $verdict, null, false, ['source_equals_target']);
        }

        if (! self::isValidVerdict($verdict)) {
            return $this->envelope(false, 'invalid_verdict', $verdict, null, false, [
                'verdict_not_in_canon_six',
            ]);
        }

        $actor = (string) ($options['actor'] ?? self::ACTOR_UNKNOWN);
        if (! in_array($actor, self::ALLOWED_ACTORS, true)) {
            $actor = self::ACTOR_UNKNOWN;
        }

        $confidence = $this->normalizeConfidence($options['confidence'] ?? null);
        $judgmentStatus = $this->normalizeJudgmentStatus($options['judgment_status'] ?? self::JUDGMENT_JUDGED);
        $reason = $this->normalizeReason($options['reason'] ?? null);
        $model = $this->normalizeString($options['model'] ?? null);
        $evidenceRefs = is_array($options['evidence_refs'] ?? null) ? $options['evidence_refs'] : [];
        $allowBypass = (bool) ($options['allow_escalation_bypass'] ?? false);

        $sourceEntry = AtlasMemoryEntry::query()->find($sourceId);
        $targetEntry = AtlasMemoryEntry::query()->find($targetId);

        if ($sourceEntry === null || $targetEntry === null) {
            return $this->envelope(false, 'memory_not_found', $verdict, null, false, ['source_or_target_missing']);
        }

        $escalation = $this->shouldEscalate($verdict, $confidence, [
            $sourceEntry->memory_type,
            $targetEntry->memory_type,
        ]);

        if ($escalation['required'] && ! $allowBypass && $actor !== self::ACTOR_HUMAN) {
            return $this->envelope(
                ok: false,
                status: 'requires_human_escalation',
                verdict: $verdict,
                relationId: null,
                shouldEscalate: true,
                escalationReasons: $escalation['reasons'],
                reason: 'Verdict de alto risco requer escalation humana; agent nao pode promover diretamente.',
            );
        }

        if (! Schema::hasTable('atlas_memory_entry_relations')) {
            return $this->envelope(
                ok: false,
                status: 'table_missing',
                verdict: $verdict,
                relationId: null,
                shouldEscalate: $escalation['required'],
                escalationReasons: $escalation['reasons'],
            );
        }

        // not_conflict e auditoria pura; nao insere row salvo se actor humano pedir.
        // Convencao engram: not_conflict so persiste se explicitamente forcado.
        $forceInsert = (bool) ($options['force_not_conflict_persist'] ?? false);
        if ($verdict === self::VERDICT_NOT_CONFLICT && $actor !== self::ACTOR_HUMAN && ! $forceInsert) {
            return $this->envelope(
                ok: true,
                status: 'audited_no_insert',
                verdict: $verdict,
                relationId: null,
                shouldEscalate: false,
                escalationReasons: [],
                reason: 'not_conflict auditado sem persistir row.',
            );
        }

        $relationId = (string) Str::uuid();
        $now = now();

        $payload = [
            'id' => $relationId,
            'source_memory_entry_id' => $sourceId,
            'target_memory_entry_id' => $targetId,
            'relation_type' => $verdict,
            'status' => $judgmentStatus === self::JUDGMENT_PENDING ? 'open' : 'closed',
            'confidence' => $confidence,
            'reason' => $reason,
            'metadata' => json_encode([
                'absorption' => 'engram_conflict_verbs',
                'verdict_schema_version' => 'atlas.memory.relation_verdict.v1',
                'options' => array_filter([
                    'allow_escalation_bypass' => $allowBypass ?: null,
                ], fn ($v) => $v !== null),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'marked_by_actor' => $actor,
            'marked_by_model' => $model,
            'judgment_status' => $judgmentStatus,
            'evidence_refs' => $evidenceRefs === [] ? null : json_encode($evidenceRefs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'verdict_schema_version' => 'atlas.memory.relation_verdict.v1',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            // Phase 1: UNIQUE(source, target, relation_type) ainda existe.
            // Se ja existir registro com mesmo trio, atualizamos os campos novos
            // (in-place upsert) preservando o id original quando possivel.
            $existing = DB::table('atlas_memory_entry_relations')
                ->where('source_memory_entry_id', $sourceId)
                ->where('target_memory_entry_id', $targetId)
                ->where('relation_type', $verdict)
                ->first();

            if ($existing !== null) {
                $relationId = (string) $existing->id;
                DB::table('atlas_memory_entry_relations')
                    ->where('id', $relationId)
                    ->update([
                        'status' => $payload['status'],
                        'confidence' => $payload['confidence'],
                        'reason' => $payload['reason'],
                        'metadata' => $payload['metadata'],
                        'marked_by_actor' => $payload['marked_by_actor'],
                        'marked_by_model' => $payload['marked_by_model'],
                        'judgment_status' => $payload['judgment_status'],
                        'evidence_refs' => $payload['evidence_refs'],
                        'verdict_schema_version' => $payload['verdict_schema_version'],
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('atlas_memory_entry_relations')->insert($payload);
            }

            return $this->envelope(
                ok: true,
                status: $existing !== null ? 'verdict_updated' : 'verdict_recorded',
                verdict: $verdict,
                relationId: $relationId,
                shouldEscalate: $escalation['required'],
                escalationReasons: $escalation['reasons'],
            );
        } catch (\Throwable $e) {
            return $this->envelope(
                ok: false,
                status: 'persist_failed',
                verdict: $verdict,
                relationId: null,
                shouldEscalate: $escalation['required'],
                escalationReasons: $escalation['reasons'],
                reason: $e->getMessage(),
            );
        }
    }

    /**
     * Heuristica engram para escalation humana.
     *
     * Engram ASK rule:
     *  - confidence < 0.7 OU
     *  - verdict in {supersedes, conflicts_with} E memory_type in {decision, architecture, policy}
     *
     * @param  array<int,?string>  $memoryTypes
     * @return array{required:bool, reasons:array<int,string>}
     */
    public function shouldEscalate(string $verdict, ?float $confidence, array $memoryTypes = []): array
    {
        $reasons = [];

        if ($confidence !== null && $confidence < 0.7) {
            $reasons[] = 'low_confidence_below_0_7';
        }

        if (in_array($verdict, self::VISIBLE_VERDICTS, true)) {
            $touchHighRisk = false;
            foreach ($memoryTypes as $type) {
                if (in_array($type, self::HIGH_RISK_MEMORY_TYPES, true)) {
                    $touchHighRisk = true;
                    break;
                }
            }
            if ($touchHighRisk) {
                $reasons[] = 'visible_verdict_on_high_risk_memory_type';
            }
        }

        return [
            'required' => $reasons !== [],
            'reasons' => $reasons,
        ];
    }

    /**
     * Lookup do verdict canonico atual entre source e target.
     */
    public function latestVerdict(string $sourceId, string $targetId): ?array
    {
        if (! Schema::hasTable('atlas_memory_entry_relations')) {
            return null;
        }

        $row = DB::table('atlas_memory_entry_relations')
            ->where('source_memory_entry_id', $sourceId)
            ->where('target_memory_entry_id', $targetId)
            ->orderByDesc('updated_at')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'relation_id' => (string) $row->id,
            'verdict' => (string) $row->relation_type,
            'judgment_status' => (string) ($row->judgment_status ?? self::JUDGMENT_PENDING),
            'marked_by_actor' => (string) ($row->marked_by_actor ?? self::ACTOR_UNKNOWN),
            'marked_by_model' => $row->marked_by_model,
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'reason' => $row->reason,
            'evidence_refs' => $row->evidence_refs ? json_decode($row->evidence_refs, true) : [],
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * Retorna pares conflitantes visiveis em search (conflicts_with, supersedes).
     *
     * @return array<int,array<string,mixed>>
     */
    public function relatedConflicts(string $memoryEntryId): array
    {
        if (! Schema::hasTable('atlas_memory_entry_relations')) {
            return [];
        }

        $rows = DB::table('atlas_memory_entry_relations')
            ->where(function ($q) use ($memoryEntryId): void {
                $q->where('source_memory_entry_id', $memoryEntryId)
                  ->orWhere('target_memory_entry_id', $memoryEntryId);
            })
            ->whereIn('relation_type', self::VISIBLE_VERDICTS)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return $rows->map(function ($row) use ($memoryEntryId): array {
            $isSource = $row->source_memory_entry_id === $memoryEntryId;

            return [
                'relation_id' => (string) $row->id,
                'verdict' => (string) $row->relation_type,
                'partner_id' => $isSource ? (string) $row->target_memory_entry_id : (string) $row->source_memory_entry_id,
                'role' => $isSource ? 'source' : 'target',
                'judgment_status' => (string) ($row->judgment_status ?? self::JUDGMENT_PENDING),
                'marked_by_actor' => (string) ($row->marked_by_actor ?? self::ACTOR_UNKNOWN),
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'evidence_refs' => $row->evidence_refs ? json_decode($row->evidence_refs, true) : [],
            ];
        })->all();
    }

    public static function isValidVerdict(string $verdict): bool
    {
        return in_array($verdict, self::ALLOWED_VERDICTS, true);
    }

    public static function isVisibleInSearch(string $verdict): bool
    {
        return in_array($verdict, self::VISIBLE_VERDICTS, true);
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;
        if ($float < 0.0) {
            return 0.0;
        }
        if ($float > 1.0) {
            return 1.0;
        }

        return round($float, 3);
    }

    private function normalizeJudgmentStatus(mixed $value): string
    {
        $str = is_string($value) ? trim($value) : '';

        return in_array($str, self::ALLOWED_JUDGMENT_STATUS, true) ? $str : self::JUDGMENT_JUDGED;
    }

    private function normalizeReason(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        // Limit razoavel para nao explodir row size.
        if (mb_strlen($trimmed) > 2000) {
            return mb_substr($trimmed, 0, 2000);
        }

        return $trimmed;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<int,string>  $escalationReasons
     * @return array{ok:bool, status:string, verdict:string, relation_id:string|null, should_escalate:bool, escalation_reasons:array<int,string>, schema_version:string, reason:string|null}
     */
    private function envelope(
        bool $ok,
        string $status,
        string $verdict,
        ?string $relationId,
        bool $shouldEscalate,
        array $escalationReasons,
        ?string $reason = null,
    ): array {
        return [
            'ok' => $ok,
            'status' => $status,
            'verdict' => $verdict,
            'relation_id' => $relationId,
            'should_escalate' => $shouldEscalate,
            'escalation_reasons' => array_values($escalationReasons),
            'schema_version' => 'atlas.memory.relation_verdict.v1',
            'reason' => $reason,
        ];
    }
}
