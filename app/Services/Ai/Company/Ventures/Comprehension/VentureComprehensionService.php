<?php

namespace App\Services\Ai\Company\Ventures\Comprehension;

use App\Models\AiVenture;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Company\Ventures\Comprehension\Capabilities\VentureAudienceUsageProfileService;
use App\Services\Ai\Company\Ventures\Comprehension\Capabilities\VentureBusinessRuleMinerService;
use App\Services\Ai\Company\Ventures\Comprehension\Capabilities\VentureDocumentationGeneratorService;
use App\Services\Ai\Company\Ventures\Comprehension\Capabilities\VentureImprovementScannerService;
use App\Services\Ai\Company\Ventures\Comprehension\Capabilities\VentureProblemMapService;
use App\Services\Ai\Company\Ventures\VentureBusinessRuleService;
use App\Services\Ai\Company\Ventures\VentureFoundryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the full "degrau 0" comprehension of a venture's real
 * workspace: opens a run, runs every capability in dependency order
 * (mining → problems → improvements → audience → documentation), consolidates
 * the report and closes the run.
 *
 * The capabilities are deterministic-first, so the whole pass works offline.
 * Order matters: documentation summarizes the findings the others recorded,
 * and improvements may consult the problem findings.
 */
class VentureComprehensionService
{
    public function __construct(
        private readonly VentureBusinessRuleMinerService $businessRules,
        private readonly VentureProblemMapService $problems,
        private readonly VentureImprovementScannerService $improvements,
        private readonly VentureAudienceUsageProfileService $audience,
        private readonly VentureDocumentationGeneratorService $documentation,
        private readonly VentureBusinessRuleService $ruleCanon,
    ) {}

    public const CAPABILITY_ORDER = [
        'business_rule',
        'problem',
        'improvement',
        'audience_usage',
        'documentation',
    ];

    /**
     * Run the full comprehension pass over the venture's workspace.
     *
     * @param  array<string,mixed>  $opts  workspace_path?, only? (list of capability keys),
     *                                     promote_rules? (bool), write_docs_to?, min_promote_confidence?
     * @return array<string,mixed>
     */
    public function run(AiVenture $venture, array $opts = []): array
    {
        $this->raiseMemoryFloor();

        $workspacePath = (string) ($opts['workspace_path'] ?? $this->resolveWorkspacePath($venture));
        if ($workspacePath === '') {
            throw ComprehensionException::missingWorkspace($venture->venture_id);
        }

        $reader = new WorkspaceReader($workspacePath);
        $only = isset($opts['only']) && is_array($opts['only']) && $opts['only'] !== []
            ? array_values(array_intersect(self::CAPABILITY_ORDER, $opts['only']))
            : self::CAPABILITY_ORDER;

        $run = AiVentureComprehensionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => $venture->id,
            'workspace_path' => $reader->root(),
            'repo_roots' => $reader->repoRoots(),
            'status' => 'running',
            'capabilities_run' => $only,
            'started_at' => Carbon::now(),
            'run_hash' => StrategyCanonicalHash::sha256([
                'venture_id' => $venture->id,
                'workspace' => $reader->root(),
                'token' => (string) Str::uuid(),
            ]),
        ]);

        $reports = [];
        foreach ($only as $capabilityKey) {
            $reports[$capabilityKey] = $this->capabilityFor($capabilityKey)->scan($run, $reader);
        }

        // Optional integration: promote high-confidence mined rules into the
        // venture's official, versioned business-rule canon.
        $promotion = null;
        if (($opts['promote_rules'] ?? false) === true && in_array('business_rule', $only, true)) {
            $promotion = $this->promoteMinedRules($venture, $run, (float) ($opts['min_promote_confidence'] ?? 0.8));
        }

        // Optional: write the generated canonical doc to disk under a base dir.
        $docWrite = null;
        if (! empty($opts['write_docs_to']) && in_array('documentation', $only, true)) {
            $docWrite = $this->writeDocs($run, (string) $opts['write_docs_to']);
        }

        $findingsTotal = (int) DB::table('ai_venture_comprehension_findings')->where('run_id', $run->id)->count();

        $summary = $this->summarize($run, $reports, $promotion, $docWrite);

        $run->forceFill([
            'status' => 'completed',
            'files_scanned' => $reader->filesRead(),
            'findings_total' => $findingsTotal,
            'summary' => $summary,
            'completed_at' => Carbon::now(),
        ])->save();

        return [
            'schema_version' => 'atlas.ai.venture.comprehension_report.v1',
            'run_uuid' => $run->uuid,
            'venture_id' => $venture->venture_id,
            'workspace_path' => $run->workspace_path,
            'repo_roots' => $run->repo_roots,
            'capabilities_run' => $only,
            'files_scanned' => $reader->filesRead(),
            'findings_total' => $findingsTotal,
            'reports' => $reports,
            'rule_promotion' => $promotion,
            'doc_write' => $docWrite,
            'summary' => $summary,
        ];
    }

    private function capabilityFor(string $key): ComprehensionCapability
    {
        return match ($key) {
            'business_rule' => $this->businessRules,
            'problem' => $this->problems,
            'improvement' => $this->improvements,
            'audience_usage' => $this->audience,
            'documentation' => $this->documentation,
            default => throw ComprehensionException::invalidCapability($key),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function promoteMinedRules(AiVenture $venture, AiVentureComprehensionRun $run, float $minConfidence): array
    {
        $candidates = $this->businessRules->candidates($run);
        $promoted = [];
        $skipped = [];

        $categoryMap = [
            'pricing' => 'pricing',
            'trial' => 'pricing',
            'commission' => 'finance',
            'validation' => 'operations',
            'authorization' => 'operations',
            'domain_constant' => 'operations',
        ];

        foreach ($candidates as $candidate) {
            $confidence = (float) ($candidate['confidence'] ?? 0);
            if ($confidence < $minConfidence) {
                $skipped[] = ['statement' => $candidate['statement'] ?? '', 'reason' => 'below_confidence'];

                continue;
            }
            $category = $categoryMap[$candidate['category'] ?? ''] ?? 'operations';
            try {
                $rule = $this->ruleCanon->declare($venture, [
                    'category' => $category,
                    'statement' => (string) ($candidate['statement'] ?? ''),
                    'rationale' => sprintf(
                        'Minerada do código em %s:%s (confiança %.2f).',
                        $candidate['evidence_path'] ?? '?',
                        (string) ($candidate['evidence_line'] ?? '?'),
                        $confidence,
                    ),
                ]);
                $promoted[] = ['rule_id' => $rule->rule_id, 'category' => $category, 'version' => $rule->version];
            } catch (VentureFoundryException $e) {
                $skipped[] = ['statement' => $candidate['statement'] ?? '', 'reason' => $e->getMessage()];
            }
        }

        return ['promoted' => count($promoted), 'rules' => $promoted, 'skipped' => $skipped];
    }

    /**
     * @return array<string,mixed>
     */
    private function writeDocs(AiVentureComprehensionRun $run, string $baseDir): array
    {
        $written = [];
        foreach ($run->documentation()->get() as $artifact) {
            $path = $this->documentation->writeToDisk($artifact, $baseDir);
            $written[] = ['relative_path' => $artifact->relative_path, 'absolute_path' => $path];
        }

        return ['written' => count($written), 'files' => $written];
    }

    /**
     * @param  array<string,mixed>  $reports
     * @return array<string,mixed>
     */
    private function summarize(AiVentureComprehensionRun $run, array $reports, ?array $promotion, ?array $docWrite): array
    {
        $bySeverity = DB::table('ai_venture_comprehension_findings')
            ->where('run_id', $run->id)
            ->where('capability', 'problem')
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->toArray();

        $byCapability = DB::table('ai_venture_comprehension_findings')
            ->where('run_id', $run->id)
            ->selectRaw('capability, count(*) as total')
            ->groupBy('capability')
            ->pluck('total', 'capability')
            ->toArray();

        return [
            'findings_by_capability' => $byCapability,
            'problems_by_severity' => $bySeverity,
            'rules_promoted' => $promotion['promoted'] ?? 0,
            'docs_written' => $docWrite['written'] ?? 0,
            'capability_reports_present' => array_keys($reports),
        ];
    }

    /**
     * Raise-only memory floor for the batch scan over a real (large) repo.
     * Never lowers an already-higher limit; no-op when unlimited.
     */
    private function raiseMemoryFloor(int $floorBytes = 1_073_741_824): void
    {
        $current = $this->parseBytes((string) ini_get('memory_limit'));
        if ($current === -1) {
            return; // already unlimited
        }
        if ($current < $floorBytes) {
            @ini_set('memory_limit', (string) $floorBytes);
        }
    }

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1_073_741_824,
            'm' => $num * 1_048_576,
            'k' => $num * 1_024,
            default => (int) $value,
        };
    }

    private function resolveWorkspacePath(AiVenture $venture): string
    {
        // Prefer a configured project repo path matching the venture slug.
        $projects = (array) config('atlas_projects.profiles', []);
        foreach ($projects as $project) {
            if (($project['id'] ?? null) === $venture->venture_id || ($project['slug'] ?? null) === $venture->venture_id) {
                $path = (string) ($project['workspace_path'] ?? $project['repo_root'] ?? '');
                if ($path !== '') {
                    return $path;
                }
            }
        }

        return '';
    }
}
