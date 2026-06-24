<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

final class AtlasLoopTaskClassMinerSupport
{
    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @return array<string, string>
     */
    public function indexOutcomeLabels(array $outcomes): array
    {
        $indexed = [];

        foreach ($outcomes as $row) {
            if (! is_array($row)) {
                continue;
            }

            $target = $this->normalizePath((string) ($row['target'] ?? ''));
            $label = $this->outcomeLabel((string) ($row['status'] ?? ''), $row['reason'] ?? null);
            if ($target === '' || $label === '') {
                continue;
            }

            $indexed[$target] = $label;
        }

        ksort($indexed);

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function packetId(array $record): string
    {
        foreach (['task_packet_id', 'packet_id'] as $key) {
            $value = trim((string) ($record[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function clusterKey(array $record): string
    {
        $explicit = trim((string) ($record['task_class_key'] ?? $record['cluster_key'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $packetId = $this->packetId($record);
        $stem = preg_replace('/-\d+$/', '', $packetId);
        $stem = is_string($stem) ? trim($stem) : '';

        $kinds = $this->evidenceKinds($record);
        if ($stem === '' && $kinds === []) {
            return '';
        }

        return $stem.'|'.implode(',', $kinds);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    public function evidenceKinds(array $record): array
    {
        $kinds = [];

        $single = trim((string) ($record['evidence_kind'] ?? ''));
        if ($single !== '') {
            $kinds[] = $single;
        }

        $multi = $record['evidence_kinds'] ?? null;
        if (is_array($multi)) {
            foreach ($multi as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $normalized = trim($value);
                if ($normalized !== '') {
                    $kinds[] = $normalized;
                }
            }
        }

        $kinds = array_values(array_unique($kinds));
        sort($kinds);

        return $kinds;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function targetPath(array $record): string
    {
        foreach (['target_path', 'target'] as $key) {
            $value = $this->normalizePath((string) ($record[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $allowedFiles = $record['allowed_files'] ?? null;
        if (is_array($allowedFiles)) {
            foreach ($allowedFiles as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $normalized = $this->normalizePath($value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    /**
     * @param  list<array{packet_id:string,evidence_kinds:list<string>}>  $members
     */
    public function cohesionMetric(array $members): float
    {
        if ($members === []) {
            return 0.0;
        }

        $histogram = [];
        foreach ($members as $member) {
            foreach ($member['evidence_kinds'] as $kind) {
                $histogram[$kind] = ($histogram[$kind] ?? 0) + 1;
            }
        }

        if ($histogram === []) {
            return 1.0;
        }

        arsort($histogram);
        $dominant = (int) reset($histogram);

        return round($dominant / count($members), 4);
    }

    public function normalizePath(string $path): string
    {
        return strtolower(ltrim(trim(str_replace('\\', '/', $path)), '/'));
    }

    public function outcomeLabel(string $status, mixed $reason = null): string
    {
        $status = trim($status);
        if ($status === 'converged') {
            return 'success';
        }

        if ($status === 'parked') {
            return 'refused';
        }

        $reason = trim((string) $reason);

        return $reason;
    }
}
