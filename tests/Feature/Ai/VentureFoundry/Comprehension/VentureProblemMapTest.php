<?php

namespace Tests\Feature\Ai\VentureFoundry\Comprehension;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\VentureFoundry\Comprehension\Capabilities\VentureProblemMapService;
use App\Services\Ai\VentureFoundry\Comprehension\ComprehensionRecorder;
use App\Services\Ai\VentureFoundry\Comprehension\WorkspaceReader;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\TestCase;

class VentureProblemMapTest extends TestCase
{
    use CreatesVentureComprehensionTables;

    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createVentureComprehensionTables();
        $this->ws = $this->makeFakeWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeFakeWorkspace($this->ws);
        $this->dropVentureComprehensionTables();
        parent::tearDown();
    }

    private function makeRun(): AiVentureComprehensionRun
    {
        return AiVentureComprehensionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => (string) Str::uuid(),
            'workspace_path' => $this->ws,
            'status' => 'running',
            'run_hash' => StrategyCanonicalHash::sha256(['x' => (string) Str::uuid()]),
        ]);
    }

    private function scan(): array
    {
        $service = new VentureProblemMapService(new ComprehensionRecorder);
        $run = $this->makeRun();
        $report = $service->scan($run, new WorkspaceReader($this->ws));

        return [$run, $report];
    }

    public function test_secret_detector_ignores_validation_messages_but_flags_real_credentials(): void
    {
        // A password VALIDATION hook (real Blackink false-positive shape): error
        // messages assigned to *Password keys must NOT be flagged as secrets.
        @mkdir($this->ws.'/src/hooks', 0777, true);
        file_put_contents($this->ws.'/src/hooks/usePasswordValidation.jsx', <<<'JSX'
export function usePasswordValidation(form) {
    const errors = {};
    if (form.newPassword.length < 6) {
        errors.newPassword = 'Nova senha deve ter pelo menos 6 caracteres';
    } else if (!/(?=.*[A-Z])/.test(form.newPassword)) {
        errors.newPassword = 'Deve conter pelo menos uma letra maiuscula';
    }
    if (form.confirmPassword !== form.newPassword) {
        errors.confirmPassword = 'Senhas nao coincidem';
    }
    return errors;
}
JSX);

        // A genuine hardcoded credential MUST still be flagged.
        @mkdir($this->ws.'/src/config', 0777, true);
        file_put_contents($this->ws.'/src/config/db.js', <<<'JS'
export const db = {
    host: 'localhost',
    password = "Pr0d!Secret_92xZ",
};
JS);

        [$run] = $this->scan();

        $secrets = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('kind', 'secret')
            ->get();

        foreach ($secrets->pluck('evidence_path')->all() as $path) {
            $this->assertStringNotContainsString('usePasswordValidation', (string) $path, 'validation messages must NOT be flagged as secrets');
        }

        $this->assertTrue(
            $secrets->contains(fn ($f) => str_contains((string) $f->evidence_path, 'src/config/db.js')),
            'a real hardcoded credential must still be flagged',
        );
        $this->assertTrue(
            $secrets->contains(fn ($f) => str_contains((string) $f->evidence_path, 'BillingController.php')),
            'the sk_live key in the fixture must still be flagged',
        );
    }

    public function test_capability_id_is_problem(): void
    {
        $service = new VentureProblemMapService(new ComprehensionRecorder);
        $this->assertSame(
            AiVentureComprehensionFinding::CAPABILITY_PROBLEM,
            $service->capability(),
        );
        $this->assertSame('problem', $service->capability());
    }

    public function test_hardcoded_secret_is_critical_and_cited_to_billing_controller(): void
    {
        [$run] = $this->scan();

        $secret = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('capability', 'problem')
            ->where('kind', VentureProblemMapService::KIND_SECRET)
            ->first();

        $this->assertNotNull($secret, 'the hardcoded sk_live secret must be found');
        $this->assertSame('critical', $secret->severity);
        $this->assertSame('app/Http/Controllers/BillingController.php', $secret->evidence_path);
        $this->assertGreaterThan(0, $secret->evidence_line);
        $this->assertNotNull($secret->evidence_snippet);
        $this->assertStringContainsString('sk_live_', $secret->evidence_snippet);
        $this->assertNotNull($secret->recommendation);
    }

    public function test_todo_and_fixme_debt_markers_are_found_and_cited(): void
    {
        [$run] = $this->scan();

        $markers = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('kind', VentureProblemMapService::KIND_DEBT_MARKER)
            ->get();

        $byMarker = [];
        foreach ($markers as $marker) {
            $byMarker[$marker->payload['marker'] ?? ''] = $marker;
        }

        $this->assertArrayHasKey('TODO', $byMarker, 'TODO marker must be detected');
        $this->assertArrayHasKey('FIXME', $byMarker, 'FIXME marker must be detected');

        // TODO is low severity; FIXME is medium.
        $this->assertSame('low', $byMarker['TODO']->severity);
        $this->assertSame('medium', $byMarker['FIXME']->severity);

        // Cited to the real files.
        $this->assertSame('app/Http/Controllers/BillingController.php', $byMarker['TODO']->evidence_path);
        $this->assertSame('app/Services/CommissionService.php', $byMarker['FIXME']->evidence_path);
        $this->assertGreaterThan(0, $byMarker['TODO']->evidence_line);
        $this->assertGreaterThan(0, $byMarker['FIXME']->evidence_line);
    }

    public function test_test_gap_reported_for_untested_controller_and_service(): void
    {
        [$run] = $this->scan();

        $gaps = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('kind', VentureProblemMapService::KIND_TEST_GAP)
            ->pluck('evidence_path')
            ->all();

        $this->assertContains('app/Http/Controllers/BillingController.php', $gaps);
        $this->assertContains('app/Services/CommissionService.php', $gaps);

        $gapFinding = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('kind', VentureProblemMapService::KIND_TEST_GAP)
            ->where('evidence_path', 'app/Services/CommissionService.php')
            ->first();
        $this->assertNotNull($gapFinding);
        $this->assertSame('medium', $gapFinding->severity);
        $this->assertSame('CommissionServiceTest.php', $gapFinding->payload['expected_test'] ?? null);
    }

    public function test_report_shape_has_severity_buckets_and_top_critical(): void
    {
        [, $report] = $this->scan();

        $this->assertSame('problem', $report['capability']);
        $this->assertArrayHasKey('total', $report);
        $this->assertGreaterThan(0, $report['total']);

        $this->assertArrayHasKey('by_severity', $report);
        foreach (['critical', 'high', 'medium', 'low', 'info'] as $bucket) {
            $this->assertArrayHasKey($bucket, $report['by_severity']);
        }
        $this->assertGreaterThanOrEqual(1, $report['by_severity']['critical']);

        $this->assertArrayHasKey('by_kind', $report);
        $this->assertArrayHasKey(VentureProblemMapService::KIND_SECRET, $report['by_kind']);

        $this->assertArrayHasKey('top_critical', $report);
        $this->assertNotEmpty($report['top_critical']);
        $first = $report['top_critical'][0];
        $this->assertSame('app/Http/Controllers/BillingController.php', $first['path']);
        $this->assertArrayHasKey('recommendation', $first);
    }

    public function test_every_finding_is_cited_with_path_and_snippet(): void
    {
        [$run] = $this->scan();

        $findings = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->get();

        $this->assertNotEmpty($findings);
        foreach ($findings as $finding) {
            $this->assertNotEmpty($finding->evidence_path, "finding {$finding->kind} must cite a path");
            // A code location exists for every detector here, so line + snippet present.
            $this->assertNotNull($finding->evidence_line, "finding {$finding->kind} must cite a line");
            $this->assertNotEmpty($finding->evidence_snippet, "finding {$finding->kind} must carry a snippet");
            // Cite-or-omit: nothing escapes the workspace into vendor.
            $this->assertStringNotContainsString('vendor/', (string) $finding->evidence_path);
        }
    }
}
