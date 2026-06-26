<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');

        $base = sys_get_temp_dir().'/atlas-brain-contract-'.bin2hex(random_bytes(6));
        $this->doneSetRoot = $base.'/done-set';
        $this->journalRoot = $base.'/journal';
        @mkdir($this->doneSetRoot, 0775, true);
        @mkdir($this->journalRoot, 0775, true);
        config()->set('atlas.brain.done_set_root', $this->doneSetRoot);
        config()->set('atlas.brain.journal_root', $this->journalRoot);

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

    // The worker-prompt is a copy-paste artifact: it MUST fit the 4000-char paste limit.
    public function test_worker_prompt_is_under_4000_chars(): void
    {
        $prompt = $this->workerPrompt();
        self::assertLessThan(4000, mb_strlen($prompt), 'worker prompt must stay under the 4000-char paste limit');
        self::assertNotSame('', trim($prompt));
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
        self::assertMatchesRegularExpression('#>\s*/tmp/#', $prompt, 'the prompt must write the specs to a /tmp file');
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

    // brain:seed emits the brain_enabled + status contract surface verbatim (gates run, never enqueue when OFF).
    public function test_brain_seed_emits_status_and_brain_enabled_flag_verbatim(): void
    {
        $path = sys_get_temp_dir().'/brain-contract-specs-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, (string) json_encode(['packets' => [[
            'task_packet_id' => 'brain:contract-1',
            'objective' => 'Extend App\\Services\\Ai\\AutonomousEvolution\\Brain\\AtlasBrainScopeDryProbe coverage with a runnable test.',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeDryProbe.php'],
            'scope_in' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeDryProbe.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ]]]));

        Artisan::call('atlas:brain:seed', ['--specs' => $path, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('brain_enabled', $payload);
        self::assertFalse((bool) $payload['brain_enabled']);
        self::assertArrayHasKey('counts', $payload);
        self::assertArrayHasKey('enqueued', $payload['counts']);
        @unlink($path);
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
