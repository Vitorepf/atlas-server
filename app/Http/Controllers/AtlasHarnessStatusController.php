<?php

namespace App\Http\Controllers;

use App\Models\AiLearningProposal;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessAutopilot;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessSurface;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Http\JsonResponse;

/**
 * GET /ai/harness — o painel do Self-Harness para o APP do operador.
 * Transparência substitui pré-aprovação: tudo que o autopilot fez (aplicou,
 * confirmou, auto-reverteu) fica visível aqui, com a alça de reverse manual
 * (`atlas:harness reverse <key>`) sempre disponível.
 */
class AtlasHarnessStatusController extends Controller
{
    public function show(AtlasHarnessSurface $surface, AtlasHarnessAutopilot $autopilot): JsonResponse
    {
        $sections = [];
        foreach ($surface->sections() as $key => $section) {
            $sections[$key] = $section + ['current_value' => $surface->currentValue($key)];
        }

        $proposals = [];
        if (DatabaseTableAvailability::has('ai_learning_proposals')) {
            $proposals = AiLearningProposal::query()
                ->where('kind', 'harness_config')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'status', 'summary', 'proposed_state', 'decided_by', 'created_at'])
                ->all();
        }

        $instructions = app(\App\Services\Ai\Cognitive\Harness\AtlasHarnessInstructionSurface::class);
        $instructionSections = [];
        foreach ($instructions->sections() as $name => $declared) {
            $instructionSections[$name] = [
                'purpose' => $declared['purpose'],
                'current_text' => $instructions->text($name),
                'is_default' => $instructions->text($name) === $declared['default'],
            ];
        }

        return response()->json([
            'schema_version' => AtlasHarnessAutopilot::SCHEMA_VERSION,
            'autopilot_enabled' => $autopilot->enabled(),
            'surface' => $sections,
            'instruction_sections' => $instructionSections,
            'active_overrides' => $surface->readOverrides(),
            'active_instruction_overrides' => $instructions->readOverrides(),
            'experiments' => $autopilot->state(),
            'recent_proposals' => $proposals,
            'manual_reverse' => 'php artisan atlas:harness reverse <key>',
        ]);
    }
}
