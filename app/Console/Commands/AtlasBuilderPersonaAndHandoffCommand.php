<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasBuilderPersonaAndHandoffService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Builder Persona And Handoff CLI.
 *
 *   php artisan atlas:aaeos:builder-persona-and-handoff [--json]
 *
 * Read-only, deterministic. Certifies whether a builder's opening move,
 * resume-context answers and handoff packet satisfy the documented contract.
 * It NEVER performs edits, writes a handoff to disk, mutates memory, promotes
 * maturity or marks a task complete.
 *
 * With no options it runs a canonical fully-satisfied example (status=ready),
 * which doubles as a self-check that the certifier is wired and green.
 *
 * @see docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md
 */
class AtlasBuilderPersonaAndHandoffCommand extends Command
{
    protected $signature = 'atlas:aaeos:builder-persona-and-handoff {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · certify builder persona, required opening move, long-session continuity and the handoff packet contract.';

    public function handle(AtlasBuilderPersonaAndHandoffService $service): int
    {
        try {
            $result = $service->certifyHandoff($this->sampleInput());

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasBuilderPersonaAndHandoffService::STATUS_READY
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'builder_persona_and_handoff_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * A canonical, fully-satisfied posture + handoff packet (safe default).
     *
     * @return array<string, mixed>
     */
    private function sampleInput(): array
    {
        return [
            'current_goal' => 'Turn builder-persona-and-handoff doc into tested runtime',
            'target_capability' => 'self_construction_builder_persona_and_handoff',
            'authoritative_docs' => [
                'docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md',
            ],
            'hot_files' => [
                'app/Services/Ai/Aaeos/Generated/AtlasBuilderPersonaAndHandoffService.php',
            ],
            'current_git_delta' => 'one new service, one command, one unit test',
            'risk' => 'low',
            'smallest_safe_slice' => 'add pure certifier + command + unit test, no DB',
            'required_validation' => 'php artisan test --filter AtlasBuilderPersonaAndHandoffTest',
            'continuity_answers' => [
                'what_is_being_built' => 'builder persona + handoff certifier runtime',
                'why_this_priority' => 'doc-as-law: documented contract needs runtime',
                'which_docs_are_law' => 'self-construction/builder-persona-and-handoff.md',
                'what_changed' => 'new generated service, command, test',
                'what_remains_unsafe' => 'nothing in scope; certifier is read-only',
                'next_smallest_step' => 'run the focused unit test',
            ],
            'handoff' => [
                'objective' => 'certify builder persona and handoff packet',
                'target_capability' => 'self_construction_builder_persona_and_handoff',
                'maturity_before' => 0,
                'maturity_after' => 1,
                'docs_changed' => ['builder-persona-and-handoff.md (referenced)'],
                'code_changed' => ['AtlasBuilderPersonaAndHandoffService.php'],
                'hot_files' => ['AtlasBuilderPersonaAndHandoffService.php'],
                'commands_run' => ['php artisan test --filter AtlasBuilderPersonaAndHandoffTest'],
                'gates_passed' => ['focused_unit_test'],
                'gates_failed' => [],
                'evidence' => ['tests/Unit/Ai/Aaeos/Generated/AtlasBuilderPersonaAndHandoffTest.php'],
                'residual_risk' => 'low',
                'next_safe_step' => 'wire certifier into self-construction readiness',
                'do_not_touch' => ['Kernel', 'Evidence Ledger writes'],
            ],
            'summary' => 'Added a pure read-only certifier with one focused test; scope limited to three files.',
        ];
    }
}
