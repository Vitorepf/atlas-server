<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/**
 * Pure facts-only class that assembles the served packet's sibling_tests,
 * green_run_exemplars and known_lessons into a worker_context envelope
 * the outcome mapper can use to make smarter give_back and retry decisions.
 *
 * No I/O, no provider calls.
 */
final class AtlasNativeWorkerContextAssembler
{
    public const SCHEMA = 'atlas.native_worker.context_assembler.v1';

    /**
     * @param  list<array{sibling_tests?:list<array{test_path?:string}>, green_run_exemplars?:list<array{commit_sha?:string,task_packet_id?:string}>, known_lessons?:list<array{lesson_key?:string}>}>  $packets
     * @return array{schema:string, sibling_test_paths:list<string>, exemplar_patch_ids:list<string>, learned_failure_anti_patterns:list<string>}
     */
    public function assemble(array $packets): array
    {
        $testPaths = [];
        $exemplarIds = [];
        $antiPatterns = [];

        foreach ($packets as $packet) {
            foreach ((array) ($packet['sibling_tests'] ?? []) as $sibling) {
                $path = is_array($sibling) ? trim((string) ($sibling['test_path'] ?? '')) : '';
                if ($path !== '') {
                    $testPaths[] = $path;
                }
            }

            foreach ((array) ($packet['green_run_exemplars'] ?? []) as $exemplar) {
                $sha = is_array($exemplar) ? trim((string) ($exemplar['commit_sha'] ?? '')) : '';
                if ($sha !== '') {
                    $exemplarIds[] = $sha;
                }
                $pid = is_array($exemplar) ? trim((string) ($exemplar['task_packet_id'] ?? '')) : '';
                if ($pid !== '') {
                    $exemplarIds[] = $pid;
                }
            }

            foreach ((array) ($packet['known_lessons'] ?? []) as $lesson) {
                $key = is_array($lesson) ? trim((string) ($lesson['lesson_key'] ?? '')) : '';
                if ($key !== '') {
                    $antiPatterns[] = $key;
                }
            }
        }

        $testPaths = array_values(array_unique($testPaths));
        $exemplarIds = array_values(array_unique($exemplarIds));
        $antiPatterns = array_values(array_unique($antiPatterns));

        sort($testPaths, SORT_STRING);
        sort($exemplarIds, SORT_STRING);
        sort($antiPatterns, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'sibling_test_paths' => $testPaths,
            'exemplar_patch_ids' => $exemplarIds,
            'learned_failure_anti_patterns' => $antiPatterns,
        ];
    }
}
