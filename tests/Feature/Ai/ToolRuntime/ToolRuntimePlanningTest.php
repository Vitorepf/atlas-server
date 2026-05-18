<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolPlanningService;
use App\Services\Ai\ToolRuntime\ToolSeedDefinitions;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimePlanningTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createToolRuntimeTables();
        app(ToolDefinitionRegistryService::class)->seedDefaults(ToolSeedDefinitions::all());
    }

    protected function tearDown(): void
    {
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    public function test_plan_selects_read_only_tools_for_research_objective(): void
    {
        $plan = app(ToolPlanningService::class)->plan(
            'pesquisar docs canonicos atlas para mission foundation e fetchar referencias',
        );

        $this->assertSame('draft', $plan->status);
        $selectedIds = array_column((array) $plan->tools_selected, 'tool_id');
        $this->assertContains('docs.search', $selectedIds);
        foreach ($plan->tools_selected as $selected) {
            $this->assertContains(
                $selected['authority_group'],
                ['read_only', 'local_mutation', 'draft'],
                "selected tool [{$selected['tool_id']}] has unsafe authority_group [{$selected['authority_group']}]",
            );
        }
    }

    public function test_plan_returns_no_match_for_irrelevant_objective(): void
    {
        $plan = app(ToolPlanningService::class)->plan('xyz qrs zzz unrelated');
        $this->assertSame('no_match', $plan->status);
        $this->assertSame([], $plan->tools_selected);
    }
}
