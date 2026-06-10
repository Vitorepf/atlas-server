<?php

namespace App\Services\Ai\VentureFoundry;

use App\Models\AiDomainHandoff;
use App\Models\AiVenture;
use App\Models\AiVentureIdea;
use App\Services\Ai\DomainRuntime\DomainHandoffService;
use Illuminate\Support\Carbon;

/**
 * Bridge venture strategy gaps into the Research company runtime.
 *
 * Emits a governed `strategy -> research` domain handoff (allowed by the
 * strategy manifest) carrying deterministic research questions derived from
 * the venture's persisted state and current ladder gaps. The research domain
 * answers under its own quality gates (sources_diverse, claims_attributed).
 */
class VentureResearchHandoffService
{
    public const SOURCE_DOMAIN = 'strategy';

    public const TARGET_DOMAIN = 'research';

    public function __construct(
        private readonly DomainHandoffService $handoffs,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $gaps  ladder gaps from the latest evaluation
     * @param  array<int,string>  $extraQuestions  operator-provided questions
     */
    public function emitForVenture(AiVenture $venture, array $gaps = [], array $extraQuestions = []): AiDomainHandoff
    {
        $idea = $venture->idea_id !== null
            ? AiVentureIdea::query()->whereKey($venture->idea_id)->first()
            : null;

        $questions = $this->questions($venture, $idea, $gaps, $extraQuestions);

        return $this->handoffs->emit(
            self::SOURCE_DOMAIN,
            self::TARGET_DOMAIN,
            sprintf('Pesquisa de mercado para a venture [%s] (%s).', $venture->venture_id, $venture->name),
            [
                'context_pack' => [
                    'schema_version' => 'atlas.ai.venture.research_handoff.v1',
                    'venture_id' => $venture->venture_id,
                    'venture_uuid' => $venture->uuid,
                    'name' => $venture->name,
                    'thesis' => $venture->thesis,
                    'stage' => $venture->stage,
                    'icp' => $idea?->icp,
                    'problem' => $idea?->problem,
                    'pain' => $idea?->pain,
                    'gaps' => array_values(array_map(
                        fn (array $gap) => ['stage' => $gap['stage'] ?? null, 'gate' => $gap['gate'] ?? null],
                        $gaps,
                    )),
                    'research_questions' => $questions,
                    'requested_at' => Carbon::now()->toIso8601String(),
                ],
                'expected_output' => [
                    'research_brief' => true,
                    'sources_diverse' => true,
                    'claims_attributed' => true,
                    'tam_sam_som_estimated' => true,
                ],
                'evidence_refs' => [
                    ['kind' => 'venture', 'id' => $venture->id, 'hash' => $venture->venture_hash],
                ],
            ],
        );
    }

    /**
     * Deterministic research questions: base market set + gap-driven + operator extras.
     *
     * @param  array<int,array<string,mixed>>  $gaps
     * @param  array<int,string>  $extraQuestions
     * @return array<int,string>
     */
    private function questions(AiVenture $venture, ?AiVentureIdea $idea, array $gaps, array $extraQuestions): array
    {
        $icp = $idea?->icp ?? 'o ICP declarado';

        $questions = [
            sprintf('Qual o TAM/SAM/SOM para [%s] considerando o ICP [%s]? Declare premissas e fontes.', $venture->name, $icp),
            sprintf('Quem já atende [%s] hoje (alternativas diretas e substitutos) e com qual posicionamento/preço?', $icp),
            sprintf('Quais referências de pricing e disposição a pagar existem para a tese: %s', $venture->thesis),
        ];

        $gapGates = array_values(array_filter(array_map(
            fn (array $gap) => is_string($gap['gate'] ?? null) ? $gap['gate'] : null,
            $gaps,
        )));

        if (in_array('arr_positive', $gapGates, true)) {
            $questions[] = sprintf('Quais canais de aquisição comprovados levam empresas semelhantes à primeira receita com [%s]?', $icp);
        }
        if (in_array('ltv_cac_healthy', $gapGates, true)) {
            $questions[] = 'Quais referências públicas de CAC/LTV existem para negócios comparáveis e o que explica as diferenças?';
        }

        foreach ($extraQuestions as $question) {
            $question = trim((string) $question);
            if ($question !== '') {
                $questions[] = $question;
            }
        }

        return array_values(array_unique($questions));
    }
}
