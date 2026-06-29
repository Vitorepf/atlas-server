<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyStore;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyTarget;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopFileAmbitionFacultyStore;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopIntentStreamOperatorIntentSource;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopOperatorIntentSource;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Proves the intent-drift loop runs on REAL adapters, not test fakes: the container resolves both contracts to
 * their production impls, the operator-intent source reuses the existing stream reader, and the file store
 * round-trips the ambition-faculty target.
 */
final class AtlasLoopIntentDriftProductionBindingTest extends TestCase
{
    private string $intentPath;

    private string $facultyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-intent-drift-binding';
        File::ensureDirectoryExists($base);
        $this->intentPath = $base.'/operator-intent.jsonl';
        $this->facultyPath = $base.'/ambition-faculty-target.json';
        @unlink($this->intentPath);
        @unlink($this->facultyPath);
        config([
            'atlas.quaternity.intent_stream_path' => $this->intentPath,
            'atlas.quaternity.ambition_faculty_path' => $this->facultyPath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->intentPath);
        @unlink($this->facultyPath);
        parent::tearDown();
    }

    public function test_container_resolves_production_adapters_for_both_contracts(): void
    {
        $this->assertInstanceOf(AtlasLoopIntentStreamOperatorIntentSource::class, app(AtlasLoopOperatorIntentSource::class));
        $this->assertInstanceOf(AtlasLoopFileAmbitionFacultyStore::class, app(AtlasLoopAmbitionFacultyStore::class));
    }

    public function test_operator_intent_source_reuses_the_stream_reader(): void
    {
        $lineA = (string) json_encode(['ts' => 1, 'raw_text' => 'ship faster', 'source' => 'chat']);
        $lineB = (string) json_encode(['ts' => 2, 'raw_text' => 'raise ambition', 'source' => 'goal']);
        File::put($this->intentPath, $lineA."\n".$lineB."\n");

        /** @var AtlasLoopOperatorIntentSource $source */
        $source = app(AtlasLoopOperatorIntentSource::class);
        $recent = $source->recent(8);

        // The deterministic message arrays the stream reader produces, oldest → newest.
        $this->assertCount(2, $recent);
        $this->assertSame('ship faster', $recent[0]['raw_text']);
        $this->assertSame('operator', $recent[0]['author']);
        $this->assertSame('raise ambition', $recent[1]['raw_text']);
        $this->assertSame('goal', $recent[1]['source']);
        // The sha256(line) id proves the record came THROUGH the stream reader, not a re-implemented parser.
        $this->assertSame(hash('sha256', $lineA), $recent[0]['id']);
    }

    public function test_file_ambition_faculty_store_round_trips_via_the_container(): void
    {
        /** @var AtlasLoopAmbitionFacultyStore $store */
        $store = app(AtlasLoopAmbitionFacultyStore::class);

        // A missing file yields the canonical default target.
        $this->assertSame(0.0, $store->current()->ambitionTarget);

        $store->save(new AtlasLoopAmbitionFacultyTarget(0.75, ['reach' => 0.5]));
        $reloaded = app(AtlasLoopAmbitionFacultyStore::class)->current();

        $this->assertSame(0.75, $reloaded->ambitionTarget);
        $this->assertSame(['reach' => 0.5], $reloaded->axisWeights);
    }
}
