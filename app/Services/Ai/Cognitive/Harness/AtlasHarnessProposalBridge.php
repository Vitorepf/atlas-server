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
    ) {}

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

            $mapping = $this->mapClusterToSurface($alert);
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
                    'kind' => 'harness_config',
                    'summary' => $mapping['summary'],
                    'current_state' => ['key' => $mapping['key'], 'value' => $mapping['current']],
                    'proposed_state' => [
                        'key' => $mapping['key'],
                        'value' => $mapping['proposed'],
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
        if (str_contains($haystack, 'timeout') || str_contains($haystack, 'timed out')) {
            return $this->scaled(
                'runtime_control.timeout_seconds',
                multiplier: 1.5,
                summary: 'Cluster recorrente de timeout de provider: propor timeout maior dentro dos bounds da Harness Surface.',
            );
        }

        return null;
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
