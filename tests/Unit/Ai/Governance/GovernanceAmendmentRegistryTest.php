<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\GovernanceAmendmentLedger;
use App\Services\Ai\Governance\GovernanceFloorRegistry;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomyLadderRuntimeService;
use PHPUnit\Framework\TestCase;

final class GovernanceAmendmentRegistryTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-governance-amendments-'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);
        }
        parent::tearDown();
    }

    public function test_amendment_without_receipt_is_rejected(): void
    {
        $registry = $this->registry();

        $result = $registry->amend([
            'autonomy_ladder.trust_threshold' => 0.96,
        ], []);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('governance_amendment_receipt_required', $result['error']);
        $this->assertSame([], $registry->ledger()->history());
    }

    public function test_safety_loosening_without_label_is_monotonicity_violation(): void
    {
        $registry = $this->registry();

        $result = $registry->amend([
            'autonomy_ladder.trust_threshold' => 0.90,
        ], $this->receipt(labels: []));

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('monotonicity_violation', $result['error']);
        $this->assertSame([], $registry->ledger()->history());
    }

    public function test_safety_loosening_with_label_and_rol_01_rollback_is_recorded(): void
    {
        $registry = $this->registry();

        $result = $registry->amend([
            'autonomy_ladder.trust_threshold' => 0.90,
        ], $this->receipt(labels: ['safety_loosening']));

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(0.90, $registry->autonomyLadderTrustThreshold());
        $this->assertCount(1, $registry->ledger()->history());
        $this->assertSame('ROL-01', $registry->ledger()->history()[0]['receipt']['rollback_predeclared']['id']);
    }

    public function test_consumers_read_effective_floor_from_registry(): void
    {
        $registry = $this->registry();
        $registry->amend([
            'autonomy_ladder.trust_threshold' => 0.97,
        ], $this->receipt(proposalId: 'MAXK-07-tighten-trust'));

        $service = new AutonomyLadderRuntimeService($registry);
        $area = [
            'current_level' => 'L6',
            'operator' => true,
            'architect' => true,
            'architect_human_review' => true,
        ];
        $metrics = [
            'days_with_3plus_departments' => 30,
            'cross_dept_blocker_resolution_p95_hours' => 2,
        ];

        $blocked = $service->evaluate($area, $metrics + ['trust_ledger_score' => 0.96], ['evidence://trust']);
        $clear = $service->evaluate($area, $metrics + ['trust_ledger_score' => 0.97], ['evidence://trust']);

        $this->assertSame(['trust_ledger_below_threshold'], $blocked['promotion_blockers']);
        $this->assertSame([], $clear['promotion_blockers']);
    }

    public function test_governance_floor_completeness_has_no_migrated_floor_reads_outside_registry(): void
    {
        $root = dirname(__DIR__, 4);
        $registryPath = $root.'/app/Services/Ai/Governance/GovernanceFloorRegistry.php';
        $ledgerPath = $root.'/app/Services/Ai/Governance/GovernanceAmendmentLedger.php';
        $commandPath = $root.'/app/Console/Commands/AtlasGovernanceAmendmentsCommand.php';
        $allowed = [$registryPath => true, $ledgerPath => true, $commandPath => true];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS),
        );
        $violations = [];
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (isset($allowed[$path])) {
                continue;
            }
            $source = (string) file_get_contents($path);
            $patterns = [
                '/\bTRUST_THRESHOLD\b/',
                '/\bDEMOTE_CONSECUTIVE_BREACHES\b/',
                '/\bDEFAULT_FORBIDDEN_ACTIONS\b/',
                '/\bDEGRADATION_THRESHOLD\b/',
                '/\bBROKEN_THRESHOLD\b/',
                '/adml_cost_outcome\.(min_evidence|min_certification_rate|min_score|max_score_drop|require_measured_cost|min_cost_samples)/',
            ];
            if (str_ends_with($path, 'AtlasDecideCostOutcomeRouter.php')) {
                $patterns[] = '/\bCONFIDENCE_HIGH_THRESHOLD\b/';
                $patterns[] = '/\bCONFIDENCE_MEDIUM_THRESHOLD\b/';
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = str_replace($root.'/', '', $path).' matches '.$pattern;
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * @param  list<string>  $labels
     * @return array<string,mixed>
     */
    private function receipt(string $proposalId = 'MAXK-07-test', array $labels = []): array
    {
        return [
            'proposal_id' => $proposalId,
            'actor' => 'phpunit',
            'at' => '2026-07-12T04:00:00+00:00',
            'labels' => $labels,
            'rollback_predeclared' => [
                'id' => 'ROL-01',
                'command' => 'php artisan atlas:governance:amendments --json',
            ],
        ];
    }

    private function registry(): GovernanceFloorRegistry
    {
        return new GovernanceFloorRegistry(new GovernanceAmendmentLedger($this->ledgerPath));
    }
}
