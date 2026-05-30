<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Ai\SoftwareCompanyStewardship\AreaFocusLoopCommandController;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Focused tests for the Atlas Loop Command Surface POST run-control endpoint (d).
 *
 * The controller writes/removes the REAL pause/kill signal files the AP-790
 * Reliable24hLoopRunnerService obeys, via the runner's OWN path getters, then
 * re-reads the TRUE state from disk. These tests drive the real controller with
 * a real runner (storage rooted at a temp dir) and assert:
 *   - pause/resume/kill/clear-kill write/delete exactly the runner's own paths;
 *   - the post-state returned is the TRUE on-disk state (never fabricated);
 *   - the runner's own pauseActive()/killSwitchActive() observe what we wrote
 *     (proves a non-fake, non-duplicating composition);
 *   - honesty guards: invalid_action and operator_actor_required both 422;
 *   - the signal is operator-owned and provider-free (honest-stop intact).
 *
 * No database is required: run-control is a pure file-signal path.
 */
final class AreaFocusLoopCommandRunControlTest extends TestCase
{
    private const AREA = 'agentic_engineering_os';

    private const FOCUS = 'dev_forge';

    private string $storageRoot;

    private Reliable24hLoopRunnerService $runner;

    private AreaFocusLoopCommandController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/atlas_loop_command_run_control_'.bin2hex(random_bytes(6));

        // Real runner — only its path/status accessors are exercised by run-control.
        $this->runner = app(Reliable24hLoopRunnerService::class);
        $this->runner->setStorageRootForTesting($this->storageRoot);

        $this->controller = new AreaFocusLoopCommandController(
            app(ProductModeCockpitSurfaceService::class),
            $this->runner,
            app(AreaFocusOperatorDecisionService::class),
            app(AtlasInboxService::class),
        );
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->storageRoot);
        parent::tearDown();
    }

    public function test_pause_writes_the_runner_pause_file_and_reports_true_state(): void
    {
        $pausePath = $this->runner->pausePath(self::AREA, self::FOCUS);
        $this->assertFileDoesNotExist($pausePath);

        $response = $this->controller->runControl(
            $this->postRequest(['action' => 'pause', 'operator_actor' => 'vitor', 'reason' => 'cooling down']),
            self::AREA,
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);

        $this->assertSame(AreaFocusLoopCommandController::RUN_CONTROL_SCHEMA, $body['schema_version']);
        $this->assertSame('pause', $body['action']);
        $this->assertSame('vitor', $body['operator_actor']);
        $this->assertTrue($body['applied']);

        // The file the runner reads now exists, and the post-state is the TRUE on-disk state.
        $this->assertFileExists($pausePath);
        $this->assertTrue($body['pause']['active']);
        $this->assertSame($pausePath, $body['pause']['path']);
        $this->assertFalse($body['kill_switch']['active']);

        // The runner itself observes the pause we wrote (non-fake composition).
        $this->assertTrue($this->runner->pauseStatus(self::AREA, self::FOCUS)['active']);

        // The signal body is operator-authored JSON the loop can audit — not a fabrication.
        $signal = json_decode((string) file_get_contents($pausePath), true);
        $this->assertSame('vitor', $signal['operator_actor']);
        $this->assertSame('cooling down', $signal['reason']);
        $this->assertSame('pause', $signal['action']);
    }

    public function test_resume_deletes_the_pause_file_and_is_idempotent(): void
    {
        $pausePath = $this->runner->pausePath(self::AREA, self::FOCUS);

        // Engage, then resume.
        $this->controller->runControl($this->postRequest(['action' => 'pause', 'operator_actor' => 'vitor']), self::AREA);
        $this->assertFileExists($pausePath);

        $response = $this->controller->runControl($this->postRequest(['action' => 'resume', 'operator_actor' => 'vitor']), self::AREA);
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($body['pause']['active']);
        $this->assertFileDoesNotExist($pausePath);
        $this->assertFalse($this->runner->pauseStatus(self::AREA, self::FOCUS)['active']);

        // Idempotent: resuming again with no pause file present still succeeds and reports inactive.
        $again = $this->controller->runControl($this->postRequest(['action' => 'resume', 'operator_actor' => 'vitor']), self::AREA);
        $this->assertSame(200, $again->getStatusCode());
        $this->assertFalse($this->decode($again)['pause']['active']);
    }

    public function test_kill_and_clear_kill_toggle_the_runner_kill_switch_file(): void
    {
        $killPath = $this->runner->killSwitchPath(self::AREA, self::FOCUS);
        $this->assertFileDoesNotExist($killPath);

        $kill = $this->controller->runControl($this->postRequest(['action' => 'kill', 'operator_actor' => 'vitor', 'reason' => 'halt']), self::AREA);
        $killBody = $this->decode($kill);

        $this->assertSame(200, $kill->getStatusCode());
        $this->assertSame('kill', $killBody['action']);
        $this->assertTrue($killBody['kill_switch']['active']);
        $this->assertSame($killPath, $killBody['kill_switch']['path']);
        $this->assertFileExists($killPath);
        // The runner's own kill-switch check honors the file we wrote.
        $this->assertTrue($this->runner->killSwitchStatus(self::AREA, self::FOCUS)['active']);

        $clear = $this->controller->runControl($this->postRequest(['action' => 'clear-kill', 'operator_actor' => 'vitor']), self::AREA);
        $clearBody = $this->decode($clear);

        $this->assertSame(200, $clear->getStatusCode());
        $this->assertFalse($clearBody['kill_switch']['active']);
        $this->assertFileDoesNotExist($killPath);
        $this->assertFalse($this->runner->killSwitchStatus(self::AREA, self::FOCUS)['active']);
    }

    public function test_pause_and_kill_are_independent_signals(): void
    {
        $this->controller->runControl($this->postRequest(['action' => 'pause', 'operator_actor' => 'vitor']), self::AREA);
        $response = $this->controller->runControl($this->postRequest(['action' => 'kill', 'operator_actor' => 'vitor']), self::AREA);
        $body = $this->decode($response);

        // Both signals coexist; clearing one must not disturb the other.
        $this->assertTrue($body['pause']['active']);
        $this->assertTrue($body['kill_switch']['active']);

        $resumed = $this->controller->runControl($this->postRequest(['action' => 'resume', 'operator_actor' => 'vitor']), self::AREA);
        $resumedBody = $this->decode($resumed);
        $this->assertFalse($resumedBody['pause']['active']);
        $this->assertTrue($resumedBody['kill_switch']['active']);
    }

    public function test_focus_keys_the_signal_to_a_distinct_file(): void
    {
        $devForgePause = $this->runner->pausePath(self::AREA, self::FOCUS);
        $otherFocusPause = $this->runner->pausePath(self::AREA, 'design_qa');
        $this->assertNotSame($devForgePause, $otherFocusPause);

        $this->controller->runControl(
            $this->postRequest(['action' => 'pause', 'operator_actor' => 'vitor', 'focus' => 'design_qa']),
            self::AREA,
        );

        // Only the design_qa pause file exists; the default dev_forge loop is untouched.
        $this->assertFileExists($otherFocusPause);
        $this->assertFileDoesNotExist($devForgePause);
        $this->assertTrue($this->runner->pauseStatus(self::AREA, 'design_qa')['active']);
        $this->assertFalse($this->runner->pauseStatus(self::AREA, self::FOCUS)['active']);
    }

    public function test_invalid_action_is_blocked_422_and_writes_nothing(): void
    {
        $response = $this->controller->runControl(
            $this->postRequest(['action' => 'nuke', 'operator_actor' => 'vitor']),
            self::AREA,
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('blocked', $body['status']);
        $this->assertSame('invalid_action', $body['reason']);

        // Honest-stop: an invalid action never wrote any signal file.
        $this->assertFileDoesNotExist($this->runner->pausePath(self::AREA, self::FOCUS));
        $this->assertFileDoesNotExist($this->runner->killSwitchPath(self::AREA, self::FOCUS));
    }

    public function test_missing_operator_actor_is_blocked_422_and_writes_nothing(): void
    {
        $response = $this->controller->runControl(
            $this->postRequest(['action' => 'kill', 'operator_actor' => '   ']),
            self::AREA,
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('blocked', $body['status']);
        $this->assertSame('operator_actor_required', $body['reason']);

        // A kill with no owner is refused before any file is written.
        $this->assertFileDoesNotExist($this->runner->killSwitchPath(self::AREA, self::FOCUS));
    }

    /**
     * Honesty: run-control is a SIGNAL only. The response never claims a merge or
     * provider invocation, and the only side-effect is the signal file itself.
     */
    public function test_run_control_is_signal_only_never_executes_or_merges(): void
    {
        $response = $this->controller->runControl(
            $this->postRequest(['action' => 'pause', 'operator_actor' => 'vitor']),
            self::AREA,
        );
        $body = $this->decode($response);

        $this->assertArrayNotHasKey('merge_hash', $body);
        $this->assertArrayNotHasKey('provider', $body);
        $this->assertStringContainsString('next iteration boundary', (string) $body['note']);

        // Exactly one signal file exists in the runner's storage tree (the pause we wrote).
        $signalFiles = glob($this->storageRoot.'/*.pause') ?: [];
        $this->assertCount(1, $signalFiles);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function postRequest(array $payload): Request
    {
        return Request::create(
            '/ai/software-company-stewardship/loop/'.self::AREA.'/run-control',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
