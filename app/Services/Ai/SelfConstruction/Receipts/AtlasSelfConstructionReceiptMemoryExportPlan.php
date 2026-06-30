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
 * INVARIANTS:
 *   - DETERMINISTIC: export_id = sha256(kind+'|'+source_id)[0:12]; rows sorted by export_id.
 *   - PURE.
 */
final class AtlasSelfConstructionReceiptMemoryExportPlan
{
    public const SCHEMA = 'atlas.selfconstruction.memory_export_plan.v1';

    public const NARRATIVE_MAX_CHARS = 280;

    /**
     * @param  list<array{id?:string, kind?:string, bound?:bool, verdict?:string, fact_summary?:string, raw_payload?:array<string,mixed>}>  $candidates
     * @return array{schema:string, export_plan:list<array{export_id:string, source_id:string, kind:string, fact_summary:string}>, rejections:list<array{source_id:string, reason:string}>}
     */
    public function plan(array $candidates): array
    {
        $exports = [];
        $rejections = [];

        foreach ($candidates as $c) {
            if (! is_array($c)) {
                continue;
            }
            $id = (string) ($c['id'] ?? '');
            $kind = (string) ($c['kind'] ?? '');
            $summary = (string) ($c['fact_summary'] ?? '');
            $verdict = (string) ($c['verdict'] ?? '');

            if ($id === '') {
                $rejections[] = ['source_id' => '', 'reason' => 'rejected:empty_id'];

                continue;
            }
            if ($kind === '') {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:empty_kind'];

                continue;
            }
            if ($summary === '') {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:empty_fact_summary'];

                continue;
            }
            if (! ($c['bound'] ?? false)) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:unbound'];

                continue;
            }
            if ($kind === 'worker_claim' && $verdict !== 'verified') {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:unverified_claim'];

                continue;
            }
            if ($verdict === 'failed' && ! preg_match('/diagnosis|root_cause|because/i', $summary)) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:failed_gate_without_diagnosis'];

                continue;
            }
            if (mb_strlen($summary) > self::NARRATIVE_MAX_CHARS) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:narrative_too_broad'];

                continue;
            }
            if ($this->containsSecret($summary, is_array($c['raw_payload'] ?? null) ? $c['raw_payload'] : [])) {
                $rejections[] = ['source_id' => $id, 'reason' => 'rejected:contains_secret'];

                continue;
            }

            $exportId = substr(hash('sha256', $kind.'|'.$id), 0, 12);
            $exports[] = ['export_id' => $exportId, 'source_id' => $id, 'kind' => $kind, 'fact_summary' => $summary];
        }

        usort($exports, static fn (array $a, array $b): int => strcmp($a['export_id'], $b['export_id']));
        usort($rejections, static fn (array $a, array $b): int => strcmp($a['source_id'], $b['source_id']));

        return [
            'schema' => self::SCHEMA,
            'export_plan' => $exports,
            'rejections' => $rejections,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function containsSecret(string $summary, array $payload): bool
    {
        if (preg_match('/SECRET|TOKEN|API_KEY|PASSWORD/i', $summary)) {
            return true;
        }

        return $this->payloadHasSecret($payload);
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
