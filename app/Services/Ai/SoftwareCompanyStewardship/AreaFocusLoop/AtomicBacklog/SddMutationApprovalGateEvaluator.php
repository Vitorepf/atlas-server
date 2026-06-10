<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

final class SddMutationApprovalGateEvaluator
{
    private const SCHEMA_VERSION = 'atlas.sdd.mutation_approval_gate.v1';

    private const VERDICT_ALLOWED = 'allowed';

    private const VERDICT_NEEDS_HUMAN_APPROVAL = 'needs_human_approval';

    private const VERDICT_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $approval
     * @return array<string,mixed>
     */
    public function evaluate(array $request, array $gate, array $approval): array
    {
        // A read-only / dry request can never write, so it is intrinsically
        // safe: it short-circuits before any hard block or human escalation.
        if (! $this->requestMutates($request)) {
            return $this->result(
                self::VERDICT_ALLOWED,
                false,
                ['sdd_decision_receipt'],
                [],
            );
        }

        $blockers = [];

        if (! $this->gatePassed($gate)) {
            $blockers[] = 'gate_failed';
        }

        if (! $this->targetWithinAllowedFiles($request)) {
            $blockers[] = 'target_outside_allowed_files';
        }

        if ($blockers !== []) {
            return $this->result(
                self::VERDICT_BLOCKED,
                false,
                ['sdd_decision_receipt', 'block_decision_receipt'],
                $blockers,
            );
        }

        if (! $this->approvalGranted($approval)) {
            return $this->result(
                self::VERDICT_NEEDS_HUMAN_APPROVAL,
                false,
                ['sdd_decision_receipt', 'human_approval_receipt'],
                ['human_approval_missing'],
            );
        }

        return $this->result(
            self::VERDICT_ALLOWED,
            true,
            ['sdd_decision_receipt', 'human_approval_receipt', 'mutation_write_receipt'],
            [],
        );
    }

    /**
     * @param  array<string,mixed>  $request
     */
    private function requestMutates(array $request): bool
    {
        if ($this->boolFlag($request, 'dry_run', false)) {
            return false;
        }

        if (array_key_exists('write', $request) && $this->boolValue($request['write']) === false) {
            return false;
        }

        $mode = AreaFocusScalarNormalizer::trimmedStringOnly($request['mode'] ?? null);
        if ($mode === 'dry' || $mode === 'dry_run' || $mode === 'read_only' || $mode === 'read-only') {
            return false;
        }

        $intent = AreaFocusScalarNormalizer::trimmedStringOnly($request['intent'] ?? null);
        if ($intent === 'read' || $intent === 'read_only' || $intent === 'inspect') {
            return false;
        }

        $method = strtoupper(AreaFocusScalarNormalizer::trimmedStringOnly($request['method'] ?? 'POST'));
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $gate
     */
    private function gatePassed(array $gate): bool
    {
        if (array_key_exists('passed', $gate)) {
            return $this->boolValue($gate['passed']);
        }

        $status = AreaFocusScalarNormalizer::trimmedStringOnly($gate['status'] ?? null);
        if ($status !== '') {
            return $status === 'passed' || $status === 'pass' || $status === 'ok';
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $request
     */
    private function targetWithinAllowedFiles(array $request): bool
    {
        $allowed = AreaFocusStringListNormalizer::trimmedStrings($request['allowed_files'] ?? null);

        $targets = $this->resolveTargets($request);

        if ($targets === []) {
            // Nothing to write means nothing falls outside the allowed set.
            return true;
        }

        foreach ($targets as $target) {
            if (! in_array($target, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $request
     * @return list<string>
     */
    private function resolveTargets(array $request): array
    {
        $single = AreaFocusScalarNormalizer::trimmedStringOnly($request['target'] ?? ($request['target_path'] ?? null));

        if ($single !== '') {
            return [$single];
        }

        return AreaFocusStringListNormalizer::trimmedStrings($request['targets'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $approval
     */
    private function approvalGranted(array $approval): bool
    {
        $granted = $this->boolFlag($approval, 'granted', false)
            || $this->boolFlag($approval, 'approved', false);

        if (! $granted) {
            return false;
        }

        // When a signature requirement is expressed, an unsigned approval does
        // not count as a granted human decision.
        if (array_key_exists('signed', $approval)) {
            return $this->boolValue($approval['signed']);
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function boolFlag(array $payload, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $payload)) {
            return $default;
        }

        return $this->boolValue($payload[$key]);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return $normalized === 'true' || $normalized === '1' || $normalized === 'yes';
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }

    /**
     * @param  list<string>  $requiredReceipts
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function result(string $verdict, bool $writeAllowed, array $requiredReceipts, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'write_allowed' => $writeAllowed,
            'required_receipts' => $requiredReceipts,
            'blockers' => $blockers,
        ];
    }
}
