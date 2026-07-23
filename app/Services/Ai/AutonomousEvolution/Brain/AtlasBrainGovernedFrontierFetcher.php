<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasSourceConnectorsAndCaptureService;

/**
 * MAXN-05 — governed external frontier fetch plan.
 *
 * The hot brain path stays pure/read-only. This service only builds provider-safe
 * outbound requests from static public source registries, and calls the transport
 * only when the caller explicitly enables live fetch and is not in dry-run mode.
 */
final class AtlasBrainGovernedFrontierFetcher
{
    public const SCHEMA = 'atlas.brain.governed_frontier_fetcher.v1';

    /** @var list<array{stage:string,tier:string,source_family:string,source_type:string,source:string}> */
    private const STAGES = [
        [
            'stage' => 'discover',
            'tier' => 'discover',
            'source_family' => 'social_community',
            'source_type' => 'social_community',
            'source' => 'social_post_thread',
        ],
        [
            'stage' => 'read',
            'tier' => 'read',
            'source_family' => 'github_official_repos',
            'source_type' => 'github',
            'source' => 'github_pr_commit',
        ],
        [
            'stage' => 'ground',
            'tier' => 'ground',
            'source_family' => 'academic_indexes',
            'source_type' => 'paper',
            'source' => 'paper',
        ],
    ];

    public function __construct(
        private readonly ?AtlasBrainResearchSourceRegistry $sources = null,
        private readonly ?AtlasSourceConnectorsAndCaptureService $connectors = null,
    ) {}

    /**
     * @param  null|callable(array<string,mixed>):array<string,mixed>  $transport
     * @return array{
     *   schema:string,
     *   status:string,
     *   enabled:bool,
     *   dry_run:bool,
     *   network_attempted:bool,
     *   outbound_payloads:list<array<string,mixed>>,
     *   egress_safety:array<string,mixed>,
     *   candidates:list<array<string,mixed>>,
     *   connector_governance:list<array<string,mixed>>
     * }
     */
    public function run(int $limit, bool $dryRun, bool $enabled, ?callable $transport = null, string $capturedAt = ''): array
    {
        $plans = $this->plans();
        $outbound = array_values(array_map(
            static fn (array $plan): array => (array) $plan['outbound_payload'],
            $plans,
        ));
        $safety = $this->egressSafetyReport($outbound);

        $base = [
            'schema' => self::SCHEMA,
            'status' => 'planned',
            'enabled' => $enabled,
            'dry_run' => $dryRun,
            'network_attempted' => false,
            'outbound_payloads' => $outbound,
            'egress_safety' => $safety,
            'candidates' => [],
            'connector_governance' => array_values(array_map(
                static fn (array $plan): array => (array) $plan['connector_governance'],
                $plans,
            )),
        ];

        if (($safety['provider_safe'] ?? false) !== true) {
            $base['status'] = 'blocked_egress_safety';

            return $base;
        }

        if ($dryRun) {
            $base['status'] = 'dry_run';

            return $base;
        }

        if (! $enabled) {
            $base['status'] = 'gated_off';

            return $base;
        }

        if ($transport === null) {
            $base['status'] = 'transport_missing';

            return $base;
        }

        $base['network_attempted'] = true;
        $candidates = [];
        foreach ($plans as $plan) {
            $response = $transport((array) $plan['outbound_payload']);
            foreach ($this->candidatesFromResponse($plan, $response, $capturedAt) as $candidate) {
                $candidates[] = $candidate;
                if (count($candidates) >= max(0, $limit)) {
                    break 2;
                }
            }
        }

        $base['status'] = 'fetched';
        $base['candidates'] = array_values($candidates);

        return $base;
    }

    /** @return list<array<string,mixed>> */
    public function plans(): array
    {
        $plans = [];
        foreach (self::STAGES as $stage) {
            $source = $this->sourceForTier($stage['tier']);
            $governance = $this->governanceFor($stage);
            if (($governance['connector_verdict']['blocked'] ?? true) === true) {
                continue;
            }

            $plans[] = [
                'stage' => $stage['stage'],
                'source' => $source,
                'connector_governance' => $governance,
                'outbound_payload' => [
                    'schema' => 'atlas.brain.frontier_fetcher.outbound_payload.v1',
                    'stage' => $stage['stage'],
                    'method' => 'GET',
                    'endpoint' => $this->endpointForStage($stage['stage'], (string) ($source['url_pattern'] ?? '')),
                    'query' => $this->queryForStage($stage['stage']),
                    'payload_origin' => 'static_public_allowlist',
                    'privacy_class' => 'public',
                    'provider_safe' => true,
                    'class_gate' => [
                        'allowed' => true,
                        'allowed_classes' => ['public'],
                        'blocked_classes' => ['sensitive', 'secret', 'cyber', 'workspace_local'],
                        'repo_derived_content_allowed' => false,
                    ],
                    'rate_limit' => [
                        'cadence' => (string) ($source['cadence'] ?? 'on_demand'),
                        'max_requests_per_run' => 1,
                    ],
                ],
            ];
        }

        return $plans;
    }

    /**
     * @param  list<array<string,mixed>>  $payloads
     * @param  list<string>  $extraForbiddenNeedles
     * @return array{provider_safe:bool, checked_payloads:int, violations:list<string>, payload_origin:string}
     */
    public function egressSafetyReport(array $payloads, array $extraForbiddenNeedles = []): array
    {
        $needles = array_values(array_filter(array_merge([
            base_path(),
            storage_path(),
            'app/Services/',
            'tests/Feature/',
            'tests/Unit/',
            '.env',
            'ATLAS_TOKEN',
            'DB_PASSWORD',
        ], $extraForbiddenNeedles), static fn (string $needle): bool => trim($needle) !== ''));

        $violations = [];
        foreach ($payloads as $index => $payload) {
            $json = strtolower((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            foreach ($needles as $needle) {
                $needle = strtolower($needle);
                if ($needle !== '' && str_contains($json, $needle)) {
                    $violations[] = 'payload_'.$index.'_contains_forbidden_needle';
                    break;
                }
            }
        }

        return [
            'provider_safe' => $violations === [],
            'checked_payloads' => count($payloads),
            'violations' => $violations,
            'payload_origin' => 'static_public_allowlist',
        ];
    }

    /** @param  array<string,string>  $stage */
    private function governanceFor(array $stage): array
    {
        $connectors = $this->connectors ?? new AtlasSourceConnectorsAndCaptureService;

        return [
            'stage' => $stage['stage'],
            'registry_directive' => $connectors->registryDirective($stage['source_family']),
            'capture_requirements' => $connectors->requiredFields($stage['source_type']),
            'action' => $connectors->actionFor($stage['source']),
            'connector_verdict' => $connectors->evaluateConnectorRun([
                'official_api_available' => true,
                'used_scraping' => false,
                'raw_evidence_stored' => true,
                'normalized_schema' => AtlasSourceConnectorsAndCaptureService::EVIDENCE_SCHEMA,
                'trust_tier_assigned' => true,
                'domain_allowlisted' => true,
                'rate_limited' => true,
                'secret_exposed_to_model' => false,
                'prompt_injection_present' => false,
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function sourceForTier(string $tier): array
    {
        $sources = ($this->sources ?? new AtlasBrainResearchSourceRegistry)->enrichedForTier($tier);

        return $sources[0] ?? [
            'url_pattern' => '',
            'tier' => $tier,
            'cadence' => 'on_demand',
            'trust_tier' => 'exploratory',
            'anti_hype_note' => '',
        ];
    }

    private function endpointForStage(string $stage, string $urlPattern): string
    {
        return match ($stage) {
            'read' => 'https://api.github.com/search/repositories',
            'ground' => 'https://export.arxiv.org/api/query',
            default => rtrim(str_replace('*', '', $urlPattern), '/') ?: 'https://trendshift.io',
        };
    }

    /** @return array<string,string|int> */
    private function queryForStage(string $stage): array
    {
        return match ($stage) {
            'read' => [
                'q' => 'topic:ai-agent tests examples evaluation harness',
                'sort' => 'updated',
                'order' => 'desc',
                'per_page' => 10,
            ],
            'ground' => [
                'search_query' => 'all:"agent evaluation harness" OR all:"multi agent benchmark"',
                'sortBy' => 'submittedDate',
                'sortOrder' => 'descending',
                'max_results' => 10,
            ],
            default => [
                'topic' => 'ai agent evaluation harness',
                'sort' => 'trending',
                'limit' => 10,
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $response
     * @return list<array<string,mixed>>
     */
    private function candidatesFromResponse(array $plan, array $response, string $capturedAt): array
    {
        $stage = (string) ($plan['stage'] ?? '');
        $source = (array) ($plan['source'] ?? []);
        $items = $this->responseItems($response);
        $candidates = [];

        foreach ($items as $item) {
            if ($stage === 'read' && ! $this->hasRealUseSignal($item)) {
                continue;
            }

            $title = $this->safeText((string) ($item['title'] ?? $item['name'] ?? $item['full_name'] ?? 'External frontier lead'));
            $url = $this->safePublicUrl((string) ($item['url'] ?? $item['html_url'] ?? $source['url_pattern'] ?? ''));
            $note = (string) ($source['anti_hype_note'] ?? '');
            $summary = $this->safeText((string) ($item['summary'] ?? $item['description'] ?? 'External source returned a candidate lead.'));
            if ($title === '' || $url === '') {
                continue;
            }

            $candidates[] = [
                'title' => $title,
                'url' => $url,
                'summary' => 'Exploratory external research lead: '.$summary,
                'source' => 'governed-fetcher:'.$stage,
                'captured_at' => $capturedAt,
                'trust_tier' => 'exploratory',
                'source_trust_tier' => (string) ($source['trust_tier'] ?? 'unknown'),
                'anti_hype_note' => $note,
                'lead_only' => true,
            ];
        }

        return $candidates;
    }

    /** @param  array<string,mixed>  $response */
    private function responseItems(array $response): array
    {
        foreach (['items', 'repositories', 'entries', 'results'] as $key) {
            if (is_array($response[$key] ?? null)) {
                return array_values(array_filter($response[$key], 'is_array'));
            }
        }

        return [];
    }

    /** @param  array<string,mixed>  $item */
    private function hasRealUseSignal(array $item): bool
    {
        if (($item['has_tests'] ?? false) === true || ($item['tests_present'] ?? false) === true) {
            return true;
        }

        $signals = (array) ($item['usage_signals'] ?? []);

        return in_array('tests', $signals, true)
            || in_array('examples', $signals, true)
            || in_array('real_usage', $signals, true);
    }

    private function safeText(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return substr($text, 0, 240);
    }

    private function safePublicUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https://(trendshift\.io|github\.com|api\.github\.com|arxiv\.org|export\.arxiv\.org)/#', $url)) {
            return '';
        }

        return $url;
    }
}
