<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneReceiptPolicy;

/**
 * READ-ONLY sentinel composing three independent FACT sources to prove a project lane is isolated
 * BEFORE 24/7 work runs there:
 *   1. queue-namespace policy facts (from {@see AtlasProjectLaneQueueNamespacePolicy})
 *   2. receipt-policy facts (from {@see AtlasProjectLaneReceiptPolicy} envelopes)
 *   3. cross-project leak-detector verdict (from {@see AtlasProjectLaneCrossProjectLeakDetector})
 *
 * STATUSES:
 *   - pass    — all three FACT bundles present + each passes for the same project_id.
 *   - hold    — one or more optional observations missing (cannot conclude yet; not a failure).
 *   - blocked — at least one bundle reports an explicit policy/leak failure.
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope.
 *   - NO scalar score / rank.
 */
final class AtlasProjectLaneIsolationSentinel
{
    public const SCHEMA = 'atlas.multiproject.lane_isolation_sentinel.v1';

    public const STATUS_PASS = 'pass';

    public const STATUS_HOLD = 'hold';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array{
     *     project_id:string,
     *     namespace_facts?:array<string,mixed>|null,
     *     receipt_facts?:array<string,mixed>|null,
     *     leak_detector_verdict?:array<string,mixed>|null
     * }  $observations
     * @return array{schema_version:string, status:string, passed:bool, project_id:string, blockers:list<string>, isolation_facts:array<string,mixed>, proof_summary:array<string,bool>}
     */
    public function evaluate(array $observations): array
    {
        $projectId = (string) ($observations['project_id'] ?? '');
        $namespaceFacts = is_array($observations['namespace_facts'] ?? null) ? $observations['namespace_facts'] : null;
        $receiptFacts = is_array($observations['receipt_facts'] ?? null) ? $observations['receipt_facts'] : null;
        $leakVerdict = is_array($observations['leak_detector_verdict'] ?? null) ? $observations['leak_detector_verdict'] : null;

        $blockers = [];
        if ($projectId === '') {
            $blockers[] = 'missing_project_id';
        }

        // HOLD reasons (missing optional observations).
        $missing = [];
        if ($namespaceFacts === null) {
            $missing[] = 'namespace_facts';
        }
        if ($receiptFacts === null) {
            $missing[] = 'receipt_facts';
        }
        if ($leakVerdict === null) {
            $missing[] = 'leak_detector_verdict';
        }

        // BLOCKED reasons (present but failing).
        $namespacePass = $namespaceFacts !== null && $this->namespacePass($namespaceFacts, $projectId);
        if ($namespaceFacts !== null && ! $namespacePass) {
            $blockers[] = 'namespace_policy_failed';
        }
        $receiptPass = $receiptFacts !== null && $this->receiptPass($receiptFacts, $projectId);
        if ($receiptFacts !== null && ! $receiptPass) {
            $blockers[] = 'receipt_policy_failed';
        }
        $leakPass = false;
        if ($leakVerdict !== null) {
            $leakProjectId = (string) ($leakVerdict['project_id'] ?? '');
            $leakProjectIdOk = $leakProjectId === '' || $leakProjectId === $projectId;
            $leakPass = (bool) ($leakVerdict['passed'] ?? false) && $leakProjectIdOk;
            if (! $leakProjectIdOk) {
                $blockers[] = 'leak_detector_project_id_mismatch:'.$leakProjectId;
            }
        }
        if ($leakVerdict !== null && ! $leakPass) {
            $blockers[] = 'leak_detector_failed';
            foreach ((array) ($leakVerdict['blockers'] ?? []) as $b) {
                $blockers[] = 'leak:'.(string) $b;
            }
        }

        sort($blockers, SORT_STRING);

        $status = self::STATUS_PASS;
        $passed = false;
        if ($blockers !== []) {
            $status = self::STATUS_BLOCKED;
        } elseif ($missing !== []) {
            $status = self::STATUS_HOLD;
        } else {
            $passed = true;
        }

        return [
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'passed' => $passed,
            'project_id' => $projectId,
            'blockers' => $blockers,
            'isolation_facts' => [
                'missing_observations' => $missing,
                'namespace_pass' => $namespacePass,
                'receipt_pass' => $receiptPass,
                'leak_pass' => $leakPass,
            ],
            'proof_summary' => [
                'namespace_observed' => $namespaceFacts !== null,
                'receipt_observed' => $receiptFacts !== null,
                'leak_observed' => $leakVerdict !== null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function namespacePass(array $facts, string $projectId): bool
    {
        // project_id in namespace_facts must be present, non-empty, and match the sentinel's project_id.
        $factsProj = (string) ($facts['project_id'] ?? '');
        if ($factsProj === '' || $factsProj !== $projectId) {
            return false;
        }
        // Must carry the canonical lane.<project_id>... namespace.
        $ns = (string) ($facts['namespace'] ?? '');

        return $ns !== '' && str_starts_with($ns, AtlasProjectLaneQueueNamespacePolicy::NAMESPACE_PREFIX);
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function receiptPass(array $facts, string $projectId): bool
    {
        // Either a single envelope or a list of envelopes — every receipt must carry the same project_id
        // AND a non-empty envelope_hash. Anything else = fail.
        $envelopes = $this->normalizeEnvelopes($facts);
        if ($envelopes === []) {
            return false;
        }
        foreach ($envelopes as $env) {
            if ((string) ($env['project_id'] ?? '') !== $projectId) {
                return false;
            }
            if ((string) ($env['envelope_hash'] ?? '') === '') {
                return false;
            }
            if ((string) ($env['schema'] ?? '') !== AtlasProjectLaneReceiptPolicy::SCHEMA) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return list<array<string,mixed>>
     */
    private function normalizeEnvelopes(array $facts): array
    {
        if (isset($facts['envelopes']) && is_array($facts['envelopes'])) {
            return array_values(array_filter($facts['envelopes'], 'is_array'));
        }
        if (isset($facts['envelope_hash'])) {
            return [$facts];
        }
        // Allow a list as the top-level facts value.
        $list = array_values(array_filter($facts, 'is_array'));
        if ($list !== [] && isset($list[0]['envelope_hash'])) {
            return $list;
        }

        return [];
    }
}
