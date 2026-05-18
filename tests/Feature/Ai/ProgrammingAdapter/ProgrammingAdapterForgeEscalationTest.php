<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Programming\Kernel\ProgrammingDomainKernelCanon;
use Tests\TestCase;

class ProgrammingAdapterForgeEscalationTest extends TestCase
{
    public function test_obra_keyword_triggers_scope_escalation(): void
    {
        $this->assertSame(
            'scope_too_large',
            ProgrammingDomainKernelCanon::shouldEscalateToForge('planejar obra grande de billing'),
        );
    }

    public function test_sdd_keyword_triggers_sdd_required_escalation(): void
    {
        $this->assertSame(
            'sdd_required',
            ProgrammingDomainKernelCanon::shouldEscalateToForge('precisamos de sdd para a refatoracao do router'),
        );
    }

    public function test_multiagent_keyword_triggers_multiagent_escalation(): void
    {
        $this->assertSame(
            'multiagent',
            ProgrammingDomainKernelCanon::shouldEscalateToForge('precisamos de multiagent para coordenar a entrega'),
        );
    }

    public function test_small_dev_prompt_does_not_escalate(): void
    {
        $this->assertNull(
            ProgrammingDomainKernelCanon::shouldEscalateToForge('corrigir typo no readme'),
        );
    }

    public function test_obra_mission_type_always_escalates(): void
    {
        $this->assertSame(
            'scope_too_large',
            ProgrammingDomainKernelCanon::shouldEscalateToForge('texto qualquer', 'obra'),
        );
    }
}
