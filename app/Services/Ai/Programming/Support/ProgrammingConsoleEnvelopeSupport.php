<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use App\Services\Ai\Programming\Console\ProgrammingConsoleCanon;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneCanon;
use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Pure envelope shaping for {@see \App\Services\Ai\Programming\Console\ProgrammingConsoleService}.
 *
 * Status/intent/blocker/action/evidence mappers only — no DB, no services, no clock.
 */
final class ProgrammingConsoleEnvelopeSupport
{
    public static function mapStatus(string $raw): string
    {
        return match ($raw) {
            ProgrammingRuntimeControlPlaneCanon::STATUS_GREEN => ProgrammingConsoleCanon::STATUS_GREEN,
            ProgrammingRuntimeControlPlaneCanon::STATUS_PARTIAL => ProgrammingConsoleCanon::STATUS_PARTIAL,
            ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED => ProgrammingConsoleCanon::STATUS_BLOCKED,
            default => ProgrammingConsoleCanon::STATUS_PARTIAL,
        };
    }

    /**
     * @param  array<int|string,mixed>  $blockers
     * @return list<array<string,mixed>>
     */
    public static function normalizeBlockers(array $blockers): array
    {
        $items = (array) ($blockers['items'] ?? $blockers);
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $out[] = [
                'source' => (string) ($item['source'] ?? 'unknown'),
                'severity' => (string) ($item['severity'] ?? 'warn'),
                'id' => (string) ($item['id'] ?? 'unknown'),
                'message' => (string) ($item['message'] ?? ''),
                'evidence_refs' => array_values((array) ($item['evidence_refs'] ?? [])),
                'remediation' => $item['remediation'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $actions
     * @return list<array<string,mixed>>
     */
    public static function normalizeNextActions(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $out[] = [
                'priority' => (string) ($action['priority'] ?? 'normal'),
                'source' => (string) ($action['source'] ?? 'console'),
                'description' => (string) ($action['description'] ?? ''),
                'evidence_refs' => array_values((array) ($action['evidence_refs'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $readinessBlockers
     * @return list<array<string,mixed>>
     */
    public static function normalizeReadinessBlockers(array $readinessBlockers): array
    {
        $out = [];
        foreach ($readinessBlockers as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $out[] = [
                'source' => 'readiness',
                'severity' => (string) ($blocker['severity'] ?? 'P2'),
                'id' => (string) ($blocker['id'] ?? 'unknown'),
                'message' => (string) ($blocker['detail'] ?? ''),
                'evidence_refs' => array_values((array) ($blocker['evidence'] ?? [])),
                'remediation' => $blocker['remediation'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $readinessActions
     * @return list<array<string,mixed>>
     */
    public static function normalizeReadinessNextActions(array $readinessActions): array
    {
        $out = [];
        foreach ($readinessActions as $action) {
            if (! is_string($action) || trim($action) === '') {
                continue;
            }
            $out[] = [
                'priority' => 'normal',
                'source' => 'readiness',
                'description' => trim($action),
                'evidence_refs' => [],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<string>
     */
    public static function extractEvidenceFromSnapshot(array $snapshot): array
    {
        $refs = [];
        foreach ((array) ($snapshot['blockers']['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['evidence_refs']) && is_array($item['evidence_refs'])) {
                foreach ($item['evidence_refs'] as $ref) {
                    if (is_string($ref) && $ref !== '') {
                        $refs[$ref] = true;
                    }
                }
            }
        }
        $cert = (array) ($snapshot['certification_summary'] ?? []);
        if (! empty($cert['temporal_certification']['id'])) {
            $refs['temporal_certification:'.$cert['temporal_certification']['id']] = true;
        }
        $keys = array_keys($refs);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string,mixed>  $certification
     */
    public static function deriveCertificationStatus(array $certification): string
    {
        $byStatus = (array) ($certification['mission_certifications_by_status'] ?? []);
        if ($byStatus === []) {
            $temporal = (array) ($certification['temporal_certification'] ?? []);
            $temporalStatus = (string) ($temporal['status'] ?? '');
            if ($temporalStatus === 'passed') {
                return ProgrammingConsoleCanon::CERTIFICATION_STATUS_PASSED;
            }
            if ($temporalStatus === 'blocked' || $temporalStatus === 'failed') {
                return ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED;
            }

            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED;
        }

        $passed = (int) ($byStatus['passed'] ?? 0);
        $failed = (int) ($byStatus['failed'] ?? 0);
        $blocked = (int) ($byStatus['blocked'] ?? 0);
        $total = (int) array_sum(array_map('intval', $byStatus));

        if ($total === 0) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_NOT_EVALUATED;
        }
        if ($blocked > 0) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_BLOCKED;
        }
        if ($failed > 0) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_FAILED;
        }
        if ($passed > 0 && $passed === $total) {
            return ProgrammingConsoleCanon::CERTIFICATION_STATUS_PASSED;
        }

        return ProgrammingConsoleCanon::CERTIFICATION_STATUS_PARTIAL;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    public static function summaryStatus(array $summary): string
    {
        $available = (bool) ($summary['available'] ?? false);
        if (! $available) {
            return ProgrammingConsoleCanon::STATUS_PARTIAL;
        }
        $count = (int) ($summary['count'] ?? 0);

        return $count > 0
            ? ProgrammingConsoleCanon::STATUS_GREEN
            : ProgrammingConsoleCanon::STATUS_PARTIAL;
    }

    /**
     * @param  array<string,mixed>  $completeness
     */
    public static function evidenceStatus(array $completeness): string
    {
        $ratio = $completeness['evidence_present_ratio'] ?? null;
        if (! is_numeric($ratio)) {
            return ProgrammingConsoleCanon::STATUS_PARTIAL;
        }
        $ratio = (float) $ratio;
        if ($ratio >= 0.85) {
            return ProgrammingConsoleCanon::STATUS_GREEN;
        }

        return $ratio > 0
            ? ProgrammingConsoleCanon::STATUS_PARTIAL
            : ProgrammingConsoleCanon::STATUS_BLOCKED;
    }

    public static function normalizeIntent(string $prompt): string
    {
        $lower = mb_strtolower(trim($prompt));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($ascii === false) {
            $ascii = $lower;
        }

        return preg_replace('/\s+/u', ' ', $ascii) ?? $lower;
    }

    /**
     * @param  array<int,array<string,mixed>>  $packs
     * @return list<string>
     */
    public static function packEvidenceRefs(array $packs): array
    {
        $refs = [];
        foreach ($packs as $pack) {
            if (! empty($pack['uuid'])) {
                $refs[] = 'continuation_pack:'.$pack['uuid'];
            } elseif (! empty($pack['id'])) {
                $refs[] = 'continuation_pack:'.$pack['id'];
            }
        }

        return AiStringListNormalizer::uniqueStrings($refs);
    }

    /**
     * @return array{id: string, status: string, severity: string, detail: string, evidence_refs: list<mixed>}
     */
    public static function certifyCheck(string $id, bool $ok, string $detail, string $severity = 'high'): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'passed' : 'blocked',
            'severity' => $severity,
            'detail' => $detail,
            'evidence_refs' => [],
        ];
    }
}
