<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Models\AiToolPlan;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class ToolPlanningService
{
    public const KEYWORDS = [
        'read' => ['filesystem.read', 'docs.search', 'github.readonly'],
        'search' => ['docs.search', 'browser.readonly'],
        'fetch' => ['browser.readonly', 'api.readonly'],
        'pesquis' => ['docs.search', 'browser.readonly'],
        'inspecion' => ['filesystem.read', 'docs.search'],
        'audit' => ['filesystem.read', 'docs.search'],
        'github' => ['github.readonly'],
        'test' => ['test.local_command'],
        'lint' => ['command.local_readonly'],
        'log' => ['command.local_readonly'],
        'writ' => ['artifact.write_local'],
        'salv' => ['artifact.write_local'],
        'persist' => ['artifact.write_local'],
        'evidenc' => ['evidence.attach'],
        'polic' => ['policy.evaluate'],
    ];

    /**
     * @param  array<string,mixed>  $context
     */
    public function plan(string $objective, array $context = []): AiToolPlan
    {
        $tools = AiToolDefinition::query()
            ->where('status', 'active')
            ->orderBy('tool_id')
            ->get();

        $normalized = mb_strtolower(trim($objective));
        $selectedIds = [];
        $reasons = [];

        foreach (self::KEYWORDS as $needle => $candidates) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                foreach ($candidates as $candidate) {
                    $selectedIds[$candidate] = true;
                    $reasons[$candidate][] = "keyword:{$needle}";
                }
            }
        }

        $considered = $tools->map(static fn (AiToolDefinition $tool): array => [
            'tool_id' => $tool->tool_id,
            'authority_group' => $tool->authority_group,
            'risk_level' => $tool->risk_level,
            'status' => $tool->status,
        ])->all();

        $rejected = [];
        $finalSelected = [];
        $safetyNotes = [];

        foreach ($tools as $tool) {
            $picked = array_key_exists($tool->tool_id, $selectedIds);
            if (! $picked) {
                continue;
            }
            if (ToolRuntimeCanon::isHighRiskAuthority($tool->authority_group)) {
                $rejected[] = [
                    'tool_id' => $tool->tool_id,
                    'reason' => "authority_group:{$tool->authority_group} requires policy approval; planning rejects without explicit operator allow",
                ];
                $safetyNotes[] = "rejected {$tool->tool_id}: high-risk authority_group requires policy gate";

                continue;
            }
            $finalSelected[] = [
                'tool_id' => $tool->tool_id,
                'authority_group' => $tool->authority_group,
                'risk_level' => $tool->risk_level,
                'reasons' => $reasons[$tool->tool_id] ?? [],
            ];
        }

        if ($finalSelected === []) {
            $safetyNotes[] = 'no read-only or local-mutation tool matched the objective; planner suggests adding evidence or refining objective';
        }

        $status = $finalSelected === [] ? 'no_match' : 'draft';

        $plan = AiToolPlan::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'domain_id' => $context['domain_id'] ?? null,
            'objective' => $objective,
            'tools_considered' => $considered,
            'tools_selected' => $finalSelected,
            'selection_reason' => $reasons,
            'rejected_tools' => $rejected,
            'safety_notes' => $safetyNotes,
            'status' => $status,
            'receipt_hash' => MissionCanonicalHash::sha256([
                'objective' => $objective,
                'tools_selected' => array_column($finalSelected, 'tool_id'),
                'rejected_tools' => array_column($rejected, 'tool_id'),
            ]),
        ]);

        return $plan->refresh();
    }
}
