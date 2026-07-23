<?php

declare(strict_types=1);

namespace App\Services\Engineering;


final class AtlasDocumentationEnforcementService
{
    public const SCHEMA_VERSION = 'atlas.documentation_enforcement.v1';

    public function __construct(
        private readonly EngineeringDocumentationHealthService $documentationHealth,
        private readonly EngineeringDocumentationAuthorityAuditService $authorityAudit,
        private readonly AtlasDocumentationRealitySystemService $documentationReality,
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
        private readonly AtlasDocumentationProviderBootstrapProbe $providerBootstrapProbe,
    ) {}

    /**
     * @param  array<int,string>  $targets
     * @return array<string,mixed>
     */
    public function report(string $task = '', string $feature = '', array $targets = [], string $workspace = 'atlas-server'): array
    {
        $docsHealth = $this->documentationHealth->report();
        $authority = $this->authorityAudit->report();
        $adrs = $this->documentationReality->report();
        $codeAudit = $this->codeReality->realityAudit();
        $antiDuplicate = trim($feature) === '' ? null : $this->codeReality->antiDuplicate($feature);
        $providerBootstrap = $this->providerBootstrapProbe->report(trim($task), trim($feature), trim($workspace));

        $blockers = array_values(array_merge(
            $this->docsHealthBlockers($docsHealth),
            $this->authorityBlockers($authority),
            $this->documentationRealityBlockers($adrs),
            $this->codeRealityBlockers($codeAudit),
            $this->antiDuplicateBlockers($antiDuplicate),
            $this->providerBootstrapBlockers($providerBootstrap),
        ));

        $reviewItems = array_values(array_merge(
            $this->docsHealthReviewItems($docsHealth),
            $this->authorityReviewItems($authority),
            $this->codeRealityReviewItems($codeAudit),
            $this->antiDuplicateReviewItems($antiDuplicate),
            $this->providerBootstrapReviewItems($providerBootstrap),
        ));

        $warnings = $this->docsHealthWarnings($docsHealth);
        $subareaScores = $this->subareaScores($docsHealth, $authority, $adrs, $codeAudit, $antiDuplicate, $providerBootstrap);
        $score = round(array_sum($subareaScores) / max(1, count($subareaScores)), 2);
        $status = $blockers !== [] ? 'blocked' : (($reviewItems !== [] || $warnings !== []) ? 'review' : 'ready');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'score' => $score,
            'grade' => $this->grade($score, $status),
            'task' => trim($task),
            'feature' => trim($feature),
            'workspace' => trim($workspace) === '' ? 'atlas-server' : trim($workspace),
            'targets' => array_values(array_filter(array_map('strval', $targets))),
            'summary' => [
                'blockers_count' => count($blockers),
                'review_count' => count($reviewItems),
                'warnings_count' => count($warnings),
                'required_before_code_count' => count($this->requiredBeforeCode(trim($task), trim($feature))),
                'documentation_docs_count' => (int) data_get($docsHealth, 'summary.doc_count', 0),
                'adrs_block_count' => (int) data_get($adrs, 'summary.block_count', 0),
                'code_reality_target_count' => (int) data_get($codeAudit, 'target_count', 0),
                'provider_bootstrap_status' => (string) data_get($providerBootstrap, 'status', 'unknown'),
            ],
            'subarea_scores' => $subareaScores,
            'blockers' => $blockers,
            'review_items' => $reviewItems,
            'warnings' => $warnings,
            'required_before_code' => $this->requiredBeforeCode(trim($task), trim($feature)),
            'command_matrix' => $this->commandMatrix(trim($task), trim($feature), $targets),
            'source_status' => [
                'docs_health' => [
                    'status' => (string) data_get($docsHealth, 'status', 'unknown'),
                    'violations' => (int) count((array) data_get($docsHealth, 'violations', [])),
                    'warnings' => (int) count((array) data_get($docsHealth, 'warnings', [])),
                    'oversized' => (int) count((array) data_get($docsHealth, 'oversized_docs', [])),
                ],
                'authority_audit' => [
                    'status' => (string) data_get($authority, 'status', 'unknown'),
                    'blockers' => (int) data_get($authority, 'summary.blocker_count', 0),
                    'review_items' => (int) data_get($authority, 'summary.review_item_count', 0),
                ],
                'documentation_reality' => [
                    'status' => (string) data_get($adrs, 'status', 'unknown'),
                    'score_average' => (float) data_get($adrs, 'documentation_reality_score.average', 0),
                    'blockers' => (int) data_get($adrs, 'summary.blocker_count', 0),
                    'honestly_classified_blocks' => (int) data_get($adrs, 'summary.honestly_classified_block_count', 0),
                ],
                'code_reality' => [
                    'status' => (string) data_get($codeAudit, 'status', 'unknown'),
                    'unknown_or_unused' => (int) data_get($codeAudit, 'unknown_or_unused_count', 0),
                    'weak_reachability' => (int) data_get($codeAudit, 'weak_reachability_count', 0),
                ],
                'anti_duplicate' => $antiDuplicate === null ? null : [
                    'status' => (string) data_get($antiDuplicate, 'status', 'unknown'),
                    'decision' => (string) data_get($antiDuplicate, 'decision', 'unknown'),
                    'match_count' => count((array) data_get($antiDuplicate, 'matches', [])),
                ],
                'provider_bootstrap' => $providerBootstrap,
            ],
            'ai_execution_contract' => [
                'docs_are_authority' => true,
                'provider_projection_is_bootstrap_only' => true,
                'chat_memory_is_not_authority' => true,
                'must_run_before_code' => true,
                'blocked_means_no_code' => true,
                'review_means_operator_or_owner_doc_decision_required' => true,
                'strict_mode_blocks_unless_ready' => true,
            ],
            'claim_policy' => [
                'writes' => false,
                'providers_invoked' => false,
                'rivals_run' => false,
                'external_benchmark_run' => false,
                'declares_documentation_perfect' => false,
                'declares_child_systems_complete' => false,
            ],
            'writes' => false,
            'generated_at' => now()->toJSON(),
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at'], $hashPayload['certification_hash']);
        $payload['certification_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function docsHealthBlockers(array $docsHealth): array
    {
        if (($docsHealth['status'] ?? null) === 'ok') {
            return [];
        }

        return array_values((array) ($docsHealth['violations'] ?? ['docs-health failed']));
    }

    /**
     * @return array<int,string>
     */
    private function authorityBlockers(array $authority): array
    {
        return array_map(
            static fn (mixed $item): string => is_string($item) ? $item : (json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unserializable_authority_blocker'),
            (array) ($authority['blockers'] ?? [])
        );
    }

    /**
     * @return array<int,string>
     */
    private function documentationRealityBlockers(array $adrs): array
    {
        if (($adrs['status'] ?? null) === 'ready' && (int) data_get($adrs, 'summary.blocker_count', 0) === 0) {
            return [];
        }

        return ['documentation reality system is not ready'];
    }

    /**
     * @return array<int,string>
     */
    private function codeRealityBlockers(array $codeAudit): array
    {
        $blockers = [];
        if ((int) data_get($codeAudit, 'unknown_or_unused_count', 0) > 0) {
            $blockers[] = 'code reality audit has unknown or unused documentation runtime targets';
        }
        if ((int) data_get($codeAudit, 'weak_reachability_count', 0) > 0) {
            $blockers[] = 'code reality audit has weak reachability targets';
        }

        return $blockers;
    }

    /**
     * @return array<int,string>
     */
    private function antiDuplicateBlockers(?array $antiDuplicate): array
    {
        if ($antiDuplicate === null) {
            return [];
        }

        return ((string) data_get($antiDuplicate, 'status', 'ready')) === 'blocked'
            ? ['feature anti-duplicate gate is blocked: '.(string) data_get($antiDuplicate, 'decision', 'unknown')]
            : [];
    }

    /**
     * @param  array<string,mixed>  $providerBootstrap
     * @return array<int,string>
     */
    private function providerBootstrapBlockers(array $providerBootstrap): array
    {
        if ((string) data_get($providerBootstrap, 'session_bootstrap.status') === 'blocked') {
            return ['provider bootstrap gate is blocked: session-bootstrap did not execute successfully'];
        }

        if ((string) data_get($providerBootstrap, 'feature_placement.status') === 'blocked') {
            return ['provider bootstrap gate is blocked: feature-placement did not execute successfully'];
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    private function docsHealthReviewItems(array $docsHealth): array
    {
        return array_values(array_map(
            static fn (array $doc): string => sprintf('%s is %s lines, limit %s', $doc['path'] ?? 'unknown', $doc['line_count'] ?? '?', $doc['limit'] ?? '?'),
            (array) ($docsHealth['oversized_docs'] ?? [])
        ));
    }

    /**
     * @return array<int,string>
     */
    private function authorityReviewItems(array $authority): array
    {
        return array_map(
            static fn (mixed $item): string => is_string($item) ? $item : (json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unserializable_authority_review_item'),
            (array) ($authority['review_items'] ?? [])
        );
    }

    /**
     * @return array<int,string>
     */
    private function codeRealityReviewItems(array $codeAudit): array
    {
        return ((string) ($codeAudit['status'] ?? 'ready')) === 'ready'
            ? []
            : ['code reality audit is not ready'];
    }

    /**
     * @return array<int,string>
     */
    private function antiDuplicateReviewItems(?array $antiDuplicate): array
    {
        if ($antiDuplicate === null) {
            return [];
        }

        $decision = (string) data_get($antiDuplicate, 'decision', 'proceed');
        if (in_array($decision, ['proceed', 'proceed_with_owner_lookup'], true)) {
            return [];
        }

        return ['feature anti-duplicate decision requires review: '.$decision];
    }

    /**
     * @param  array<string,mixed>  $providerBootstrap
     * @return array<int,string>
     */
    private function providerBootstrapReviewItems(array $providerBootstrap): array
    {
        $reviewItems = [];

        if ((string) data_get($providerBootstrap, 'session_bootstrap.status') === 'review') {
            $reviewItems[] = 'provider bootstrap gate requires review: session-bootstrap returned review';
        }

        if ((string) data_get($providerBootstrap, 'feature_placement.status') === 'review') {
            $reviewItems[] = 'provider bootstrap gate requires review: feature-placement returned review or was skipped';
        }

        return $reviewItems;
    }

    /**
     * @return array<int,string>
     */
    private function docsHealthWarnings(array $docsHealth): array
    {
        return array_values(array_map(
            fn (mixed $item): string => $this->stringify($item, 'unserializable_docs_health_warning'),
            (array) ($docsHealth['warnings'] ?? [])
        ));
    }

    private function stringify(mixed $item, string $fallback): string
    {
        if (is_string($item)) {
            return $item;
        }

        if (is_scalar($item) || $item === null) {
            return (string) $item;
        }

        return json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $fallback;
    }

    /**
     * @return array<string,float>
     */
    private function subareaScores(array $docsHealth, array $authority, array $adrs, array $codeAudit, ?array $antiDuplicate, array $providerBootstrap): array
    {
        $warningCount = count((array) ($docsHealth['warnings'] ?? []));
        $oversizedCount = count((array) ($docsHealth['oversized_docs'] ?? []));
        $violationCount = count((array) ($docsHealth['violations'] ?? []));
        $authorityReview = (int) data_get($authority, 'summary.review_item_count', 0);
        $authorityBlockers = (int) data_get($authority, 'summary.blocker_count', 0);
        $adrsAverage = (float) data_get($adrs, 'documentation_reality_score.average', 0);
        $codeUnknown = (int) data_get($codeAudit, 'unknown_or_unused_count', 0);
        $codeWeak = (int) data_get($codeAudit, 'weak_reachability_count', 0);
        $antiDuplicatePenalty = $antiDuplicate === null ? 0.4 : (((string) data_get($antiDuplicate, 'status')) === 'ready' ? 0.0 : 1.0);

        return [
            'canonical_docs_health' => $this->clamp(10.0 - ($violationCount * 3.0) - ($warningCount * 0.35) - ($oversizedCount * 0.45)),
            'documentation_reality' => $this->clamp($adrsAverage / 10.0),
            'authority_control' => $this->clamp(10.0 - ($authorityBlockers * 4.0) - min(2.0, $authorityReview * 0.08)),
            'code_reality_alignment' => $this->clamp(10.0 - ($codeUnknown * 3.0) - ($codeWeak * 1.5)),
            'cartography_boundary' => $this->cartographyBoundaryScore($adrs),
            'provider_bootstrap_enforcement' => $this->providerBootstrapEnforcementScore($providerBootstrap),
            'strict_gate_strength' => $this->strictGateStrengthScore(),
            'validation_completeness' => $this->validationCompletenessScore($antiDuplicate, $antiDuplicatePenalty),
        ];
    }

    private function cartographyBoundaryScore(array $adrs): float
    {
        return ((string) data_get($adrs, 'evaluations.aurc_visual_reality.status')) === 'ready'
            ? 10.0
            : 7.0;
    }

    private function providerBootstrapEnforcementScore(array $providerBootstrap): float
    {
        $commands = $this->requiredBeforeCode('<task>', '<feature>');
        $hasBootstrap = in_array('php artisan atlas:ai:session-bootstrap --task="<task>" --strict --json', $commands, true);
        $hasPlacement = in_array('php artisan atlas:ai:place-feature "<feature>" --strict --json', $commands, true);
        $hasDocsHealth = in_array('php artisan atlas:engineering:knowledge docs-health --json', $commands, true);
        $runtimeReady = (string) data_get($providerBootstrap, 'status') === 'ready';

        if ($hasBootstrap && $hasPlacement && $hasDocsHealth && $runtimeReady) {
            return 10.0;
        }

        return $runtimeReady ? 8.0 : 0.0;
    }

    private function strictGateStrengthScore(): float
    {
        $firstCommand = $this->requiredBeforeCode('<task>', '<feature>')[0] ?? '';
        $hasStrictHardGate = str_starts_with($firstCommand, 'php artisan atlas:documentation:enforce')
            && str_contains($firstCommand, '--strict')
            && str_contains($firstCommand, '--json');
        $matrixBlocks = (string) data_get($this->commandMatrix('<task>', '<feature>', []), 'hard_gate.blocks_code_when') === 'status != ready';

        return ($hasStrictHardGate && $matrixBlocks) ? 10.0 : 8.0;
    }

    private function validationCompletenessScore(?array $antiDuplicate, float $antiDuplicatePenalty): float
    {
        $commands = $this->requiredBeforeCode('<task>', '<feature>');
        $requiredChecks = [
            'php artisan atlas:engineering:knowledge docs-health --json',
            'php artisan atlas:documentation-reality score --strict --json',
            'php artisan atlas:documentation-reality acceptance --strict --json',
            'php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json',
            'php artisan atlas:code-reality reality-audit --json',
            'php artisan atlas:universal-reality-cartography navigation-slice --strict --json',
        ];
        $hasAllRequiredChecks = count(array_intersect($requiredChecks, $commands)) === count($requiredChecks);
        $antiDuplicateReady = $antiDuplicate !== null
            && ((string) data_get($antiDuplicate, 'status')) === 'ready'
            && in_array((string) data_get($antiDuplicate, 'decision'), ['proceed', 'proceed_with_owner_lookup'], true);

        if ($hasAllRequiredChecks && $antiDuplicateReady) {
            return 10.0;
        }

        return $this->clamp(9.3 - $antiDuplicatePenalty);
    }

    private function clamp(float $value): float
    {
        return round(max(0.0, min(10.0, $value)), 2);
    }

    private function grade(float $score, string $status): string
    {
        if ($status === 'blocked') {
            return 'blocked';
        }
        if ($score >= 9.6 && $status === 'ready') {
            return 'elite';
        }
        if ($score >= 9.0) {
            return 'strong';
        }
        if ($score >= 8.0) {
            return 'usable_with_review';
        }

        return 'weak';
    }

    /**
     * @return array<int,string>
     */
    private function requiredBeforeCode(string $task, string $feature): array
    {
        $taskValue = $task === '' ? '<task>' : $task;
        $featureValue = $feature === '' ? '<feature>' : $feature;

        return [
            'php artisan atlas:documentation:enforce --task="'.$taskValue.'" --feature="'.$featureValue.'" --strict --json',
            'php artisan atlas:ai:session-bootstrap --task="'.$taskValue.'" --strict --json',
            'php artisan atlas:ai:place-feature "'.$featureValue.'" --strict --json',
            'php artisan atlas:engineering:knowledge docs-health --json',
            'php artisan atlas:documentation-reality score --strict --json',
            'php artisan atlas:documentation-reality acceptance --strict --json',
            'php artisan atlas:code-reality anti-duplicate --feature="'.$featureValue.'" --json',
            'php artisan atlas:code-reality reality-audit --json',
            'php artisan atlas:universal-reality-cartography navigation-slice --strict --json',
        ];
    }

    /**
     * @param  array<int,string>  $targets
     * @return array<string,array<string,mixed>>
     */
    private function commandMatrix(string $task, string $feature, array $targets): array
    {
        $primaryTarget = (string) ($targets[0] ?? '<target>');
        $taskValue = $task === '' ? '<task>' : $task;
        $featureValue = $feature === '' ? '<feature>' : $feature;

        return [
            'hard_gate' => [
                'command' => 'php artisan atlas:documentation:enforce --task="'.$taskValue.'" --feature="'.$featureValue.'" --strict --json',
                'blocks_code_when' => 'status != ready',
            ],
            'bootstrap' => [
                'command' => 'php artisan atlas:ai:session-bootstrap --task="'.$taskValue.'" --strict --json',
                'purpose' => 'fresh provider context pack',
                'blocks_code_when' => 'command fails or status != ready',
            ],
            'placement' => [
                'command' => 'php artisan atlas:ai:place-feature "'.$featureValue.'" --strict --json',
                'purpose' => 'owner doc and duplication gate',
                'blocks_code_when' => 'command fails or status != ready',
            ],
            'docs' => [
                'command' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'purpose' => 'frontmatter, required docs, line limits and warnings',
            ],
            'documentation_reality' => [
                'command' => 'php artisan atlas:documentation-reality acceptance --strict --json',
                'purpose' => '52-block ADRS acceptance matrix',
            ],
            'code_reality' => [
                'command' => 'php artisan atlas:code-reality reachability --target="'.$primaryTarget.'" --json',
                'purpose' => 'target reachability before claims or deletion',
            ],
            'cartography' => [
                'command' => 'php artisan atlas:universal-reality-cartography navigation-slice --strict --json',
                'purpose' => 'human/AI visual reality boundary',
            ],
        ];
    }
}
