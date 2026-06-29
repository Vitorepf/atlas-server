<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHeartbeatLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FROZEN cross-command contract for the EXTERNAL BRAIN. Pins the shared vocabulary the three thin commands +
 * the worker-prompt MUST agree on: the status words, the four FATAL advisory flags, and the worker-prompt's
 * author≠judge invariants (< 4000 chars, NO self-reenable command, PRINT+STOP on disabled). Provider-free.
 */
final class AtlasBrainContractTest extends TestCase
{
    private const TEST_DISK = 'atlas_serving_brain_contract_test';

    private string $envPath;

    private string $doneSetRoot;

    private string $journalRoot;

    private string $provenanceRoot;

    private string $heartbeatRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');

        $base = sys_get_temp_dir().'/atlas-brain-contract-'.bin2hex(random_bytes(6));
        $this->doneSetRoot = $base.'/done-set';
        $this->journalRoot = $base.'/journal';
        $this->provenanceRoot = $base.'/provenance';
        $this->heartbeatRoot = $base.'/heartbeat';
        @mkdir($this->doneSetRoot, 0775, true);
        @mkdir($this->journalRoot, 0775, true);
        @mkdir($this->provenanceRoot, 0775, true);
        @mkdir($this->heartbeatRoot, 0775, true);
        config()->set('atlas.brain.done_set_root', $this->doneSetRoot);
        config()->set('atlas.brain.journal_root', $this->journalRoot);
        config()->set('atlas.brain.provenance_root', $this->provenanceRoot);
        config()->set('atlas.brain.heartbeat_root', $this->heartbeatRoot);
        config()->set('atlas.brain.default_scope', 'loop');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test',
            'roots' => ['app/Services/Ai/AutonomousEvolution'],
            'docs_roots' => [],
            'meta_harness' => true,
        ]);

        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=false\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    private function workerPrompt(): string
    {
        Artisan::call('atlas:brain:worker-prompt', ['--client' => 'contract-fixed', '--scope' => 'loop']);

        return Artisan::output();
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function creditedSeedPacket(array $packet): array
    {
        $target = (string) (((array) ($packet['allowed_files'] ?? []))[0] ?? 'app/Models/AtlasNonHarnessTarget.php');
        $id = (string) ($packet['task_packet_id'] ?? 'brain:contract');

        if (($packet['acceptance_criteria'] ?? []) === ['php artisan test passes']) {
            $packet['acceptance_criteria'] = ['php artisan test --filter=AtlasBrainContractTest proves the target behavior changes'];
        }

        return $packet + [
            'problem' => 'A real Atlas brain seed gap needs credited runtime proof before quota can move.',
            'expected_delta' => 'The target '.$target.' changes behavior and the listed test gate proves the runtime delta.',
            'value' => 'This adds runtime test proof for Atlas autonomy and blocks proxy quota progress.',
            'duplicate_key' => $id.'|'.$target.'|runtime-test-proof',
            'freshness_check' => 'Re-run atlas:brain:next or rg the target before seeding so the packet is not stale.',
            'anti_proxy' => 'Invalid if it only wraps, renames, or exposes dormant code without behavior proof.',
            'modifies_existing_files' => true,
            'existing_file_delta' => 'Existing targets must receive a concrete behavior delta proved by the listed gate.',
        ];
    }

    // The worker-prompt is a copy-paste artifact: it MUST fit the 4000-char paste limit.
    public function test_worker_prompt_is_under_4000_chars(): void
    {
        $prompt = $this->workerPrompt();
        self::assertLessThan(4000, mb_strlen($prompt), 'worker prompt must stay under the 4000-char paste limit');
        self::assertNotSame('', trim($prompt));
    }

    public function test_worker_prompt_supports_valid_seed_quota(): void
    {
        $provenance = new AtlasBrainProvenanceLedger($this->provenanceRoot);
        $provenance->append('loop', ['cycle_id' => 'actor-seed', 'actor' => 'quota-fixed']);
        $provenance->append('loop', ['cycle_id' => 'other-seed', 'actor' => 'other-brain']);

        Artisan::call('atlas:brain:worker-prompt', [
            '--client' => 'quota-fixed',
            '--scope' => 'loop',
            '--target-seeds' => 3,
        ]);
        $prompt = Artisan::output();

        self::assertGreaterThanOrEqual(3900, mb_strlen($prompt), 'quota prompt should spend the available paste budget');
        self::assertLessThan(4000, mb_strlen($prompt), 'quota prompt must stay under the 4000-char paste limit');
        self::assertStringStartsWith('FIRST ACTION: quota preflight', $prompt);
        self::assertStringContainsString('TARGET QUOTA: 3 CREDITED VALID SEEDS', $prompt);
        self::assertStringContainsString('ACTOR=quota-fixed BASELINE_SEEDED=1', $prompt);
        self::assertStringContainsString("atlas:brain:state --scope='loop' --target-seeds=3 --baseline-seeded=1 --actor='quota-fixed' --json", $prompt);
        self::assertStringContainsString("--actor='quota-fixed'", $prompt);
        self::assertStringContainsString("atlas:brain:next 'loop' --scope-signals --actor='quota-fixed' --json", $prompt);
        self::assertStringContainsString('quota.credited_valid_seeds/status', $prompt);
        self::assertStringContainsString('QUOTA PREFLIGHT before step1', $prompt);
        self::assertStringContainsString('any quota result=>PREFLIGHT', $prompt);
        self::assertStringContainsString('quota_met=>STOP', $prompt);
        self::assertStringContainsString('stalled_before_quota', $prompt);
        self::assertStringContainsString('quota.first_action.command/must_run_now nonempty=>EXECUTE VERBATIM NOW; no prose/analysis', $prompt);
        self::assertStringContainsString('analysis_before_must_run_now=contract_violation', $prompt);
        self::assertStringContainsString('quota.unattributed_seeded>0=>do_not_count', $prompt);
        self::assertStringContainsString('obedience_failure=>RUN must_run_now', $prompt);
        self::assertStringNotContainsString('quota.next_command=>RUN first', $prompt);
        self::assertStringNotContainsString('external_engine.stalled_actors[].recovery_command', $prompt);
        self::assertStringContainsString('external_actor_must_execute=>true', $prompt);
        self::assertStringContainsString('operator_input_required=>false', $prompt);
        self::assertStringContainsString('never ask/stop', $prompt);
        self::assertStringContainsString('step1', $prompt);
        self::assertStringContainsString('counts.credited>0+no warnings=valid', $prompt);
        self::assertStringContainsString('skipped_done_set=>RUN next_command before seed', $prompt);
        self::assertStringContainsString('missing_actor_attribution invalid', $prompt);
        self::assertStringContainsString('rotate', $prompt);
        self::assertStringContainsString('Never proxy', $prompt);
        self::assertStringContainsString('temp_spec_already_done=>never reseed;auto_recovery_required', $prompt);
        self::assertStringContainsString('analysis_allowed_before_recovery=false', $prompt);
        self::assertStringContainsString('discard_existing_spec_command+step1', $prompt);
        self::assertStringContainsString('seed_existing_spec_first', $prompt);
        self::assertStringContainsString('dry_run_existing_spec_command', $prompt);
        self::assertStringContainsString('seed_existing_spec_command', $prompt);
        self::assertStringContainsString('--cleanup-specs', $prompt);
        self::assertStringContainsString('Pre-dry=>harden', $prompt);
        self::assertStringContainsString('rotate/expand via patterns+web if under quota', $prompt);
        self::assertStringContainsString('SPARE-BUDGET', $prompt);
        self::assertStringContainsString('use suggested_next_path', $prompt);
        self::assertStringContainsString('Hermes/refused/no_proposal=>ORIGINATE+SEED', $prompt);
        self::assertStringContainsString('no final', $prompt);
        self::assertStringContainsString('Obra/prod/pipeline valid', $prompt);
        self::assertStringContainsString('no self-contained dry', $prompt);
        self::assertStringContainsString('Start: QUOTA PREFLIGHT, then step1.', $prompt);
        self::assertStringNotContainsString('Start step 1.', $prompt);
        self::assertStringNotContainsString('Go to 1', $prompt);
        self::assertStringNotContainsString('Continue unless quota reached', $prompt);
    }

    public function test_scope_signal_digest_is_armed_by_default(): void
    {
        self::assertTrue((bool) config('atlas.brain.scope_signal_digest_enabled'));
    }

    public function test_quota_prompt_fits_with_hardened_php_flags(): void
    {
        Artisan::call('atlas:brain:worker-prompt', [
            '--client' => 'quota-hard-php',
            '--scope' => 'autonomous',
            '--target-seeds' => 10,
            '--php' => '/opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0',
        ]);
        $prompt = Artisan::output();

        self::assertLessThan(4000, mb_strlen($prompt), 'hardened PHP quota prompt must stay pasteable');
        self::assertStringContainsString('-d memory_limit=4096M', $prompt);
        self::assertStringContainsString('-d pcov.enabled=0', $prompt);
    }

    public function test_quota_prompt_fits_with_real_long_client_id(): void
    {
        Artisan::call('atlas:brain:worker-prompt', [
            '--client' => 'claude-brain-2-quota-100',
            '--scope' => 'autonomous',
            '--target-seeds' => 100,
        ]);
        $prompt = Artisan::output();

        self::assertLessThan(3950, mb_strlen($prompt), 'real 100-task external brain prompt must keep paste-limit headroom');
        self::assertStringContainsString("--actor='claude-brain-2-quota-100'", $prompt);
        self::assertStringContainsString("atlas:brain:seed --specs='/tmp/brain-claude-brain-2-quota-100.json' --scope='autonomous' --actor='claude-brain-2-quota-100' --require-actor --dry-run --json", $prompt);
        self::assertStringContainsString("atlas:brain:seed --specs='/tmp/brain-claude-brain-2-quota-100.json' --scope='autonomous' --actor='claude-brain-2-quota-100' --require-actor --cleanup-specs --json", $prompt);
        self::assertStringContainsString('CREDIT fields REQUIRED', $prompt);
    }

    public function test_worker_prompt_shell_quotes_opaque_client_commands(): void
    {
        Artisan::call('atlas:brain:worker-prompt', [
            '--client' => 'claude brain 2 quota 100',
            '--scope' => 'autonomous',
            '--target-seeds' => 100,
        ]);
        $prompt = Artisan::output();

        self::assertLessThan(3950, mb_strlen($prompt), 'shell-safe 100-task prompt must keep paste-limit headroom');
        self::assertStringContainsString("atlas:brain:state --scope='autonomous' --target-seeds=100 --baseline-seeded=0 --actor='claude brain 2 quota 100' --json", $prompt);
        self::assertStringContainsString("atlas:brain:next 'autonomous' --scope-signals --actor='claude brain 2 quota 100' --json", $prompt);
        self::assertStringContainsString("> '/tmp/brain-claude brain 2 quota 100.json'", $prompt);
        self::assertStringContainsString("atlas:brain:seed --specs='/tmp/brain-claude brain 2 quota 100.json' --scope='autonomous' --actor='claude brain 2 quota 100' --require-actor --dry-run --json", $prompt);
        self::assertStringContainsString("atlas:brain:seed --specs='/tmp/brain-claude brain 2 quota 100.json' --scope='autonomous' --actor='claude brain 2 quota 100' --require-actor --cleanup-specs --json", $prompt);
        self::assertStringNotContainsString('--actor=claude brain 2 quota 100', $prompt);
        self::assertStringNotContainsString('--specs=/tmp/brain-claude brain 2 quota 100.json', $prompt);
    }

    // author≠judge: the prompt PRINTS + STOPS on disabled and NEVER self-enables (no 'serving on' style command).
    public function test_worker_prompt_stops_on_disabled_and_never_self_reenables(): void
    {
        $prompt = $this->workerPrompt();

        // It must instruct PRINT + STOP on disabled, and name the operator-only flag.
        self::assertStringContainsString('disabled', $prompt);
        self::assertStringContainsString('STOP', $prompt);
        self::assertStringContainsString(AtlasBrainMasterSwitch::KEY, $prompt);

        // It must NEVER contain a self-reenable command (the muscle's 'atlas:task:serving on' foot-gun) nor any
        // command that flips the brain switch on.
        self::assertStringNotContainsString('atlas:task:serving on', $prompt);
        self::assertStringNotContainsString('atlas:brain:on', $prompt);
        self::assertStringNotContainsStringIgnoringCase('--enable', $prompt);
        self::assertDoesNotMatchRegularExpression(
            '/'.preg_quote(AtlasBrainMasterSwitch::KEY, '/').'\s*=\s*(true|1|on|yes)/i',
            $prompt,
            'the worker prompt must never tell the worker to set the switch on',
        );
    }

    // The prompt must drive ONLY the three thin brain commands + temp-file seed contract (never edit app/, commit).
    public function test_worker_prompt_drives_only_the_thin_commands_and_temp_file_seed(): void
    {
        $prompt = $this->workerPrompt();

        self::assertStringContainsString('atlas:brain:next', $prompt);
        self::assertStringContainsString('atlas:brain:seed', $prompt);
        self::assertStringContainsString('--dry-run', $prompt);
        self::assertStringContainsString('--specs=', $prompt);

        // author≠judge hard rule must be stated verbatim-ish: never edit app/, never commit/merge.
        self::assertMatchesRegularExpression('/never\s+edit\s+app\//i', $prompt);
        self::assertMatchesRegularExpression('/commit|merge/i', $prompt);

        // The temp specs file must be written OUTSIDE the serving disk + ledgers (the dedicated-disk gotcha):
        // the prompt names a /tmp path AND explicitly forbids the serving/ledger dirs as the temp-file location.
        self::assertMatchesRegularExpression('#>\s*[\'"]?/tmp/#', $prompt, 'the prompt must write the specs to a /tmp file');
        self::assertMatchesRegularExpression(
            '/NOT\s+under\s+storage\/app\/atlas\/task-serving\s+or\s+storage\/ledgers/i',
            $prompt,
            'the prompt must forbid writing the temp file under the serving disk or ledgers (the dedicated-disk gotcha)',
        );
    }

    // brain:next emits the disabled status verbatim (the OFF-switch contract word).
    public function test_brain_next_emits_disabled_status_verbatim(): void
    {
        Artisan::call('atlas:brain:next', ['scope' => 'loop', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);
        self::assertSame('disabled', $payload['status']);
    }

    public function test_brain_next_records_actor_heartbeat_when_disabled(): void
    {
        Artisan::call('atlas:brain:next', ['scope' => 'loop', '--actor' => 'claude-10', '--json' => true]);
        $rows = (new AtlasBrainHeartbeatLedger($this->heartbeatRoot))->tail('loop', 10);

        self::assertCount(1, $rows);
        self::assertSame('claude-10', $rows[0]['actor']);
        self::assertSame('next', $rows[0]['command']);
        self::assertSame('disabled', $rows[0]['status']);
    }

    public function test_brain_next_discards_actor_temp_spec_when_target_is_already_done(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $target = 'app/Models/AtlasNonHarnessTarget.php';
        (new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot))->record([
            'snapshot_id' => 's',
            'status' => 'seeded',
            'produced' => true,
            'action' => 'seed',
            'target_path' => $target,
            'task_packet_id' => 'already-done',
            'refusal' => false,
        ]);

        $actor = 'contract-stale-'.bin2hex(random_bytes(4));
        $path = '/tmp/brain-'.$actor.'.json';
        file_put_contents($path, (string) json_encode(['packets' => [[
            'task_packet_id' => 'already-done',
            'allowed_files' => [$target],
        ]]], JSON_UNESCAPED_SLASHES));

        $repo = sys_get_temp_dir().'/atlas-brain-empty-repo-'.bin2hex(random_bytes(6));
        @mkdir($repo.'/app/Services/Ai/AutonomousEvolution', 0775, true);

        try {
            Artisan::call('atlas:brain:next', ['scope' => 'loop', '--repo' => $repo, '--actor' => $actor, '--json' => true]);
            $payload = json_decode(trim(Artisan::output()), true);

            self::assertFileDoesNotExist($path);
            self::assertSame('discarded_done_set_spec', $payload['temp_spec_recovery']['action']);
            self::assertSame($target, $payload['temp_spec_recovery']['target_path']);
        } finally {
            @unlink($path);
        }
    }

    // brain:seed emits the brain_enabled + status contract surface verbatim (gates run, never enqueue when OFF).
    public function test_brain_seed_emits_status_and_brain_enabled_flag_verbatim(): void
    {
        $path = sys_get_temp_dir().'/contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => 'brain:contract-1',
            'objective' => 'Extend App\\Services\\Ai\\AutonomousEvolution\\Brain\\AtlasBrainScopeDryProbe coverage with a runnable test.',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeDryProbe.php'],
            'scope_in' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeDryProbe.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('brain_enabled', $payload);
        self::assertFalse((bool) $payload['brain_enabled']);
        self::assertArrayHasKey('counts', $payload);
        self::assertArrayHasKey('enqueued', $payload['counts']);
        @unlink($path);
    }

    public function test_brain_seed_records_actor_heartbeat_on_dry_run(): void
    {
        $path = sys_get_temp_dir().'/contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => 'brain:heartbeat-1',
            'objective' => 'Harden App\\Models\\AtlasNonHarnessHeartbeatTarget so seed heartbeat is recorded.',
            'allowed_files' => ['app/Models/AtlasNonHarnessHeartbeatTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessHeartbeatTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--actor' => 'claude-100', '--dry-run' => true, '--json' => true]);
        $rows = (new AtlasBrainHeartbeatLedger($this->heartbeatRoot))->tail('loop', 10);

        self::assertNotEmpty($rows);
        self::assertSame('claude-100', $rows[array_key_last($rows)]['actor']);
        self::assertSame('seed', $rows[array_key_last($rows)]['command']);
        self::assertSame('ok', $rows[array_key_last($rows)]['status']);
        self::assertTrue($rows[array_key_last($rows)]['dry_run']);
        @unlink($path);
    }

    public function test_brain_seed_dry_run_can_skip_heartbeat_for_observer_probe(): void
    {
        $path = sys_get_temp_dir().'/contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => 'brain:observer-heartbeat-1',
            'objective' => 'Harden App\\Models\\AtlasNonHarnessObserverTarget so observer probes do not fake actor liveness.',
            'allowed_files' => ['app/Models/AtlasNonHarnessObserverTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessObserverTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--actor' => 'codex-vigia', '--dry-run' => true, '--no-heartbeat' => true, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);
        $rows = (new AtlasBrainHeartbeatLedger($this->heartbeatRoot))->tail('loop', 10);

        self::assertSame('ok', $payload['status']);
        self::assertSame(1, $payload['counts']['dry_run']);
        self::assertSame([], $rows);
        @unlink($path);
    }

    public function test_brain_seed_records_provenance_on_real_enqueue(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $id = 'brain:provenance-'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/brain-contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => $id,
            'objective' => 'Harden App\\Models\\AtlasNonHarnessTarget so provenance is recorded when seed enqueues.',
            'allowed_files' => ['app/Models/AtlasNonHarnessTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--actor' => 'claude-100', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);
        $rows = (new AtlasBrainProvenanceLedger($this->provenanceRoot))->tail('loop', 10);
        $heartbeats = (new AtlasBrainHeartbeatLedger($this->heartbeatRoot))->tail('loop', 10);

        self::assertSame(1, (int) $payload['counts']['enqueued']);
        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]['cycle_id']);
        self::assertSame($id, $rows[0]['task_packet_id']);
        self::assertSame('app/Models/AtlasNonHarnessTarget.php', $rows[0]['target_path']);
        self::assertSame('claude-100', $rows[0]['actor']);
        self::assertNotEmpty($heartbeats);
        self::assertSame('claude-100', $heartbeats[array_key_last($heartbeats)]['actor']);
        self::assertSame('seed', $heartbeats[array_key_last($heartbeats)]['command']);
        self::assertSame('ok', $heartbeats[array_key_last($heartbeats)]['status']);
        self::assertFalse($heartbeats[array_key_last($heartbeats)]['dry_run']);
        @unlink($path);
    }

    public function test_brain_seed_cleanup_specs_removes_temp_file_after_real_enqueue(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $id = 'brain:cleanup-specs-'.bin2hex(random_bytes(4));
        $dir = sys_get_temp_dir().'/atlas-brain-cleanup-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0775, true);
        $path = $dir.'/brain-claude-cleanup.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => $id,
            'objective' => 'Harden App\\Models\\AtlasNonHarnessCleanupTarget so seeded temp specs are cleaned automatically.',
            'allowed_files' => ['app/Models/AtlasNonHarnessCleanupTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessCleanupTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--actor' => 'claude-cleanup', '--cleanup-specs' => true, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(1, (int) $payload['counts']['enqueued']);
        self::assertFileDoesNotExist($path);
        @rmdir($dir);
    }

    public function test_brain_seed_cleanup_specs_removes_done_set_temp_file(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $target = 'app/Models/AtlasNonHarnessDoneCleanupTarget.php';
        (new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot))->record([
            'snapshot_id' => 'snap-prev',
            'status' => 'seeded',
            'produced' => true,
            'action' => 'seed',
            'target_path' => $target,
            'task_packet_id' => 'brain:prev-cleanup',
            'refusal' => false,
        ]);

        $path = '/tmp/brain-claude-done-cleanup-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => 'brain:done-cleanup',
            'objective' => 'Harden App\\Models\\AtlasNonHarnessDoneCleanupTarget so stale done-set specs are cleaned automatically.',
            'allowed_files' => [$target],
            'scope_in' => [$target],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--actor' => 'claude-done-cleanup', '--cleanup-specs' => true, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(1, (int) $payload['counts']['skipped_done_set']);
        self::assertFileDoesNotExist($path);
        self::assertSame('resume_external_brain_step_1', $payload['recovery_hint']['action']);
        self::assertSame($payload['recovery_hint']['command'], $payload['next_command']);
        self::assertStringContainsString("atlas:brain:next 'loop' --scope-signals --actor='claude-done-cleanup' --json", $payload['next_command']);
    }

    public function test_brain_seed_cleanup_specs_keeps_non_brain_temp_file(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $id = 'brain:cleanup-keeps-'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => $id,
            'objective' => 'Harden App\\Models\\AtlasNonHarnessCleanupKeepTarget so cleanup only deletes external brain temp specs.',
            'allowed_files' => ['app/Models/AtlasNonHarnessCleanupKeepTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessCleanupKeepTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--actor' => 'claude-cleanup', '--cleanup-specs' => true, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(1, (int) $payload['counts']['enqueued']);
        self::assertFileExists($path);
        @unlink($path);
    }

    public function test_brain_seed_warns_when_actor_is_missing(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $id = 'brain:missing-actor-'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => $id,
            'objective' => 'Harden App\\Models\\AtlasNonHarnessMissingActorTarget so actorless seeds are visible.',
            'allowed_files' => ['app/Models/AtlasNonHarnessMissingActorTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessMissingActorTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('', $payload['actor']);
        self::assertContains('missing_actor_attribution', $payload['warnings']);
        @unlink($path);
    }

    public function test_brain_seed_require_actor_blocks_actorless_enqueue(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $id = 'brain:require-actor-'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => $id,
            'objective' => 'Harden App\\Models\\AtlasNonHarnessRequireActorTarget so strict quota seeds require actor attribution.',
            'allowed_files' => ['app/Models/AtlasNonHarnessRequireActorTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessRequireActorTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        try {
            $exit = Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--require-actor' => true, '--json' => true]);
        } catch (\Throwable $e) {
            $this->fail('Strict actor mode must be a supported seed option: '.$e->getMessage());
        }

        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(1, $exit);
        self::assertSame('missing_actor', $payload['status']);
        self::assertSame('', $payload['actor']);
        self::assertContains('missing_actor_attribution', $payload['warnings']);
        self::assertSame(0, (int) $payload['counts']['enqueued']);
        @unlink($path);
    }

    public function test_brain_seed_infers_actor_from_external_brain_temp_file(): void
    {
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");

        $id = 'brain:actor-path-'.bin2hex(random_bytes(4));
        $dir = sys_get_temp_dir().'/atlas-brain-actor-path-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0775, true);
        $path = $dir.'/brain-claude-brain-2-quota-100.json';
        file_put_contents($path, (string) json_encode(['packets' => [$this->creditedSeedPacket([
            'task_packet_id' => $id,
            'objective' => 'Harden App\\Models\\AtlasNonHarnessPathActorTarget so temp-file actor attribution is preserved.',
            'allowed_files' => ['app/Models/AtlasNonHarnessPathActorTarget.php'],
            'scope_in' => ['app/Models/AtlasNonHarnessPathActorTarget.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ])]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--scope' => 'loop', '--json' => true]);
        $rows = (new AtlasBrainProvenanceLedger($this->provenanceRoot))->tail('loop', 10);

        self::assertSame(1, count($rows));
        self::assertSame('claude-brain-2-quota-100', $rows[0]['actor']);
        @unlink($path);
        @rmdir($dir);
    }

    // The four FATAL advisory flags are the EXACT contract the seed gate promotes — frozen so a rename breaks here.
    public function test_brain_fatal_advisory_flag_vocabulary_is_frozen(): void
    {
        self::assertSame([
            'vague_objective',
            'acceptance_not_runnable',
            'blind_orphan_wiring_proxy',
        ], AtlasBrainSeedQualityGate::BRAIN_FATAL_ADVISORY);
    }

    // The brain:next status vocabulary is frozen in the command + mirrored in the worker prompt.
    public function test_status_vocabulary_is_present_in_command_and_prompt(): void
    {
        $nextSource = (string) file_get_contents(base_path('app/Console/Commands/AtlasBrainNextCommand.php'));
        // The six literal status words emitted verbatim via 'status' => '...'.
        foreach (['disabled', 'dry', 'served', 'already_done', 'prepare_blocked', 'forbidden_target'] as $word) {
            self::assertStringContainsString("'status' => '".$word."'", $nextSource, "brain:next must emit the '{$word}' status verbatim");
        }
        // refused/abstain are emitted via a computed $status (a produced abstain is still a non-origination) —
        // assert they exist as the exact string literals in the source.
        self::assertStringContainsString("'refused' : 'abstain'", $nextSource, 'brain:next must emit refused/abstain verbatim');

        $prompt = $this->workerPrompt();
        foreach (['served', 'dry', 'disabled', 'already_done', 'prepare_blocked', 'forbidden_target'] as $word) {
            self::assertStringContainsString($word, $prompt, "the worker prompt must handle the '{$word}' status");
        }
    }
}
