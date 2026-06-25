<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\Feedback;

use App\Console\Commands\AtlasLoopFeedbackCli;
use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackHonestyAuditor;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wires AtlasLoopGiveBackHonestyAuditor into the live `atlas:loop:feedback audit` flow. Proves the
 * orphan capability is reached by a real production call path (the CLI). The test injects a
 * counting auditor double, runs the CLI through Artisan::call, and asserts (a) the auditor was
 * invoked exactly once, and (b) its rows reach the CLI output unchanged.
 */
final class AtlasLoopGiveBackHonestyAuditorWiringWiredTest extends TestCase
{
    public function test_command_class_imports_the_auditor_symbol(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AtlasLoopFeedbackCli::class))->getFileName(),
        );
        $this->assertStringContainsString(
            AtlasLoopGiveBackHonestyAuditor::class,
            $source,
            'AtlasLoopFeedbackCli must reference the auditor so it is no longer an orphan',
        );
    }

    public function test_audit_action_invokes_the_auditor_and_surfaces_its_rows(): void
    {
        $rows = [
            [
                'packet_id' => 'pkt-1',
                'reason' => 'out_of_scope',
                'reason_category' => 'out_of_scope',
                'verdict' => 'honest',
                'evidence_pointer' => 'no_evidence',
            ],
            [
                'packet_id' => 'pkt-2',
                'reason' => 'wrong_site',
                'reason_category' => 'wrong_site',
                'verdict' => 'suspect',
                'evidence_pointer' => 'sha-abc',
            ],
        ];

        $counter = new \stdClass();
        $counter->n = 0;
        $this->app->instance(AtlasLoopGiveBackHonestyAuditor::class, new class($rows, $counter) extends AtlasLoopGiveBackHonestyAuditor
        {
            public function __construct(private array $injectedRows, private \stdClass $counter)
            {
                parent::__construct();
            }

            public function audit(int $limit = 200, int $windowHours = 168): array
            {
                $this->counter->n++;

                return $this->injectedRows;
            }
        });

        $exit = Artisan::call('atlas:loop:feedback', [
            'action' => 'audit',
            '--limit' => 5,
            '--window-hours' => 48,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('audit', $payload['action']);
        $this->assertSame(48, $payload['window_hours']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame('honest', $payload['rows'][0]['verdict']);
        $this->assertSame('sha-abc', $payload['rows'][1]['evidence_pointer']);
        $this->assertSame(1, $counter->n, 'auditor must be invoked exactly once by the new call path');
    }
}
