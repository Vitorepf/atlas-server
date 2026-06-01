<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphDocOsService;
use Tests\TestCase;

/**
 * Pins the Documentation Operating System (`doc-os`) repo-source admission rules:
 * the v1 schema gate, the repo-first invariant, the evidence gate and the macro
 * naming gate. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/system-graph/doc-os.md
 */
class AtlasSystemGraphDocOsTest extends TestCase
{
    private function service(): AtlasSystemGraphDocOsService
    {
        return new AtlasSystemGraphDocOsService();
    }

    /**
     * @return array<string,mixed> a fully canonical v1 frontmatter that should
     *                             pass every gate.
     */
    private function canonicalFrontmatter(): array
    {
        return [
            'doc_schema' => AtlasSystemGraphDocOsService::CANONICAL_DOC_SCHEMA,
            'graph_id' => 'doc-os',
            'graph_title' => 'Documentation Operating System',
            'graph_world' => 'atlas',
            'graph_layer' => 'module',
            'graph_kind' => 'module',
            'graph_parent' => 'atlas-ai-kernel-pipeline',
            'graph_status' => 'active',
            'graph_source' => 'repo',
            'owner' => 'atlas-documentation',
            'repo_paths' => ['docs/engineering-knowledge-base/system-graph/doc-os.md'],
            'allowed_changes' => ['Evoluir regras de doc canonica com docs-health.'],
            'forbidden_changes' => ['Criar cartografia paralela.'],
            'evidence' => ['docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md'],
            'required_tests' => ['php artisan atlas:engineering:knowledge docs-health --json'],
            'risk_level' => 'high',
            'next_actions' => ['Adicionar gate visual de schema na Cartografia.'],
            'requires_evidence' => true,
        ];
    }

    /**
     * Exemplos: "Uma etapa do Kernel so entra na Cartografia como repo source
     * quando tem frontmatter canonico validado." A complete v1 doc is admitted.
     */
    public function test_complete_canonical_doc_is_admitted_as_repo_source(): void
    {
        $verdict = $this->service()->admit($this->canonicalFrontmatter());

        $this->assertSame('repo_source_ready', $verdict['verdict']);
        $this->assertTrue($verdict['admitted']);
        $this->assertTrue($verdict['repo_first']);
        $this->assertSame([], $verdict['breaches']);
        $this->assertSame(AtlasSystemGraphDocOsService::SCHEMA, $verdict['schema']);
    }

    /**
     * Invariant "doc oficial e repo-first para tecnica": a non-repo source (e.g.
     * graph_source: vault) can never be admitted as technical canon and is not
     * repo_first.
     */
    public function test_non_repo_source_is_rejected_and_not_repo_first(): void
    {
        $fm = $this->canonicalFrontmatter();
        $fm['graph_source'] = 'vault';

        $verdict = $this->service()->admit($fm);

        $this->assertSame('rejected', $verdict['verdict']);
        $this->assertFalse($verdict['admitted']);
        $this->assertFalse($verdict['repo_first']);
        $this->assertContains('non_repo_source', $verdict['breaches']);
    }

    /**
     * Flow "Docs oficiais sao validadas por schema": the wrong doc_schema fails
     * the schema gate even if everything else is filled.
     */
    public function test_wrong_doc_schema_fails_the_schema_gate(): void
    {
        $fm = $this->canonicalFrontmatter();
        $fm['doc_schema'] = 'legacy_freeform';

        $check = $this->service()->validateSchema($fm);

        $this->assertFalse($check['ok']);
        $this->assertContains('wrong_doc_schema', $check['breaches']);
    }

    /**
     * A missing required v1 field (here `owner`) is flagged as a specific
     * missing_field breach and blocks admission.
     */
    public function test_missing_required_field_blocks_admission(): void
    {
        $fm = $this->canonicalFrontmatter();
        unset($fm['owner']);

        $verdict = $this->service()->admit($fm);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('missing_field:owner', $verdict['breaches']);
    }

    /**
     * Forbidden_changes "Remover evidencia obrigatoria": when requires_evidence is
     * true but evidence is empty, the evidence gate fails closed; an empty-evidence
     * doc that otherwise requires it cannot be admitted. A doc that does NOT
     * require evidence passes that gate.
     */
    public function test_required_evidence_gate_fails_closed(): void
    {
        $svc = $this->service();

        $fm = $this->canonicalFrontmatter();
        $fm['evidence'] = [];

        $gate = $svc->evidenceGate($fm);
        $this->assertFalse($gate['ok']);
        $this->assertTrue($gate['required']);
        $this->assertFalse($gate['present']);
        $this->assertSame('evidence_required_but_missing', $gate['breach']);

        // requires_evidence false -> the gate passes regardless of evidence.
        $fm['requires_evidence'] = false;
        $this->assertTrue($svc->evidenceGate($fm)['ok']);
    }

    /**
     * v1 macro gate: a macro_layer doc missing the four macro naming fields is
     * blocked; supplying all four clears the gate.
     */
    public function test_macro_layer_requires_the_four_naming_fields(): void
    {
        $svc = $this->service();

        $fm = $this->canonicalFrontmatter();
        $fm['macro_layer'] = true;

        $gate = $svc->macroNamingGate($fm);
        $this->assertFalse($gate['ok']);
        $this->assertTrue($gate['is_macro']);
        $this->assertEqualsCanonicalizing(
            ['product_name', 'runtime_acronym', 'internal_product_name', 'technical_runtime'],
            $gate['missing'],
        );

        $verdict = $svc->admit($fm);
        $this->assertFalse($verdict['admitted']);
        $this->assertContains('macro_layer_naming_incomplete', $verdict['breaches']);

        // Supply all four -> macro gate clears.
        $fm['product_name'] = 'Documentation Operating System';
        $fm['runtime_acronym'] = 'doc-os';
        $fm['internal_product_name'] = 'Docs OS';
        $fm['technical_runtime'] = 'AtlasSystemGraphDocOsService';
        $this->assertTrue($svc->macroNamingGate($fm)['ok']);
        $this->assertTrue($svc->admit($fm)['admitted']);
    }
}
