<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Discovery\DiscoveryFixtureFactory;

/**
 * C1 — the Dev prompt must carry the memory DECISION, not an opaque
 * atlas-memory://entry/<id> URI nothing dereferences. Proven by construction:
 * translateRefs inlines a provider-safe title+summary; sensitive entries stay
 * URI-only.
 */
class OpenBrainMemoryDereferenceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function seedMemory(array $attributes): string
    {
        // `id` is not fillable + HasUuids mints its own — so trust the row's id.
        return (string) AtlasMemoryEntry::query()->create(array_merge([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'body' => 'corpo',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'source_type' => 'test_fixture',
        ], $attributes))->getKey();
    }

    private function reasonFor(string $id, string $memoryType = 'decision'): string
    {
        $adapter = new OpenBrainProjectionAdapter($this->fakeOpenBrain([
            ['type' => 'atlas_memory_entry', 'id' => $id, 'memory_type' => $memoryType],
        ]));

        $envelope = DiscoveryFixtureFactory::envelope();
        $projection = $adapter->projectFor($envelope, DiscoveryFixtureFactory::compactSdd(), new ContextRetrievalPlan(
            runId: $envelope->runId,
            selectedTiers: ['core'],
            budgetChars: 12000,
            requiredSources: [],
            optionalSources: [],
            missingSources: [],
            truncationPolicy: ['open_brain_mode' => DocContextTierSelector::OPEN_BRAIN_MODE_AUTO, 'reasons' => []],
            providerSafe: true,
            planHash: 'planhash-c1',
        ));

        foreach ($projection->memoryRefs as $ref) {
            if ($ref->ref === 'atlas-memory://entry/'.$id) {
                return $ref->reason;
            }
        }

        return '';
    }

    public function test_provider_safe_title_and_summary_are_inlined_into_the_memory_ref(): void
    {
        $id = $this->seedMemory([
            'title' => 'Scheduler roda via launchd',
            'summary' => 'Quando parar, checar print-disabled primeiro.',
        ]);

        $reason = $this->reasonFor($id);
        $this->assertStringContainsString('Scheduler roda via launchd', $reason, 'the memory title must ride in the prompt, not just a URI.');
        $this->assertStringContainsString('print-disabled', $reason, 'the summary must ride too.');
    }

    public function test_sensitive_entry_stays_uri_only(): void
    {
        $id = $this->seedMemory([
            'title' => 'Segredo do cofre',
            'summary' => 'não pode vazar para provider',
            'privacy_class' => 'secret',
            'external_ai_allowed' => false,
        ]);

        $reason = $this->reasonFor($id);
        $this->assertSame('open_brain memory decision', $reason, 'a secret/external-blocked entry must not inline its content.');
        $this->assertStringNotContainsString('Segredo do cofre', $reason);
    }

    public function test_missing_entry_keeps_generic_reason_and_never_throws(): void
    {
        $reason = $this->reasonFor('019f3a30-0000-7000-8000-000000000000', 'technical_context');
        $this->assertSame('open_brain memory technical_context', $reason);
    }

    public function test_context_ref_object_is_still_provider_safe(): void
    {
        $ref = new ContextRef(ContextRef::KIND_DECISION, 'atlas-memory://entry/x', 'decision: t — s');
        $this->assertTrue($ref->isProviderSafe());
    }

    /**
     * @param  list<array<string,mixed>>  $contextRefs
     */
    private function fakeOpenBrain(array $contextRefs): AtlasOpenBrainService
    {
        return new class($contextRefs) extends AtlasOpenBrainService
        {
            /** @param  list<array<string,mixed>>  $contextRefs */
            public function __construct(private readonly array $contextRefs)
            {
                // Bypass parent constructor — the real builder is never touched.
            }

            public function contextPack(array $data, string $surface = 'api'): array
            {
                return ['ok' => true, 'context_refs' => $this->contextRefs];
            }
        };
    }
}
