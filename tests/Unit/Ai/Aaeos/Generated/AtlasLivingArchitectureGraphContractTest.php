<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasLivingArchitectureGraphContractService;
use Tests\TestCase;

/**
 * Pins the documented Living Architecture Graph (L1) node-update and
 * Definition-of-Done rules. Pure; no database.
 *
 * @see docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
 */
class AtlasLivingArchitectureGraphContractTest extends TestCase
{
    private function service(): AtlasLivingArchitectureGraphContractService
    {
        return new AtlasLivingArchitectureGraphContractService();
    }

    /**
     * Builds a node that passes every rule, so individual tests can break ONE
     * thing and assert the specific failure.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function validNode(array $overrides = []): array
    {
        return array_merge([
            'id' => 'node-x',
            'status' => 'planned',
            'frontmatter' => [
                'type' => 'module',
                'status' => 'planned',
                'owner' => 'atlas-ai',
                'parent' => 'atlas-ai-canonical-architecture-index',
                'canonical_doc' => 'docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md',
            ],
            'next_actions' => ['Implementar runtime.'],
            'evidence' => [],
        ], $overrides);
    }

    /**
     * "Status Meaning In Living Graph" defines exactly 8 statuses, and the Node
     * Update Rule names implemented/obsolete/archive as the only ones that do not
     * owe a next action — i.e. the terminal set.
     */
    public function test_status_set_and_terminal_set_match_the_doc(): void
    {
        $this->assertCount(8, AtlasLivingArchitectureGraphContractService::NODE_STATUSES);
        $this->assertSame(
            ['implemented', 'obsolete', 'archive'],
            AtlasLivingArchitectureGraphContractService::TERMINAL_STATUSES,
        );
        // A status the doc does not list must NOT be treated as terminal.
        $this->assertNotContains('blocked', AtlasLivingArchitectureGraphContractService::TERMINAL_STATUSES);
    }

    /**
     * "must prefer `planned` when status is uncertain" + "instead of pretending
     * unknown work is done": an unknown/empty token coerces to planned, is
     * flagged as coerced, and is non-terminal (so it still owes a next action).
     */
    public function test_uncertain_status_is_coerced_to_planned_never_implemented(): void
    {
        $unknown = $this->service()->resolveStatus('totally-made-up');
        $this->assertSame('planned', $unknown['resolved_status']);
        $this->assertTrue($unknown['coerced']);
        $this->assertTrue($unknown['requires_next_action']);

        $empty = $this->service()->resolveStatus('');
        $this->assertSame('planned', $empty['resolved_status']);
        $this->assertTrue($empty['coerced']);

        // A real status passes through untouched and is not flagged coerced.
        $real = $this->service()->resolveStatus('Building');
        $this->assertSame('building', $real['resolved_status']);
        $this->assertFalse($real['coerced']);
    }

    /**
     * "must not mark a node `implemented` without evidence." An implemented node
     * with no acceptable evidence fails; adding a repo doc evidence makes it pass.
     */
    public function test_implemented_node_requires_acceptable_evidence(): void
    {
        $noEvidence = $this->validNode([
            'status' => 'implemented',
            'next_actions' => [], // terminal: no next action required
            'evidence' => [],
        ]);
        $r = $this->service()->validateNode($noEvidence);
        $this->assertFalse($r['valid']);
        $this->assertContains(
            'status is implemented but no acceptable repo evidence is linked',
            $r['blocking_reasons'],
        );

        $withEvidence = $this->validNode([
            'status' => 'implemented',
            'next_actions' => [],
            'evidence' => [['kind' => 'test', 'ref' => 'tests/Unit/Foo/BarTest.php']],
        ]);
        $ok = $this->service()->validateNode($withEvidence);
        $this->assertTrue($ok['valid']);
        $this->assertSame(AtlasLivingArchitectureGraphContractService::STATUS_PASS, $ok['verdict']);
    }

    /**
     * "must not use chat memory as canonical evidence" — chat_memory is rejected
     * even with a non-empty ref, so an implemented node backed only by chat memory
     * still fails the evidence obligation. Repo doc/test/command/code is accepted.
     */
    public function test_chat_memory_is_not_acceptable_evidence(): void
    {
        $chat = $this->service()->classifyEvidence(['kind' => 'chat_memory', 'ref' => 'I remember building it']);
        $this->assertFalse($chat['acceptable']);
        $this->assertContains('chat_memory is not canonical evidence', $chat['blocking_reasons']);

        $doc = $this->service()->classifyEvidence(['kind' => 'doc', 'ref' => 'docs/foo.md']);
        $this->assertTrue($doc['acceptable']);

        // Acceptable kind but no ref => still rejected ("link back to ... paths").
        $noRef = $this->service()->classifyEvidence(['kind' => 'command', 'ref' => '']);
        $this->assertFalse($noRef['acceptable']);
    }

    /**
     * Node Update Rule: "must add a next action unless the node is implemented,
     * obsolete or archived." A non-terminal (planned) node with no next action
     * fails; an archive node with no next action passes.
     */
    public function test_non_terminal_node_must_have_next_action(): void
    {
        $planned = $this->validNode(['status' => 'planned', 'next_actions' => []]);
        $r = $this->service()->validateNode($planned);
        $this->assertFalse($r['valid']);
        $this->assertContains("non-terminal node (status 'planned') has no next action", $r['blocking_reasons']);

        $archived = $this->validNode(['status' => 'archive', 'next_actions' => []]);
        $this->assertTrue($this->service()->validateNode($archived)['valid']);
    }

    /**
     * Capability 7 + Definition Of Done: a node missing required frontmatter
     * (e.g. canonical_doc) fails and the missing key is named.
     */
    public function test_node_missing_required_frontmatter_fails(): void
    {
        $node = $this->validNode();
        unset($node['frontmatter']['canonical_doc']);

        $r = $this->service()->validateNode($node);
        $this->assertFalse($r['valid']);
        $this->assertSame(['canonical_doc'], $r['missing_frontmatter']);
    }

    /**
     * "First Build Scope" + "Forbidden in this L1 build": a write under the Vault
     * graph folder is permitted; a write touching app code is forbidden; deleting
     * a Vault note is forbidden.
     */
    public function test_write_scope_enforces_first_build_scope(): void
    {
        $allowed = $this->service()->checkWriteScope([
            'path' => 'AtlasVault/00-constituicao/atlas-system-graph/self-construction-os.md',
            'operation' => 'create',
        ]);
        $this->assertTrue($allowed['permitted']);

        $appCode = $this->service()->checkWriteScope([
            'path' => 'app/Services/Foo/BarService.php',
            'operation' => 'update',
        ]);
        $this->assertFalse($appCode['permitted']);
        $this->assertContains('changes_app_code', $appCode['forbidden_targets']);

        $deleteNote = $this->service()->checkWriteScope([
            'path' => 'AtlasVault/00-constituicao/atlas-system-graph/old.md',
            'operation' => 'delete',
        ]);
        $this->assertFalse($deleteNote['permitted']);
        $this->assertContains('deleting Vault notes is forbidden in the L1 build', $deleteNote['blocking_reasons']);
    }

    /**
     * Definition Of Done: the required-node set must all be present. A graph
     * missing scope-validator fails with that node named; a graph with all
     * required nodes (each valid) passes.
     */
    public function test_definition_of_done_requires_all_canonical_nodes(): void
    {
        $required = AtlasLivingArchitectureGraphContractService::DEFINITION_OF_DONE_REQUIRED_NODES;

        // Drop one required node -> not done.
        $partialNodes = [];
        foreach ($required as $id) {
            if ($id === 'scope-validator') {
                continue;
            }
            $partialNodes[] = $this->validNode(['id' => $id, 'status' => 'planned']);
        }
        $partial = $this->service()->checkDefinitionOfDone(['top_level_systems' => [], 'nodes' => $partialNodes]);
        $this->assertSame(AtlasLivingArchitectureGraphContractService::STATUS_FAIL, $partial['status']);
        $this->assertContains('scope-validator', $partial['missing_nodes']);

        // All required nodes present and valid, plus one top-level system -> done.
        $fullNodes = [$this->validNode(['id' => 'atlas-system-graph', 'status' => 'active'])];
        foreach ($required as $id) {
            $fullNodes[] = $this->validNode(['id' => $id, 'status' => 'planned']);
        }
        $full = $this->service()->checkDefinitionOfDone([
            'top_level_systems' => ['atlas-system-graph'],
            'nodes' => $fullNodes,
        ]);
        $this->assertSame(AtlasLivingArchitectureGraphContractService::STATUS_PASS, $full['status']);
        $this->assertTrue($full['definition_of_done_met']);
        $this->assertSame([], $full['missing_nodes']);
    }
}
