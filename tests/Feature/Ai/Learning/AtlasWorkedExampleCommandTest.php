<?php

namespace Tests\Feature\Ai\Learning;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class AtlasWorkedExampleCommandTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = [
        '2026_05_07_120000_create_dreyfus_overlays_table.php',
        '2026_05_07_140000_create_worked_examples_table.php',
    ];

    private const LEDGER_COMPANION_TABLES = ['worked_examples', 'dreyfus_overlays'];

    public function test_command_authors_lists_shows_and_delivers_worked_example(): void
    {
        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'author',
            'subject' => 'pull-request-review',
            '--domain' => 'programming',
            '--title' => 'PR review estrutural',
            '--json' => true,
        ]);
        $authored = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('authored', $authored['status']);
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::WorkedExampleAuthored->value)->count());

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'list',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $list = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $list['examples']);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'show',
            'subject' => (string) data_get($authored, 'example.id'),
            '--json' => true,
        ]);
        $show = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('show', $show['mode']);

        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'pull-request-review',
            '--domain' => 'programming',
            '--dreyfus-stage' => 2,
            '--json' => true,
        ]);
        $delivered = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $delivered['status']);
        $this->assertSame([1, 3, 5], data_get($delivered, 'worked_example.fading.visible_steps'));
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::WorkedExampleDelivered->value)->count());
    }
}
