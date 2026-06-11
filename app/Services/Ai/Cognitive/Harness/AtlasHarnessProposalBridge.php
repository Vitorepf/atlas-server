<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Harness;

use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Cognitive\Failure\FailureSignatureRepository;
use Throwable;

/**
 * AP-819 Obra B (F2′) — a ponte cluster→harness-surface.
 *
 * Lê os alertas de repetição do cérebro de falhas (alimentado pela F1) e, para
 * clusters com mapeamento DETERMINÍSTICO numa seção da Harness Surface v1,
 * cria uma proposta `harness_config` (tipo os edits aceitos do paper: "redirect
 * after N tool calls" ⇒ aqui "sobe o TTL do DecisionReceipt").
 *
 * PROPOSE-ONLY por construção: a proposta nasce `proposed`; o applier só aceita
 * apply de proposta APROVADA pelo operador, e `harness_config` nunca entra no
 * auto-apply (supportsAutoApply=false). Clusters sem mapeamento são reportados
 * como `unmapped` — honestidade > cobertura.
 */
final class AtlasHarnessProposalBridge
{
    public const SCHEMA_VERSION = 'atlas.cognitive.harness_proposal_bridge.v1';

    private const MIN_REPETITIONS = 3;

    public function __construct(
        private readonly FailureSignatureRepository $signatures,
        private readonly AtlasHarnessSurface $surface,
        private readonly ?AtlasHarnessInstructionSurface $instructions = null,
    ) {}

    private function instructionSurface(): AtlasHarnessInstructionSurface
    {
        return $this->instructions ?? app(AtlasHarnessInstructionSurface::class);
    }

    /**
     * @return array<string,mixed>
     */
    public function propose(int $limit = 5): array
    {
        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'alerts_considered' => 0,
            'proposals_created' => 0,
            'proposals' => [],
            'unmapped' => [],
            'skipped' => [],
        ];

        $alerts = $this->signatures->alerts('open');
        foreach (array_slice($alerts, 0, max(1, $limit)) as $alert) {
            if ((int) ($alert['repetition_count'] ?? 0) < self::MIN_REPETITIONS) {
                continue;
            }
            $report['alerts_considered']++;

            $mapping = $this->mapClusterToSurface($alert) ?? $this->mapClusterToInstruction($alert);
            if ($mapping === null) {
                $report['unmapped'][] = [
                    'signature_key' => $alert['signature_key'] ?? null,
                    'domain' => $alert['domain'] ?? null,
                    'reason' => 'no_surface_section_for_cluster',
                ];

                continue;
            }

            try {
                $proposal = app(AtlasLearningProposalService::class)->propose([
                    'kind' => $mapping['kind'] ?? 'harness_config',
                    'summary' => $mapping['summary'],
                    'current_state' => ['key' => $mapping['key'], 'value' => $mapping['current']],
                    'proposed_state' => [
                        'key' => $mapping['key'],
                        ...(($mapping['kind'] ?? 'harness_config') === 'harness_instruction'
                            ? ['text' => (string) $mapping['proposed']]
                            : ['value' => $mapping['proposed']]),
                        'from_cluster' => (string) ($alert['signature_key'] ?? ''),
                    ],
                    'evidence_refs' => array_values(array_filter([
                        'failure_alert:'.(string) ($alert['id'] ?? ''),
                        'failure_signature_key:'.(string) ($alert['signature_key'] ?? ''),
                    ])),
                    'confidence' => 60,
                ]);
                $report['proposals_created']++;
                $report['proposals'][] = [
                    'proposal_id' => (string) $proposal->getKey(),
                    'status' => (string) $proposal->status,
                    'key' => $mapping['key'],
                    'current' => $mapping['current'],
                    'proposed' => $mapping['proposed'],
                    'from_cluster' => $alert['signature_key'] ?? null,
                ];
            } catch (Throwable $exception) {
                $report['skipped'][] = [
                    'signature_key' => $alert['signature_key'] ?? null,
                    'reason' => mb_substr($exception->getMessage(), 0, 200),
                ];
            }
        }

        return $report;
    }

    /**
     * Mapeamento DETERMINÍSTICO cluster→seção da superfície. Pequeno e honesto:
     * só os padrões com relação causal direta entram; o resto fica `unmapped`.
     *
     * @param  array<string,mixed>  $alert
     * @return array{key:string,current:int,proposed:int,summary:string}|null
     */
    private function mapClusterToSurface(array $alert): ?array
    {
        $sample = $this->clusterSample($alert);
        $haystack = strtolower(json_encode([$alert['signature_key'] ?? '', $sample], JSON_UNESCAPED_UNICODE) ?: '');

        if (str_contains($haystack, 'decision_expired') || str_contains($haystack, 'decisionreceipt expir')) {
            return $this->scaled(
                'runtime_control.decision_receipt_ttl_seconds',
                multiplier: 2.0,
                summary: 'Cluster recorrente decision_expired: DecisionReceipt expira antes do provider iniciar — propor TTL maior dentro dos bounds da Harness Surface.',
            );
        }
        // Expansão 3 — ordem por ESPECIFICIDADE (rate-limit antes de timeout:
        // mensagens 429 não contêm "timeout", mas o inverso poderia colidir).
        if (str_contains($haystack, 'rate limit') || str_contains($haystack, 'rate_limit') || str_contains($haystack, '429') || str_contains($haystack, 'quota')) {
            return $this->scaled(
                'runtime_control.retry_delay_seconds',
                multiplier: 2.0,
                summary: 'Cluster recorrente de rate-limit/quota do provider: propor backoff maior entre tentativas dentro dos bounds da Harness Surface.',
            );
        }
        if (str_contains($haystack, 'max turns') || str_contains($haystack, 'max_turns') || str_contains($haystack, 'turn limit')) {
            return $this->scaled(
                'provider_policy.hermes_max_turns',
                multiplier: 4 / 3,
                summary: 'Cluster recorrente de sessão estourando o limite de turnos: propor teto de turnos maior dentro dos bounds da Harness Surface.',
            );
        }
        if (str_contains($haystack, 'timeout') || str_contains($haystack, 'timed out')) {
            return $this->scaled(
                'runtime_control.timeout_seconds',
                multiplier: 1.5,
                summary: 'Cluster recorrente de timeout de provider: propor timeout maior dentro dos bounds da Harness Surface.',
            );
        }
        if (str_contains($haystack, 'provider_exception') || str_contains($haystack, 'exited unexpectedly') || str_contains($haystack, 'connection') || str_contains($haystack, 'econn') || str_contains($haystack, 'broken pipe')) {
            return $this->incremented(
                'runtime_control.max_attempts',
                step: 1,
                summary: 'Cluster recorrente de crash/queda transitória do provider: propor +1 tentativa dentro dos bounds da Harness Surface (o auto-reverse desfaz se não reduzir a recorrência).',
            );
        }

        return null;
    }

    /**
     * Surface v2 — mapeamento determinístico cluster→seção de INSTRUÇÃO.
     * Falhas COMPORTAMENTAIS (sem entrega, loop de erro de ferramenta, sucesso
     * sem verificação) não se curam com número: se curam trocando a instrução
     * por uma variante DECLARADA da biblioteca — a próxima ainda não tentada.
     *
     * @param  array<string,mixed>  $alert
     * @return array{kind:string,key:string,current:string,proposed:string,summary:string}|null
     */
    private function mapClusterToInstruction(array $alert): ?array
    {
        $sample = $this->clusterSample($alert);
        $haystack = strtolower(json_encode([$alert['signature_key'] ?? '', $sample], JSON_UNESCAPED_UNICODE) ?: '');

        $section = match (true) {
            str_contains($haystack, 'no output') || str_contains($haystack, 'empty output')
                || str_contains($haystack, 'output_missing') || str_contains($haystack, 'sem entrega')
                || str_contains($haystack, 'empty_response') || str_contains($haystack, 'empty response') => 'worker.output_discipline',
            str_contains($haystack, 'tool error') || str_contains($haystack, 'tool_error')
                || str_contains($haystack, 'tool_failed') || str_contains($haystack, 'invalid tool')
                || str_contains($haystack, 'tool loop') || str_contains($haystack, 'tool_loop') => 'worker.tool_error_recovery',
            str_contains($haystack, 'not certified') || str_contains($haystack, 'not_certified')
                || str_contains($haystack, 'certification failed') || str_contains($haystack, 'certification_failed')
                || str_contains($haystack, 'verification failed') || str_contains($haystack, 'verification_failed')
                || str_contains($haystack, 'nao verificado') => 'worker.verification_guidance',
            default => null,
        };
        if ($section === null) {
            return null;
        }

        $surface = $this->instructionSurface();
        $current = (string) $surface->text($section);
        foreach ($surface->searchSpace($section) as $variant) {
            if ($variant !== $current) {
                return [
                    'kind' => 'harness_instruction',
                    'key' => $section,
                    'current' => $current,
                    'proposed' => $variant,
                    'summary' => sprintf(
                        'Cluster comportamental recorrente mapeado em %s: propor a próxima variante declarada da biblioteca (espaço de busca finito; auto-reverse se a recorrência não cair).',
                        $section,
                    ),
                ];
            }
        }

        return null; // espaço de busca esgotado para esta seção — honesto.
    }

    /**
     * @return array{key:string,current:int,proposed:int,summary:string}|null
     */
    private function incremented(string $key, int $step, string $summary): ?array
    {
        $current = $this->surface->currentValue($key);
        if ($current === null) {
            return null;
        }
        $max = $this->surface->sections()[$key]['max'];
        $proposed = min($current + $step, $max);
        if ($proposed === $current) {
            return null; // já no teto — não há edit a propor.
        }

        return ['key' => $key, 'current' => $current, 'proposed' => $proposed, 'summary' => $summary];
    }

    /**
     * @return array{key:string,current:int,proposed:int,summary:string}|null
     */
    private function scaled(string $key, float $multiplier, string $summary): ?array
    {
        $current = $this->surface->currentValue($key);
        if ($current === null) {
            return null;
        }
        $max = $this->surface->sections()[$key]['max'];
        $proposed = min((int) round($current * $multiplier), $max);
        if ($proposed === $current) {
            return null; // já no teto — não há edit a propor.
        }

        return ['key' => $key, 'current' => $current, 'proposed' => $proposed, 'summary' => $summary];
    }

    /**
     * @param  array<string,mixed>  $alert
     */
    private function clusterSample(array $alert): string
    {
        try {
            $recent = $this->signatures->recent((string) ($alert['domain'] ?? '') ?: null, 30);
            foreach ($recent as $signature) {
                if (($signature['signature_key'] ?? null) === ($alert['signature_key'] ?? '__none__')) {
                    return (string) ($signature['context_summary'] ?? '').' '.(string) ($signature['sub_cause'] ?? '');
                }
            }
        } catch (Throwable) {
            // amostra é enriquecimento; o mapeamento degrada para o signature_key.
        }

        return '';
    }
}
