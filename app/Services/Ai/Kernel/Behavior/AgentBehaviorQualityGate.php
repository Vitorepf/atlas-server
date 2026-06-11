<?php

namespace App\Services\Ai\Kernel\Behavior;

use App\Services\Ai\Kernel\Provider\AgentBehaviorContract;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Str;

final readonly class AgentBehaviorQualityGate
{
    public function __construct(
        private AgentBehaviorContract $contract,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    public function evaluate(array $input): array
    {
        $findings = [];
        $response = trim((string) ($input['response_text'] ?? ''));
        $taskType = $this->normalize((string) ($input['task_type'] ?? ''));
        $taskLooksLikeDevelopment = (bool) ($input['task_looks_like_development'] ?? in_array($taskType, [
            'dev',
            'debug',
            'review',
            'implementation',
            'debugging',
            'code_review',
            'refactor',
            'technical_design',
        ], true));

        if ($taskLooksLikeDevelopment && ! $this->hasVerificationSignal($response)) {
            $findings[] = $this->finding(
                code: 'agent.verification_missing',
                severity: 'p2',
                confidence: 0.86,
                title: 'Verificacao ausente em tarefa tecnica',
                body: 'A resposta tecnica nao declarou teste, gate, comando executado ou motivo objetivo para nao validar.',
                recommendation: 'Registrar comandos executados, gate equivalente ou motivo claro de nao verificacao antes de declarar a tarefa concluida.',
                evidence: [
                    'task_type' => $taskType ?: null,
                    'response_chars' => mb_strlen($response),
                    'required_principle' => 'Verifiable Goal Loop',
                ],
            );
        }

        $changedFiles = AiStringListNormalizer::trimmedStringsFromArrayCast($input['changed_files'] ?? []);
        $allowedPaths = AiStringListNormalizer::trimmedStringsFromArrayCast($input['allowed_paths'] ?? []);
        $outsideScope = $this->outsideAllowedPaths($changedFiles, $allowedPaths);

        if ($outsideScope !== []) {
            $findings[] = $this->finding(
                code: 'agent.unsurgical_diff',
                severity: 'p1',
                confidence: 0.82,
                title: 'Diff fora do escopo declarado',
                body: 'A execucao alterou arquivos fora dos caminhos permitidos pelo contrato da tarefa.',
                recommendation: 'Revisar o escopo, justificar explicitamente cada arquivo lateral ou separar a mudanca em outra operacao.',
                evidence: [
                    'changed_files' => $changedFiles,
                    'allowed_paths' => $allowedPaths,
                    'outside_scope_files' => $outsideScope,
                    'required_principle' => 'Surgical Diff Discipline',
                ],
            );
        }

        return $findings;
    }

    public function hasVerificationSignal(string $response): bool
    {
        $text = $this->normalize($response);

        foreach ([
            'teste',
            'testes',
            'valid',
            'verific',
            'rodei',
            'passou',
            'falhou',
            'nao rodei',
            'php artisan',
            'npm run',
            'lint',
            'typecheck',
            'architecture-validate',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @param  array<int,string>  $allowedPaths
     * @return array<int,string>
     */
    private function outsideAllowedPaths(array $changedFiles, array $allowedPaths): array
    {
        if ($changedFiles === [] || $allowedPaths === []) {
            return [];
        }

        return collect($changedFiles)
            ->filter(function (string $file) use ($allowedPaths): bool {
                $file = ltrim($file, '/');

                foreach ($allowedPaths as $path) {
                    $path = ltrim(rtrim($path, '/'), '/');
                    if ($path !== '' && ($file === $path || str_starts_with($file, $path.'/'))) {
                        return false;
                    }
                }

                return true;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(
        string $code,
        string $severity,
        float $confidence,
        string $title,
        string $body,
        string $recommendation,
        array $evidence,
    ): array {
        return [
            'code' => $code,
            'source' => 'agent_behavior_quality_gate',
            'severity' => $severity,
            'status' => 'open',
            'confidence' => $confidence,
            'category' => 'agent_behavior',
            'title' => $title,
            'body' => $body,
            'recommendation' => $recommendation,
            'evidence' => $evidence,
            'metadata' => [
                'schema_version' => 'atlas.agent_behavior.finding.v1',
                'contract_id' => AgentBehaviorContract::CONTRACT_ID,
                'contract_hash' => $this->contract->contentHash(),
                'review_signal' => [
                    'status' => $severity === 'p1' ? 'blocking' : 'warning',
                    'severity' => $severity === 'p1' ? 'high' : 'medium',
                    'recommended_action' => 'review_agent_behavior_contract_violation',
                ],
            ],
        ];
    }

    private function normalize(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }
}
