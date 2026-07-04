<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Receipts;

/**
 * Decides which proven receipt FACTS are safe to export into FUTURE context / memory proposals. Pure:
 * never writes memory or docs — only emits an export PLAN.
 *
 * INPUT FACT (per candidate row):
 *   { id, kind, bound:bool, verdict?:string, fact_summary:string, raw_payload?:array }
 *
 * REJECTION RULES (any triggered ⇒ exclude with named reason):
 *   - rejected:unbound                       — bound !== true
 *   - rejected:unverified_claim              — kind=worker_claim AND verdict != 'verified'
 *   - rejected:failed_gate_without_diagnosis — verdict='failed' AND fact_summary lacks 'diagnosis'/'root_cause'/'because'
 *   - rejected:narrative_too_broad           — fact_summary exceeds NARRATIVE_MAX_CHARS
 *   - rejected:contains_secret               — fact_summary or any raw_payload key matches /SECRET|TOKEN|API_KEY|PASSWORD/i
 *
 * OUTPUT:
 *   { schema, export_plan:list<{export_id, source_id, kind, fact_summary}>, rejections:list<{source_id, reason}> }
 *
 * ADDITIVE (AC2/AC3/AC4): `decisions` classifies EVERY candidate into export|redact|defer|reject
 * with a reason, on top of the untouched export_plan/rejections arrays above. New opt-in candidate
 * fields, all defaulting to values that never change legacy behavior when omitted:
 *   - kind='raw_transcript'                    -> reject:raw_transcript
 *   - kind='hint' AND is_stale=true             -> reject:stale_hint
 *   - kind='status_chatter' OR reuse_value<0.2  -> reject:low_utility_chatter (reuse_value defaults 1.0)
 *   - secret found ONLY in raw_payload AND raw_payload_redactable=true -> redact (export_plan row
 *     flagged redacted=true) instead of the default hard reject:contains_secret
 *   - evidence_durability='ephemeral'           -> defer:ephemeral_evidence_pending_durability
 *     (defaults 'durable', excluded from both export_plan and rejections while deferred)
 *
 * INVARIANTS:
 *   - DETERMINISTIC: export_id = sha256(kind+'|'+source_id)[0:12]; rows sorted by export_id.
 *   - PURE.
 */
final class AtlasSelfConstructionReceiptMemoryExportPlan
{
    public const SCHEMA = 'atlas.selfconstruction.memory_export_plan.v1';

    public const NARRATIVE_MAX_CHARS = 280;

    public const DECISION_EXPORT = 'export';

    public const DECISION_REDACT = 'redact';

    public const DECISION_DEFER = 'defer';

    public const DECISION_REJECT = 'reject';

    private const KIND_RAW_TRANSCRIPT = 'raw_transcript';

    private const KIND_STATUS_CHATTER = 'status_chatter';

    private const KIND_HINT = 'hint';

    private const DURABILITY_EPHEMERAL = 'ephemeral';

    private const REUSE_VALUE_LOW_UTILITY_FLOOR = 0.2;

    /**
     * @param  list<array{id?:string, kind?:string, bound?:bool, verdict?:string, fact_summary?:string, raw_payload?:array<string,mixed>}>  $candidates
     * @return array{schema:string, export_plan:list<array{export_id:string, source_id:string, kind:string, fact_summary:string}>, rejections:list<array{source_id:string, reason:string}>}
     */
    public function plan(array $candidates): array
    {
        $exports = [];
        $rejections = [];
        $decisions = [];

        foreach ($candidates as $c) {
            if (! is_array($c)) {
                continue;
            }
            $id = (string) ($c['id'] ?? '');
            $kind = (string) ($c['kind'] ?? '');
            $summary = (string) ($c['fact_summary'] ?? '');
            $verdict = (string) ($c['verdict'] ?? '');
            $rawPayload = is_array($c['raw_payload'] ?? null) ? $c['raw_payload'] : [];
            $reuseValue = max(0.0, min(1.0, (float) ($c['reuse_value'] ?? 1.0)));
            $isStale = (bool) ($c['is_stale'] ?? false);
            $isEphemeral = (string) ($c['evidence_durability'] ?? 'durable') === self::DURABILITY_EPHEMERAL;
            $redactable = (bool) ($c['raw_payload_redactable'] ?? false);

            if ($id === '') {
                $rejections[] = ['source_id' => '', 'reason' => 'rejected:empty_id'];
                $decisions[] = ['source_id' => '', 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:empty_id'];

                continue;
            }
            if ($kind === '') {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:empty_kind'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:empty_kind'];

                continue;
            }
            if ($summary === '') {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:empty_fact_summary'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:empty_fact_summary'];

                continue;
            }
            if ($kind === self::KIND_RAW_TRANSCRIPT) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:raw_transcript'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:raw_transcript'];

                continue;
            }
            if ($kind === self::KIND_HINT && $isStale) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:stale_hint'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:stale_hint'];

                continue;
            }
            if ($kind === self::KIND_STATUS_CHATTER || $reuseValue < self::REUSE_VALUE_LOW_UTILITY_FLOOR) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:low_utility_chatter'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:low_utility_chatter'];

                continue;
            }
            if (! ($c['bound'] ?? false)) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:unbound'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:unbound'];

                continue;
            }
            if ($kind === 'worker_claim' && $verdict !== 'verified') {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:unverified_claim'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:unverified_claim'];

                continue;
            }
            if ($verdict === 'failed' && ! preg_match('/diagnosis|root_cause|because/i', $summary)) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:failed_gate_without_diagnosis'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:failed_gate_without_diagnosis'];

                continue;
            }
            if (mb_strlen($summary) > self::NARRATIVE_MAX_CHARS) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:narrative_too_broad'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:narrative_too_broad'];

                continue;
            }
            if (preg_match('/SECRET|TOKEN|API_KEY|PASSWORD/i', $summary)) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:contains_secret'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:contains_secret'];

                continue;
            }
            if ($this->payloadHasSecret($rawPayload)) {
                if ($redactable) {
                    $exportId = substr(hash('sha256', $kind.'|'.$id), 0, 12);
                    $exports[] = [
                        'export_id' => $exportId,
                        'source_id' => $id,
                        'kind' => $kind,
                        'fact_summary' => $summary,
                        'redacted' => true,
                        'provider_safe_delta' => (string) ($c['provider_safe_delta'] ?? ''),
                        'source_hash' => (string) ($c['source_hash'] ?? ''),
                    ];
                    $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REDACT, 'reason' => 'redacted:secret_in_raw_payload_stripped'];

                    continue;
                }
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:contains_secret'];
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_REJECT, 'reason' => 'rejected:contains_secret'];

                continue;
            }
            if ($isEphemeral) {
                $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_DEFER, 'reason' => 'deferred:ephemeral_evidence_pending_durability'];

                continue;
            }

            $exportId = substr(hash('sha256', $kind.'|'.$id), 0, 12);
            $exports[] = [
                'export_id' => $exportId,
                'source_id' => $id,
                'kind' => $kind,
                'fact_summary' => $summary,
                'redacted' => false,
                'provider_safe_delta' => (string) ($c['provider_safe_delta'] ?? ''),
                'source_hash' => (string) ($c['source_hash'] ?? ''),
            ];
            $decisions[] = ['source_id' => $id, 'decision' => self::DECISION_EXPORT, 'reason' => 'exported:durable_high_value_provider_safe'];
        }

        usort($exports, static fn (array $a, array $b): int => strcmp($a['export_id'], $b['export_id']));
        usort($rejections, static fn (array $a, array $b): int => strcmp($a['source_id'], $b['source_id']));
        usort($decisions, static fn (array $a, array $b): int => strcmp($a['source_id'], $b['source_id']));

        return [
            'schema' => self::SCHEMA,
            'export_plan' => $exports,
            'rejections' => $rejections,
            'decisions' => $decisions,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function payloadHasSecret(array $payload): bool
    {
        foreach ($payload as $k => $v) {
            if (preg_match('/SECRET|TOKEN|API_KEY|PASSWORD/i', (string) $k)) {
                return true;
            }
            if (is_string($v) && preg_match('/SECRET|TOKEN|API_KEY|PASSWORD/i', $v)) {
                return true;
            }
            if (is_array($v) && $this->payloadHasSecret($v)) {
                return true;
            }
        }

        return false;
    }
}
