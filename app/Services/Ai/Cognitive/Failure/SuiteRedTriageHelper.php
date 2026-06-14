<?php

namespace App\Services\Ai\Cognitive\Failure;

/**
 * L5-3 — triagem do lote vermelho da suíte (mid-refactor): classifica cada falha como
 * environmental, real_failure ou unknown, e deriva a contagem REAL de vermelhos a
 * partir das linhas do relatório. Single-source reusado pelo CLI `atlas:failure review`
 * e pelo gravador semanal `atlas:failure:weekly-red-snapshot`.
 *
 * Read-only por contrato: só LÊ e classifica; jamais corrige ou quarentena testes.
 */
class SuiteRedTriageHelper
{
    public const SCHEMA_VERSION = 'atlas.cognitive.test_suite_triage.v1';

    /**
     * @return array<string,mixed>
     */
    public function triage(?string $path): array
    {
        if ($path === null || $path === '') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'not_supplied',
                'read_only' => true,
            ];
        }

        if (! is_file($path)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'reason' => 'test_report_missing',
                'path' => $path,
                'read_only' => true,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'reason' => 'test_report_json_invalid',
                'path' => $path,
                'read_only' => true,
            ];
        }

        $tests = $this->testRows($decoded);
        $items = [];
        foreach ($tests as $test) {
            $status = mb_strtolower(trim((string) ($test['status'] ?? '')));
            if (! in_array($status, ['failed', 'failure', 'error'], true)) {
                continue;
            }
            $message = (string) ($test['message'] ?? $test['error'] ?? $test['output'] ?? '');
            $classification = $this->classifyTestFailure($message);
            $items[] = [
                'test' => (string) ($test['name'] ?? $test['test'] ?? $test['file'] ?? 'unknown_test'),
                'classification' => $classification['classification'],
                'confidence' => $classification['confidence'],
                'reason' => $classification['reason'],
                'recommended_action' => $classification['recommended_action'],
                'message_excerpt' => mb_substr($message, 0, 220),
            ];
        }

        $environmental = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'environmental'));
        $real = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'real_failure'));
        $unknown = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'unknown'));
        $failedCount = count($items);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'triaged',
            'path' => $path,
            'read_only' => true,
            'total_tests_seen' => count($tests),
            'failed_tests_seen' => $failedCount,
            'counts' => [
                'environmental' => $environmental,
                'real_failure' => $real,
                'unknown' => $unknown,
            ],
            'items' => $items,
            'red_lot_triage' => [
                'environmental' => $environmental,
                'real_failure' => $real,
                'unknown' => $unknown,
                'environmental_ratio' => $failedCount > 0 ? round($environmental / $failedCount, 3) : 0.0,
                'guidance' => 'environmental → quarentena documentada (operator review); real → fix-forward; unknown → triagem manual',
            ],
            // Trend do `history` do relatório é APENAS display — NÃO alimenta o gate.
            // O gate usa o trend dos snapshots REAIS persistidos (WeeklyRedCountSnapshotStore).
            'supplied_history_trend' => $this->suiteTrend((array) ($decoded['history'] ?? [])),
            'supplied_history_is_display_only' => true,
            'claim_policy' => [
                'auto_corrects_tests' => false,
                'auto_quarantines_tests' => false,
                'operator_review_required_for_quarantine' => true,
                'fix_forward_required_for_real_failures' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return list<array<string,mixed>>
     */
    private function testRows(array $decoded): array
    {
        $rows = $decoded['tests'] ?? $decoded['failures'] ?? $decoded;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{classification:string,confidence:float,reason:string,recommended_action:string}
     */
    public function classifyTestFailure(string $message): array
    {
        $lower = mb_strtolower($message);
        $environmentalNeedles = [
            'connection refused',
            'sqlstate',
            'database is locked',
            'redis',
            'timed out',
            'timeout',
            'permission denied',
            'no such file or directory',
            'docker',
            'chromium',
            'browser',
            'port already in use',
        ];
        foreach ($environmentalNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return [
                    'classification' => 'environmental',
                    'confidence' => 0.82,
                    'reason' => 'matched_environmental_signal:'.$needle,
                    'recommended_action' => 'document_quarantine_candidate_and_rerun_after_environment_repair',
                ];
            }
        }

        $realNeedles = [
            'failed asserting',
            'typeerror',
            'parse error',
            'undefined method',
            'undefined function',
            'expected',
            'actual',
            'assertsame',
        ];
        foreach ($realNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return [
                    'classification' => 'real_failure',
                    'confidence' => 0.78,
                    'reason' => 'matched_real_failure_signal:'.$needle,
                    'recommended_action' => 'fix_forward_code_or_test_contract_then_rerun_targeted_suite',
                ];
            }
        }

        return [
            'classification' => 'unknown',
            'confidence' => 0.4,
            'reason' => 'no_known_signal_matched',
            'recommended_action' => 'manual_triage_required_before_quarantine_or_fix_claim',
        ];
    }

    /**
     * Trend do `history` fornecido — APENAS display. Mantido por compatibilidade de
     * payload; o gate L5-3 NÃO o consome (usa WeeklyRedCountSnapshotStore::trend).
     *
     * @param  array<int,mixed>  $history
     * @return array<string,mixed>
     */
    public function suiteTrend(array $history): array
    {
        $points = [];
        foreach ($history as $row) {
            if (! is_array($row)) {
                continue;
            }
            $failed = $row['failed'] ?? $row['failed_tests'] ?? null;
            if (is_numeric($failed)) {
                $points[] = [
                    'label' => (string) ($row['week'] ?? $row['date'] ?? count($points) + 1),
                    'failed' => (int) $failed,
                ];
            }
        }

        if (count($points) < 2) {
            return [
                'status' => 'insufficient_history',
                'points' => $points,
                'weekly_reds_decreasing' => false,
            ];
        }

        $first = (int) $points[0]['failed'];
        $last = (int) $points[count($points) - 1]['failed'];

        return [
            'status' => $last < $first ? 'decreasing' : ($last > $first ? 'increasing' : 'flat'),
            'points' => $points,
            'weekly_reds_decreasing' => $last < $first,
            'delta' => $last - $first,
        ];
    }
}
