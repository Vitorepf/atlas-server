<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

use RuntimeException;

/**
 * Operator-gated proposer for new canonical task classes.
 *
 * Consumes AtlasLoopTaskClassMiner clusters and proposes a new task-class definition. The
 * proposal is ALWAYS persisted with `status=pending`. Activation requires an explicit
 * `AtlasLoopTaskClassRegistry::approve(class_id, operator_token)` call (lives behind a separate
 * packet).
 *
 * Anti-Goodhart:
 *   - Refuses a proposal whose `acceptance_criteria_template` is only a proxy
 *     (line-count / file-count / presence-of-string), with no observable behavior reference
 *     (test-name pattern, gate id, or evidence kind).
 *   - Refuses when cluster `success_rate` is below the configured floor.
 */
final class AtlasLoopTaskClassProposer
{
    public const PROXY_ONLY_TEMPLATE = 'proxy_only_acceptance_criteria_template';

    public const SUCCESS_RATE_BELOW_FLOOR = 'cluster_success_rate_below_floor';

    public const REQUIRED_BEHAVIOR_TOKEN_REGEXES = [
        '/\btest\b/i',
        '/\bphpunit\b/i',
        '/\bgate\b/i',
        '/\bevidence\b/i',
        '/\bgreen\b/i',
        '/\bproves?\b/i',
    ];

    public const PROXY_TOKEN_REGEXES = [
        '/\bline[ _-]?count\b/i',
        '/\bfile[ _-]?count\b/i',
        '/\bpresence[ _-]?of[ _-]?string\b/i',
        '/\bcontains[ _-]?text\b/i',
    ];

    public function __construct(private readonly float $successRateFloor = 0.6)
    {
    }

    /**
     * @param  array<string,mixed>  $cluster
     * @return AtlasLoopTaskClassProposal
     */
    public function propose(array $cluster): AtlasLoopTaskClassProposal
    {
        $template = array_values(array_map('strval', (array) ($cluster['acceptance_criteria_template'] ?? [])));
        if (! $this->templateHasBehaviorReference($template)) {
            throw new RuntimeException(self::PROXY_ONLY_TEMPLATE.': proposed acceptance_criteria_template references only proxies (line/file count or presence-of-string)');
        }

        $successRate = (float) ($cluster['success_rate'] ?? 0.0);
        if ($successRate < $this->successRateFloor) {
            throw new RuntimeException(self::SUCCESS_RATE_BELOW_FLOOR.': '.$successRate.' < '.$this->successRateFloor);
        }

        return new AtlasLoopTaskClassProposal(
            classId: (string) ($cluster['class_id'] ?? 'unnamed-class'),
            humanLabel: (string) ($cluster['human_label'] ?? 'Unnamed Class'),
            shapeRules: array_values(array_map('strval', (array) ($cluster['shape_rules'] ?? []))),
            defaultAcceptanceCriteriaTemplate: $template,
            defaultRequiredEvidenceKinds: array_values(array_map('strval', (array) ($cluster['required_evidence_kinds'] ?? []))),
            defaultAllowedFilesGlobs: array_values(array_map('strval', (array) ($cluster['allowed_files_globs'] ?? []))),
            justificationFacts: [
                'cluster_size' => (int) ($cluster['cluster_size'] ?? 0),
                'success_rate' => $successRate,
                'cluster_id' => (string) ($cluster['cluster_id'] ?? ''),
                'observed_packet_ids_sample' => array_values((array) ($cluster['observed_packet_ids_sample'] ?? [])),
            ],
        );
    }

    /**
     * @param  list<string>  $template
     */
    private function templateHasBehaviorReference(array $template): bool
    {
        if ($template === []) {
            return false;
        }
        $hasBehavior = false;
        foreach ($template as $line) {
            foreach (self::REQUIRED_BEHAVIOR_TOKEN_REGEXES as $rx) {
                if (preg_match($rx, $line)) {
                    $hasBehavior = true;
                    break 2;
                }
            }
        }
        if (! $hasBehavior) {
            return false;
        }
        // Behavior reference present — still refuse if the template is OVERWHELMINGLY proxy:
        // every line is a proxy AND no line has a behavior token. We already checked behavior is
        // present, so this is sufficient.
        return true;
    }
}
