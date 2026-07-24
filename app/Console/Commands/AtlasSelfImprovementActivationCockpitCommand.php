<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService;
use Illuminate\Console\Command;

/**
 * Atlas Self-Improvement Activation Cockpit CLI (read-only).
 *
 * Emits the canonical cockpit read-model so operators (and CI) can audit
 * activation status, counters, selected detail and next safe action
 * without going through the desktop UI. NEVER calls a provider; NEVER
 * mutates state; NEVER triggers Fast Path. Mutations stay on the
 * existing `atlas:self-improvement:activate-forge` command.
 *
 * Schema: atlas.self_improvement.activation_cockpit.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
 */
final class AtlasSelfImprovementActivationCockpitCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:self-improvement:activation-cockpit
        {--activation= : Inspect a specific activation id and project its detail in the response}
        {--status= : Filter list by status (blocked, needs_revision, pending_human_review, accepted, obra_created, rejected, dry_run_planned)}
        {--bucket= : Filter list by strategy bucket}
        {--has-obra : Show only activations that already created an Obra}
        {--workspace= : Workspace root override (reserved — current cockpit is workspace-agnostic)}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when blockers exist on the selected activation or counters report pending_human_review work}';

    protected $description = 'Atlas Self-Improvement Activation Cockpit v1 (read-only). Lists activations, summarises power gate and surfaces next safe action for the operator.';

    public function handle(AtlasSelfImprovementActivationCockpitService $service): int
    {
        $activationId = $this->stringOption('activation');
        $payload = $service->cockpit([
            'status' => $this->stringOption('status'),
            'bucket' => $this->stringOption('bucket'),
            'has_obra' => (bool) $this->option('has-obra') ? true : null,
            'activation_id' => $activationId,
        ]);

        $this->emit($payload);

        return $this->strictExit($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $counters = is_array($payload['counters'] ?? null) ? $payload['counters'] : [];
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('total', (string) ($counters['total'] ?? 0));
        $this->components->twoColumnDetail('pending_human_review', (string) ($counters['pending_human_review'] ?? 0));
        $this->components->twoColumnDetail('obra_created', (string) ($counters['obra_created'] ?? 0));
        $this->components->twoColumnDetail('rejected', (string) ($counters['rejected'] ?? 0));
        $this->components->twoColumnDetail('human_summary', (string) ($payload['human_summary'] ?? '—'));
        $this->components->twoColumnDetail('next_safe_action', (string) ($payload['next_safe_action'] ?? '—'));

        $selected = $payload['selected_activation'] ?? null;
        if (is_array($selected)) {
            $this->components->twoColumnDetail('selected.activation_id', (string) ($selected['activation_id'] ?? '—'));
            $this->components->twoColumnDetail('selected.status', (string) ($selected['status_label'] ?? $selected['status'] ?? '—'));
            $this->components->twoColumnDetail('selected.next_safe_action', (string) ($selected['next_safe_action'] ?? '—'));
            $obra = $selected['created_obra'] ?? null;
            if (is_array($obra)) {
                $this->components->twoColumnDetail('selected.created_obra_id', (string) ($obra['obra_id'] ?? '—'));
            }
            $blockers = $selected['blockers'] ?? [];
            if (is_array($blockers) && $blockers !== []) {
                $this->components->bulletList($blockers);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function strictExit(array $payload): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        $selected = $payload['selected_activation'] ?? null;
        if (is_array($selected)) {
            $blockers = $selected['blockers'] ?? [];
            if (is_array($blockers) && $blockers !== []) {
                return self::FAILURE;
            }
            $status = (string) ($selected['status'] ?? '');
            if (in_array($status, ['blocked', 'rejected'], true)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

}
