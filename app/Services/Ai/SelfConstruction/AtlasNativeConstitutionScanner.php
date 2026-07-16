<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

/**
 * V3 Self-Construction · atlas-native constitution scanner.
 *
 * Pure rule core over a local repo snapshot. Command execution is only used to
 * collect R3/R4 measurements when their baselines exist and tests did not pass
 * explicit output seams.
 */
final class AtlasNativeConstitutionScanner
{
    public const SCHEMA_VERSION = 'atlas.native.constitution_scan.v1';

    public const FINDING_SCHEMA_VERSION = 'atlas.native.constitution_finding.v1';

    public const SOURCE = 'native_constitution_scan';

    /** @var array<string,array{slug:string,text:string,severity:string,policy:string}> */
    public const RULES = [
        'R1' => [
            'slug' => 'file_over_limit',
            'text' => 'View em App/Atlas > 400 linhas ou arquivo Sources > 500 linhas.',
            'severity' => 'medium',
            'policy' => 'observe',
        ],
        'R2' => [
            'slug' => 'dead_symbol',
            'text' => 'Símbolo public de AtlasCore sem consumidor em App/ e Sources fora do próprio arquivo.',
            'severity' => 'medium',
            'policy' => 'heal',
        ],
        'R3' => [
            'slug' => 'warning_regression',
            'text' => 'Build nativo contém mais warnings que o baseline registrado.',
            'severity' => 'high',
            'policy' => 'heal',
        ],
        'R4' => [
            'slug' => 'check_count_regression',
            'text' => 'AtlasCoreChecks imprimiu menos checks que o baseline registrado.',
            'severity' => 'high',
            'policy' => 'heal',
        ],
        'R5' => [
            'slug' => 'dead_theme_token',
            'text' => 'Token AtlasTheme sem uso fora da definição.',
            'severity' => 'low',
            'policy' => 'heal',
        ],
    ];

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function scan(string $repo, array $options = []): array
    {
        $repo = rtrim($repo, DIRECTORY_SEPARATOR);
        if ($repo === '' || ! is_dir($repo)) {
            throw new InvalidArgumentException('native_repo_missing_or_unreadable');
        }

        $files = $this->swiftFiles($repo);
        $findings = [
            ...$this->scanFileLimits($repo, $files),
            ...$this->scanDeadSymbols($repo, $files),
            ...$this->scanWarningRegression($repo, $options),
            ...$this->scanCheckCountRegression($repo, $options),
            ...$this->scanDeadThemeTokens($repo, $files),
        ];

        usort($findings, static fn (array $a, array $b): int => ((string) $a['finding_hash']) <=> ((string) $b['finding_hash']));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => basename($repo),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'finding_count' => count($findings),
            'findings' => $findings,
            'policy' => [
                'R1' => 'observe',
                'R2' => 'heal',
                'R3' => 'heal',
                'R4' => 'heal',
                'R5' => 'heal',
            ],
            'provider_safe' => true,
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /** @return list<array<string,mixed>> */
    private function scanFileLimits(string $repo, array $files): array
    {
        $findings = [];
        foreach ($files as $relative => $contents) {
            $limit = null;
            if (str_starts_with($relative, 'App/Atlas/') && str_contains(basename($relative), 'View')) {
                $limit = 400;
            } elseif (str_starts_with($relative, 'Sources/') && ! str_starts_with($relative, 'Sources/AtlasCoreChecks/')) {
                $limit = 500;
            }
            if ($limit === null) {
                continue;
            }

            $lineCount = $this->lineCount($contents);
            if ($lineCount > $limit) {
                $findings[] = $this->finding('R1', $relative, [
                    'file' => $relative,
                    'measure' => ['line_count' => $lineCount, 'limit' => $limit],
                ]);
            }
        }

        return $findings;
    }

    /** @return list<array<string,mixed>> */
    private function scanDeadSymbols(string $repo, array $files): array
    {
        $findings = [];
        foreach ($files as $relative => $contents) {
            if (! str_starts_with($relative, 'Sources/AtlasCore/') || str_starts_with($relative, 'Sources/AtlasCoreChecks/')) {
                continue;
            }

            foreach ($this->publicSymbols($contents) as $symbol) {
                if ($this->isStructuralCodableResponse($contents, $symbol)) {
                    continue;
                }
                $references = $this->referenceCount($files, $symbol, $relative);
                if ($references === 0) {
                    $findings[] = $this->finding('R2', $relative.':'.$symbol, [
                        'file' => $relative,
                        'symbol' => $symbol,
                        'measure' => ['references' => 0],
                    ]);
                }
            }
        }

        return $findings;
    }

    /** @return list<array<string,mixed>> */
    private function scanWarningRegression(string $repo, array $options): array
    {
        $baseline = $this->baselineInt($repo.'/docs/evidence/perf-baseline/warnings.txt');
        if ($baseline === null) {
            return [];
        }

        $output = is_string($options['build_output'] ?? null)
            ? (string) $options['build_output']
            : $this->runOptional($repo, 'cd App && make build', 180);
        $warnings = preg_match_all('/\bwarning:/i', $output);

        return $warnings > $baseline
            ? [$this->finding('R3', 'build:warnings', [
                'file' => 'docs/evidence/perf-baseline/warnings.txt',
                'measure' => ['warnings' => $warnings, 'baseline' => $baseline],
            ])]
            : [];
    }

    /** @return list<array<string,mixed>> */
    private function scanCheckCountRegression(string $repo, array $options): array
    {
        $baseline = $this->baselineInt($repo.'/docs/evidence/perf-baseline/checks.txt');
        if ($baseline === null) {
            return [];
        }

        $output = is_string($options['checks_output'] ?? null)
            ? (string) $options['checks_output']
            : $this->runOptional($repo, 'swift run AtlasCoreChecks', 180);
        $checks = $this->parseCheckCount($output);
        if ($checks === null || $checks >= $baseline) {
            return [];
        }

        return [$this->finding('R4', 'AtlasCoreChecks:count', [
            'file' => 'docs/evidence/perf-baseline/checks.txt',
            'measure' => ['checks' => $checks, 'baseline' => $baseline],
        ])];
    }

    /** @return list<array<string,mixed>> */
    private function scanDeadThemeTokens(string $repo, array $files): array
    {
        $theme = $files['App/Atlas/AtlasTheme.swift'] ?? null;
        if (! is_string($theme)) {
            return [];
        }

        preg_match_all('/\bstatic\s+(?:let|var)\s+([A-Za-z_]\w*)\b/', $theme, $matches);
        $tokens = array_values(array_unique($matches[1] ?? []));
        $findings = [];
        foreach ($tokens as $token) {
            $references = 0;
            foreach ($files as $relative => $contents) {
                if ($relative === 'App/Atlas/AtlasTheme.swift') {
                    continue;
                }
                $references += preg_match_all('/\bAtlasTheme\.'.preg_quote($token, '/').'\b/', $contents);
            }
            if ($references === 0) {
                $findings[] = $this->finding('R5', 'App/Atlas/AtlasTheme.swift:'.$token, [
                    'file' => 'App/Atlas/AtlasTheme.swift',
                    'symbol' => $token,
                    'measure' => ['references' => 0],
                ]);
            }
        }

        return $findings;
    }

    /** @return array<string,string> */
    private function swiftFiles(string $repo): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repo, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'swift') {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($repo) + 1));
            if ($this->isSkippedRelativePath($relative)) {
                continue;
            }
            $files[$relative] = (string) file_get_contents($path);
        }
        ksort($files);

        return $files;
    }

    private function isSkippedRelativePath(string $relative): bool
    {
        foreach (['.build/', 'App/build/', 'DerivedData/', '.git/'] as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function publicSymbols(string $contents): array
    {
        preg_match_all('/\bpublic\s+(?:struct|enum|class|actor|protocol)\s+([A-Za-z_]\w*)\b/', $contents, $typeMatches);
        preg_match_all('/\bpublic\s+(?:func|var|let)\s+([A-Za-z_]\w*)\b/', $contents, $memberMatches);

        return array_values(array_unique([...($typeMatches[1] ?? []), ...($memberMatches[1] ?? [])]));
    }

    private function isStructuralCodableResponse(string $contents, string $symbol): bool
    {
        if (! str_ends_with($symbol, 'Response') && ! str_ends_with($symbol, 'Payload') && ! str_ends_with($symbol, 'Input')) {
            return false;
        }

        return preg_match('/\bpublic\s+(?:struct|enum)\s+'.preg_quote($symbol, '/').'\s*:[^{\n]*\bCodable\b/', $contents) === 1;
    }

    private function referenceCount(array $files, string $symbol, string $ownFile): int
    {
        $count = 0;
        foreach ($files as $relative => $contents) {
            if ($relative === $ownFile || str_starts_with($relative, 'Sources/AtlasCoreChecks/')) {
                continue;
            }
            if (! str_starts_with($relative, 'App/') && ! str_starts_with($relative, 'Sources/')) {
                continue;
            }
            $count += preg_match_all('/\b'.preg_quote($symbol, '/').'\b/', $contents);
        }

        return $count;
    }

    /** @return array<string,mixed> */
    private function finding(string $ruleId, string $target, array $evidence): array
    {
        $rule = self::RULES[$ruleId] ?? throw new InvalidArgumentException('unknown_native_constitution_rule');
        $hash = sha1($ruleId.'|'.$target);
        $severity = $rule['severity'];
        $policy = $rule['policy'];

        return [
            'schema_version' => self::FINDING_SCHEMA_VERSION,
            'finding_id' => 'native_constitution_'.substr($hash, 0, 16),
            'finding_hash' => 'sha1:'.$hash,
            'rule_id' => $ruleId,
            'rule_slug' => $rule['slug'],
            'rule_text' => $rule['text'],
            'title' => $ruleId.': '.$this->humanTitle($rule['slug'], $target),
            'severity' => $severity,
            'risk_level' => $severity,
            'policy' => $policy,
            'target' => $target,
            'source' => self::SOURCE,
            'source_owner' => 'self_construction',
            'gap_kind' => $rule['slug'],
            'route' => $policy === 'observe' ? 'inbox_only' : 'atlas_dev',
            'priority_score' => $this->priorityScore($severity, $policy),
            'safe_to_autofix' => $policy === 'heal',
            'requires_operator_review' => $policy === 'observe',
            'evidence' => $evidence,
            'evidence_refs' => [
                'rule:'.$ruleId,
                'target:'.$target,
            ],
            'affected_paths' => isset($evidence['file']) && is_string($evidence['file']) ? [$evidence['file']] : [],
            'recommended_action' => $policy === 'observe'
                ? 'Registrar decisão do operador antes de transformar a estrutura do app.'
                : 'Encaminhar ao owner para cura mecânica com gates antes de merge.',
        ];
    }

    private function humanTitle(string $slug, string $target): string
    {
        return match ($slug) {
            'file_over_limit' => $target.' passou do limite da constituição',
            'dead_symbol' => $target.' não tem consumidor público',
            'warning_regression' => 'warnings do build passaram do baseline',
            'check_count_regression' => 'AtlasCoreChecks ficou abaixo do baseline',
            'dead_theme_token' => $target.' não é usado pela casca',
            default => $target,
        };
    }

    private function priorityScore(string $severity, string $policy): int
    {
        $base = match ($severity) {
            'high' => 30,
            'medium' => 20,
            'low' => 10,
            default => 0,
        };

        return $base + ($policy === 'heal' ? 2 : 1);
    }

    private function lineCount(string $contents): int
    {
        if ($contents === '') {
            return 0;
        }

        return substr_count($contents, "\n") + (! str_ends_with($contents, "\n") ? 1 : 0);
    }

    private function baselineInt(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }
        $contents = (string) file_get_contents($path);

        return preg_match('/\d+/', $contents, $match) === 1 ? (int) $match[0] : null;
    }

    private function parseCheckCount(string $output): ?int
    {
        if (preg_match('/(\d+)\s+(?:golden\s+)?checks?\s+(?:passed|verdes|ok)?/i', $output, $match) === 1) {
            return (int) $match[1];
        }
        if (preg_match('/checks?\s*[:=]\s*(\d+)/i', $output, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private function runOptional(string $repo, string $command, int $timeout): string
    {
        $process = Process::fromShellCommandline($command, $repo, null, null, $timeout);
        $process->run();

        return $process->getOutput()."\n".$process->getErrorOutput();
    }

    /** @param array<string,mixed> $report */
    public function writeFindingCache(array $report, ?string $path = null): string
    {
        $path ??= $this->defaultFindingCachePath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $path;
    }

    public function defaultFindingCachePath(): string
    {
        return storage_path('app/atlas/native-constitution/findings.json');
    }

    /** @return array<string,mixed>|null */
    public function readFindingCache(?string $path = null): ?array
    {
        $path ??= $this->defaultFindingCachePath();
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['generated_at'], $payload['report_hash']);

        return $payload;
    }
}
