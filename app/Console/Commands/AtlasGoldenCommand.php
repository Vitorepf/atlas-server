<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * P5 (Obra #19, Frente P) — `atlas:golden freeze|check <name>`: the ad-hoc
 * golden-hash pattern of obras #8/#12 becomes an organ. `freeze` canonicalises a
 * JSON artifact and records a manifest of per-case hashes; `check` re-canonicalises
 * the current artifact and diffs it PER CASE, so drift points at the exact case.
 *
 * GOTCHA (the reason this exists): canonicalisation uses DEEP recursive key
 * ordering via {@see MissionCanonicalHash::canonicalJson} — NOT ReadinessHash::stable,
 * which only ksorts the top level and would let a reordered nested map slip through.
 *
 * ponytail: the "target" is a JSON artifact the caller produces (deterministically,
 * e.g. under setTestNow) — this organ owns the freeze/diff, not the run, so it never
 * fights cross-process clock state.
 */
class AtlasGoldenCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:golden
        {action : freeze|check}
        {name : golden set name}
        {--from= : path to the JSON artifact (required)}
        {--dir= : golden manifest dir (default: storage/atlas/golden)}
        {--json : machine-readable output}';

    protected $description = 'P5 · freeze/check a golden manifest of per-case DEEP-canonical hashes (Obra #19).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $this->argument('name')) ?: 'golden';
        $dir = rtrim((string) ($this->option('dir') ?: storage_path('atlas/golden')), '/');
        $from = (string) $this->option('from');

        if ($from === '' || ! is_file($from)) {
            return $this->emit(['ok' => false, 'reason' => 'from_artifact_missing'], self::FAILURE);
        }
        try {
            $data = json_decode((string) file_get_contents($from), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return $this->emit(['ok' => false, 'reason' => 'from_not_json', 'detail' => $e->getMessage()], self::FAILURE);
        }

        $cases = $this->cases($data);

        return match ($action) {
            'freeze' => $this->freeze($name, $dir, $data, $cases),
            'check' => $this->check($name, $dir, $data, $cases),
            default => $this->emit(['ok' => false, 'reason' => 'unknown_action_use_freeze_or_check'], self::FAILURE),
        };
    }

    /**
     * Split the artifact into named cases: an associative map freezes per key; a list
     * or scalar freezes as one 'root' case.
     *
     * @return array<string,string> case => deep-canonical hash
     */
    private function cases(mixed $data): array
    {
        // An empty array is a list, so `! array_is_list` already excludes it — a
        // non-list array is always a non-empty associative map here.
        if (is_array($data) && ! array_is_list($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                $out[(string) $key] = MissionCanonicalHash::sha256($value);
            }

            return $out;
        }

        return ['root' => MissionCanonicalHash::sha256($data)];
    }

    /**
     * @param  array<string,string>  $cases
     */
    private function freeze(string $name, string $dir, mixed $data, array $cases): int
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $manifest = [
            'schema' => 'atlas.golden.manifest.v1',
            'name' => $name,
            'root_hash' => MissionCanonicalHash::sha256($data),
            'case_count' => count($cases),
            'cases' => $cases,
        ];
        file_put_contents($dir.'/'.$name.'.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $this->emit(['ok' => true, 'action' => 'freeze', 'name' => $name, 'case_count' => count($cases), 'root_hash' => $manifest['root_hash']], self::SUCCESS);
    }

    /**
     * @param  array<string,string>  $cases
     */
    private function check(string $name, string $dir, mixed $data, array $cases): int
    {
        $path = $dir.'/'.$name.'.json';
        if (! is_file($path)) {
            return $this->emit(['ok' => false, 'reason' => 'no_frozen_manifest', 'name' => $name], self::FAILURE);
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        $frozen = (array) ($manifest['cases'] ?? []);

        $changed = [];
        $removed = [];
        foreach ($frozen as $case => $hash) {
            if (! array_key_exists($case, $cases)) {
                $removed[] = $case;
            } elseif ($cases[$case] !== $hash) {
                $changed[] = $case;
            }
        }
        $added = array_values(array_diff(array_keys($cases), array_keys($frozen)));

        $ok = $changed === [] && $removed === [] && $added === [];

        return $this->emit([
            'ok' => $ok,
            'action' => 'check',
            'name' => $name,
            'root_hash_match' => ($manifest['root_hash'] ?? null) === MissionCanonicalHash::sha256($data),
            'changed_cases' => $changed,
            'added_cases' => $added,
            'removed_cases' => $removed,
        ], $ok ? self::SUCCESS : self::FAILURE);
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        if ($this->option('json')) {
            $this->line($this->encode($payload));
        } elseif (($payload['ok'] ?? false) === true) {
            $this->info(sprintf('golden %s OK · %s (%s cases)', $payload['action'] ?? '?', $payload['name'] ?? '?', $payload['case_count'] ?? count((array) ($payload['changed_cases'] ?? []))));
        } else {
            $this->error('golden '.($payload['reason'] ?? 'drift').': '.implode(',', (array) ($payload['changed_cases'] ?? [])));
        }

        return $code;
    }
}
