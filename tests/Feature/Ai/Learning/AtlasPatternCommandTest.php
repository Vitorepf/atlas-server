<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class AtlasPatternCommandTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_150000_create_process_patterns_table.php'];

    private const LEDGER_COMPANION_TABLES = ['process_pattern_applications', 'process_patterns'];

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
