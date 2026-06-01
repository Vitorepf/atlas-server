<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSpecCompilerAndCriticService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas SDD Spec Compiler And Critic.
 *
 * Runs the full pipeline against a clean, fully-fielded demo spec with no
 * critic findings and a single high-confidence, well-evidenced assumption:
 *  - the twelve-field compiler output completeness check;
 *  - the eleven-check spec critic;
 *  - the assumption-ledger assessment;
 *  - the output-state resolver (a clean, complete spec is `ready_for_plan`).
 *
 *   php artisan atlas:aaeos:spec-compiler-and-critic --json
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md
 */
final class AtlasSpecCompilerAndCriticCommand extends Command
{
    protected $signature = 'atlas:aaeos:spec-compiler-and-critic {--json : Machine-readable JSON output}';

    protected $description = 'Compile intent into a fielded spec, run the spec critic and assumption ledger, and resolve the output state.';

    public function handle(AtlasSpecCompilerAndCriticService $service): int
    {
        try {
            $spec = [
                'raw_user_request' => 'Add a Save button to the profile edit form.',
                'interpreted_goal' => 'Persist the active profile form when the user clicks Save.',
                'non_goals' => ['Redesign the profile page', 'Change the data model'],
                'product_area' => 'profile',
                'business_actor_object_action' => 'user saves profile',
                'requirements' => ['Save button persists ProfileForm', 'Show success and error states'],
                'acceptance_criteria' => ['Clicking Save persists the form and shows a success toast'],
                'design_system_constraints' => ['Use the primary button token'],
                'security_privacy_constraints' => ['Only the owner may edit their profile'],
                'assumptions' => ['A1: button saves the active profile form'],
                'blocking_questions' => [],
                'test_strategy' => ['Feature test exercising save + error path'],
            ];

            $assumptions = [
                [
                    'id' => 'A1',
                    'text' => 'The button saves the currently active profile form.',
                    'confidence' => 0.91,
                    'evidence' => ['active_file: ProfileForm.tsx', 'route: /profile/edit'],
                    'blocking' => false,
                ],
            ];

            $result = $service->evaluate($spec, [], $assumptions);
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
