<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
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

    private ?string $violationsLogOverride = null;

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

        $this->appendJsonl($this->violationsLogPath(), $ticket);

        return $ticket;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listViolations(): array
    {
        return $this->readJsonl($this->violationsLogPath());
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
