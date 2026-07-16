<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * V3 Self-Construction · mechanical healer for native constitution findings.
 *
 * AP-786 / senior-loop is the wrong tool for R2 dead-symbol cleanup: those
 * findings are deterministic and already marked safe_to_autofix. This healer
 * deletes the orphan public symbol file when evidence.references == 0 and the
 * file only defines that symbol, then re-scans to prove the finding is gone.
 *
 * dry_run is the default path; execute mutates the working tree. No merge,
 * no provider, no fabricated delivered cycle.
 */
final class AtlasNativeConstitutionHealer
{
    public const SCHEMA_VERSION = 'atlas.native.constitution_heal.v1';

    public function __construct(
        private readonly AtlasNativeConstitutionScanner $scanner = new AtlasNativeConstitutionScanner,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function heal(string $repo, string $findingHash, bool $execute = false): array
    {
        $repo = rtrim($repo, DIRECTORY_SEPARATOR);
        if ($repo === '' || ! is_dir($repo)) {
            throw new InvalidArgumentException('repo_missing');
        }

        $hash = trim($findingHash);
        if ($hash === '') {
            return $this->blocked('finding_hash_required');
        }

        $report = $this->scanner->scan($repo);
        $finding = $this->findByHash((array) ($report['findings'] ?? []), $hash);
        if ($finding === null) {
            return $this->blocked('finding_not_found', [
                'finding_hash' => $hash,
                'finding_count' => (int) ($report['finding_count'] ?? 0),
            ]);
        }

        if (($finding['policy'] ?? '') !== 'heal') {
            return $this->blocked('policy_not_heal', [
                'finding_hash' => $hash,
                'rule_id' => $finding['rule_id'] ?? null,
                'policy' => $finding['policy'] ?? null,
            ]);
        }

        if (($finding['rule_id'] ?? '') !== 'R2') {
            return $this->blocked('rule_not_mechanically_healable', [
                'finding_hash' => $hash,
                'rule_id' => $finding['rule_id'] ?? null,
                'detail' => 'v1 mechanical healer only supports R2 dead_symbol file deletion.',
            ]);
        }

        $relative = $this->relativeFile($finding);
        if ($relative === null) {
            return $this->blocked('evidence_file_missing', ['finding_hash' => $hash]);
        }

        $absolute = $repo.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (! is_file($absolute)) {
            return $this->blocked('file_not_found', [
                'finding_hash' => $hash,
                'file' => $relative,
            ]);
        }

        $refs = (int) data_get($finding, 'evidence.measure.references', -1);
        if ($refs !== 0) {
            return $this->blocked('references_not_zero', [
                'finding_hash' => $hash,
                'references' => $refs,
            ]);
        }

        $symbol = (string) data_get($finding, 'evidence.symbol', '');
        if ($symbol === '' || ! $this->fileIsExclusivePublicSymbol($absolute, $symbol)) {
            return $this->blocked('file_not_exclusive_dead_symbol', [
                'finding_hash' => $hash,
                'file' => $relative,
                'symbol' => $symbol,
                'detail' => 'Mechanical delete only when the file solely defines the dead public symbol.',
            ]);
        }

        if (! $execute) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'dry_run_planned',
                'executed' => false,
                'mutated' => false,
                'finding_hash' => $hash,
                'rule_id' => 'R2',
                'policy' => 'heal',
                'files_to_delete' => [$relative],
                'symbol' => $symbol,
                'provider_invoked' => false,
                'merge_performed' => false,
            ];
        }

        File::delete($absolute);

        $after = $this->scanner->scan($repo);
        $stillThere = $this->findByHash((array) ($after['findings'] ?? []), $hash) !== null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $stillThere ? 'blocked' : 'healed',
            'reason' => $stillThere ? 'finding_still_present_after_delete' : null,
            'executed' => true,
            'mutated' => true,
            'finding_hash' => $hash,
            'rule_id' => 'R2',
            'policy' => 'heal',
            'files_deleted' => [$relative],
            'symbol' => $symbol,
            'finding_present_after' => $stillThere,
            'finding_count_after' => (int) ($after['finding_count'] ?? 0),
            'provider_invoked' => false,
            'merge_performed' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,mixed>|null
     */
    private function findByHash(array $findings, string $hash): ?array
    {
        foreach ($findings as $finding) {
            if (is_array($finding) && (string) ($finding['finding_hash'] ?? '') === $hash) {
                return $finding;
            }
        }

        return null;
    }

    /** @param  array<string,mixed>  $finding */
    private function relativeFile(array $finding): ?string
    {
        $file = data_get($finding, 'evidence.file');
        if (is_string($file) && $file !== '') {
            return str_replace('\\', '/', $file);
        }

        $paths = $finding['affected_paths'] ?? [];
        if (is_array($paths) && isset($paths[0]) && is_string($paths[0]) && $paths[0] !== '') {
            return str_replace('\\', '/', $paths[0]);
        }

        $target = (string) ($finding['target'] ?? '');
        if (str_contains($target, ':')) {
            return str_replace('\\', '/', explode(':', $target, 2)[0]);
        }

        return null;
    }

    private function fileIsExclusivePublicSymbol(string $absolute, string $symbol): bool
    {
        $source = (string) file_get_contents($absolute);
        if ($source === '' || ! preg_match('/\bpublic\s+(?:enum|struct|class|actor|protocol)\s+'.preg_quote($symbol, '/').'\b/', $source)) {
            return false;
        }

        preg_match_all('/\bpublic\s+(?:enum|struct|class|actor|protocol)\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $source, $matches);
        $publicTypes = array_values(array_unique($matches[1] ?? []));

        return $publicTypes === [$symbol];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $reason, array $extra = []): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'reason' => $reason,
            'executed' => false,
            'mutated' => false,
            'provider_invoked' => false,
            'merge_performed' => false,
        ], $extra);
    }
}
