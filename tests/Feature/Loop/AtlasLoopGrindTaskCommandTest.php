<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the durable execution bridge end-to-end: a stored task (a snapshot that
 * survives restarts) is materialized into a fresh workspace, ground through the REAL
 * runner+explorer+frozen-judge, and its result persisted to the durable ledger — with
 * the never-merge invariant intact. The provider is faked (deterministic) so the proof
 * is fast, free and repeatable; everything else (workspace isolation, git baseline,
 * frozen-judge re-proof, proposal shaping, persistence) is the real engine.
 */
final class AtlasLoopGrindTaskCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The full pgsql schema can't migrate on sqlite:memory (raw JSONB), and this
        // proof only needs the loop tables — so create just those, in isolation. The
        // completion migration's pgsql-only DDL (never-merge trigger) is driver-guarded,
        // so it is a safe no-op on sqlite (the Eloquent guard covers never-merge here).
        // The :memory: DB is discarded at class teardown, so no down() is needed.
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    public function test_grinds_a_durable_task_and_persists_a_certified_proposal(): void
    {
        // Deterministic driver: writes the typo fix into the scenario workspace (no provider).
        $this->app->bind(LoopExecutionDriver::class, fn () => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents(
                    $workspace.'/src/SmokeSubject.php',
                    "<?php\nnamespace Smoke;\nfinal class SmokeSubject{ public function greeting(): string { return 'hello atlas'; } }\n",
                );

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove durable grind worker',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 60,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'target_path' => 'src/SmokeSubject.php',
            'objective' => 'Fix the greeting typo so the test passes. Edit src/SmokeSubject.php directly.',
            'payload' => [
                'target_relative_path' => 'src/SmokeSubject.php',
                'target_content' => "<?php\nnamespace Smoke;\nfinal class SmokeSubject{ public function greeting(): string { return 'helo atlas'; } }\n",
                'frozen_tests' => [[
                    'path' => 'tests/SmokeSubjectTest.php',
                    'content' => "<?php\nrequire __DIR__.'/../src/SmokeSubject.php';\n\$s = new \\Smoke\\SmokeSubject();\nif (\$s->greeting() !== 'hello atlas') { fwrite(STDERR, 'bad'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => [
                    'commands' => ['php tests/SmokeSubjectTest.php'],
                    'allowed_globs' => ['src/**'],
                    'frozen_globs' => ['tests/**', 'composer.json'],
                    'metric_kind' => 'gate',
                ],
                'allowed_files' => ['src/SmokeSubject.php'],
                'validation_commands' => ['php tests/SmokeSubjectTest.php'],
            ],
            'dedupe_key' => 'smoke-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        // The task processed and produced a winner.
        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertNull($task->lease_expires_at);

        // A certified-for-review proposal landed in the durable ledger — never merged.
        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertFalse((bool) $proposals[0]->merged_to_main);
        $this->assertSame(AtlasLoopProposal::STATUS_CERTIFIED, $proposals[0]->status);
        $this->assertNotEmpty($proposals[0]->diff_text);

        // The exploration audit was recorded (the loop's growing experience).
        $this->assertGreaterThanOrEqual(1, AtlasLoopExploration::query()->where('campaign_id', $campaign->id)->count());

        // Campaign counters advanced.
        $campaign->refresh();
        $this->assertSame(1, $campaign->tasks_processed);
        $this->assertSame(1, $campaign->proposals_count);
        $this->assertGreaterThanOrEqual(1, $campaign->scenarios_explored);
    }

    /**
     * O-2 slice (d): with universal_certification ON, the DISCOVERY path routes through
     * the adversarial gate too (not just framework tasks). The safety property proven
     * here is fail-SOFT: even when the gate can't certify the proposal, the task still
     * completes 'done' (never 'failed') — the earlier naive always-gate broke 29 tests
     * exactly because gate errors failed the whole task.
     */
    public function test_universal_certification_gates_the_discovery_path_fail_soft(): void
    {
        config(['atlas.loop.universal_certification' => true]);

        $this->app->bind(LoopExecutionDriver::class, fn () => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents(
                    $workspace.'/src/SmokeSubject.php',
                    "<?php\nnamespace Smoke;\nfinal class SmokeSubject{ public function greeting(): string { return 'hello atlas'; } }\n",
                );

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove universal certification on discovery path',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 60,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'target_path' => 'src/SmokeSubject.php',
            'objective' => 'Fix the greeting typo so the test passes. Edit src/SmokeSubject.php directly.',
            'payload' => [
                'target_relative_path' => 'src/SmokeSubject.php',
                'target_content' => "<?php\nnamespace Smoke;\nfinal class SmokeSubject{ public function greeting(): string { return 'helo atlas'; } }\n",
                'frozen_tests' => [[
                    'path' => 'tests/SmokeSubjectTest.php',
                    'content' => "<?php\nrequire __DIR__.'/../src/SmokeSubject.php';\n\$s = new \\Smoke\\SmokeSubject();\nif (\$s->greeting() !== 'hello atlas') { fwrite(STDERR, 'bad'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => [
                    'commands' => ['php tests/SmokeSubjectTest.php'],
                    'allowed_globs' => ['src/**'],
                    'frozen_globs' => ['tests/**', 'composer.json'],
                    'metric_kind' => 'gate',
                ],
                'allowed_files' => ['src/SmokeSubject.php'],
                'validation_commands' => ['php tests/SmokeSubjectTest.php'],
            ],
            'dedupe_key' => 'smoke-universal-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        // Fail-soft: the universal gate ran on the discovery path; whatever its verdict,
        // the task lifecycle completed cleanly (the regression that broke 29 tests was
        // a gate error turning the task 'failed').
        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertNull($task->lease_expires_at);
    }

    public function test_grinds_framework_materialized_p4_small_task_through_implementation_gate(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php';
        $testPath = 'tests/Feature/Loop/SemanticImplementationCertificationFixture.php';
        $class = 'App\\\\Services\\\\Ai\\\\AutonomousEvolution\\\\AtlasLoopWorkspaceMaterializer';
        $acceptanceCommand = 'php '.$testPath;
        $sealedCommand = 'php -r "require \'vendor/autoload.php\'; exit(class_exists(\''.$class.'\') ? 0 : 1);"';
        $refuterCommand = <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;

        $this->app->bind(LoopExecutionDriver::class, fn () => new class($target) implements LoopExecutionDriver
        {
            public function __construct(private readonly string $target) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/'.$this->target, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

final class AtlasLoopWorkspaceMaterializer
{
    public function p4Probe(): string
    {
        return 'ok';
    }
}
PHP);

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove framework p4 grind worker',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 120,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => false,
            'target_path' => $target,
            'objective' => 'Add the tiny p4Probe method. Edit only the target file.',
            'payload' => [
                'materializer' => 'framework',
                'target_relative_path' => $target,
                'frozen_tests' => [[
                    'path' => $testPath,
                    'content' => <<<'PHP'
<?php

declare(strict_types=1);

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializer;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$subject = new AtlasLoopWorkspaceMaterializer();
if (! method_exists($subject, 'p4Probe')) {
    fwrite(STDERR, 'p4Probe missing');
    exit(1);
}
if ($subject->p4Probe() !== 'ok') {
    fwrite(STDERR, 'p4Probe did not return ok');
    exit(1);
}
PHP,
                ]],
                'acceptance' => [
                    'commands' => [$acceptanceCommand],
                    'allowed_globs' => [$target],
                    'frozen_globs' => [$testPath],
                    'metric_kind' => 'gate',
                    'timeout_seconds' => 120,
                ],
                'allowed_files' => [$target],
                'validation_commands' => [$acceptanceCommand],
                'sealed_holdout_commands' => [$sealedCommand],
                'semantic_refuter_commands' => [$refuterCommand],
                'provider_refuters_required' => 1,
            ],
            'dedupe_key' => 'framework-p4-small-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertSame(1, data_get($task->result, 'implementation_gate.proposals_in'), json_encode($task->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame(1, data_get($task->result, 'implementation_gate.proposals_certified'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.proposals_in'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.proposals_certified'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.provider_refuters_required'));

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertFalse((bool) $proposals[0]->merged_to_main);
        $this->assertStringContainsString('p4Probe', (string) $proposals[0]->diff_text);
        $this->assertSame(
            'semantic_implementation_certified_with_external_refuters',
            data_get($task->result, 'semantic_implementation_certification.reports.0.level'),
        );
    }

    public function test_grinds_framework_p4_task_from_compiled_intent_verifier(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php';
        $method = 'intentCompilerProbe';
        $refuterCommand = <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;
        $verifierRefuterCommand = <<<'CMD'
php -r '$p=getenv("ATLAS_INTENT_VERIFIER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["red_preflight"]["status"] ?? null) === "red") && (($j["acceptance"]["revert_recheck"] ?? false) === true); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "verifier_clean" : "verifier_not_clean"]); exit(0);'
CMD;

        $this->app->bind(LoopExecutionDriver::class, fn () => new class($target, $method) implements LoopExecutionDriver
        {
            public function __construct(private readonly string $target, private readonly string $method) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $path = $workspace.'/'.$this->target;
                $source = (string) file_get_contents($path);
                $addition = "\n    public function ".$this->method."(): string\n    {\n        return 'ok';\n    }\n";
                file_put_contents($path, preg_replace('/}\\s*$/', $addition."}\n", $source, 1) ?: $source);

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove intent verifier factory p4 grind worker',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 120,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => false,
            'target_path' => $target,
            'objective' => 'Add method '.$method.'() returns "ok". Edit only the target file.',
            'payload' => [
                'materializer' => 'framework',
                'intent_verifier_factory' => true,
                'target_relative_path' => $target,
                'method' => $method,
                'returns' => 'ok',
                'semantic_refuter_commands' => [$refuterCommand],
                'provider_refuters_required' => 1,
                'verifier_refuter_commands' => [$verifierRefuterCommand],
                'verifier_refuters_required' => 1,
            ],
            'dedupe_key' => 'framework-p4-intent-verifier-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertTrue(data_get($task->result, 'intent_verifier_factory.ready'), json_encode($task->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('red', data_get($task->result, 'intent_verifier_factory.red_preflight.status'));
        $this->assertSame(1, data_get($task->result, 'intent_verifier_factory.verifier_refuters.executed'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.proposals_certified'));

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertFalse((bool) $proposals[0]->merged_to_main);
        $this->assertStringContainsString($method, (string) $proposals[0]->diff_text);
    }

    public function test_grinds_framework_p4_http_response_task_from_compiled_intent_verifier(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php';
        $routeFile = 'routes/api.php';
        $path = '/__atlas_http_intent_probe';
        $refuterCommand = <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;

        $this->app->bind(LoopExecutionDriver::class, fn () => new class($routeFile, $path) implements LoopExecutionDriver
        {
            public function __construct(private readonly string $routeFile, private readonly string $path) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents(
                    $workspace.'/'.$this->routeFile,
                    "\nRoute::get('".$this->path."', static fn () => response('ok'));\n",
                    FILE_APPEND,
                );

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove http intent verifier factory p4 grind worker',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 120,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => false,
            'target_path' => $target,
            'objective' => 'Make GET '.$path.' return ok. Edit only the declared route file.',
            'payload' => [
                'materializer' => 'framework',
                'intent_verifier_factory' => true,
                'target_relative_path' => $target,
                'allowed_files' => [$routeFile],
                'http_path' => $path,
                'http_status' => 200,
                'http_body_contains' => 'ok',
                'semantic_refuter_commands' => [$refuterCommand],
                'provider_refuters_required' => 1,
            ],
            'dedupe_key' => 'framework-p4-http-intent-verifier-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertSame('http_response', data_get($task->result, 'intent_verifier_factory.verification_atom_types.0'));
        $this->assertSame('red', data_get($task->result, 'intent_verifier_factory.red_preflight.status'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.proposals_certified'));

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertStringContainsString($path, (string) $proposals[0]->diff_text);
    }

    public function test_grinds_framework_p4_event_dispatched_task_from_compiled_intent_verifier(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php';
        $method = 'dispatchIntentCompilerProbe';
        $event = 'atlas.intent.compiler.event_probe';
        $refuterCommand = <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;

        $this->app->bind(LoopExecutionDriver::class, fn () => new class($target, $method, $event) implements LoopExecutionDriver
        {
            public function __construct(
                private readonly string $target,
                private readonly string $method,
                private readonly string $event,
            ) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $path = $workspace.'/'.$this->target;
                $source = (string) file_get_contents($path);
                $addition = "\n    public function ".$this->method."(): void\n    {\n        event('".$this->event."');\n    }\n";
                file_put_contents($path, preg_replace('/}\\s*$/', $addition."}\n", $source, 1) ?: $source);

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove event intent verifier factory p4 grind worker',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 120,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => false,
            'target_path' => $target,
            'objective' => 'Add method '.$method.'() and dispatch event '.$event.'. Edit only the target file.',
            'payload' => [
                'materializer' => 'framework',
                'intent_verifier_factory' => true,
                'target_relative_path' => $target,
                'method' => $method,
                'event_class' => $event,
                'semantic_refuter_commands' => [$refuterCommand],
                'provider_refuters_required' => 1,
            ],
            'dedupe_key' => 'framework-p4-event-intent-verifier-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertSame('event_dispatched', data_get($task->result, 'intent_verifier_factory.verification_atom_types.0'));
        $this->assertSame('red', data_get($task->result, 'intent_verifier_factory.red_preflight.status'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.proposals_certified'));

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertFalse((bool) $proposals[0]->merged_to_main);
        $this->assertStringContainsString($event, (string) $proposals[0]->diff_text);
    }

    public function test_grinds_framework_p4_job_dispatched_task_from_compiled_intent_verifier(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php';
        $method = 'dispatchIntentCompilerJobProbe';
        $job = 'App\\Jobs\\FlushBatchedMobilePushes';
        $refuterCommand = <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;

        $this->app->bind(LoopExecutionDriver::class, fn () => new class($target, $method, $job) implements LoopExecutionDriver
        {
            public function __construct(
                private readonly string $target,
                private readonly string $method,
                private readonly string $job,
            ) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $path = $workspace.'/'.$this->target;
                $source = (string) file_get_contents($path);
                $addition = "\n    public function ".$this->method."(): void\n    {\n        \\".$this->job."::dispatch();\n    }\n";
                file_put_contents($path, preg_replace('/}\\s*$/', $addition."}\n", $source, 1) ?: $source);

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove job intent verifier factory p4 grind worker',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 120,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => false,
            'target_path' => $target,
            'objective' => 'Add method '.$method.'() and dispatch job '.$job.'. Edit only the target file.',
            'payload' => [
                'materializer' => 'framework',
                'intent_verifier_factory' => true,
                'target_relative_path' => $target,
                'method' => $method,
                'job_class' => $job,
                'semantic_refuter_commands' => [$refuterCommand],
                'provider_refuters_required' => 1,
            ],
            'dedupe_key' => 'framework-p4-job-intent-verifier-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertSame('job_dispatched', data_get($task->result, 'intent_verifier_factory.verification_atom_types.0'));
        $this->assertSame('red', data_get($task->result, 'intent_verifier_factory.red_preflight.status'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.proposals_certified'));

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertFalse((bool) $proposals[0]->merged_to_main);
        $this->assertStringContainsString($job, (string) $proposals[0]->diff_text);
    }

    public function test_grinds_framework_p4_db_state_task_from_compiled_intent_verifier(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php';
        $method = 'recordIntentCompilerDbProbe';
        $table = 'intent_compiler_records';
        $refuterCommand = <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;

        $this->app->bind(LoopExecutionDriver::class, fn () => new class($target, $method, $table) implements LoopExecutionDriver
        {
            public function __construct(
                private readonly string $target,
                private readonly string $method,
                private readonly string $table,
            ) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $path = $workspace.'/'.$this->target;
                $source = (string) file_get_contents($path);
                $addition = "\n    public function ".$this->method."(): void\n    {\n        \\Illuminate\\Support\\Facades\\DB::table('".$this->table."')->insert(['marker' => 'ok']);\n    }\n";
                file_put_contents($path, preg_replace('/}\\s*$/', $addition."}\n", $source, 1) ?: $source);

                return ['status' => 'completed'];
            }
        });

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'prove db state intent verifier factory p4 grind worker',
            'config' => ['scenarios_per_task' => 1],
            'max_seconds' => 120,
        ]);

        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => false,
            'target_path' => $target,
            'objective' => 'Add method '.$method.'() and insert a marker row in '.$table.'. Edit only the target file.',
            'payload' => [
                'materializer' => 'framework',
                'intent_verifier_factory' => true,
                'target_relative_path' => $target,
                'method' => $method,
                'db_table' => $table,
                'db_setup_sql' => ['CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY AUTOINCREMENT, marker TEXT NOT NULL)'],
                'db_where' => ['marker' => 'ok'],
                'db_expected_count' => 1,
                'db_count_operator' => '>=',
                'semantic_refuter_commands' => [$refuterCommand],
                'provider_refuters_required' => 1,
            ],
            'dedupe_key' => 'framework-p4-db-state-intent-verifier-1',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertSame('db_state', data_get($task->result, 'intent_verifier_factory.verification_atom_types.0'));
        $this->assertSame('red', data_get($task->result, 'intent_verifier_factory.red_preflight.status'));
        $this->assertSame(1, data_get($task->result, 'semantic_implementation_certification.proposals_certified'));

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $proposals);
        $this->assertFalse((bool) $proposals[0]->merged_to_main);
        $this->assertStringContainsString($table, (string) $proposals[0]->diff_text);
    }
}
