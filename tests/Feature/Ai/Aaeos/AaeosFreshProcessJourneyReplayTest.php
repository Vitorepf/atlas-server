<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AaeosFreshProcessJourneyReplayTest extends TestCase
{
    private string $originalConnection;

    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'atlas-p2a1-fresh-');
        config([
            'database.connections.atlas_p2a1_fresh' => [
                'driver' => 'sqlite',
                'database' => $this->databaseFile,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'atlas_p2a1_fresh',
        ]);
        DB::setDefaultConnection('atlas_p2a1_fresh');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        DB::purge('atlas_p2a1_fresh');
        config(['database.default' => $this->originalConnection]);
        DB::setDefaultConnection($this->originalConnection);
        @unlink($this->databaseFile);

        parent::tearDown();
    }

    public function test_fresh_laravel_process_recomputes_the_sealed_journey_without_object_cache(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $first = $ledger->record(LedgerEventType::AaeosCycleRecorded, ['step' => 1], $this->context(1));
        $second = $ledger->record(LedgerEventType::AaeosCycleRecorded, ['step' => 2], $this->context(2));
        self::assertNotNull($first);
        self::assertNotNull($second);

        $replay = app(AtlasLedgerReplayService::class);
        $cutoff = $replay->authenticatedCutoffForTenantChain('tenant-fresh', (string) $first->chain_key_hash);
        self::assertNotNull($cutoff);
        $manifest = $replay->journeyManifestForTenantChain('tenant-fresh', (string) $first->chain_key_hash, $cutoff);

        $directory = storage_path('framework/testing/atlas-p2a1-fresh-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0777, true);
        $manifestPath = $directory.'/manifest.json';
        $scriptPath = $directory.'/verify.php';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($scriptPath, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config([
    'database.connections.atlas_p2a1_fresh_child' => [
        'driver' => 'sqlite',
        'database' => $argv[2],
        'prefix' => '',
        'foreign_key_constraints' => true,
    ],
    'database.default' => 'atlas_p2a1_fresh_child',
    'database.ledger_roles.enforced' => false,
]);
Illuminate\Support\Facades\DB::purge('atlas_p2a1_fresh_child');
Illuminate\Support\Facades\DB::setDefaultConnection('atlas_p2a1_fresh_child');
$manifest = json_decode((string) file_get_contents($argv[3]), true, flags: JSON_THROW_ON_ERROR);
$result = app(App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService::class)->verifyJourneyManifest($manifest);
echo json_encode(['pid' => getmypid(), 'result' => $result], JSON_THROW_ON_ERROR);
PHP);

        try {
            $process = new Process(
                [PHP_BINARY, $scriptPath, base_path(), $this->databaseFile, $manifestPath],
                base_path(),
                ['APP_KEY' => (string) config('app.key')],
            );
            $process->setTimeout(20);
            $process->mustRun();
            $fresh = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($manifestPath);
            @unlink($scriptPath);
            @rmdir($directory);
        }

        self::assertNotSame(getmypid(), $fresh['pid']);
        $verified = $fresh['result'];

        self::assertTrue($verified['valid']);
        self::assertSame(2, $verified['event_count']);
        self::assertSame($manifest['journey_manifest_hash'], $verified['journey_manifest_hash']);
    }

    /** @return array<string,mixed> */
    private function context(int $position): array
    {
        return [
            'tenant_id' => 'tenant-fresh',
            'operator_id' => 'operator-fresh',
            'envelope_id' => 'fresh-'.$position,
            'correlation_id' => 'fresh-journey',
            'scope_type' => 'journey',
            'scope_id' => 'fresh-journey',
            'occurred_at' => sprintf('2026-07-24T14:00:%02dZ', $position),
        ];
    }
}
