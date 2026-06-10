<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Atlas Constitutional Kernel — Patamar 4 Foundation (4.0).
 *
 * Single pétreo gate that every autonomous change must traverse before
 * being applied. Loads canonical invariants in-memory (source of truth),
 * exposes validateChange/listInvariants/listViolations/kernelHash.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-constitutional-kernel.md
 *
 * Schemas:
 *   - atlas.constitutional_kernel.invariant.v1
 *   - atlas.constitutional_kernel.validation_envelope.v1
 *   - atlas.constitutional_kernel.violation_ticket.v1
 *
 * Invariantes:
 *   - fail-closed (input malformado → block);
 *   - append-only violations log;
 *   - deterministic kernel_hash;
 *   - nenhuma mudança em invariante sem registro.
 */
final class AtlasConstitutionalKernelService
{
    public const INVARIANT_SCHEMA = 'atlas.constitutional_kernel.invariant.v1';

    public const VALIDATION_SCHEMA = 'atlas.constitutional_kernel.validation_envelope.v1';

    public const VIOLATION_SCHEMA = 'atlas.constitutional_kernel.violation_ticket.v1';

    public const CLASS_PETREO = 'petreo';

    public const CLASS_ELASTIC = 'elastic';

    public const CLASS_RUNTIME = 'runtime';

    public const VALID_CLASSES = [self::CLASS_PETREO, self::CLASS_ELASTIC, self::CLASS_RUNTIME];

    public const DECISION_ALLOW = 'allow';

    public const DECISION_BLOCK = 'block';

    public const DECISION_ALLOW_WITH_APPROVAL = 'allow_with_human_approval';

    public const PROHIBITED_CLAIMS = ['benchmark', 'rivals', 'superiority', 'concurrent'];

    public const PROHIBITED_VOCAB = ['jarvis', 'wrapper de ia', 'ferramenta de produtividade', 'sistema de memoria'];

    public const SENSITIVE_CLASSES = ['sensitive', 'secret', 'cyber'];

    public const VALID_PRIVACY_CLASSES = ['public', 'normal', 'sensitive', 'secret', 'cyber'];

    public const ELASTIC_FLIP_SCHEMA = 'atlas.constitutional_kernel.elastic_flip.v1';

    public const RUNTIME_TUNE_SCHEMA = 'atlas.constitutional_kernel.runtime_tune.v1';

    /**
     * Canonical windows for runtime-class invariants. Auto-tune callers MUST
     * stay within these bounds — the Kernel rejects anything outside.
     *
     * @var array<string, array<int, string>|array{min:int|float, max:int|float}>
     */
    private const RUNTIME_WINDOWS = [
        'reconciliation_cadence_window' => ['minute', 'five', 'ten', 'fifteen', 'thirty', 'hourly'],
        'tdc_ttl_window' => ['min' => 60, 'max' => 86400],
        'admission_trust_modifier_window' => ['min' => -1, 'max' => 1],
    ];

    private ?string $violationsLogOverride = null;

    private ?string $elasticStateLogOverride = null;

    private ?string $runtimeStateLogOverride = null;

    /**
     * Canonical invariant set. Editing this list is the ONLY way to change
     * pétreos — must be reviewed in PR + redeploy. Runtime never mutates this.
     *
     * @var list<array{id:string, class:string, statement:string, enabled:bool}>
     */
    private const INVARIANTS = [
        // Pétreos — imutáveis runtime, alteração só por PR + redeploy.
        ['id' => 'claim_policy_provider_safe',          'class' => self::CLASS_PETREO, 'statement' => 'No benchmark/rivals/superiority claims in code, doc or provider output.', 'enabled' => true],
        ['id' => 'sovereignty_local_first',             'class' => self::CLASS_PETREO, 'statement' => 'sensitive/secret/cyber data never leaves the machine.', 'enabled' => true],
        ['id' => 'cognitive_immune_law',                'class' => self::CLASS_PETREO, 'statement' => 'Raw Capture != Evidence != Learning Signal != Memory != Context != Decision.', 'enabled' => true],
        ['id' => 'external_rivals_certification_blocked', 'class' => self::CLASS_PETREO, 'statement' => 'external_rivals_certification stays blocked forever.', 'enabled' => true],
        ['id' => 'no_jarvis_vocabulary',                'class' => self::CLASS_PETREO, 'statement' => 'Forbidden vocab: Jarvis/Rivals/benchmark/superiority/concurrent.', 'enabled' => true],
        ['id' => 'atlas_is_substrato_not_wrapper',      'class' => self::CLASS_PETREO, 'statement' => 'Atlas is sovereignty substrate, not wrapper / productivity tool / memory system.', 'enabled' => true],
        ['id' => 'evidence_append_only',                'class' => self::CLASS_PETREO, 'statement' => 'Evidence Ledger is append-only; no retroactive ops.', 'enabled' => true],
        ['id' => 'human_approval_for_high_risk',        'class' => self::CLASS_PETREO, 'statement' => 'Cross-domain changes touching sensitive/secret/cyber demand operator approval.', 'enabled' => true],
        ['id' => 'no_silent_invariant_mutation',        'class' => self::CLASS_PETREO, 'statement' => 'No invariant mutation without append-only ledger entry.', 'enabled' => true],

        // Elastic — operador pode flipar via --check + --confirm + receipt.
        ['id' => 'autonomous_self_construction_enabled', 'class' => self::CLASS_ELASTIC, 'statement' => 'Operator can disable ASCB autonomous proposal firing without breaking the loop.', 'enabled' => true],
        ['id' => 'reconciliation_cron_enabled',         'class' => self::CLASS_ELASTIC, 'statement' => 'Operator can pause the autonomous reconciliation cron.', 'enabled' => true],
        ['id' => 'teos_meta_projection_enabled',        'class' => self::CLASS_ELASTIC, 'statement' => 'Operator can disable TEOS-I3 meta-projection before ASCB.propose().', 'enabled' => true],
        ['id' => 'swarm_local_fallback_enabled',        'class' => self::CLASS_ELASTIC, 'statement' => 'Operator can disable the atlas_local fallback arm in Swarm Conductor.', 'enabled' => true],

        // Runtime — auto-tune dentro de range pré-declarado (ADML/Reconciliation com receipt).
        ['id' => 'reconciliation_cadence_window',       'class' => self::CLASS_RUNTIME, 'statement' => 'Cron cadence may be tuned in {minute|five|ten|fifteen|thirty|hourly} window.', 'enabled' => true],
        ['id' => 'tdc_ttl_window',                      'class' => self::CLASS_RUNTIME, 'statement' => 'Temporary domain capsule TTL may be tuned in [60s..86400s] window.', 'enabled' => true],
        ['id' => 'admission_trust_modifier_window',     'class' => self::CLASS_RUNTIME, 'statement' => 'Trust modifier may shift autonomy cap by at most ±1 tier.', 'enabled' => true],
    ];

    public function setViolationsLogPathForTesting(?string $path): void
    {
        $this->violationsLogOverride = $path;
    }

    public function setElasticStateLogPathForTesting(?string $path): void
    {
        $this->elasticStateLogOverride = $path;
    }

    public function setRuntimeStateLogPathForTesting(?string $path): void
    {
        $this->runtimeStateLogOverride = $path;
    }

    public function runtimeStateLogPath(): string
    {
        if ($this->runtimeStateLogOverride !== null) {
            return $this->runtimeStateLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'runtime_state.jsonl';
    }

    /**
     * Read the current effective value of a runtime-class invariant. Returns
     * null when the invariant has never been tuned (caller uses canonical
     * default). Pétreos and elastics throw — only runtime values are tunable.
     *
     * @return string|int|float|null
     */
    public function currentRuntimeValue(string $invariantId): null|string|int|float
    {
        $inv = $this->findInvariant($invariantId);
        if ($inv === null || $inv['class'] !== self::CLASS_RUNTIME) {
            return null;
        }
        $last = null;
        foreach (AppendOnlyJsonlStore::read($this->runtimeStateLogPath()) as $entry) {
            if (($entry['invariant_id'] ?? null) === $invariantId) {
                $last = $entry;
            }
        }
        if ($last === null) {
            return null;
        }
        $value = $last['value'] ?? null;
        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Tune a runtime-class invariant. The value MUST fall inside the canonical
     * window declared in self::RUNTIME_WINDOWS — anything else throws.
     *
     * @param  string|int|float  $value
     * @return array<string,mixed> receipt
     */
    public function tuneRuntime(string $invariantId, string|int|float $value, string $actor, string $reason): array
    {
        $inv = $this->findInvariant($invariantId);
        if ($inv === null) {
            throw new InvalidArgumentException("Unknown invariant '{$invariantId}'.");
        }
        if ($inv['class'] !== self::CLASS_RUNTIME) {
            throw new InvalidArgumentException(
                "Invariant '{$invariantId}' is class '{$inv['class']}' — only 'runtime' is tunable."
            );
        }
        if (trim($actor) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('tuneRuntime requires non-empty actor and reason.');
        }
        if (! array_key_exists($invariantId, self::RUNTIME_WINDOWS)) {
            throw new InvalidArgumentException("No tuning window declared for '{$invariantId}'.");
        }
        $window = self::RUNTIME_WINDOWS[$invariantId];

        // Enum window or numeric range.
        if (array_is_list($window)) {
            if (! in_array($value, $window, true)) {
                throw new InvalidArgumentException(
                    "Value '{$value}' is outside the canonical window for '{$invariantId}': ".implode('|', $window)
                );
            }
        } else {
            if (! (is_int($value) || is_float($value))) {
                throw new InvalidArgumentException("Value must be numeric for '{$invariantId}'.");
            }
            if ($value < $window['min'] || $value > $window['max']) {
                throw new InvalidArgumentException(
                    "Value {$value} outside range [{$window['min']}..{$window['max']}] for '{$invariantId}'."
                );
            }
        }

        $entry = [
            'schema_version' => self::RUNTIME_TUNE_SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'invariant_id' => $invariantId,
            'value' => $value,
            'actor' => $actor,
            'reason' => $reason,
            'kernel_hash' => $this->kernelHash(),
        ];
        $entry['entry_hash'] = 'sha256:'.hash('sha256', json_encode([
            'invariant_id' => $invariantId,
            'value' => $value,
            'actor' => $actor,
            'reason' => $reason,
            'recorded_at' => $entry['recorded_at'],
            'kernel_hash' => $entry['kernel_hash'],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->runtimeStateLogPath(), $entry);

        return $entry;
    }

    /**
     * @return array<string, array<int, string>|array{min:int|float, max:int|float}>
     */
    public function runtimeWindows(): array
    {
        return self::RUNTIME_WINDOWS;
    }

    public function elasticStateLogPath(): string
    {
        if ($this->elasticStateLogOverride !== null) {
            return $this->elasticStateLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'elastic_state.jsonl';
    }

    /**
     * Read the current effective state of an elastic invariant. Pétreos and
     * runtime classes return their canonical `enabled` (cannot be flipped via
     * this API). Elastic invariants honor the last flip event in the ledger.
     */
    public function isElasticEnabled(string $invariantId): bool
    {
        $inv = $this->findInvariant($invariantId);
        if ($inv === null) {
            return false;
        }
        if ($inv['class'] !== self::CLASS_ELASTIC) {
            // Pétreos and runtime return their canonical enabled state —
            // pétreos cannot be flipped by definition.
            return (bool) $inv['enabled'];
        }
        $lastFlip = null;
        foreach (AppendOnlyJsonlStore::read($this->elasticStateLogPath()) as $entry) {
            if (($entry['invariant_id'] ?? null) === $invariantId) {
                $lastFlip = $entry;
            }
        }
        if ($lastFlip === null) {
            return (bool) $inv['enabled'];
        }

        return (bool) ($lastFlip['enabled'] ?? $inv['enabled']);
    }

    /**
     * Flip an elastic invariant's enabled state. Only ELASTIC class is allowed.
     * Pétreos throw — they require PR + redeploy to change.
     *
     * @return array<string,mixed>
     */
    public function flipElastic(string $invariantId, bool $enabled, string $actor, string $reason): array
    {
        $inv = $this->findInvariant($invariantId);
        if ($inv === null) {
            throw new InvalidArgumentException("Unknown invariant '{$invariantId}'.");
        }
        if ($inv['class'] !== self::CLASS_ELASTIC) {
            throw new InvalidArgumentException(
                "Invariant '{$invariantId}' is class '{$inv['class']}' — only 'elastic' can be flipped at runtime."
            );
        }
        if (trim($actor) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('flipElastic requires non-empty actor and reason.');
        }

        $entry = [
            'schema_version' => self::ELASTIC_FLIP_SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'invariant_id' => $invariantId,
            'enabled' => $enabled,
            'actor' => $actor,
            'reason' => $reason,
            'kernel_hash' => $this->kernelHash(),
        ];
        $entry['entry_hash'] = 'sha256:'.hash('sha256', json_encode([
            'invariant_id' => $invariantId,
            'enabled' => $enabled,
            'actor' => $actor,
            'reason' => $reason,
            'recorded_at' => $entry['recorded_at'],
            'kernel_hash' => $entry['kernel_hash'],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->elasticStateLogPath(), $entry);

        return $entry;
    }

    /**
     * @return array{id:string,class:string,statement:string,enabled:bool}|null
     */
    private function findInvariant(string $id): ?array
    {
        foreach (self::INVARIANTS as $inv) {
            if ($inv['id'] === $id) {
                return $inv;
            }
        }

        return null;
    }

    public function violationsLogPath(): string
    {
        if ($this->violationsLogOverride !== null) {
            return $this->violationsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'violations.jsonl';
    }

    /**
     * @return list<array{id:string,class:string,statement:string,enabled:bool,schema_version:string}>
     */
    public function listInvariants(?string $class = null): array
    {
        if ($class !== null && ! in_array($class, self::VALID_CLASSES, true)) {
            throw new InvalidArgumentException("Unknown invariant class '{$class}'.");
        }
        $out = [];
        foreach (self::INVARIANTS as $inv) {
            if ($class !== null && $inv['class'] !== $class) {
                continue;
            }
            $out[] = [
                'schema_version' => self::INVARIANT_SCHEMA,
                'id' => $inv['id'],
                'class' => $inv['class'],
                'statement' => $inv['statement'],
                'enabled' => $inv['enabled'],
            ];
        }

        return $out;
    }

    public function kernelHash(): string
    {
        $canonical = array_map(static fn (array $i): array => [
            'id' => $i['id'],
            'class' => $i['class'],
            'enabled' => $i['enabled'],
        ], self::INVARIANTS);
        usort($canonical, static fn ($a, $b): int => strcmp($a['id'], $b['id']));

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * Validate a proposed change against pétreo invariants.
     * Fail-closed: malformed input → block.
     *
     * @param  array<string,mixed>  $change
     * @return array<string,mixed>
     */
    public function validateChange(array $change): array
    {
        $violations = [];
        $requiresHuman = false;

        $changeKind = (string) ($change['change_kind'] ?? '');
        $proposedEffect = (string) ($change['proposed_effect'] ?? '');
        $claims = array_map('strval', (array) ($change['claims'] ?? []));
        $outboundClasses = array_map('strval', (array) ($change['outbound_data_classes'] ?? []));
        $scope = (array) ($change['scope'] ?? []);
        $actor = (string) ($change['actor'] ?? 'unknown');
        $privacy = (string) ($scope['privacy_class'] ?? '');

        // Fail-closed malformed.
        if ($changeKind === '' || $proposedEffect === '') {
            $envelope = $this->buildEnvelope(self::DECISION_BLOCK, [[
                'invariant_id' => 'no_silent_invariant_mutation',
                'reason' => 'malformed change envelope (missing change_kind or proposed_effect)',
            ]], []);
            $this->recordViolation([
                'actor' => $actor,
                'change_kind' => $changeKind,
                'decision' => self::DECISION_BLOCK,
                'reason' => 'malformed',
            ]);

            return $envelope;
        }

        // Privacy class shape.
        if ($privacy !== '' && ! in_array($privacy, self::VALID_PRIVACY_CLASSES, true)) {
            $violations[] = ['invariant_id' => 'sovereignty_local_first', 'reason' => "unknown privacy_class '{$privacy}'"];
        }

        // claim_policy_provider_safe.
        foreach ($claims as $c) {
            $cl = strtolower(trim($c));
            if ($cl === '') {
                continue;
            }
            if (in_array($cl, self::PROHIBITED_CLAIMS, true)) {
                $violations[] = ['invariant_id' => 'claim_policy_provider_safe', 'reason' => "prohibited claim '{$cl}'"];
            }
        }

        // sovereignty_local_first — sensitive/secret/cyber MUST NOT appear in outbound_data_classes.
        foreach ($outboundClasses as $oc) {
            $ocl = strtolower(trim($oc));
            if (in_array($ocl, self::SENSITIVE_CLASSES, true)) {
                $violations[] = ['invariant_id' => 'sovereignty_local_first', 'reason' => "outbound data class '{$ocl}' violates local-first"];
            }
        }

        // no_jarvis_vocabulary on proposed_effect.
        $effLower = mb_strtolower($proposedEffect);
        foreach (self::PROHIBITED_VOCAB as $bad) {
            if (str_contains($effLower, $bad)) {
                $violations[] = ['invariant_id' => 'no_jarvis_vocabulary', 'reason' => "prohibited vocab '{$bad}' in proposed_effect"];
            }
        }
        foreach (self::PROHIBITED_CLAIMS as $bad) {
            if (preg_match('/\b'.preg_quote($bad, '/').'\b/u', $effLower) === 1) {
                $violations[] = ['invariant_id' => 'no_jarvis_vocabulary', 'reason' => "prohibited claim word '{$bad}' in proposed_effect"];
            }
        }

        // external_rivals_certification_blocked.
        if (str_contains($effLower, 'external_rivals_certification') && str_contains($effLower, 'unblock')) {
            $violations[] = ['invariant_id' => 'external_rivals_certification_blocked', 'reason' => 'attempt to unblock external_rivals_certification'];
        }

        // human_approval_for_high_risk — privacy class sensitive/secret/cyber demands approval.
        if (in_array($privacy, self::SENSITIVE_CLASSES, true)) {
            $requiresHuman = true;
        }
        if ((bool) ($change['requires_human_approval_hint'] ?? false)) {
            $requiresHuman = true;
        }

        // Decide.
        if ($violations !== []) {
            $envelope = $this->buildEnvelope(self::DECISION_BLOCK, $violations, []);
            $this->recordViolation([
                'actor' => $actor,
                'change_kind' => $changeKind,
                'decision' => self::DECISION_BLOCK,
                'violations' => $violations,
            ]);

            return $envelope;
        }
        if ($requiresHuman) {
            $envelope = $this->buildEnvelope(self::DECISION_ALLOW_WITH_APPROVAL, [], ['operator']);
            $this->recordViolation([
                'actor' => $actor,
                'change_kind' => $changeKind,
                'decision' => self::DECISION_ALLOW_WITH_APPROVAL,
                'reason' => 'high-risk scope requires operator approval',
            ]);

            return $envelope;
        }

        return $this->buildEnvelope(self::DECISION_ALLOW, [], []);
    }

    /**
     * Record an arbitrary violation/decision ticket (append-only).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordViolation(array $payload): array
    {
        $ticket = [
            'schema_version' => self::VIOLATION_SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'actor' => (string) ($payload['actor'] ?? 'unknown'),
            'change_kind' => (string) ($payload['change_kind'] ?? ''),
            'decision' => (string) ($payload['decision'] ?? self::DECISION_BLOCK),
            'reason' => $payload['reason'] ?? null,
            'violations' => array_values((array) ($payload['violations'] ?? [])),
            'kernel_hash' => $this->kernelHash(),
        ];
        $ticket['ticket_hash'] = 'sha256:'.hash('sha256', json_encode([
            'actor' => $ticket['actor'],
            'change_kind' => $ticket['change_kind'],
            'decision' => $ticket['decision'],
            'violations' => $ticket['violations'],
            'kernel_hash' => $ticket['kernel_hash'],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->violationsLogPath(), $ticket);

        return $ticket;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listViolations(): array
    {
        return AppendOnlyJsonlStore::read($this->violationsLogPath());
    }

    // ---------- internals ----------

    /**
     * @param  list<array{invariant_id:string,reason:string}>  $violations
     * @param  list<string>  $approvals
     * @return array<string,mixed>
     */
    private function buildEnvelope(string $decision, array $violations, array $approvals): array
    {
        return [
            'schema_version' => self::VALIDATION_SCHEMA,
            'decision' => $decision,
            'violations' => $violations,
            'required_approvals' => $approvals,
            'kernel_hash' => $this->kernelHash(),
        ];
    }

}
