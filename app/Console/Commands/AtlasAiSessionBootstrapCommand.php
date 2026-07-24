<?php

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use App\Services\Ai\Kernel\Architecture\AtlasGovernanceGateService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use App\Services\Ai\Obra\AtlasObraStateService;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiSessionBootstrapCommand extends Command
{
    use EmitsCanonicalJson;

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

        // S2 (Obra #19) — the minute-1 ops room: what is in flight + what would
        // clobber me, without a single grep. CONSUMES the T1 obra-state file (never
        // duplicates it), the master switch, a NON-BLOCKING probe of the two
        // main-write locks, and the open-WO count. Additive + fail-safe.
        $payload['ops_room'] = $this->opsRoom();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

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
        $this->components->twoColumnDetail('Ops room', sprintf(
            'switch=%s · obra=%s · locks(commit/merge)=%s/%s · WOs=%s',
            data_get($payload, 'ops_room.master_switch', '?'),
            data_get($payload, 'ops_room.obra.obra_id', '—'),
            data_get($payload, 'ops_room.locks.task_commit', '?'),
            data_get($payload, 'ops_room.locks.main_merge', '?'),
            data_get($payload, 'ops_room.open_work_orders', '?'),
        ));

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
        // HONEST (the ADRS principle applied to the ADRS itself — no over-claim): the L0
        // write-gate is BUILT + tested + available, but the operator DISABLED the blocking
        // pre-commit (it blocked their commits). So write-bound enforcement is NOT active —
        // canonical-doc writes are not auto-gated at commit. The discipline is advisory here.
        $block = [
            'write_bound_immune_active' => false,
            'enforcement_status' => 'gate built + available but NOT installed as a blocking pre-commit (operator disabled it — it blocked their commits); run "atlas:documentation-reality-write-gate --staged --strict" manually, or "composer atlas:install-hooks", to enforce',
            'predict_before_writing' => 'atlas:documentation-reality-flow (P1 predict duplication/drift/owner + O2 intent advisory) for a proposed change',
            'honest_self_state_command' => 'atlas:documentation-reality-reflective-status',
            'self_improvement_command' => 'atlas:documentation-reality-self-improvement-modeling (the self-model proposes its own next rung from its declared limits; session/global, never per-write)',
            'reminder' => 'The L0 gate is ADVISORY here (not installed as a blocking pre-commit): still predict before writing, and never set implementation_state partial/verified without resolving evidence_refs — the discipline holds even though it is not auto-enforced.',
            'summary' => 'gate_built_enforcement_not_installed',
        ];

        try {
            $assessment = $reflective->selfAssessment();
            $confidence = (string) data_get($assessment, 'headline.confidence', 'unknown');
            $block['reflective_headline'] = data_get($assessment, 'headline.assessment');
            $block['reflective_confidence'] = $confidence;
            $block['reflective_blind_spots'] = data_get($assessment, 'headline.declared_blind_spots', []);
            $block['summary'] = "gate built; enforcement not installed; honest self-state confidence={$confidence}";
        } catch (Throwable $e) {
            $block['reflective_headline'] = null;
            $block['summary'] = 'gate built; enforcement not installed (reflective self-state unavailable this run: '.class_basename($e).')';
        }

        return $block;
    }

    /**
     * S2 (Obra #19) — the minute-1 ops room. Read-only + fail-safe aggregation of
     * what is in flight and what would clobber this session: the master switch, the
     * active obra (CONSUMED from the T1 obra-state file, never duplicated), a
     * non-blocking probe of the two main-write locks, and the open-WO count. Any
     * fault degrades that field to null/unknown — never breaks bootstrap. Kept small
     * (the S2 brief budget) by summarising, not dumping, the obra state.
     *
     * @return array<string,mixed>
     */
    private function opsRoom(): array
    {
        $room = ['schema' => 'atlas.session.ops_room.v1'];

        try {
            $room['master_switch'] = AtlasLoopMasterSwitch::state();
        } catch (Throwable) {
            $room['master_switch'] = 'unknown';
        }

        try {
            $obra = (new AtlasObraStateService)->current();
            if (is_array($obra)) {
                $sessions = (array) ($obra['sessions'] ?? []);
                $last = $sessions === [] ? null : end($sessions);
                $room['obra'] = [
                    'obra_id' => $obra['obra_id'] ?? null,
                    'phase' => $obra['phase'] ?? null,
                    'sessions' => count($sessions),
                    'last_session_at' => is_array($last) ? ($last['at'] ?? null) : null,
                    'last_session_files' => is_array($last) ? count((array) ($last['files'] ?? [])) : 0,
                    'last_session_result' => is_array($last) ? ($last['result'] ?? null) : null,
                ];
            } else {
                $room['obra'] = null;
            }
        } catch (Throwable) {
            $room['obra'] = null;
        }

        // Non-blocking probe of the two main-write locks — "what would clobber me".
        $room['locks'] = [
            'task_commit' => $this->lockState(base_path('.git/'.basename(AtlasTaskScopedCommitter::LOCK_REL))),
            'main_merge' => $this->lockState(base_path('.git/'.AtlasLoopMergeActuator::LOCK_BASENAME)),
        ];

        try {
            $room['open_work_orders'] = count(glob(base_path('docs/work-orders/WO-*.json')) ?: []);
        } catch (Throwable) {
            $room['open_work_orders'] = null;
        }

        return $room;
    }

    /**
     * Non-blocking lock probe: 'free' when the lock can be taken right now (or was
     * never created), 'contended' when another writer holds it, 'unknown' on error.
     * Never blocks — LOCK_NB returns immediately.
     */
    private function lockState(string $absPath): string
    {
        if (! file_exists($absPath)) {
            return 'free';
        }
        $handle = @fopen($absPath, 'c');
        if ($handle === false) {
            return 'unknown';
        }
        try {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                @flock($handle, LOCK_UN);

                return 'free';
            }

            return 'contended';
        } finally {
            @fclose($handle);
        }
    }
}
