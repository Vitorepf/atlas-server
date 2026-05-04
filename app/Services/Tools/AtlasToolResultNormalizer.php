<?php

namespace App\Services\Tools;

use Illuminate\Support\Str;

class AtlasToolResultNormalizer
{
    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function normalize(string $toolSlug, array $raw): array
    {
        $rawFindings = (array) ($raw['findings'] ?? []);
        if ($rawFindings === [] && (isset($raw['stdout']) || isset($raw['stderr']))) {
            $rawFindings = $this->findingsFromOutput(
                $toolSlug,
                is_string($raw['stdout'] ?? null) ? (string) $raw['stdout'] : '',
                is_string($raw['stderr'] ?? null) ? (string) $raw['stderr'] : '',
            );
        }

        $stdout = is_string($raw['stdout'] ?? null) ? (string) $raw['stdout'] : '';
        $stderr = is_string($raw['stderr'] ?? null) ? (string) $raw['stderr'] : '';
        $metrics = (array) ($raw['metrics'] ?? []);
        if ($metrics === [] && ($stdout !== '' || $stderr !== '')) {
            $metrics = $this->metricsFromOutput($toolSlug, $stdout, $stderr);
        }

        $artifacts = (array) ($raw['artifacts'] ?? []);
        if ($artifacts === [] && ($stdout !== '' || $stderr !== '')) {
            $artifacts = $this->artifactsFromOutput($toolSlug, $stdout, $stderr);
        }

        $findings = collect($rawFindings)
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->map(fn (array $finding): array => $this->finding($toolSlug, $finding))
            ->values()
            ->all();

        $status = (string) ($raw['status'] ?? 'unknown');
        $blockingFailures = collect($findings)->where('blocks_resolved', true)->values()->all();

        return [
            'tool' => $toolSlug,
            'status' => $status,
            'required' => (bool) ($raw['required'] ?? false),
            'exit_code' => $raw['exit_code'] ?? null,
            'duration_ms' => (int) ($raw['duration_ms'] ?? 0),
            'findings' => $findings,
            'metrics' => $metrics,
            'artifacts' => $artifacts,
            'recommendations' => (array) ($raw['recommendations'] ?? []),
            'blocking_failures' => $blockingFailures,
            'summary' => [
                'finding_count' => count($findings),
                'blocking_finding_count' => count($blockingFailures),
                'severity_counts' => collect($findings)->countBy('severity')->all(),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findingsFromOutput(string $toolSlug, string $stdout, string $stderr = ''): array
    {
        if ($toolSlug === 'typescript') {
            return $this->parseTypeScriptText($stdout."\n".$stderr);
        }

        $payload = $this->jsonPayload($stdout) ?? $this->jsonPayload($stderr);
        if (! is_array($payload)) {
            return [];
        }

        return match ($toolSlug) {
            'gitleaks' => $this->parseGitleaks($payload),
            'semgrep' => $this->parseSemgrep($payload),
            'laravel_pint' => $this->parsePint($payload),
            'biome' => $this->parseBiome($payload),
            'eslint' => $this->parseEslint($payload),
            'phpstan' => $this->parsePhpstan($payload),
            'psalm' => $this->parsePsalm($payload),
            'shellcheck' => $this->parseShellCheck($payload),
            'hadolint' => $this->parseHadolint($payload),
            'codeql', 'checkov' => $this->parseSarif($payload, $toolSlug),
            'trivy' => $this->parseTrivy($payload),
            'osv_scanner' => $this->parseOsvScanner($payload),
            'grype' => $this->parseGrype($payload),
            default => [],
        };
    }

    /**
     * @return array<mixed>|null
     */
    private function jsonPayload(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }

        $payload = json_decode($output, true);
        if (is_array($payload)) {
            return $payload;
        }

        $start = strpos($output, '{');
        $arrayStart = strpos($output, '[');
        $offsets = array_values(array_filter([$start, $arrayStart], fn (int|false $offset): bool => $offset !== false));
        if ($offsets === []) {
            return null;
        }

        $payload = json_decode(substr($output, min($offsets)), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseGitleaks(array $payload): array
    {
        return collect($payload)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'severity' => 'critical',
                'rule_id' => $item['RuleID'] ?? $item['rule'] ?? 'gitleaks',
                'title' => $item['Description'] ?? 'Secret detected',
                'file' => $item['File'] ?? null,
                'line' => $item['StartLine'] ?? null,
                'end_line' => $item['EndLine'] ?? null,
                'message' => $item['Description'] ?? 'Gitleaks detected a secret-like value.',
                'blocks_resolved' => true,
                'metadata' => [
                    'commit' => $item['Commit'] ?? null,
                    'author' => $item['Author'] ?? null,
                    'date' => $item['Date'] ?? null,
                    'tags' => $item['Tags'] ?? [],
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function metricsFromOutput(string $toolSlug, string $stdout, string $stderr = ''): array
    {
        $payload = $this->jsonPayload($stdout) ?? $this->jsonPayload($stderr);
        if (! is_array($payload)) {
            return [];
        }

        return match ($toolSlug) {
            'syft' => $this->syftMetrics($payload),
            'trivy' => $this->trivyMetrics($payload),
            'grype' => $this->grypeMetrics($payload),
            'osv_scanner' => $this->osvScannerMetrics($payload),
            default => [],
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function artifactsFromOutput(string $toolSlug, string $stdout, string $stderr = ''): array
    {
        $payload = $this->jsonPayload($stdout) ?? $this->jsonPayload($stderr);
        if (! is_array($payload)) {
            return [];
        }

        return match ($toolSlug) {
            'syft' => [[
                'type' => 'sbom_summary',
                'name' => 'syft_packages',
                'package_count' => count((array) ($payload['artifacts'] ?? [])),
                'source' => data_get($payload, 'source.name') ?: data_get($payload, 'source.target'),
            ]],
            default => [],
        };
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseSemgrep(array $payload): array
    {
        return collect((array) ($payload['results'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $severity = strtolower((string) data_get($item, 'extra.severity', 'warning'));
                $normalizedSeverity = match (true) {
                    in_array($severity, ['error', 'critical', 'high'], true) => 'high',
                    $severity === 'warning' || $severity === 'medium' => 'medium',
                    default => 'low',
                };

                return [
                    'severity' => $normalizedSeverity,
                    'rule_id' => $item['check_id'] ?? 'semgrep',
                    'title' => data_get($item, 'extra.message', 'Semgrep finding'),
                    'file' => $item['path'] ?? null,
                    'line' => data_get($item, 'start.line'),
                    'end_line' => data_get($item, 'end.line'),
                    'message' => data_get($item, 'extra.message', 'Semgrep finding'),
                    'blocks_resolved' => in_array($normalizedSeverity, ['critical', 'high'], true),
                    'metadata' => [
                        'metadata' => data_get($item, 'extra.metadata', []),
                        'fingerprint' => data_get($item, 'extra.fingerprint'),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseEslint(array $payload): array
    {
        return collect($payload)
            ->filter(fn (mixed $file): bool => is_array($file))
            ->flatMap(function (array $file): array {
                return collect((array) ($file['messages'] ?? []))
                    ->filter(fn (mixed $message): bool => is_array($message))
                    ->map(fn (array $message): array => [
                        'severity' => ((int) ($message['severity'] ?? 0)) >= 2 ? 'high' : 'medium',
                        'rule_id' => $message['ruleId'] ?? 'eslint',
                        'title' => $message['message'] ?? 'ESLint finding',
                        'file' => $file['filePath'] ?? null,
                        'line' => $message['line'] ?? null,
                        'end_line' => $message['endLine'] ?? null,
                        'message' => $message['message'] ?? 'ESLint finding',
                        'blocks_resolved' => ((int) ($message['severity'] ?? 0)) >= 2,
                        'metadata' => ['column' => $message['column'] ?? null],
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parsePint(array $payload): array
    {
        $files = $payload['files'] ?? [];

        if (is_array($files) && array_is_list($files)) {
            return collect($files)
                ->filter(fn (mixed $file): bool => is_array($file) || is_string($file))
                ->map(function (array|string $file): array {
                    $path = is_array($file) ? (string) ($file['name'] ?? $file['file'] ?? $file['path'] ?? 'unknown') : $file;

                    return [
                        'severity' => 'medium',
                        'rule_id' => 'pint.format',
                        'title' => 'PHP file needs formatting',
                        'file' => $path,
                        'message' => 'Laravel Pint reported that this file is not formatted.',
                        'blocks_resolved' => true,
                        'metadata' => is_array($file) ? $file : [],
                    ];
                })
                ->values()
                ->all();
        }

        return collect((array) $files)
            ->filter(fn (mixed $value, string|int $path): bool => is_string($path) && is_array($value))
            ->map(fn (array $file, string $path): array => [
                'severity' => 'medium',
                'rule_id' => 'pint.format',
                'title' => 'PHP file needs formatting',
                'file' => $path,
                'message' => 'Laravel Pint reported that this file is not formatted.',
                'blocks_resolved' => true,
                'metadata' => $file,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseBiome(array $payload): array
    {
        return collect((array) ($payload['diagnostics'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $severity = strtolower((string) ($item['severity'] ?? 'warning'));
                $normalizedSeverity = match ($severity) {
                    'fatal', 'error' => 'high',
                    'warning' => 'medium',
                    'information', 'hint' => 'low',
                    default => 'medium',
                };

                return [
                    'severity' => $normalizedSeverity,
                    'rule_id' => data_get($item, 'category') ?: data_get($item, 'tags.0') ?: 'biome',
                    'title' => data_get($item, 'description') ?: data_get($item, 'message.0.content') ?: 'Biome finding',
                    'file' => data_get($item, 'location.path.file') ?: data_get($item, 'location.resource') ?: data_get($item, 'file'),
                    'line' => data_get($item, 'location.span.start.line') ?: data_get($item, 'location.start.line'),
                    'end_line' => data_get($item, 'location.span.end.line') ?: data_get($item, 'location.end.line'),
                    'message' => data_get($item, 'description') ?: 'Biome reported a lint or format diagnostic.',
                    'blocks_resolved' => in_array($normalizedSeverity, ['critical', 'high'], true),
                    'metadata' => [
                        'category' => data_get($item, 'category'),
                        'advices' => data_get($item, 'advices', []),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parsePhpstan(array $payload): array
    {
        return collect((array) ($payload['files'] ?? []))
            ->flatMap(function (array $file, string $path): array {
                return collect((array) ($file['messages'] ?? []))
                    ->filter(fn (mixed $message): bool => is_array($message))
                    ->map(fn (array $message): array => [
                        'severity' => 'high',
                        'rule_id' => $message['identifier'] ?? 'phpstan',
                        'title' => $message['message'] ?? 'PHPStan finding',
                        'file' => $path,
                        'line' => $message['line'] ?? null,
                        'message' => $message['message'] ?? 'PHPStan finding',
                        'blocks_resolved' => true,
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseTypeScriptText(string $output): array
    {
        return collect(preg_split('/\R/', $output) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->map(function (string $line): ?array {
                if (! preg_match('/^(?<file>.+?)\((?<line>\d+),(?<column>\d+)\):\s+error\s+(?<code>TS\d+):\s+(?<message>.+)$/', $line, $matches)) {
                    return null;
                }

                return [
                    'severity' => 'high',
                    'rule_id' => $matches['code'] ?? 'typescript',
                    'title' => $matches['message'] ?? 'TypeScript finding',
                    'file' => $matches['file'] ?? null,
                    'line' => isset($matches['line']) ? (int) $matches['line'] : null,
                    'message' => $matches['message'] ?? 'TypeScript finding',
                    'blocks_resolved' => true,
                    'metadata' => [
                        'column' => isset($matches['column']) ? (int) $matches['column'] : null,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parsePsalm(array $payload): array
    {
        return collect((array) ($payload['issues'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $severity = strtolower((string) ($item['severity'] ?? 'error'));

                return [
                    'severity' => $severity === 'info' ? 'info' : ($severity === 'warning' ? 'medium' : 'high'),
                    'rule_id' => $item['type'] ?? 'psalm',
                    'title' => $item['message'] ?? 'Psalm finding',
                    'file' => $item['file_name'] ?? null,
                    'line' => $item['line_from'] ?? null,
                    'end_line' => $item['line_to'] ?? null,
                    'message' => $item['message'] ?? 'Psalm finding',
                    'blocks_resolved' => $severity !== 'info',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseShellCheck(array $payload): array
    {
        return collect((array) ($payload['comments'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'severity' => in_array($item['level'] ?? '', ['error'], true) ? 'high' : 'medium',
                'rule_id' => isset($item['code']) ? 'SC'.$item['code'] : 'shellcheck',
                'title' => $item['message'] ?? 'ShellCheck finding',
                'file' => $item['file'] ?? null,
                'line' => $item['line'] ?? null,
                'end_line' => $item['endLine'] ?? null,
                'message' => $item['message'] ?? 'ShellCheck finding',
                'blocks_resolved' => in_array($item['level'] ?? '', ['error'], true),
                'metadata' => ['column' => $item['column'] ?? null],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseHadolint(array $payload): array
    {
        return collect($payload)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $severity = match (strtolower((string) ($item['level'] ?? 'warning'))) {
                    'error' => 'high',
                    'warning' => 'medium',
                    'info', 'style' => 'low',
                    default => 'medium',
                };

                return [
                    'severity' => $severity,
                    'rule_id' => $item['code'] ?? 'hadolint',
                    'title' => $item['message'] ?? 'Hadolint finding',
                    'file' => $item['file'] ?? 'Dockerfile',
                    'line' => $item['line'] ?? null,
                    'message' => $item['message'] ?? 'Hadolint reported a Dockerfile issue.',
                    'blocks_resolved' => $severity === 'high',
                    'metadata' => [
                        'column' => $item['column'] ?? null,
                        'level' => $item['level'] ?? null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseSarif(array $payload, string $toolSlug): array
    {
        return collect((array) ($payload['runs'] ?? []))
            ->filter(fn (mixed $run): bool => is_array($run))
            ->flatMap(function (array $run) use ($toolSlug): array {
                $rules = collect((array) data_get($run, 'tool.driver.rules', []))
                    ->filter(fn (mixed $rule): bool => is_array($rule))
                    ->keyBy(fn (array $rule): string => (string) ($rule['id'] ?? ''));

                return collect((array) ($run['results'] ?? []))
                    ->filter(fn (mixed $result): bool => is_array($result))
                    ->map(function (array $result) use ($rules, $toolSlug): array {
                        $ruleId = (string) ($result['ruleId'] ?? $toolSlug);
                        $rule = (array) ($rules->get($ruleId) ?? []);
                        $location = collect((array) ($result['locations'] ?? []))
                            ->first(fn (mixed $candidate): bool => is_array($candidate));
                        $physicalLocation = is_array($location) ? (array) data_get($location, 'physicalLocation', []) : [];
                        $region = (array) data_get($physicalLocation, 'region', []);
                        $severity = $this->sarifSeverity($result['level'] ?? data_get($result, 'properties.severity') ?? data_get($rule, 'properties.problem.severity'));

                        return [
                            'severity' => $severity,
                            'rule_id' => $ruleId,
                            'title' => data_get($rule, 'shortDescription.text') ?: data_get($result, 'message.text') ?: $ruleId,
                            'file' => data_get($physicalLocation, 'artifactLocation.uri'),
                            'line' => $region['startLine'] ?? null,
                            'end_line' => $region['endLine'] ?? null,
                            'message' => data_get($result, 'message.text') ?: data_get($rule, 'fullDescription.text') ?: 'SARIF finding',
                            'blocks_resolved' => in_array($severity, ['critical', 'high'], true),
                            'metadata' => [
                                'level' => $result['level'] ?? null,
                                'kind' => $result['kind'] ?? null,
                                'precision' => data_get($rule, 'properties.precision'),
                                'help_uri' => $rule['helpUri'] ?? null,
                            ],
                        ];
                    })
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseTrivy(array $payload): array
    {
        return collect((array) ($payload['Results'] ?? []))
            ->filter(fn (mixed $result): bool => is_array($result))
            ->flatMap(function (array $result): array {
                $target = $result['Target'] ?? null;
                $vulnerabilities = collect((array) ($result['Vulnerabilities'] ?? []))
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->map(fn (array $item): array => [
                        'severity' => $this->securitySeverity($item['Severity'] ?? 'UNKNOWN'),
                        'rule_id' => $item['VulnerabilityID'] ?? 'trivy',
                        'title' => $item['Title'] ?? $item['VulnerabilityID'] ?? 'Trivy vulnerability',
                        'file' => $target,
                        'message' => $item['Description'] ?? $item['Title'] ?? 'Trivy vulnerability',
                        'blocks_resolved' => in_array($this->securitySeverity($item['Severity'] ?? 'UNKNOWN'), ['critical', 'high'], true),
                        'metadata' => [
                            'package' => $item['PkgName'] ?? null,
                            'installed_version' => $item['InstalledVersion'] ?? null,
                            'fixed_version' => $item['FixedVersion'] ?? null,
                            'primary_url' => $item['PrimaryURL'] ?? null,
                        ],
                    ]);
                $misconfigurations = collect((array) ($result['Misconfigurations'] ?? []))
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->map(fn (array $item): array => [
                        'severity' => $this->securitySeverity($item['Severity'] ?? 'UNKNOWN'),
                        'rule_id' => $item['ID'] ?? 'trivy_misconfiguration',
                        'title' => $item['Title'] ?? 'Trivy misconfiguration',
                        'file' => $target,
                        'message' => $item['Message'] ?? $item['Description'] ?? 'Trivy misconfiguration',
                        'blocks_resolved' => in_array($this->securitySeverity($item['Severity'] ?? 'UNKNOWN'), ['critical', 'high'], true),
                    ]);

                return $vulnerabilities->merge($misconfigurations)->values()->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseOsvScanner(array $payload): array
    {
        return collect((array) ($payload['results'] ?? []))
            ->filter(fn (mixed $result): bool => is_array($result))
            ->flatMap(function (array $result): array {
                return collect((array) data_get($result, 'packages', []))
                    ->filter(fn (mixed $package): bool => is_array($package))
                    ->flatMap(function (array $package) use ($result): array {
                        return collect((array) ($package['vulnerabilities'] ?? []))
                            ->filter(fn (mixed $vulnerability): bool => is_array($vulnerability))
                            ->map(fn (array $vulnerability): array => [
                                'severity' => 'high',
                                'rule_id' => $vulnerability['id'] ?? 'osv',
                                'title' => $vulnerability['summary'] ?? $vulnerability['id'] ?? 'OSV vulnerability',
                                'file' => data_get($result, 'source.path'),
                                'message' => $vulnerability['details'] ?? $vulnerability['summary'] ?? 'OSV vulnerability',
                                'blocks_resolved' => true,
                                'metadata' => [
                                    'package' => data_get($package, 'package.name'),
                                    'ecosystem' => data_get($package, 'package.ecosystem'),
                                    'version' => data_get($package, 'package.version'),
                                ],
                            ])
                            ->all();
                    })
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function parseGrype(array $payload): array
    {
        return collect((array) ($payload['matches'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $severity = $this->securitySeverity(data_get($item, 'vulnerability.severity', 'UNKNOWN'));
                $package = data_get($item, 'artifact.name');
                $version = data_get($item, 'artifact.version');
                $vulnerabilityId = (string) (data_get($item, 'vulnerability.id') ?: 'grype');
                $locations = (array) data_get($item, 'artifact.locations', []);
                $firstLocation = collect($locations)->first(fn (mixed $location): bool => is_array($location));

                return [
                    'severity' => $severity,
                    'rule_id' => $vulnerabilityId,
                    'title' => trim($vulnerabilityId.' '.($package ? 'in '.$package : '')) ?: 'Grype vulnerability',
                    'file' => is_array($firstLocation) ? data_get($firstLocation, 'path') : null,
                    'message' => data_get($item, 'vulnerability.description')
                        ?: trim(sprintf('%s affects %s %s', $vulnerabilityId, (string) $package, (string) $version)),
                    'blocks_resolved' => in_array($severity, ['critical', 'high'], true),
                    'metadata' => [
                        'package' => $package,
                        'installed_version' => $version,
                        'fixed_versions' => (array) data_get($item, 'vulnerability.fix.versions', []),
                        'namespace' => data_get($item, 'vulnerability.namespace'),
                        'artifact_type' => data_get($item, 'artifact.type'),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string,mixed>
     */
    private function syftMetrics(array $payload): array
    {
        $packages = collect((array) ($payload['artifacts'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item));

        return [
            'package_count' => $packages->count(),
            'package_type_counts' => $packages
                ->map(fn (array $item): string => (string) ($item['type'] ?? 'unknown'))
                ->countBy()
                ->all(),
            'source_type' => data_get($payload, 'source.type'),
            'source_name' => data_get($payload, 'source.name') ?: data_get($payload, 'source.target'),
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string,mixed>
     */
    private function trivyMetrics(array $payload): array
    {
        $findings = $this->parseTrivy($payload);

        return [
            'result_count' => count((array) ($payload['Results'] ?? [])),
            'finding_count' => count($findings),
            'severity_counts' => collect($findings)->countBy('severity')->all(),
            'blocking_finding_count' => collect($findings)->where('blocks_resolved', true)->count(),
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string,mixed>
     */
    private function grypeMetrics(array $payload): array
    {
        $findings = $this->parseGrype($payload);

        return [
            'match_count' => count((array) ($payload['matches'] ?? [])),
            'finding_count' => count($findings),
            'severity_counts' => collect($findings)->countBy('severity')->all(),
            'blocking_finding_count' => collect($findings)->where('blocks_resolved', true)->count(),
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string,mixed>
     */
    private function osvScannerMetrics(array $payload): array
    {
        $packages = collect((array) ($payload['results'] ?? []))
            ->filter(fn (mixed $result): bool => is_array($result))
            ->flatMap(fn (array $result): array => (array) data_get($result, 'packages', []))
            ->filter(fn (mixed $package): bool => is_array($package));

        return [
            'result_count' => count((array) ($payload['results'] ?? [])),
            'package_count' => $packages->count(),
            'vulnerability_count' => $packages
                ->sum(fn (array $package): int => count((array) ($package['vulnerabilities'] ?? []))),
        ];
    }

    private function securitySeverity(mixed $severity): string
    {
        $severity = strtolower((string) $severity);

        return match ($severity) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'info',
        };
    }

    private function sarifSeverity(mixed $severity): string
    {
        $severity = strtolower((string) $severity);

        return match ($severity) {
            'critical' => 'critical',
            'error', 'high' => 'high',
            'warning', 'medium' => 'medium',
            'note', 'recommendation', 'low' => 'low',
            default => 'medium',
        };
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function finding(string $toolSlug, array $finding): array
    {
        $severity = strtolower((string) ($finding['severity'] ?? 'medium'));
        $severity = in_array($severity, ['critical', 'high', 'medium', 'low', 'info'], true) ? $severity : 'medium';
        $file = $finding['file_path'] ?? $finding['file'] ?? null;
        $line = $finding['line'] ?? $finding['start_line'] ?? null;
        $rule = (string) ($finding['rule_id'] ?? $toolSlug);
        $title = (string) ($finding['title'] ?? 'Tool finding');

        return [
            'tool' => $toolSlug,
            'severity' => $severity,
            'rule_id' => $rule,
            'title' => $title,
            'message' => $finding['message'] ?? $title,
            'file_path' => is_string($file) && $file !== '' ? $file : null,
            'line' => is_numeric($line) ? (int) $line : null,
            'end_line' => isset($finding['end_line']) && is_numeric($finding['end_line']) ? (int) $finding['end_line'] : null,
            'confidence' => isset($finding['confidence']) && is_numeric($finding['confidence']) ? (float) $finding['confidence'] : null,
            'blocks_resolved' => (bool) ($finding['blocks_resolved'] ?? in_array($severity, ['critical', 'high'], true)),
            'fingerprint' => hash('sha256', implode('|', [
                $toolSlug,
                $rule,
                (string) $file,
                (string) $line,
                Str::limit($title, 160, ''),
            ])),
            'metadata' => (array) ($finding['metadata'] ?? []),
        ];
    }
}
