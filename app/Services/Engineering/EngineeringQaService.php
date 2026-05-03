<?php

namespace App\Services\Engineering;

use App\Models\AtlasTask;
use Illuminate\Validation\ValidationException;

class EngineeringQaService
{
    public function __construct(private readonly EngineeringRunArtifactService $artifacts) {}

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function record(AtlasTask $task, array $data): array
    {
        $status = (string) ($data['status'] ?? 'needs_review');
        $steps = $this->stepList($data['steps'] ?? []);
        $visualRequired = (bool) ($data['visual_required'] ?? false);
        $screenshot = trim((string) ($data['screenshot_url'] ?? $data['artifact_url'] ?? ''));

        if ($steps === []) {
            throw ValidationException::withMessages(['steps' => 'QA manual precisa registrar passos executados.']);
        }
        if (trim((string) ($data['expected_result'] ?? '')) === '') {
            throw ValidationException::withMessages(['expected_result' => 'QA manual precisa registrar resultado esperado.']);
        }
        if (trim((string) ($data['actual_result'] ?? '')) === '') {
            throw ValidationException::withMessages(['actual_result' => 'QA manual precisa registrar resultado real.']);
        }
        if ($visualRequired && $screenshot === '' && $status !== 'not_applicable') {
            throw ValidationException::withMessages(['screenshot_url' => 'QA visual precisa de screenshot/artifact ou not_applicable justificado.']);
        }
        if ($status === 'not_applicable' && trim((string) ($data['risk_notes'] ?? '')) === '') {
            throw ValidationException::withMessages(['risk_notes' => 'not_applicable exige justificativa em risk_notes.']);
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'QA manual '.$status.': '.trim((string) $data['actual_result']);
        }

        $entry = $this->artifacts->recordEvidence($task, [
            'evidence_type' => 'manual_qa',
            'target_id' => $data['target_id'] ?? 'manual_qa',
            'status' => $status,
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'summary' => $summary,
            'artifact_url' => $screenshot !== '' ? $screenshot : null,
            'output_excerpt' => $data['console_output'] ?? null,
            'files' => $data['files'] ?? [],
            'metadata' => [
                'steps' => $steps,
                'expected_result' => (string) $data['expected_result'],
                'actual_result' => (string) $data['actual_result'],
                'screenshot_url' => $screenshot !== '' ? $screenshot : null,
                'console_output' => $data['console_output'] ?? null,
                'network_output' => $data['network_output'] ?? null,
                'risk_notes' => $data['risk_notes'] ?? null,
                'visual_required' => $visualRequired,
            ],
        ], 'atlas:qa');

        return [
            'task_id' => $task->id,
            'qa' => $entry,
            'blocking' => in_array($status, ['failed', 'needs_review'], true),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function stepList(mixed $steps): array
    {
        if (! is_array($steps)) {
            $steps = preg_split('/\r?\n/', (string) $steps) ?: [];
        }

        return collect($steps)
            ->map(fn (mixed $step): string => is_array($step) ? (string) ($step['step'] ?? $step['description'] ?? '') : (string) $step)
            ->filter(fn (string $step): bool => trim($step) !== '')
            ->map(fn (string $step): string => trim($step))
            ->values()
            ->all();
    }
}
