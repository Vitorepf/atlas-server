<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

final class AtlasTestAttestationService
{
    public const SCHEMA_VERSION = 'atlas.test_attestation.v1';

    /** @var list<string> */
    private const RECOGNIZED_RUNNERS = ['phpunit', 'pest', 'artisan_test'];

    /**
     * @param  list<string>  $paths
     */
    public function stateHash(string $root, array $paths): string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $entries = [];
        foreach (array_values(array_unique(array_map('strval', $paths))) as $path) {
            $rel = ltrim(str_replace('\\', '/', $path), '/');
            if ($rel === '') {
                continue;
            }
            $abs = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $entries[] = [
                'path' => $rel,
                'exists' => is_file($abs),
                'sha256' => is_file($abs) ? hash_file('sha256', $abs) : null,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        return 'sha256:'.hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  list<string>  $suite
     * @return array<string,mixed>
     */
    public function attest(string $runner, array $suite, int $nTests, int $nAssertions, int $exitCode, string $treeHash): array
    {
        $runner = strtolower(trim($runner));
        $blockers = [];
        if (! in_array($runner, self::RECOGNIZED_RUNNERS, true)) {
            $blockers[] = 'runner_unrecognized';
        }
        if ($exitCode === 0 && $nTests <= 0) {
            $blockers[] = 'vacuous';
        }

        $status = $blockers === [] ? 'valid' : (in_array('vacuous', $blockers, true) ? 'vacuous' : 'invalid');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'runner' => $runner,
            'suite' => array_values(array_map('strval', $suite)),
            'n_tests' => max(0, $nTests),
            'n_assertions' => max(0, $nAssertions),
            'exit_code' => $exitCode,
            'tree_hash' => $treeHash,
            'blockers' => $blockers,
            'recognized_runners' => self::RECOGNIZED_RUNNERS,
        ];
    }

    /**
     * @param  array<string,mixed>  $attestation
     * @return array{valid:bool,status:string,blockers:list<string>}
     */
    public function validate(array $attestation, string $currentTreeHash): array
    {
        $blockers = array_values(array_filter(array_map('strval', (array) ($attestation['blockers'] ?? []))));
        $runner = strtolower(trim((string) ($attestation['runner'] ?? '')));
        if (! in_array($runner, self::RECOGNIZED_RUNNERS, true) && ! in_array('runner_unrecognized', $blockers, true)) {
            $blockers[] = 'runner_unrecognized';
        }
        if ((int) ($attestation['exit_code'] ?? 1) === 0 && (int) ($attestation['n_tests'] ?? 0) <= 0 && ! in_array('vacuous', $blockers, true)) {
            $blockers[] = 'vacuous';
        }
        if ((string) ($attestation['tree_hash'] ?? '') !== $currentTreeHash) {
            $blockers[] = 'attestation_stale';
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'valid' => $blockers === [],
            'status' => $blockers === [] ? 'valid' : (in_array('attestation_stale', $blockers, true) ? 'attestation_stale' : 'invalid'),
            'blockers' => $blockers,
        ];
    }
}
