<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveImplementationBriefingService;
use Tests\TestCase;

/**
 * Pins the briefing's machine-checkable contracts: the status taxonomy
 * readiness column, the forbidden bare `implemented`, the command policy
 * (php artisan atlas:* is the contract; the wrapper is an alias) and the
 * 8-AP roster with its read-model/runtime distinction.
 *
 * @see docs/engineering-knowledge-base/cognitive/implementation-briefing.md
 */
class AtlasCognitiveImplementationBriefingTest extends TestCase
{
    private function service(): AtlasCognitiveImplementationBriefingService
    {
        return new AtlasCognitiveImplementationBriefingService();
    }

    /**
     * Taxonomy readiness column: scaffold = not ready; read-model = partial;
     * runtime/surface/self-improving = callable-ready with the exact verdicts.
     */
    public function test_status_taxonomy_maps_each_status_to_its_documented_readiness(): void
    {
        $svc = $this->service();

        $scaffold = $svc->classifyStatus(AtlasCognitiveImplementationBriefingService::STATUS_SCAFFOLD);
        $this->assertSame('no', $scaffold['ready']);
        $this->assertFalse($scaffold['callable_ready']);

        $readModel = $svc->classifyStatus(AtlasCognitiveImplementationBriefingService::STATUS_READ_MODEL);
        $this->assertSame('partial', $readModel['ready']);
        $this->assertTrue($readModel['callable_ready']);
        // read-model is callable-ready (partially) but NOT runtime-ready.
        $this->assertFalse($svc->isRuntimeReady(AtlasCognitiveImplementationBriefingService::STATUS_READ_MODEL));

        $this->assertSame('yes-for-runtime', $svc->classifyStatus(AtlasCognitiveImplementationBriefingService::STATUS_RUNTIME)['ready']);
        $this->assertSame('yes-for-daily-use', $svc->classifyStatus(AtlasCognitiveImplementationBriefingService::STATUS_SURFACE)['ready']);
        $this->assertSame('yes-high-maturity', $svc->classifyStatus(AtlasCognitiveImplementationBriefingService::STATUS_SELF_IMPROVING)['ready']);

        // Runtime grade and above are genuinely runtime-ready.
        $this->assertTrue($svc->isRuntimeReady(AtlasCognitiveImplementationBriefingService::STATUS_RUNTIME));
        $this->assertTrue($svc->isRuntimeReady(AtlasCognitiveImplementationBriefingService::STATUS_SELF_IMPROVING));
    }

    /**
     * The briefing forbids the bare `implemented` status in new APs because it
     * hides the gap — it must be flagged as a violation, never marked ready.
     */
    public function test_bare_implemented_status_is_a_forbidden_violation(): void
    {
        $r = $this->service()->classifyStatus(AtlasCognitiveImplementationBriefingService::STATUS_FORBIDDEN_GENERIC);

        $this->assertTrue($r['forbidden']);
        $this->assertFalse($r['known']);
        $this->assertFalse($r['callable_ready']);
        $this->assertSame('generic_implemented_status_forbidden', $r['violation']);
    }

    /**
     * Command policy: `php artisan atlas:*` is the implementation contract; a
     * bare `atlas <cap>` wrapper is a permitted alias, never the contract.
     */
    public function test_command_policy_separates_contract_from_alias(): void
    {
        $svc = $this->service();

        $contract = $svc->classifyCommand('php artisan atlas:dreyfus node-1');
        $this->assertTrue($contract['is_contract']);
        $this->assertFalse($contract['is_alias']);
        $this->assertSame('laravel_local_canonical', $contract['layer']);

        $alias = $svc->classifyCommand('atlas dreyfus node-1');
        $this->assertFalse($alias['is_contract']);
        $this->assertTrue($alias['is_alias']);
        $this->assertSame('product_wrapper', $alias['layer']);
    }

    /**
     * An IMPLEMENTED AP doc that shows only the product wrapper violates the
     * policy; the same doc with `php artisan atlas:*` is allowed. A future AP
     * doc may show the wrapper only when flagged as a future product alias.
     */
    public function test_implemented_ap_doc_must_show_php_artisan_contract(): void
    {
        $svc = $this->service();

        $bad = $svc->gateApDocCommand('atlas dreyfus node-1', apImplemented: true);
        $this->assertFalse($bad['allowed']);
        $this->assertSame('implemented_ap_doc_must_show_php_artisan_atlas', $bad['violation']);

        $good = $svc->gateApDocCommand('php artisan atlas:dreyfus node-1', apImplemented: true);
        $this->assertTrue($good['allowed']);
        $this->assertNull($good['violation']);

        // Future AP doc: unflagged wrapper is blocked, flagged wrapper is allowed.
        $futureUnflagged = $svc->gateApDocCommand('atlas predict', apImplemented: false, markedFutureAlias: false);
        $this->assertFalse($futureUnflagged['allowed']);
        $this->assertSame('future_alias_must_be_marked_future_product_alias', $futureUnflagged['violation']);

        $futureFlagged = $svc->gateApDocCommand('atlas predict', apImplemented: false, markedFutureAlias: true);
        $this->assertTrue($futureFlagged['allowed']);
    }

    /**
     * The AP roster reflects the briefing exactly: 8 APs, AP-163 is the Dreyfus
     * read model, and NONE of the eight is yet runtime/surface grade.
     */
    public function test_ap_roster_matches_briefing_and_no_ap_is_runtime_ready_yet(): void
    {
        $svc = $this->service();

        $roster = $svc->apRoster();
        $this->assertSame(8, $roster['ap_count']);

        $ap163 = $svc->resolveAp('AP-163');
        $this->assertNotNull($ap163);
        $this->assertSame('Dreyfus Dynamic Pedagogy', $ap163['capability']);
        $this->assertSame(AtlasCognitiveImplementationBriefingService::STATUS_READ_MODEL, $ap163['status']);
        $this->assertSame('partial', $ap163['ready']);
        $this->assertFalse($ap163['runtime_ready']);

        // An AP outside the roster does not resolve.
        $this->assertNull($svc->resolveAp('AP-999'));

        $readiness = $svc->readiness();
        $this->assertSame(0, $readiness['runtime_ready_count']);
        $this->assertSame(8, $readiness['not_yet_runtime_count']);
        $this->assertFalse($readiness['any_runtime_ready']);
    }

    /**
     * The mandatory reading order is the full 13-step sequence and starts at the
     * thesis-multiplier channel.
     */
    public function test_reading_order_is_the_full_mandatory_sequence(): void
    {
        $order = $this->service()->readingOrder();

        $this->assertCount(13, $order);
        $this->assertSame('atlas-ai-thesis-multiplier-channel.md', $order[0]);
        $this->assertSame('atlas-ai-canonical-architecture-index.md', $order[1]);
        $this->assertContains('cognitive/principles.md', $order);
    }
}
