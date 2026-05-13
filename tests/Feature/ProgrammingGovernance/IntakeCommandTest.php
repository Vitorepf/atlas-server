<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

class IntakeCommandTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_intake_command_creates_trackable_work_item(): void
    {
        $exit = Artisan::call('atlas:programming:intake', [
            'intent' => 'Adicionar nova feature de export pdf no runner',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('atlas.programming.work_item.v1', $payload['schema_version']);
        $this->assertNotEmpty($payload['code']);
        $this->assertSame('feature', $payload['intent_type']);
        $this->assertSame('structural', $payload['scope_mode']);
        $this->assertSame('spec_required', $payload['status']);
        $this->assertSame('intake', $payload['current_stage']);
        $this->assertNotEmpty($payload['required_gates']);

        $this->assertDatabaseCount('atlas_programming_work_items', 1);
    }

    public function test_intake_with_bugfix_intent_starts_open(): void
    {
        Artisan::call('atlas:programming:intake', [
            'intent' => 'Conserte typo no comentário do AtlasFoo',
            '--json' => true,
        ]);

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();

        $this->assertSame('bugfix', $item->intent_type);
        $this->assertSame('compact', $item->scope_mode);
        $this->assertSame('open', $item->status);
    }

    public function test_explicit_type_override_wins(): void
    {
        Artisan::call('atlas:programming:intake', [
            'intent' => 'Mudar coisa qualquer',
            '--type' => 'refactor',
            '--mode' => 'structural',
            '--owner' => 'vitor',
            '--json' => true,
        ]);

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();

        $this->assertSame('refactor', $item->intent_type);
        $this->assertSame('structural', $item->scope_mode);
        $this->assertSame('vitor', $item->owner);
    }

    public function test_intake_rejects_empty_intent(): void
    {
        $exit = Artisan::call('atlas:programming:intake', [
            'intent' => '   ',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseCount('atlas_programming_work_items', 0);
    }

    public function test_initial_gaps_include_known_future_pieces(): void
    {
        Artisan::call('atlas:programming:intake', [
            'intent' => 'Refatorar harness para suportar Forge OS',
            '--json' => true,
        ]);

        $item = AtlasProgrammingWorkItem::query()->firstOrFail();
        $gapNames = array_map(static fn (array $gap): string => $gap['name'], $item->gaps_json);

        $this->assertContains('plan_autogeneration', $gapNames);
        $this->assertContains('cartography_publishing', $gapNames);
        $this->assertContains('learning_loop_automation', $gapNames);
    }
}
