<?php

declare(strict_types=1);

namespace App\Services\Vault;

/**
 * The canonical expectation of what the Atlas cartography should show.
 *
 * This is the *contract written in node-catalog-and-build-contract.md* materialized
 * as a PHP structure. The cartography uses this to:
 *   1. Render the canvas (positions, kinds, views) consistently.
 *   2. Detect `missing_source` — when a canon piece has no matching .md on disk.
 *
 * When a real .md is found (by frontmatter `id` == `graph_id`), its fields are
 * merged onto the canon entry. The canon is the skeleton; the filesystem is the
 * flesh. If the flesh is missing, the skeleton still appears, marked as such.
 */
final class CartographyCanon
{
    /**
     * The 6 continents of the Vault Universe.
     *
     * @return list<array<string, mixed>>
     */
    public function continents(): array
    {
        return [
            ['graph_id' => 'atlas', 'name' => 'Atlas', 'graph_source' => 'repo',
                'expected_path' => 'docs/engineering-knowledge-base/atlas-ai-master-architecture.md',
                'lookup_ids' => ['atlas-ai-master-architecture']],
            ['graph_id' => 'memory', 'name' => 'Memória', 'graph_source' => 'vault',
                'expected_path' => 'AtlasVault/01-acervo/',
                'lookup_ids' => []],
            ['graph_id' => 'works', 'name' => 'Obras', 'graph_source' => 'mixed',
                'expected_path' => 'docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md',
                'lookup_ids' => ['atlas-ai-obras-operating-system']],
            ['graph_id' => 'forge', 'name' => 'Forge', 'graph_source' => 'repo',
                'expected_path' => 'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'lookup_ids' => ['atlas-ai-self-construction-os']],
            ['graph_id' => 'philosophy', 'name' => 'Filosofia', 'graph_source' => 'vault',
                'expected_path' => 'AtlasVault/03-principios/',
                'lookup_ids' => []],
            ['graph_id' => 'risks', 'name' => 'Gargalos', 'graph_source' => 'repo',
                'expected_path' => 'docs/engineering-knowledge-base/atlas-ai-architecture-audit.md',
                'lookup_ids' => ['atlas-ai-architecture-audit']],
        ];
    }

    /**
     * The 17 canonical steps of the Atlas AI Kernel Pipeline. Order = roman numeral.
     *
     * `lookup_ids` are alternative frontmatter `id` values that may carry the
     * canonical content while the dedicated system-graph file doesn't exist yet.
     *
     * @return list<array<string, mixed>>
     */
    public function pipelineSteps(): array
    {
        return [
            $this->step(1, 'surface-plane', 'Surface Plane',
                'Usuário · App · Mobile · CLI · API · MCP',
                ['atlas-ai-mobile-surface-gateway']),
            $this->step(2, 'surface-adapter', 'Surface Adapter',
                'coleta input, apresenta output, não decide',
                []),
            $this->step(3, 'atlas-input', 'Atlas Input',
                'texto · imagem · áudio · arquivo · paste',
                []),
            $this->step(4, 'operation-envelope', 'Operation Envelope',
                'unidade canônica · trace · tenant · origem',
                []),
            $this->step(5, 'intent-routing', 'Intent / Routing',
                'entende pedido · risco · tipo de tarefa',
                []),
            $this->step(6, 'business-context', 'Business Context',
                'projeto · ambiente · empresa · produto',
                ['atlas-ai-business-contexts']),
            $this->step(7, 'domain-profile-flow', 'Domain / Profile / Flow',
                'domínio cognitivo + sistema vertical',
                []),
            $this->step(8, 'context-builder', 'Context Builder',
                'Open Brain · Memory · Engineering KB · Sync',
                ['atlas-ai-memory-context-core-open-brain']),
            $this->step(9, 'policy-profile', 'Policy / Profile',
                'permissão · privacidade · autonomia · custo',
                []),
            $this->step(10, 'atlas-decide', 'Atlas Decide',
                'escolhe modelo · provider · budget · contrato',
                []),
            $this->step(11, 'decision-receipt', 'Decision Receipt',
                'contrato assinado · hash · dry-run · audit',
                []),
            $this->step(12, 'runtime-executor', 'Runtime / Executor',
                'executa via runtime · drivers · harnesses',
                ['atlas-ai-runtime-language-boundaries']),
            $this->step(13, 'quality-gates', 'Quality Gates',
                'segurança · testes · SLO · visual QA',
                ['engineering-blueprint-quality-gates']),
            $this->step(14, 'repair-escalation', 'Repair / Escalation',
                'corrige · reexecuta · escala ou bloqueia',
                []),
            $this->step(15, 'evidence-ledger', 'Evidence Ledger',
                'eventos append-only · replay · auditoria',
                ['atlas-ai-telemetry-evidence-performance']),
            $this->step(16, 'learning-proposals', 'Learning / Proposals',
                'memória · métricas · quality score · propostas',
                ['atlas-ai-research-self-improvement-runtime']),
            $this->step(17, 'output-renderer', 'Output Renderer',
                'resposta · patch · plano · proposta · briefing',
                []),
        ];
    }

    /**
     * Lateral lanes that feed/receive from the pipeline.
     *
     * Each lane carries an explicit `nodes` array — the canonical pieces that
     * compose the lane. The graph_ids mirror the canon HTML mockup
     * (atlas-server/public/atlas-vault-cockpit-mockup.html); each node uses
     * `lookup_ids` to point at a real .md file when one already exists.
     * Pieces without a real source surface as `missing_source: true`, never
     * as fake content — that is the canon.
     *
     * @return list<array<string, mixed>>
     */
    public function lanes(): array
    {
        return [
            ['graph_id' => 'domain-plane', 'side' => 'left', 'name' => 'Domain Plane',
                'deck' => 'conecta domain/profile/flow',
                'expected_path' => 'docs/engineering-knowledge-base/system-graph/domain-plane.md',
                'lookup_ids' => ['domain-plane'],
                'nodes' => [
                    $this->laneNode('dom-prog', 'Programming', 'dev · forge · fix · review · QA',
                        'docs/engineering-knowledge-base/domains/atlas-ai-programming-domain.md',
                        ['atlas-ai-programming-domain']),
                    $this->laneNode('dom-fin', 'Finance', 'análise · risco · compliance',
                        'docs/engineering-knowledge-base/domains/finance.md',
                        ['atlas-ai-finance-domain']),
                    $this->laneNode('dom-pd', 'Personal Development', 'hábitos · performance',
                        'docs/engineering-knowledge-base/domains/personal-development.md',
                        ['atlas-ai-personal-development-domain']),
                    $this->laneNode('dom-mkt', 'Marketing', 'campanha · criativos · brand',
                        'docs/engineering-knowledge-base/domains/marketing.md',
                        ['atlas-ai-marketing-domain']),
                    $this->laneNode('dom-strat', 'Strategic Decision', 'trade-offs operacionais',
                        'docs/engineering-knowledge-base/domains/strategic-decision.md',
                        ['atlas-ai-strategic-decision-domain']),
                    $this->laneNode('dom-research', 'Research / Learning', 'papers · docs · síntese',
                        'docs/engineering-knowledge-base/domains/atlas-ai-research-domain.md',
                        ['atlas-ai-research-self-improvement-runtime']),
                    $this->laneNode('dom-self', 'Self-Improvement', 'audita Atlas · acha gaps',
                        'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                        ['atlas-ai-self-construction-os']),
                    $this->laneNode('dom-health', 'Health / Writing', 'safety · escrita',
                        'docs/engineering-knowledge-base/domains/health.md',
                        ['atlas-ai-health-domain']),
                ]],
            ['graph_id' => 'capabilities', 'side' => 'left', 'name' => 'Capabilities / Harnesses',
                'deck' => 'não são domínio · capacidades chamadas pelo Runtime',
                'expected_path' => 'docs/engineering-knowledge-base/system-graph/capabilities.md',
                'lookup_ids' => ['capabilities'],
                'nodes' => [
                    $this->laneNode('cap-prog', 'Programming Harness', 'execução técnica · forge',
                        'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                        ['atlas-ai-self-construction-os']),
                    $this->laneNode('cap-frontend', 'Frontend Design Harness', 'UI · visual QA',
                        'docs/engineering-knowledge-base/atlas-desktop-code-surface.md',
                        ['atlas-desktop-code-surface']),
                    $this->laneNode('cap-scenario', 'Scenario Simulation', 'simulação de fluxos',
                        'docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md',
                        ['atlas-ai-scenario-simulation-harness']),
                    $this->laneNode('cap-content', 'Content Intelligence', 'curadoria · escrita',
                        'docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md',
                        ['atlas-ai-content-intelligence-curation']),
                    $this->laneNode('cap-sandbox', 'Tool Synthesis Sandbox', 'ferramentas novas',
                        'docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md',
                        ['atlas-ai-cognitive-runtime']),
                    $this->laneNode('cap-market', 'Dynamic Compute Market', 'roteamento de custo',
                        'docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md',
                        ['atlas-ai-model-selection-strategy']),
                    $this->laneNode('cap-multimodal', 'Continuous Multimodal Context', 'streams contínuos',
                        'docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md',
                        ['atlas-ai-cli-multimodal']),
                ]],
            ['graph_id' => 'business-context-side', 'side' => 'mid', 'name' => 'Business / Product',
                'deck' => 'fonte contextual única',
                'expected_path' => 'docs/engineering-knowledge-base/atlas-ai-business-contexts.md',
                'lookup_ids' => ['atlas-ai-business-contexts'],
                'nodes' => [
                    $this->laneNode('biz-ctx', 'Business Context', 'projeto · ambiente · empresa · cliente',
                        'docs/engineering-knowledge-base/atlas-ai-business-contexts.md',
                        ['atlas-ai-business-contexts']),
                ]],
            ['graph_id' => 'hks', 'side' => 'right', 'name' => 'Human Knowledge Surface',
                'deck' => 'AtlasVault como contexto curado · nunca fonte crua',
                'expected_path' => 'docs/engineering-knowledge-base/vault/contracts.md',
                'lookup_ids' => ['atlas-vault-contracts'],
                'nodes' => [
                    $this->laneNode('hks-vault', 'AtlasVault / Obsidian', 'segundo cérebro compartilhado',
                        'docs/engineering-knowledge-base/obsidian-atlas-vault.md',
                        ['obsidian-atlas-vault', 'atlas-vault-cartography-schema']),
                    $this->laneNode('hks-pkw', 'Personal Knowledge', 'livros · filosofia · histórias',
                        'docs/engineering-knowledge-base/atlas-ai-cognitive-development-plane.md',
                        ['atlas-ai-cognitive-development-plane']),
                    $this->laneNode('hks-sync', 'Managed Sync · Review', 'curadoria humana',
                        'docs/engineering-knowledge-base/vault/contracts.md',
                        ['atlas-vault-contracts']),
                    $this->laneNode('hks-raw', 'Não-fonte operacional crua', 'entra via contexto revisado',
                        'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
                        ['atlas-ai-knowledge-governance-system']),
                ]],
            ['graph_id' => 'evidence-loop', 'side' => 'right', 'name' => 'Evidence + Learning Loop',
                'deck' => 'tudo que executa volta como evidência ou proposta',
                'expected_path' => 'docs/engineering-knowledge-base/system-graph/evidence-loop.md',
                'lookup_ids' => ['evidence-loop'],
                'nodes' => [
                    $this->laneNode('evi-ledger', 'Evidence Ledger', 'append-only · replay · audit',
                        'docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md',
                        ['atlas-ai-telemetry-evidence-performance']),
                    $this->laneNode('evi-tele', 'Read Models / Telemetry', 'SLO · custo · repair',
                        'docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md',
                        ['atlas-ai-telemetry-evidence-performance']),
                    $this->laneNode('evi-learn', 'Learning Signals', 'memória · métrica · outcome',
                        'docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md',
                        ['atlas-ai-research-self-improvement-runtime']),
                    $this->laneNode('evi-self', 'Self-Improvement / Curator', 'proposal · gaps · drift',
                        'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                        ['atlas-ai-self-construction-os']),
                    $this->laneNode('evi-inbox', 'Proposal Inbox / Human Review', 'humano confirma o crítico',
                        'docs/engineering-knowledge-base/atlas-ai-governed-backlog.md',
                        ['atlas-ai-governed-backlog']),
                ]],
            ['graph_id' => 'doc-os', 'side' => 'right', 'name' => 'Documentation OS',
                'deck' => 'orienta humanos e IAs · impede duplicação',
                'expected_path' => 'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md',
                'lookup_ids' => ['atlas-ai-documentation-operating-system'],
                'nodes' => [
                    $this->laneNode('doc-canonical', 'START_HERE + Canonical Index', 'impede duplicação e escopo errado',
                        'docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md',
                        ['atlas-ai-canonical-architecture-index']),
                ]],
        ];
    }

    /**
     * Build a canonical lane child node entry. Same shape as a pipeline step
     * but tagged with `graph_kind=lateral` so the cartography frontend knows
     * to render it as a lateral atom (not a numbered pipeline step).
     *
     * @param  list<string>  $lookupIds
     * @return array<string, mixed>
     */
    private function laneNode(string $graphId, string $name, string $deck, string $expectedPath, array $lookupIds): array
    {
        return [
            'graph_id' => $graphId,
            'graph_kind' => 'lateral',
            'graph_layer' => 'module',
            'graph_source' => 'repo',
            'name' => $name,
            'deck' => $deck,
            'expected_path' => $expectedPath,
            'lookup_ids' => $lookupIds,
        ];
    }

    /**
     * Connections between pieces. Generated declaratively; the cartography draws SVG paths from this.
     *
     * @return list<array{from: string, to: string, kind: string}>
     */
    public function connections(): array
    {
        $connections = [];
        // sequência pipeline
        $steps = $this->pipelineSteps();
        for ($i = 0; $i < count($steps) - 1; $i++) {
            $connections[] = ['from' => $steps[$i]['graph_id'], 'to' => $steps[$i + 1]['graph_id'], 'kind' => 'sequence'];
        }
        // laterais → pipeline
        $connections[] = ['from' => 'domain-plane', 'to' => 'domain-profile-flow', 'kind' => 'feed'];
        $connections[] = ['from' => 'business-context-side', 'to' => 'business-context', 'kind' => 'feed'];
        $connections[] = ['from' => 'capabilities', 'to' => 'runtime-executor', 'kind' => 'feed'];
        $connections[] = ['from' => 'hks', 'to' => 'context-builder', 'kind' => 'feed'];
        $connections[] = ['from' => 'evidence-loop', 'to' => 'atlas-decide', 'kind' => 'feedback'];
        $connections[] = ['from' => 'evidence-ledger', 'to' => 'evidence-loop', 'kind' => 'feed'];
        $connections[] = ['from' => 'learning-proposals', 'to' => 'evidence-loop', 'kind' => 'feed'];
        $connections[] = ['from' => 'doc-os', 'to' => 'context-builder', 'kind' => 'feed'];

        return $connections;
    }

    /**
     * @param  list<string>  $lookupIds
     * @return array<string, mixed>
     */
    private function step(int $order, string $graphId, string $name, string $deck, array $lookupIds): array
    {
        return [
            'graph_id' => $graphId,
            'graph_order' => $order,
            'graph_kind' => 'step',
            'graph_view' => 'atlas-ai-kernel',
            'graph_layer' => 'flow',
            'graph_group' => 'kernel-pipeline',
            'graph_source' => 'repo',
            'name' => $name,
            'deck' => $deck,
            'expected_path' => "docs/engineering-knowledge-base/system-graph/{$graphId}.md",
            'lookup_ids' => $lookupIds,
        ];
    }
}
