<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasPatternCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_150000_create_process_patterns_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('process_pattern_applications');
        Schema::dropIfExists('process_patterns');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_pattern_command_catalog_show_match_author_and_apply(): void
    {
        Artisan::call('atlas:pattern', ['actionOrName' => 'catalog', '--json' => true]);
        $catalog = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $catalog['status']);
        $this->assertGreaterThanOrEqual(5, count($catalog['patterns']));

        Artisan::call('atlas:pattern', ['actionOrName' => 'validate-then-scale', '--json' => true]);
        $show = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('show', $show['mode']);
        $this->assertSame('validate-then-scale', data_get($show, 'pattern.name'));

        Artisan::call('atlas:pattern', [
            'actionOrName' => 'matcher',
            'subject' => 'decisao reversivel sem evidencia precisa validar antes de escalar',
            '--json' => true,
        ]);
        $match = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $match['status']);

        Artisan::call('atlas:pattern', [
            'actionOrName' => 'author',
            'subject' => 'measure-before-refactor',
            '--category' => 'architecture',
            '--json' => true,
        ]);
        $authored = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('authored', $authored['status']);
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::ProcessPatternCataloged->value)->count());

        Artisan::call('atlas:pattern', [
            'actionOrName' => 'apply',
            'subject' => 'measure-before-refactor',
            '--outcome' => 'success',
            '--json' => true,
        ]);
        $applied = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('applied', $applied['status']);
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::ProcessPatternApplied->value)->count());
    }
}
