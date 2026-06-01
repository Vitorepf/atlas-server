<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiSessionBootstrapCommand extends Command
{
    protected $signature = 'atlas:ai:session-bootstrap
        {--task= : Task, feature, bug or question this session will handle}
        {--workspace= : Workspace root used for provider projection status}
        {--strict : Return failure when the session placement gate is blocked}
        {--json : Print machine-readable JSON}';

    protected $description = 'Return the canonical Atlas AI session bootstrap package for a task.';

    public function handle(
        AtlasSessionBootstrapService $bootstrap,
        AtlasGovernanceGateService $gate,
        AtlasDocumentationRealityReflectiveStatusService $reflective,
    ): int {
        $payload = $bootstrap->bootstrap((string) ($this->option('task') ?: ''), [
            'workspace' => $this->option('workspace') ?: base_path(),
        ]);

        // ADRS immune-system presence at session start — the documented "session-bootstrap"
        // entry of the Fluxo alvo para IA. ADDITIVE + FAIL-SAFE: it enriches the bootstrap
        // package so every session is AWARE the write-bound immune system is active, knows
        // the honest ladder self-state, and is prompted to predict before writing. It never
        // breaks bootstrap (the core service is untouched; any error degrades to a note).
        $payload['adrs'] = $this->adrsPresence($reflective);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $gate->cliExitCode($payload, (bool) $this->option('strict'));
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Session Bootstrap</>', $payload['status']);
        $this->components->twoColumnDetail('Task', $payload['task']);
        $this->components->twoColumnDetail('Placement', data_get($payload, 'placement.layer').' / '.data_get($payload, 'placement.domain').' / '.data_get($payload, 'placement.flow'));
        $this->components->twoColumnDetail('Gate', (string) ($payload['gate_status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Docs', data_get($payload, 'docs_health.status').' / '.data_get($payload, 'docs_health.required_missing_count').' missing');
        $this->components->twoColumnDetail('Docs split owner', data_get($payload, 'docs_split_plan.owner').' / '.data_get($payload, 'docs_split_plan.split_required_count').' docs');
        $this->components->twoColumnDetail('KB', data_get($payload, 'kb_status.status').' / '.data_get($payload, 'kb_status.active').' active');
        $this->components->twoColumnDetail('Provider projection', data_get($payload, 'provider_projection.status'));
        $this->components->twoColumnDetail('ADRS immune', (string) data_get($payload, 'adrs.summary', 'unknown'));

        $safeNextBlocks = array_slice((array) ($payload['safe_next_blocks'] ?? []), 0, 5);
        if ($safeNextBlocks !== []) {
            $this->newLine();
            $this->line('Blocos seguros:');
            foreach ($safeNextBlocks as $block) {
                $this->line('  - '.data_get($block, 'order').'. '.data_get($block, 'block').' — '.data_get($block, 'dod_minimum'));
            }
        }

        $this->newLine();
        $this->line('Leia primeiro:');
        foreach ($payload['read_first'] as $doc) {
            $this->line('  - '.$doc);
        }

        $this->newLine();
        $this->line('ADRS (sistema imune da documentacao):');
        $this->line('  - '.(string) data_get($payload, 'adrs.reminder', ''));
        $headline = (string) data_get($payload, 'adrs.reflective_headline', '');
        if ($headline !== '') {
            $this->line('  - estado honesto: '.$headline);
        }

        $risks = (array) ($payload['risks'] ?? []);
        if ($risks !== []) {
            $this->newLine();
            $this->warn('Riscos: '.implode(', ', $risks));
        }

        $blocked = (array) ($payload['blocked_when'] ?? []);
        if ($blocked !== []) {
            $this->newLine();
            $this->warn('Bloqueios: '.implode(', ', $blocked));
        }

        return $gate->cliExitCode($payload, (bool) $this->option('strict'));
    }

    /**
     * The ADRS immune-system presence block: static facts about the active write-bound
     * gate + the documented pre-write flow, plus the honest reflective self-state
     * (fail-safe — a heavy/unavailable reflective read degrades to a note, never an error).
     *
     * @return array<string,mixed>
     */
    private function adrsPresence(AtlasDocumentationRealityReflectiveStatusService $reflective): array
    {
        $block = [
            'write_bound_immune_active' => true,
            'enforced_via' => 'scripts/hooks/pre-commit (atlas:documentation-reality-write-gate) — canonical-doc writes are gated at the commit boundary',
            'predict_before_writing' => 'atlas:documentation-reality-flow (P1 predict duplication/drift/owner + O2 intent advisory) for a proposed change',
            'honest_self_state_command' => 'atlas:documentation-reality-reflective-status',
            'self_improvement_command' => 'atlas:documentation-reality-self-improvement-modeling (the self-model proposes its own next rung from its declared limits; session/global, never per-write)',
            'reminder' => 'Writes to docs/engineering-knowledge-base/*.md pass the ADRS L0 immune gate; predict before writing, and never set implementation_state partial/verified without resolving evidence_refs (the gate blocks the over-claim).',
            'summary' => 'active',
        ];

        try {
            $assessment = $reflective->selfAssessment();
            $confidence = (string) data_get($assessment, 'headline.confidence', 'unknown');
            $block['reflective_headline'] = data_get($assessment, 'headline.assessment');
            $block['reflective_confidence'] = $confidence;
            $block['reflective_blind_spots'] = data_get($assessment, 'headline.declared_blind_spots', []);
            $block['summary'] = "active; honest self-state confidence={$confidence}";
        } catch (Throwable $e) {
            $block['reflective_headline'] = null;
            $block['summary'] = 'active (reflective self-state unavailable this run: '.class_basename($e).')';
        }

        return $block;
    }
}
