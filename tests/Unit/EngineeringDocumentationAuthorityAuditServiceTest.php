<?php

declare(strict_types=1);

use App\Services\Engineering\EngineeringDocumentationAuthorityAuditService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Tests\TestCase;

final class EngineeringDocumentationAuthorityAuditServiceTest extends TestCase
{
    public function test_detects_duplicate_canonical_ids_graph_ids_and_technical_runtimes(): void
    {
        $root = sys_get_temp_dir().'/atlas-doc-authority-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        file_put_contents($root.'/first.md', $this->canonicalDoc([
            'id' => 'same-id',
            'title' => 'First Runtime',
            'graph_id' => 'same-graph',
            'technical_runtime' => 'DuplicatedRuntimeService',
            'runtime_acronym' => 'DUP',
            'product_name' => 'Duplicated Runtime',
            'capabilities' => ['shared_capability'],
        ]));
        file_put_contents($root.'/second.md', $this->canonicalDoc([
            'id' => 'same-id',
            'title' => 'Second Runtime',
            'graph_id' => 'same-graph',
            'technical_runtime' => 'DuplicatedRuntimeService',
            'runtime_acronym' => 'DUP',
            'product_name' => 'Duplicated Runtime',
            'capabilities' => ['shared_capability'],
        ]));

        $payload = (new EngineeringDocumentationAuthorityAuditService(new CanonicalDocsFrontmatterParser))->report($root);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(3, $payload['summary']['blocker_count']);
        $this->assertContains('duplicate_doc_id', array_column($payload['blockers'], 'reason'));
        $this->assertContains('duplicate_graph_id', array_column($payload['blockers'], 'reason'));
        $this->assertContains('duplicate_technical_runtime', array_column($payload['blockers'], 'reason'));
        $this->assertTrue($payload['ai_enforcement_contract']['blocked_means_no_new_doc_or_runtime_until_resolved']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['providers_invoked']);
    }

    public function test_surfaces_cross_owner_capability_overlap_as_review_instead_of_blocker(): void
    {
        $root = sys_get_temp_dir().'/atlas-doc-authority-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        file_put_contents($root.'/first.md', $this->canonicalDoc([
            'id' => 'first-doc',
            'title' => 'First Owner',
            'graph_id' => 'first-doc',
            'graph_parent' => 'first-parent',
            'owner' => 'owner-a',
            'technical_runtime' => 'FirstRuntimeService',
            'runtime_acronym' => 'ONE',
            'product_name' => 'First Runtime',
            'capabilities' => ['same_capability'],
        ]));
        file_put_contents($root.'/second.md', $this->canonicalDoc([
            'id' => 'second-doc',
            'title' => 'Second Owner',
            'graph_id' => 'second-doc',
            'graph_parent' => 'second-parent',
            'owner' => 'owner-b',
            'technical_runtime' => 'SecondRuntimeService',
            'runtime_acronym' => 'TWO',
            'product_name' => 'Second Runtime',
            'capabilities' => ['same_capability'],
        ]));

        $payload = (new EngineeringDocumentationAuthorityAuditService(new CanonicalDocsFrontmatterParser))->report($root);

        $this->assertSame('review', $payload['status']);
        $this->assertSame(0, $payload['summary']['blocker_count']);
        $this->assertSame(1, $payload['summary']['capability_overlap_group_count']);
        $this->assertContains('capability_overlap_cluster', array_column($payload['review_items'], 'reason'));
    }

    public function test_same_owner_graph_family_capability_overlap_is_not_review_noise(): void
    {
        $root = sys_get_temp_dir().'/atlas-doc-authority-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        file_put_contents($root.'/parent.md', $this->canonicalDoc([
            'id' => 'parent-doc',
            'title' => 'Parent Runtime',
            'graph_id' => 'parent-doc',
            'graph_parent' => 'atlas-root',
            'owner' => 'same-owner',
            'technical_runtime' => 'ParentRuntimeService',
            'runtime_acronym' => 'PAR',
            'product_name' => 'Parent Runtime',
            'capabilities' => ['family_capability'],
        ]));
        file_put_contents($root.'/child.md', $this->canonicalDoc([
            'id' => 'child-doc',
            'title' => 'Child Runtime',
            'graph_id' => 'child-doc',
            'graph_parent' => 'parent-doc',
            'owner' => 'same-owner',
            'technical_runtime' => 'ChildRuntimeService',
            'runtime_acronym' => 'CHI',
            'product_name' => 'Child Runtime',
            'capabilities' => ['family_capability'],
        ]));

        $payload = (new EngineeringDocumentationAuthorityAuditService(new CanonicalDocsFrontmatterParser))->report($root);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame(0, $payload['summary']['capability_overlap_group_count']);
        $this->assertSame([], $payload['review_items']);
    }

    public function test_cross_owner_graph_family_capability_overlap_is_not_review_noise(): void
    {
        $root = sys_get_temp_dir().'/atlas-doc-authority-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        file_put_contents($root.'/parent.md', $this->canonicalDoc([
            'id' => 'parent-doc',
            'title' => 'Parent Runtime',
            'graph_id' => 'parent-doc',
            'graph_parent' => 'atlas-root',
            'owner' => 'parent-owner',
            'technical_runtime' => 'ParentRuntimeService',
            'runtime_acronym' => 'PAR',
            'product_name' => 'Parent Runtime',
            'capabilities' => ['family_capability'],
        ]));
        file_put_contents($root.'/child.md', $this->canonicalDoc([
            'id' => 'child-doc',
            'title' => 'Child Runtime',
            'graph_id' => 'child-doc',
            'graph_parent' => 'parent-doc',
            'owner' => 'child-owner',
            'technical_runtime' => 'ChildRuntimeService',
            'runtime_acronym' => 'CHI',
            'product_name' => 'Child Runtime',
            'capabilities' => ['family_capability'],
        ]));

        $payload = (new EngineeringDocumentationAuthorityAuditService(new CanonicalDocsFrontmatterParser))->report($root);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame(0, $payload['summary']['capability_overlap_group_count']);
        $this->assertSame([], $payload['review_items']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function canonicalDoc(array $overrides): string
    {
        $frontmatter = array_replace([
            'id' => 'doc-id',
            'type' => 'engineering_knowledge',
            'doc_schema' => 'atlas_canonical_module_doc.v1',
            'title' => 'Doc Title',
            'status' => 'active',
            'category' => 'test',
            'priority' => 1,
            'summary' => 'Test summary.',
            'tags' => ['test'],
            'capabilities' => ['test_capability'],
            'decisions' => ['Test decision.'],
            'maintenance' => ['Test maintenance.'],
            'related_paths' => ['docs/engineering-knowledge-base/test.md'],
            'graph_id' => 'doc-id',
            'graph_title' => 'Doc Title',
            'graph_world' => 'atlas',
            'graph_layer' => 'module',
            'graph_kind' => 'module',
            'graph_parent' => 'parent',
            'graph_status' => 'active',
            'graph_source' => 'repo',
            'owner' => 'test-owner',
            'repo_paths' => ['docs/engineering-knowledge-base/test.md'],
            'allowed_changes' => ['test'],
            'forbidden_changes' => ['test'],
            'depends_on' => ['parent'],
            'flows_to' => ['child'],
            'unlocks' => ['test'],
            'governs' => ['test'],
            'evidence' => ['test'],
            'required_tests' => ['test'],
            'requires_evidence' => true,
            'risk_level' => 'low',
            'next_actions' => ['test'],
            'product_name' => 'Test Product',
            'runtime_acronym' => 'TP',
            'internal_product_name' => 'Test Product Surface',
            'technical_runtime' => 'TestRuntimeService',
        ], $overrides);

        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            if (is_array($value)) {
                $yaml .= $key.":\n";
                foreach ($value as $item) {
                    $yaml .= '  - '.$item."\n";
                }
            } elseif (is_bool($value)) {
                $yaml .= $key.': '.($value ? 'true' : 'false')."\n";
            } else {
                $yaml .= $key.': '.$value."\n";
            }
        }

        return $yaml."---\n\n# {$frontmatter['title']}\n\n## Resumo\n\nTest.\n";
    }
}
