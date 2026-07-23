<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasDevFailureCapsule;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * L1-P3 — Self-Immunizing Antibody (L7), first increment: the ANTIBODY PROPOSER.
 *
 * P1 (predictive simulator) and P2 (self-healing repair proposer) are DONE. P3 is
 * the last L1 pillar of the generative leap. When a rot ESCAPES — a failure that
 * got THROUGH the existing gates and was captured as an AtlasDevFailureCapsule —
 * this decider synthesises the ANTIBODY that makes the failure un-repeatable: the
 * outline of the detector that, had it existed, would have caught the escape.
 *
 * ABSOLUTE doc rule (atlas-documentation-reality-generative-leap.md:207 — "cada
 * anticorpo precisa de teste que reproduz a falha original ANTES de virar gate"):
 * every antibody MUST carry a reproducing_test_outline. An antibody proposal
 * WITHOUT a reproducing_test_outline is INVALID and is NEVER emitted — proposalFor
 * always builds one, and the outline is never null/empty.
 *
 * CRITICAL SAFETY: this is strictly READ-ONLY and a PROPOSER. It NEVER creates or
 * writes a gate, a test, or any file; NEVER executes anything; NEVER auto-installs
 * enforcement; NEVER generates code onto disk. A human/gate reviews the proposal
 * and does the real work of writing the reproducing test FIRST and then the gate,
 * through the existing gates + Evidence Ledger. The actual auto-synthesis of the
 * gate's runtime code is a deliberately LATER P3 increment, out of scope here.
 *
 * It does NOT detect failures: it CONSUMES escaped-failure records
 * (AtlasDevFailureCapsule, or a plain {kind, summary, location, detail}) as input.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
 */
class AtlasDocumentationRealityAntibodyProposerService
{
    public const SCHEMA = 'atlas.documentation_reality.antibody.v1';

    /**
     * Resolved status for every antibody. There is no other status: an antibody is
     * a proposal awaiting a human/gate, never a self-installed enforcement.
     */
    public const STATUS = 'proposed_requires_human_review';

    /**
     * Propose ONE antibody from a single escaped-failure record. Accepts either an
     * AtlasDevFailureCapsule shape (failure_class / failing_gate / error_excerpt /
     * changed_files / suggested_repair) or a plain {kind, summary, location, detail}.
     *
     * The returned antibody ALWAYS contains a non-empty reproducing_test_outline:
     * an antibody without one is invalid and is never produced.
     *
     * @param  array<string,mixed>  $failure
     * @return array<string,mixed>
     */
    public function proposeFromCapsule(array $failure): array
    {
        return $this->proposalFor($this->normalizeFailure($failure));
    }

    /**
     * Read the most recent escalated escaped-failure capsules (escalate_to_forge) and propose one
     * antibody for each. If the capsule table is absent or empty, DEGRADE — never
     * fabricate a failure to have something to immunise against.
     *
     * @return array<string,mixed>
     */
    public function proposeRecent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));

        // Degrade-SAFE: without a real escaped-failure substrate there is nothing to
        // immunise against. Inventing a failure would manufacture a fake antibody and
        // a fake gate-to-be — the opposite of the doc rule. So withhold and degrade.
        if (! DatabaseTableAvailability::has('atlas_dev_failure_capsules')) {
            return $this->degradedEnvelope('no_failure_capsules');
        }

        $rows = AtlasDevFailureCapsule::query()
            ->where('escalate_to_forge', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return $this->degradedEnvelope('no_failure_capsules');
        }

        $antibodies = [];
        foreach ($rows as $row) {
            $antibodies[] = $this->proposalFor($this->normalizeCapsule($row));
        }

        return $this->envelope($antibodies, count($rows), degraded: false);
    }

    /**
     * @param  array<int,array<string,mixed>>  $antibodies
     * @return array<string,mixed>
     */
    private function envelope(array $antibodies, int $failureCount, bool $degraded, ?string $reason = null): array
    {
        $payload = [
            'schema_version' => self::SCHEMA,
            'mode' => 'self_immunizing_antibody_proposer',
            'pillar' => 'P3_self_immunizing_antibody',
            'increment' => 'antibody_proposer_no_auto_synthesis',
            'summary' => [
                'failure_count' => $failureCount,
                'antibody_count' => count($antibodies),
            ],
            'degraded' => $degraded,
            'antibodies' => $antibodies,
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return $this->finalize($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function degradedEnvelope(string $reason): array
    {
        return $this->envelope([], 0, degraded: true, reason: $reason);
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'auto_creates_gate' => false,
            'writes' => false,
            'executes' => false,
            'installs_enforcement' => false,
            'generates_code' => false,
            'requires_reproducing_test' => true,
            'goes_through_gates' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function finalize(array $envelope): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload['antibody_hash']);
        $envelope['antibody_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }

    /**
     * Build the antibody for one normalized failure. The reproducing_test_outline is
     * built UNCONDITIONALLY and is always non-empty — it is the un-skippable core of
     * an antibody, per the absolute doc rule. The proposed_detector is the SPEC of a
     * gate/check to add (description, kind, where, example_assertion), never the
     * installed gate itself.
     *
     * @param  array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}  $failure
     * @return array<string,mixed>
     */
    private function proposalFor(array $failure): array
    {
        $kind = $failure['kind'];
        $detectorKind = $this->detectorKindFor($kind);

        $antibody = [
            'failure_ref' => $failure['ref'],
            'failure_kind' => $kind,
            'reproducing_test_outline' => $this->reproducingTestOutline($failure),
            'proposed_detector' => $this->proposedDetector($failure, $detectorKind),
            'plug_in_point' => $this->plugInPoint($detectorKind),
            'status' => self::STATUS,
        ];

        // Invariant guard, in code: the absolute rule is that no antibody can exist
        // without a reproducing test. If the outline ever came back empty this is a
        // bug — fail loud rather than emit an invalid antibody.
        if ($antibody['reproducing_test_outline'] === [] || ($antibody['reproducing_test_outline']['steps'] ?? []) === []) {
            throw new \LogicException('Invariant violation: an antibody must always carry a non-empty reproducing_test_outline.');
        }

        return $antibody;
    }

    /**
     * The reproducing test that must exist and FAIL on the unpatched system BEFORE
     * any gate is added. Given/When/Then arrange_act_assert steps that reproduce the
     * original escape, plus a suggested target path — a suggestion only, never a
     * file this service creates.
     *
     * @param  array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}  $failure
     * @return array<string,mixed>
     */
    private function reproducingTestOutline(array $failure): array
    {
        $location = $failure['location'] !== '' ? $failure['location'] : 'the unit/service that escaped';
        $summary = $failure['summary'];

        return [
            'description' => "Reproduce the escaped failure FIRST so the antibody fails on the unpatched system: {$summary}",
            'given_when_then' => [
                'given' => "the system state in which the failure escaped (at {$location})",
                'when' => "the same trigger that produced the escape is exercised: {$summary}",
                'then' => 'the test asserts the failing behavior is observed (so it RED-fails before the fix, GREEN-passes after)',
            ],
            'arrange_act_assert' => [
                'arrange' => "set up the inputs/fixtures that reproduce: {$failure['detail']}",
                'act' => "invoke {$location} with the escape trigger",
                'assert' => 'assert the specific wrong outcome is detected — this is the regression lock',
            ],
            'steps' => [
                "arrange the inputs that reproduced the escape at {$location}",
                'act: exercise the same trigger that slipped past the gates',
                'assert the failing behavior is caught (RED before fix, GREEN after)',
            ],
            'target_test_path_suggestion' => $this->testPathSuggestion($failure),
            'must_fail_before_fix' => true,
        ];
    }

    /**
     * The detector SPEC: what gate/check to add and where it would plug in. This is
     * a description of work for a human/gate, never an installed gate. No field here
     * writes, creates, or executes anything.
     *
     * @param  array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}  $failure
     * @return array<string,mixed>
     */
    private function proposedDetector(array $failure, string $detectorKind): array
    {
        return [
            'description' => "Add a {$detectorKind} that catches this class of escape before it ships: {$failure['summary']}",
            'kind' => $detectorKind,
            'where' => $this->plugInPoint($detectorKind),
            'example_assertion' => $this->exampleAssertion($failure, $detectorKind),
            'derived_from_suggested_repair' => $failure['suggested_repair'],
        ];
    }

    /**
     * Map an escaped-failure kind to the most fitting detector kind. The vocabulary
     * matches the AtlasDevFailureCapsule failure_class taxonomy and the four detector
     * kinds named in the contract doc.
     */
    private function detectorKindFor(string $failureKind): string
    {
        return match ($failureKind) {
            'missing_context' => 'drift_rule',
            'type_error' => 'static_scan',
            'scope_violation', 'architecture_risk' => 'gate_check',
            'doc_drift', 'frontmatter' => 'frontmatter_rule',
            default => 'gate_check',
        };
    }

    private function plugInPoint(string $detectorKind): string
    {
        return match ($detectorKind) {
            'frontmatter_rule' => 'docs-health frontmatter validation (atlas:engineering:knowledge docs-health)',
            'drift_rule' => 'the maturity/drift ledger (atlas:aeos:maturity)',
            'static_scan' => 'the static-analysis / architecture-validate layer (atlas:ai:architecture-validate)',
            default => 'the failing gate that let the escape through (a new assertion in the existing gate)',
        };
    }

    /**
     * @param  array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}  $failure
     */
    private function exampleAssertion(array $failure, string $detectorKind): string
    {
        $location = $failure['location'] !== '' ? $failure['location'] : 'the affected unit';

        return match ($detectorKind) {
            'frontmatter_rule' => "assert the frontmatter rule rejects the shape that produced: {$failure['summary']}",
            'drift_rule' => "assert the drift ledger flags {$location} instead of returning clean for: {$failure['summary']}",
            'static_scan' => "assert the static scan fails on the pattern at {$location}: {$failure['summary']}",
            default => "assert the gate at {$location} blocks the escape: {$failure['summary']}",
        };
    }

    /**
     * @param  array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}  $failure
     */
    private function testPathSuggestion(array $failure): string
    {
        return match ($this->detectorKindFor($failure['kind'])) {
            'frontmatter_rule', 'drift_rule' => 'tests/Feature/Engineering/<AntibodyForThisEscape>Test.php',
            'static_scan' => 'tests/Feature/Engineering/<StaticScanAntibody>Test.php',
            default => 'tests/Feature/<Area>/<AntibodyForThisEscape>Test.php',
        };
    }

    /**
     * Normalize either accepted input shape into one internal failure record. A
     * plain {kind, summary, location, detail} is taken as-is; an AtlasDevFailureCapsule
     * shape is mapped (failure_class -> kind, failing_gate/error_excerpt -> summary,
     * changed_files -> location, suggested_repair carried through).
     *
     * @param  array<string,mixed>  $failure
     * @return array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}
     */
    private function normalizeFailure(array $failure): array
    {
        // Capsule shape detection: it carries failure_class/failing_gate. A plain
        // record carries kind/summary. Prefer the plain shape when present.
        $isPlain = array_key_exists('kind', $failure) || array_key_exists('summary', $failure);
        $isCapsule = array_key_exists('failure_class', $failure) || array_key_exists('failing_gate', $failure);

        if ($isCapsule && ! $isPlain) {
            return $this->mapCapsuleArray($failure);
        }

        $kind = $this->str($failure['kind'] ?? null) ?? 'unknown';
        $summary = $this->str($failure['summary'] ?? null)
            ?? $this->str($failure['failing_gate'] ?? null)
            ?? 'unspecified escaped failure';
        $location = $this->locationFrom($failure['location'] ?? null);
        $detail = $this->str($failure['detail'] ?? null)
            ?? $this->str($failure['error_excerpt'] ?? null)
            ?? $summary;

        return [
            'ref' => $this->str($failure['failure_ref'] ?? $failure['ref'] ?? $failure['uuid'] ?? null) ?? $this->refFrom($kind, $summary),
            'kind' => $kind,
            'summary' => $summary,
            'location' => $location,
            'detail' => $detail,
            'suggested_repair' => $this->str($failure['suggested_repair'] ?? null) ?? '',
        ];
    }

    /**
     * @param  array<string,mixed>  $failure
     * @return array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}
     */
    private function mapCapsuleArray(array $failure): array
    {
        $kind = $this->str($failure['failure_class'] ?? null) ?? 'unknown';
        $gate = $this->str($failure['failing_gate'] ?? null) ?? 'unknown gate';
        $excerpt = $this->str($failure['error_excerpt'] ?? null) ?? '';
        $summary = $excerpt !== '' ? "{$kind} escaped at {$gate}: {$excerpt}" : "{$kind} escaped at {$gate}";

        return [
            'ref' => $this->str($failure['uuid'] ?? $failure['failure_hash'] ?? $failure['run_id'] ?? null) ?? $this->refFrom($kind, $summary),
            'kind' => $kind,
            'summary' => $summary,
            'location' => $this->locationFrom($failure['changed_files'] ?? null) !== ''
                ? $this->locationFrom($failure['changed_files'] ?? null)
                : $gate,
            'detail' => $excerpt !== '' ? $excerpt : $summary,
            'suggested_repair' => $this->str($failure['suggested_repair'] ?? null) ?? '',
        ];
    }

    /**
     * @return array{ref:string, kind:string, summary:string, location:string, detail:string, suggested_repair:string}
     */
    private function normalizeCapsule(AtlasDevFailureCapsule $capsule): array
    {
        return $this->mapCapsuleArray([
            'failure_class' => $capsule->failure_class,
            'failing_gate' => $capsule->failing_gate,
            'error_excerpt' => $capsule->error_excerpt,
            'changed_files' => $capsule->changed_files,
            'suggested_repair' => $capsule->suggested_repair,
            'uuid' => $capsule->uuid,
            'run_id' => $capsule->run_id,
        ]);
    }

    private function locationFrom(mixed $value): string
    {
        if (is_array($value)) {
            $first = $this->str($value[0] ?? null);

            return $first ?? '';
        }

        return $this->str($value) ?? '';
    }

    private function refFrom(string $kind, string $summary): string
    {
        return 'escape:'.substr(hash('sha256', $kind.'|'.$summary), 0, 16);
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
