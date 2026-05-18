<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainHandoffService;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeHandoffTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDomainRuntimeTables();
        app(DomainManifestRegistryService::class)->seedDefaults(DomainSeedManifests::all());
    }

    protected function tearDown(): void
    {
        $this->dropDomainRuntimeTables();
        parent::tearDown();
    }

    public function test_handoff_emits_receipt_with_context_pack_and_hash(): void
    {
        $handoff = app(DomainHandoffService::class)->emit('software', 'research', 'need primary source check', [
            'context_pack' => ['summary' => 'need rfc'],
            'expected_output' => ['research_brief' => true],
            'evidence_refs' => ['evid:1'],
        ]);

        $this->assertSame('software', $handoff->source_domain_id);
        $this->assertSame('research', $handoff->target_domain_id);
        $this->assertSame(64, strlen($handoff->receipt_hash));
        $this->assertSame(['summary' => 'need rfc'], $handoff->context_pack);
        $this->assertSame(['evid:1'], $handoff->evidence_refs);
    }

    public function test_handoff_without_context_pack_is_rejected(): void
    {
        $this->expectException(DomainRuntimeException::class);
        $this->expectExceptionMessageMatches('/without context_pack and receipt_hash/');
        app(DomainHandoffService::class)->emit('software', 'research', 'no context', [
            'expected_output' => ['ack' => true],
        ]);
    }

    public function test_handoff_violating_rules_is_rejected(): void
    {
        $this->expectException(DomainRuntimeException::class);
        $this->expectExceptionMessageMatches('/not permitted by manifest handoff_rules/');
        app(DomainHandoffService::class)->emit('personal_development', 'finance', 'invalid hop', [
            'context_pack' => ['x' => 1],
            'expected_output' => ['ack' => true],
        ]);
    }

    public function test_handoff_to_unknown_domain_is_rejected(): void
    {
        $this->expectException(DomainRuntimeException::class);
        $this->expectExceptionMessageMatches('/Domain manifest \[unknown_domain\] not found/');
        app(DomainHandoffService::class)->emit('software', 'unknown_domain', 'oops', [
            'context_pack' => ['x' => 1],
            'expected_output' => ['ack' => true],
        ]);
    }
}
