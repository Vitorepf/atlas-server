<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasCognitionRemintTouchedCommandTest extends TestCase
{
    public function test_paths_map_to_owner_docs_and_remint_only_affected_deduped_capabilities(): void
    {
        $truth = new class extends AtlasImplementationTruthService
        {
            public function __construct() {}

            public function docsWithEvidence(): array
            {
                return [
                    [
                        'id' => 'cap.alpha',
                        'path' => 'docs/alpha.md',
                        'implementation_state' => 'partial',
                        'evidence_refs' => [['kind' => 'symbol', 'ref' => 'AlphaService']],
                    ],
                    [
                        'id' => 'cap.beta',
                        'path' => 'docs/beta.md',
                        'implementation_state' => 'partial',
                        'evidence_refs' => [['kind' => 'symbol', 'ref' => 'BetaService']],
                    ],
                ];
            }

            public function capabilityTestRefs(?string $capability = null): array
            {
                $caps = [
                    'cap.alpha' => [
                        'capability_id' => 'cap.alpha',
                        'owner_doc' => 'docs/alpha.md',
                        'evidence_refs' => [['kind' => 'symbol', 'ref' => 'AlphaService']],
                        'test_refs' => [['ref' => 'AlphaServiceTest', 'index_resolved' => true, 'matched' => 'AlphaServiceTest']],
                    ],
                    'cap.beta' => [
                        'capability_id' => 'cap.beta',
                        'owner_doc' => 'docs/beta.md',
                        'evidence_refs' => [['kind' => 'symbol', 'ref' => 'BetaService']],
                        'test_refs' => [['ref' => 'BetaServiceTest', 'index_resolved' => true, 'matched' => 'BetaServiceTest']],
                    ],
                ];

                return $capability !== null ? array_values(array_filter($caps, fn (array $cap): bool => $cap['capability_id'] === $capability)) : array_values($caps);
            }

            public function freshnessHashes(array $evidenceRefs, string $testRef): array
            {
                return ['test_file_hash' => 'test-'.$testRef, 'impl_files_hash' => 'impl-'.$testRef];
            }
        };
        $resolver = new class extends AtlasCognitionEvidenceResolver
        {
            public function __construct() {}

            public function ownerCapabilityIdsForPaths(array $paths): array
            {
                return [
                    'app/AlphaService.php' => ['cap.alpha'],
                    'app/BetaService.php' => ['cap.beta', 'cap.alpha'],
                    'docs/ignored.md' => [],
                ];
            }
        };
        $execution = new class extends AtlasCapabilityTestExecutionService
        {
            /** @var list<array{capability_id:string,test_ref:string}> */
            public array $calls = [];

            public function __construct() {}

            public function runAndRecord(string $capabilityId, string $testRef, ?string $explicitPath = null, ?string $testFileHash = null, ?string $implFilesHash = null): array
            {
                $this->calls[] = ['capability_id' => $capabilityId, 'test_ref' => $testRef];

                return ['passed' => true, 'tests_run' => 1, 'test_file_hash' => $testFileHash, 'impl_files_hash' => $implFilesHash];
            }
        };

        $this->app->instance(AtlasImplementationTruthService::class, $truth);
        $this->app->instance(AtlasCognitionEvidenceResolver::class, $resolver);
        $this->app->instance(AtlasCapabilityTestExecutionService::class, $execution);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:cognition:remint-touched', [
            '--paths' => 'app/AlphaService.php,app/BetaService.php,docs/ignored.md',
            '--limit' => 10,
            '--json' => true,
        ], $out);
        $report = json_decode($out->fetch(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.cognition.remint_touched.v1', $report['schema_version']);
        $this->assertSame(['app/AlphaService.php', 'app/BetaService.php', 'docs/ignored.md'], $report['paths']);
        $this->assertSame(['cap.alpha', 'cap.beta'], $report['owner_capability_ids']);
        $this->assertSame(2, $report['capabilities_processed']);
        $this->assertSame([
            ['capability_id' => 'cap.alpha', 'test_ref' => 'AlphaServiceTest'],
            ['capability_id' => 'cap.beta', 'test_ref' => 'BetaServiceTest'],
        ], $execution->calls);
    }

    public function test_landing_seam_enqueues_when_enabled_and_never_runs_remint_inline(): void
    {
        Storage::fake('local');
        config([
            'atlas.cognition.remint_touched_enabled' => true,
            'atlas.cognition.remint_touched_queue_disk' => 'local',
            'atlas.cognition.remint_touched_queue_path' => 'atlas/cognition/remint-touched-queue.jsonl',
        ]);

        $committer = app(AtlasTaskScopedCommitter::class);
        $result = $committer->enqueueRemintTouchedForLanding(
            ['app/AlphaService.php', 'docs/no-owner.md'],
            'task-123',
            ['commit_sha' => 'abc123'],
        );

        $this->assertTrue($result['queued']);
        $this->assertSame('deferred_disk_queue', $result['mode']);
        Storage::disk('local')->assertExists('atlas/cognition/remint-touched-queue.jsonl');

        $line = trim(Storage::disk('local')->get('atlas/cognition/remint-touched-queue.jsonl'));
        $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.cognition.remint_touched.queue_item.v1', $payload['schema_version']);
        $this->assertSame('task-123', $payload['task_packet_id']);
        $this->assertSame(['app/AlphaService.php', 'docs/no-owner.md'], $payload['paths']);
        $this->assertSame('php artisan atlas:cognition:remint-touched --paths=app/AlphaService.php,docs/no-owner.md --json', $payload['command']);
    }

    public function test_landing_seam_is_default_off_and_fail_open(): void
    {
        config(['atlas.cognition.remint_touched_enabled' => false]);

        $committer = app(AtlasTaskScopedCommitter::class);
        $result = $committer->enqueueRemintTouchedForLanding(['app/AlphaService.php'], 'task-off');

        $this->assertFalse($result['queued']);
        $this->assertSame('disabled', $result['reason']);
    }
}
