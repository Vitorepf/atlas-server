<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiJob;
use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\MarketingDomain\Knowledge\PagePatternLibrary;
use App\Services\Ai\MarketingDomain\Scoring\MessageMatchScorer;
use App\Services\Ai\MarketingDomain\Scoring\PageAuditScorer;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * BridgePageComposerService — Atlas' capability to AUTHOR an aggressive, high-converting bridge
 * (pre-sell advertorial) from three grounded inputs the operator named:
 *   1) the dissected VSL/offer (lead, hook, angle, trick, mechanism, devices, metrics, avatar),
 *   2) the patterns that ACTUALLY sold in the niche (AiMarketingWinningPattern: real CVR + the exact
 *      keywords that converted — message-match with the traffic that buys),
 *   3) the affiliate skills (advertorial listicle, awareness/sophistication routing, Cialdini, value
 *      equation, open-loops, policy=uptime).
 *
 * The bridge's ONE job is to warm the cold lead and maximize VSL watch-through — every section opens
 * a curiosity gap only the VSL closes. Aggressiveness lives in the angle/curiosity/emotion/callout,
 * not in unhedged medical claims on the bridge (that keeps the account live = uptime = profit).
 *
 * The intelligence lives here (prompt + grounding + deterministic validation); the words are written
 * by the governed provider (hermes_cli, never a pinned model). Output is validated fail-closed by the
 * deterministic BridgePagePolicyGuard + scored by MessageMatchScorer/PageAuditScorer.
 */
class BridgePageComposerService
{
    public ?string $lastModel = null;

    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly MarketingPlaybook $playbook = new MarketingPlaybook,
        private readonly MessageMatchScorer $messageMatch = new MessageMatchScorer,
        private readonly PageAuditScorer $pageAudit = new PageAuditScorer,
        private readonly BridgePagePolicyGuard $policyGuard = new BridgePagePolicyGuard,
        private readonly BridgePageHtmlRenderer $renderer = new BridgePageHtmlRenderer,
        private readonly KeywordRelevanceGate $keywordGate = new KeywordRelevanceGate,
        private readonly PagePatternLibrary $patterns = new PagePatternLibrary,
        private readonly MarketingSkillRegistry $skills = new MarketingSkillRegistry,
        private readonly CopyQualityGate $copyGate = new CopyQualityGate,
        private readonly VslThumbnailGenerator $thumbnail = new VslThumbnailGenerator,
        private readonly BridgeHeadlineForge $headlines = new BridgeHeadlineForge,
        private readonly ProofForge $proof = new ProofForge,
        private readonly RsaAdForge $ads = new RsaAdForge,
        private readonly \App\Services\Ai\MarketingDomain\Campaign\SearchNetworkPlanner $search = new \App\Services\Ai\MarketingDomain\Campaign\SearchNetworkPlanner,
        private readonly LeadForge $leadForge = new LeadForge,
        private readonly EmailFollowupForge $emails = new EmailFollowupForge,
        private readonly \App\Services\Ai\MarketingDomain\Content\TransformationAssetSourcer $transformations = new \App\Services\Ai\MarketingDomain\Content\TransformationAssetSourcer,
        private readonly PersuasionScorer $persuasion = new PersuasionScorer,
        private readonly AwarenessRouter $awareness = new AwarenessRouter,
    ) {}

    /**
     * @param  array<string,mixed>  $opts  angle (string), pattern_niche (string), language (string),
     *                                     aggressiveness ('max'|'high'|'balanced'), brand (string),
     *                                     render (bool, default true)
     * @return array<string,mixed>
     */
    public function compose(AiMarketingVslAsset $asset, array $opts = []): array
    {
        if (trim((string) $asset->transcript) === '' && trim((string) $asset->big_idea) === '') {
            throw new RuntimeException("asset [{$asset->id}] has no dissection yet — ingest+extract the VSL first.");
        }

        $pattern = $this->resolvePattern($asset, $opts['pattern_niche'] ?? null);
        $language = $this->resolveLanguage($asset, $opts['language'] ?? null);
        $angle = trim((string) ($opts['angle'] ?? '')) ?: $this->defaultAngle($asset);
        $aggressiveness = (string) ($opts['aggressiveness'] ?? 'max');

        // Shape the page to the AUDIENCE: a weight-loss page for women is built nothing like a prostate
        // page for men. The pattern library gives the gender playbook + the fold blueprint + the brutal
        // sales mechanisms so the model builds to a proven shape, not from scratch.
        $profile = $this->patterns->audienceProfile(is_array($asset->avatar) ? $asset->avatar : [], $asset->niche);
        $temperature = (string) ($opts['temperature'] ?? 'cold');
        $blueprint = $this->patterns->bridgeBlueprint($profile['gender'], $asset->awareness_level, $asset->sophistication_level, $temperature);

        // KEYWORDS COME FROM THIS VSL ONLY. The niche winning-pattern mixes OTHER offers' keywords
        // (e.g. a gelatin offer dominates the weight_loss niche) — using it would make the bridge
        // promise a theme THIS VSL never delivers (the exact "crime" the operator forbids). The right
        // vocabulary is the keyword clusters the extractor derived from what the OT169 actually says
        // (triple hormone drops, retatrutide alternative, lipo bliss…), not the niche pool.
        $vslKeywords = $this->vslKeywords($asset);
        // Cross-niche crime signal: other niches' keywords. This niche's own pattern is NOT an anchor.
        $otherNicheKw = $this->otherNicheKeywords($pattern);

        // Generate with a self-correcting keyword-relevance loop: if the bridge promises a thematic
        // keyword the VSL never delivers (a "crime"), re-generate telling the model exactly which
        // orphan terms to anchor-or-remove. This makes the no-crime rule enforced, not hoped for.
        // Elite headlines forged deterministically from the VSL's ammunition — the headline never
        // depends on the weak LLM. They anchor the prompt AND override a weak generated headline.
        $eliteHeadlines = $this->headlines->forge($asset, ['lang' => str_starts_with(strtolower($language), 'port') ? 'pt' : 'en']);
        $headlineBlock = $eliteHeadlines === [] ? '' :
            "\n=== HEADLINES DE ELITE (use UMA destas como a headline, igual ou variação mínima — são nível profissional) ===\n• ".implode("\n• ", array_slice($eliteHeadlines, 0, 7))."\n";

        $system = $this->systemPrompt($language, $aggressiveness);
        $user = $this->groundedUser($asset, $vslKeywords, $angle, $language, $profile, $blueprint)
            .$headlineBlock
            .$this->skills->recallPromptBlock($asset->niche, (string) ($profile['gender'] ?? ''));
        $maxAttempts = (int) ($opts['max_attempts'] ?? 4);
        $bridge = null;
        $relevance = null;
        $copy = null;
        $best = null;
        $bestScore = -1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $note = $attempt === 1 ? '' : "\n\n".$this->correctionNote($relevance, $copy, $bridge);
            $raw = $this->callModel($system, $user.$note, 'atlas.vsl.bridge.v1');
            $bridge = $this->normalize($raw, $asset, $angle, $language);
            // Anchor ONLY on the VSL (pattern is not an anchor) — a "gelatin" headline on a drops VSL is a crime.
            $relevance = $this->keywordGate->evaluate($bridge, $asset, null, $otherNicheKw);
            $copy = $this->copyGate->assess($bridge, $asset);
            $substantial = $this->isSubstantial($bridge);

            // rank attempts so we keep the best one even if none is perfect
            $score = ($substantial ? 40 : 0)
                + ($relevance['verdict'] === 'ok' ? 25 : 0)
                + ($copy['verdict'] === 'ok' ? 25 : ($copy['verdict'] === 'generic' ? 8 : 0))
                + min(20, (int) ($copy['concrete_hooks_count'] ?? 0) * 3);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['bridge' => $bridge, 'relevance' => $relevance, 'copy' => $copy];
            }

            if ($substantial && $relevance['verdict'] === 'ok' && $copy['verdict'] === 'ok') {
                break; // substantial + no keyword crime + concrete, non-meta copy
            }
        }
        // ship the best attempt we got
        if ($best !== null) {
            $bridge = $best['bridge'];
            $relevance = $best['relevance'];
            $copy = $best['copy'];
        }

        // Headline override: a forged elite headline (real number + named authority + mechanism) beats
        // a weak journalistic LLM headline. This guarantees the most conversion-critical line is mine.
        if ($eliteHeadlines !== [] && $this->headlineStrength((string) $bridge['headline'], $asset) < $this->headlineStrength($eliteHeadlines[0], $asset)) {
            $bridge['headline'] = $eliteHeadlines[0];
            $bridge['meta']['slug'] = Str::slug(Str::limit($eliteHeadlines[0], 60, ''));
        }

        // Proof override: real names + real believable numbers + an elite human testimonial voice beat
        // the LLM's invented generic blurbs. Only when the forge actually has real social proof to use.
        $forgedProof = $this->proof->forge($asset, ['lang' => str_starts_with(strtolower($language), 'port') ? 'pt' : 'en']);
        if (count($forgedProof['testimonials']) >= 3) {
            $bridge['proof_block'] = $forgedProof;
        }
        // Before/after: the engine sources the offer's real creative assets (producer resource center
        // dropped into the assets dir, or a manifest/explicit opts) and fills the proof block itself.
        $bridge['proof_block'] = is_array($bridge['proof_block'] ?? null) ? $bridge['proof_block'] : [];
        $forgedTransformations = $this->transformations->source($asset, [
            'transformations' => $opts['transformations'] ?? null,
            'assets_dir' => $opts['assets_dir'] ?? null,
            'base_url' => $opts['assets_base_url'] ?? null,
        ]);
        if ($forgedTransformations !== []) {
            $bridge['proof_block']['transformations'] = $forgedTransformations;
        }

        // Lead override: a forged elite opening (avatar callout + concrete agitation + common enemy +
        // mechanism plant + open loop) beats a journalistic LLM lead. The opening decides if they read on.
        $forgedLead = $this->leadForge->forge($asset, ['lang' => str_starts_with(strtolower($language), 'port') ? 'pt' : 'en']);
        if ($this->leadStrength((string) ($bridge['lead_paragraph'] ?? ''), $asset) < $this->leadStrength($forgedLead, $asset)) {
            $bridge['lead_paragraph'] = $forgedLead;
        }

        // --- deterministic validation -------------------------------------------------------
        // Message-match + coverage are measured against THIS VSL's own keywords (what the offer truly
        // is about), so a headline that drifts to an unrelated theme scores low — never against the
        // niche pool that mixes other offers.
        $promise = trim((string) $asset->core_promise) ?: trim((string) $asset->big_idea);
        $adHeadline = implode(' ', array_slice($vslKeywords, 0, 8)) ?: $angle;
        $aboveFold = (string) $bridge['headline'].' '.($bridge['kicker'] ?? '').' '.($bridge['subheadline'] ?? '');
        $message = $this->messageMatch->score($adHeadline, $aboveFold, $promise);
        $coverage = $this->keywordCoverage($aboveFold, array_slice($vslKeywords, 0, 14));
        $policy = $this->policyGuard->evaluate($bridge);
        $audit = $this->pageAudit->auditPage([
            'title' => (string) $bridge['headline'],
            'ad_headline' => $adHeadline,
            'vsl_promise' => $promise,
            'form_fields' => [],                         // bridge has no form — 1 goal = watch
            'cta_placements' => $bridge['cta_blocks'],
            'load_time_ms' => 1200,                       // inline-CSS self-contained page target
            'trust_near_top' => ! empty($bridge['trust_bar']),
            'conversion_goals' => 1,
        ]);

        $result = [
            'bridge' => $bridge,
            'grounding' => [
                'asset_id' => $asset->id,
                'asset_label' => $asset->label,
                'angle' => $angle,
                'language' => $language,
                'aggressiveness' => $aggressiveness,
                'niche' => $asset->niche,
                'vsl_keywords_used' => array_slice($vslKeywords, 0, 14),
                'elite_headlines' => array_slice($eliteHeadlines, 0, 7),
                'rsa_ads' => $this->ads->forge($asset, ['lang' => str_starts_with(strtolower($language), 'port') ? 'pt' : 'en']),
                'search_network' => $this->search->plan($asset, ['pattern' => $pattern]),
                'email_sequence' => $this->emails->forge($asset, ['lang' => str_starts_with(strtolower($language), 'port') ? 'pt' : 'en']),
                'persuasion_audit' => $this->persuasion->score($this->persuasionCopy($bridge)),
                'cognitive_bias_audit' => (new PatternLibraryScorer)->score(new \App\Services\Ai\MarketingDomain\Knowledge\CognitiveBiasLibrary, $this->persuasionCopy($bridge)),
                'offer_audit' => (new PatternLibraryScorer)->score(new \App\Services\Ai\MarketingDomain\Knowledge\OfferArchitectureLibrary, $this->persuasionCopy($bridge)),
                'objection_audit' => (new PatternLibraryScorer)->score(new \App\Services\Ai\MarketingDomain\Knowledge\ObjectionLibrary, $this->persuasionCopy($bridge)),
                'hook_lead_audit' => (new PatternLibraryScorer)->score(new \App\Services\Ai\MarketingDomain\Knowledge\HookLeadLibrary, $this->persuasionCopy($bridge)),
                'narrative_voice_audit' => (new PatternLibraryScorer)->score(new \App\Services\Ai\MarketingDomain\Knowledge\NarrativeVoiceLibrary, $this->persuasionCopy($bridge)),
                'awareness_routing' => $this->awareness->route((string) $asset->awareness_level)
                    + ['alignment' => $this->awareness->match($this->persuasionCopy($bridge), (string) $asset->awareness_level)],
                'awareness_target' => $asset->awareness_level,
                'sophistication' => $asset->sophistication_level,
                'audience_gender' => $profile['gender'] ?? null,
                'fold_count' => $blueprint['fold_count'] ?? null,
                'word_target' => $blueprint['word_target'] ?? null,
                'temperature' => $temperature,
                'model' => $this->lastModel ?? 'hermes_cli',
            ],
            'validation' => [
                'policy' => $policy,
                'keyword_relevance' => $relevance,
                'copy_quality' => $copy,
                'message_match' => $message,
                'keyword_coverage' => $coverage,
                'page_audit' => $audit,
            ],
        ];

        if (($opts['render'] ?? true) !== false) {
            $result['html'] = $this->renderer->render($bridge, [
                'brand' => (string) ($opts['brand'] ?? $this->defaultBrand($asset)),
                'vsl_embed_html' => (string) ($opts['vsl_embed_html'] ?? ''),
                'thumbnail_svg' => $this->thumbnail->svg($asset),
            ]);
        }

        // Self-improvement: a high-quality page teaches the next one in this niche/audience.
        if (($opts['learn'] ?? true) !== false) {
            $result['learned_skill_id'] = $this->skills->learn($result);
        }

        return $result;
    }

    // ---- the intelligence (skills as a governed prompt) ------------------------------------

    private function systemPrompt(string $language, string $aggressiveness): string
    {
        $advert = $this->playbook->advertorialSpec();
        $page = $this->playbook->pageAnatomy();
        $cialdini = implode(', ', array_keys($this->playbook->persuasionPrinciples()));
        $aggro = match ($aggressiveness) {
            'balanced' => 'AGRESSIVIDADE ALTA mas equilibrada.',
            'high' => 'AGRESSIVIDADE MUITO ALTA.',
            default => 'AGRESSIVIDADE MÁXIMA (a VSL é agressiva; a bridge tem que estar no mesmo registro de intensidade emocional e de curiosidade).',
        };

        return <<<TXT
Você é uma das maiores COPYWRITERS de resposta direta do mundo, escrevendo um ADVERTORIAL (uma reportagem/história real) que vai antes da VSL. Você escreve como gente de verdade fala com gente de verdade — com sangue, emoção e história. Sua copy JÁ vendeu centenas de milhões.

== REGRA DE OURO (a mais importante de todas) ==
NUNCA, JAMAIS, escreva SOBRE marketing. A leitora é uma mulher real, não um "público". PROIBIDO escrever palavras como: conversão, audiência, "lead", funil, "future pacing", "open loop", "message-match", "the click", "cold visitor", "apresentação técnica", "advertorial", "esta página/reportagem faz X". Se você descrever a TÉCNICA em vez de USÁ-LA, falhou. Você é uma jornalista contando uma história verdadeira e chocante para UMA mulher — fale COM ela, sobre a VIDA dela, o espelho dela, o medo dela, a esperança dela. Quem lê tem que esquecer que é um anúncio.

== USE A MUNIÇÃO CONCRETA DA VSL (não invente abstração genérica) ==
A copy GENÉRICA ("um lento metabolismo oculto", "três sinais") é lixo — poderia ser qualquer oferta. Use os elementos CONCRETOS e NOMEÁVEIS que vêm no grounding: os NOMES (Melania, Dr. Attia, FDA, as mulheres reais com nome e número), o INIMIGO (big pharma escondendo a solução), o MECANISMO específico (os ingredientes, as gotas, o protocolo), as DORES e DESEJOS exatos do avatar (o espelho, a roupa que não fecha, o medo do diabetes, o cansaço do Ozempic). Cada seção tem que ter carne, nome, número, cena. Concreto > abstrato, SEMPRE.

== OBJETIVO ÚNICO (o KPI) ==
Pegar uma mulher FRIA e DESQUALIFICADA (tráfego barato) e deixá-la FERVENDO, qualificada, implorando para apertar o play da VSL. A bridge NÃO vende o produto e NÃO atrapalha a VSL — ela AQUECE e QUALIFICA o lead. Cada seção abre uma lacuna de curiosidade emocional que SOMENTE a VSL fecha. Você revela o suficiente para fisgar e prometer a resposta "na apresentação acima" — nunca entrega a receita/mecanismo completo.

== {$aggro} ==
A agressividade mora no ÂNGULO, na curiosidade, na emoção crua, no callout do avatar, na escassez/urgência aquecida e no "inimigo comum". NÃO mora em claims médicos absolutos cravados na bridge. Use os DISPOSITIVOS da VSL (autoridade tipo Melania/FDA/governo, conspiração big pharma, prova social extrema) como GANCHO DE NOTÍCIA/CURIOSIDADE — relate-os como "o que está sendo dito / o que viralizou / o que ela revelou", empurrando pro vídeo, em vez de AFIRMAR como fato médico provado na sua voz. Isso mantém a intensidade E mantém a conta no ar (uptime = lucro).

== SKILLS A APLICAR ==
- FORMATO: {$advert['format']} — {$advert['items']}, {$advert['length']}. {$advert['why_converts']} Conteúdo ORIGINAL real (a página ajuda/informa mesmo se todos os links sumissem) — é o que converte E sobrevive à política de "Insufficient Original Content".
- AWARENESS/SOPHISTICATION: lidere conforme o nível dado no grounding. solution_aware ⇒ lidere pelo MECANISMO único (o "como" diferente). Sophistication 5 ⇒ abra com IDENTIDADE/identificação do avatar ("Se você é [avatar] e já tentou [X, Y, Z]…") + mecanismo NOMEADO.
- MESSAGE-MATCH: a headline e o corpo devem ecoar as PALAVRAS-CHAVE DESTA OFERTA (vêm no grounding, derivadas da própria VSL) — é por esses termos que o tráfego desta VSL busca. NUNCA use keywords de outro tema/oferta. Congruência anúncio→bridge→VSL.
- PERSUASÃO (Cialdini): {$cialdini}. Value Equation (sonho × probabilidade ÷ tempo × esforço). Emoção > lógica.
- ESTRUTURA DE PÁGINA: above-the-fold com value-prop específico/numérico + vídeo + CTA; prova social (trust) logo abaixo do hero; 1 objetivo de conversão; CTA em 2–4 lugares por contraste. Benchmarks: {$page['benchmarks']['median_cvr']}, topo > {$page['benchmarks']['top_quartile']}.
- CTA: TODO CTA leva a ASSISTIR A VSL (target "#vsl"). Nunca para checkout, nunca link de afiliado cru.
- DURABILIDADE (uptime): inclua um disclosure curto de resultado típico; claims fortes com hedge/atribuição ("segundo…", "muitas relataram…", "resultados variam").

== IDIOMA ==
Escreva TODA a COPY da página em {$language} (é o idioma do mercado/tráfego — a página tem que falar a língua de quem vai clicar). Campos de nota/meta podem ser curtos.

== SAÍDA ==
Responda SOMENTE com UM bloco JSON válido e completo, schema "atlas.vsl.bridge.v1", com EXATAMENTE estas chaves:
{
  "schema_version": "atlas.vsl.bridge.v1",
  "meta": {"slug": "...", "seo_title": "...", "seo_description": "...", "lang": "{$language}"},
  "kicker": "eyebrow curto estilo manchete (ex.: 'SPECIAL HEALTH REPORT')",
  "headline": "H1 — manchete agressiva de curiosidade, ecoa as keywords que venderam",
  "subheadline": "dek — 1 frase que aprofunda a promessa + abre loop",
  "hero_cta": {"label": "CTA pro vídeo", "target": "#vsl"},
  "trust_bar": ["item curto de prova/autoridade", "..."],
  "lead_paragraph": "abertura: identidade do avatar + agita a dor + planta o mecanismo como o motivo oculto. 2-4 parágrafos (separe por linha em branco).",
  "body_sections": [{"heading": "item da listicle (com número implícito)", "body": "2-4 parágrafos de conteúdo ORIGINAL útil", "open_loop": "frase que joga pro vídeo"}],
  "mechanism_tease": "explica o ÂNGULO/mecanismo único o suficiente pra fisgar, sem dar a receita — diz que o passo-a-passo está na apresentação",
  "proof_block": {"testimonials": [{"name": "...", "result": "ex.: -34 lbs", "quote": "..."}], "stat_callouts": ["número/estatística curta"]},
  "objection_flips": [{"objection": "a dúvida do cético", "flip": "a virada que empurra pro vídeo"}],
  "cta_blocks": [{"label": "...", "target": "#vsl", "subtext": "micro-copy de reforço"}],
  "ps": "P.S. de escassez/urgência aquecida que reforça assistir agora",
  "disclosure": "1 frase curta de disclaimer/resultado típico",
  "angle_used": "...",
  "awareness_target": "...",
  "format": "listicle | story",
  "persuasion_devices_used": ["authority", "conspiracy", "social_proof", "..."]
}
Mínimo: 5 body_sections, 3 testimonials, 3 objection_flips, 2 cta_blocks. Sem texto fora do bloco JSON.

ATENÇÃO — COPY REAL, NÃO RÓTULOS: os rótulos de dobra/estrutura acima são um GUIA interno. Os headings e a headline da página devem ser MANCHETES REAIS de venda. NUNCA escreva termos de processo como texto visível da página (proibido: "bridge", "fold", "dobra", "teaser", "format", "why-now", "open loop", "headline", "authority", "advertorial", "landing", "CTA"). Escreva como um copywriter escreve para o público — não como um briefing.
TXT;
    }

    /**
     * @param  array<string,mixed>  $profile  audience profile (gender + playbook)
     * @param  array<string,mixed>  $blueprint  the fold/length/tone blueprint for this audience
     */
    private function groundedUser(AiMarketingVslAsset $asset, array $vslKeywords, string $angle, string $language, array $profile = [], array $blueprint = []): string
    {
        $offer = [
            'niche' => $asset->niche,
            'sub_niche' => $asset->sub_niche,
            'avatar' => $asset->avatar,
            'awareness_level' => $asset->awareness_level,
            'sophistication_level' => $asset->sophistication_level,
            'big_idea' => $asset->big_idea,
            'core_promise' => $asset->core_promise,
            'mechanism_name' => $asset->mechanism_name,
            'problem_mechanism' => $asset->problem_mechanism,
            'solution_mechanism' => $asset->solution_mechanism,
            'angle' => $asset->angle,
            'hook' => $asset->hook,
            'trick' => $asset->trick,
            'lead' => $asset->lead,
            'metrics' => $asset->metrics,
            'persuasion_devices' => $asset->persuasion_devices,
            'offer' => $asset->offer,
            'objection_rebuttals' => $asset->objection_rebuttals,
            'power_phrases' => $asset->power_phrases,
            'essential_summary' => $asset->essential_summary,
            'target_geo' => $asset->target_geo,
        ];

        $offerJson = json_encode($offer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';

        // Keywords belong to THIS VSL (its own dissected clusters). NOT a niche pool with other offers.
        $kwList = implode(' | ', array_slice($vslKeywords, 0, 16));
        $bridgeTerms = array_slice($vslKeywords, 0, 5);
        $bridgeInstruction = $bridgeTerms === []
            ? ''
            : "\n=== PALAVRAS-CHAVE DA PRÓPRIA OFERTA (OBRIGATÓRIO — o tráfego desta VSL busca POR ISTO) ===\n".
              'Estas são as palavras-chave que descrevem ESTA oferta especificamente (derivadas do que a VSL diz). '.
              'A HEADLINE, o KICKER e a SUBHEADLINE DEVEM falar nesse vocabulário e ecoar 2–3 destes termos literalmente: '.
              $kwList."\n".
              'PROIBIDO trazer qualquer tema/keyword que NÃO esteja nesta lista ou na oferta dissecada acima '.
              '(ex.: NÃO escreva sobre "gelatina/jello/keto" se não estiver aqui — seria um crime de keyword).'."\n";

        $blueprintBlock = $this->blueprintBlock($profile, $blueprint);
        $ammo = $this->ammunitionBlock($asset);

        return <<<TXT
ÂNGULO ESCOLHIDO PARA A BRIDGE: {$angle}
IDIOMA DA COPY: {$language}

=== 1) OFERTA / VSL DISSECADA (a base — use lead, hook, angle, trick, mechanism, devices, metrics, avatar) ===
{$offerJson}
{$ammo}
{$bridgeInstruction}
{$blueprintBlock}
=== REGRA ANTI-CRIME (palavras-chave) ===
TODA palavra-chave TEMÁTICA proeminente (headline/kicker/subheadline/seções) DEVE existir na OFERTA dissecada acima OU na lista de palavras-chave DESTA oferta. NUNCA traga um tema que a VSL não trata (ex.: gelatina, jello, keto) — isso é erro crítico que faz a página escalar negativo.

LEMBRE: a bridge aquece e maximiza o watch-through da VSL. Open-loops em cada dobra. Agressividade no ângulo/curiosidade/emoção — não em claim médico absoluto. Todo CTA → "#vsl". Responda só o JSON do schema atlas.vsl.bridge.v1.
TXT;
    }

    /**
     * Build the corrective instruction when the previous bridge had orphan keywords (a crime).
     *
     * @param  array<string,mixed>|null  $relevance
     */
    /**
     * @param  array<string,mixed>|null  $relevance
     * @param  array<string,mixed>|null  $copy
     * @param  array<string,mixed>|null  $bridge
     */
    private function correctionNote(?array $relevance, ?array $copy, ?array $bridge): string
    {
        $orphans = array_filter(array_map(
            static fn ($o): string => (string) ($o['keyword'] ?? ''),
            is_array($relevance['orphan_keywords'] ?? null) ? $relevance['orphan_keywords'] : [],
        ));
        $leaks = array_filter(is_array($relevance['meta_leaks'] ?? null) ? $relevance['meta_leaks'] : []);
        $metaLeaks = array_filter(is_array($copy['meta_leaks'] ?? null) ? $copy['meta_leaks'] : []);

        $parts = ['CORREÇÃO OBRIGATÓRIA na próxima versão:'];
        if ($bridge !== null && ! $this->isSubstantial($bridge)) {
            $parts[] = 'A versão anterior veio VAZIA ou curta demais. Gere a bridge COMPLETA: headline forte + 5 a 7 seções de copy real (2-4 parágrafos cada) + prova + objeções + CTAs. NÃO retorne campos vazios.';
        }
        if ($orphans !== []) {
            $parts[] = 'ERRO CRÍTICO (crime de keyword): os termos [' .implode(', ', $orphans).
                '] aparecem na copy mas NÃO existem na VSL. Remova-os ou substitua por termos da própria oferta.';
        }
        if ($metaLeaks !== []) {
            $parts[] = 'PARECE IA BURRA (vazamento meta): a copy FALA SOBRE marketing ['.implode(', ', array_slice($metaLeaks, 0, 6)).
                ']. PROIBIDO. Você é uma jornalista escrevendo uma reportagem real para a leitora — NUNCA mencione "conversão", "audiência", "vídeo como apresentação técnica", "future pacing", "the click", "funil". Fale COM ela, sobre a vida dela.';
        }
        if (($copy['verdict'] ?? '') === 'generic') {
            $available = implode(' | ', array_slice($copy['concrete_hooks_available'] ?? [], 0, 16));
            $parts[] = 'COPY GENÉRICA (parece qualquer oferta): use os elementos CONCRETOS desta VSL — nomes, inimigo, mecanismo, dores REAIS. Munição obrigatória (use ao menos '.CopyQualityGate::MIN_CONCRETE_HOOKS.'): '.$available;
        }
        if ($leaks !== []) {
            $parts[] = 'Não use rótulos de processo como texto visível ('.implode(', ', $leaks).').';
        }
        $parts[] = 'Responda só o JSON do schema atlas.vsl.bridge.v1.';

        return implode("\n", $parts);
    }

    /** Conversion-strength of a headline: real number + named authority + mechanism + differentiator. */
    private function headlineStrength(string $headline, AiMarketingVslAsset $asset): int
    {
        $h = mb_strtolower($headline);
        $s = 0;
        if (preg_match('/\d{2,3}\s*(lbs?|pounds|libras|kg)/u', $h)) {
            $s += 3;
        }
        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        foreach (array_slice((array) ($devices['authority'] ?? []), 0, 6) as $name) {
            $first = mb_strtolower(trim((string) (is_array($name) ? reset($name) : $name)));
            $first = trim((string) preg_replace('/\s*[\(\[].*$/u', '', $first));
            if ($first !== '' && str_word_count($first) <= 4 && str_contains($h, $first)) {
                $s += 3;
                break;
            }
        }
        $mech = mb_strtolower((string) preg_replace('/\s*\(.*$/u', '', (string) $asset->mechanism_name));
        if ($mech !== '' && str_contains($h, mb_substr($mech, 0, 12))) {
            $s += 1;
        }
        foreach (['injection', 'injeç', 'ozempic', 'mounjaro', 'needle', 'big pharma', 'leaked', 'vazou'] as $w) {
            if (str_contains($h, $w)) {
                $s += 1;
                break;
            }
        }

        return $s;
    }

    /** Flatten the bridge's visible copy into one blob for the persuasion audit. */
    private function persuasionCopy(array $bridge): string
    {
        $parts = [
            (string) ($bridge['headline'] ?? ''),
            (string) ($bridge['kicker'] ?? ''),
            (string) ($bridge['subheadline'] ?? ''),
            (string) ($bridge['lead_paragraph'] ?? ''),
            (string) ($bridge['mechanism_tease'] ?? ''),
            (string) ($bridge['ps'] ?? ''),
        ];
        foreach ((array) ($bridge['body_sections'] ?? []) as $s) {
            $parts[] = is_array($s) ? (string) ($s['heading'] ?? '').' '.(string) ($s['body'] ?? $s['text'] ?? '') : (string) $s;
        }
        foreach ((array) ($bridge['cta_blocks'] ?? []) as $c) {
            $parts[] = is_array($c) ? (string) ($c['label'] ?? '') : (string) $c;
        }
        foreach ((array) (($bridge['proof_block']['testimonials'] ?? [])) as $t) {
            $parts[] = is_array($t) ? (string) ($t['quote'] ?? '').' '.(string) ($t['result'] ?? '') : (string) $t;
        }
        foreach ((array) (($bridge['proof_block']['stat_callouts'] ?? [])) as $st) {
            $parts[] = is_string($st) ? $st : '';
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /** Conversion-strength of a lead: direct address + common enemy + mechanism plant + concrete scene + length. */
    private function leadStrength(string $lead, AiMarketingVslAsset $asset): int
    {
        $l = mb_strtolower($lead);
        $s = 0;
        $words = str_word_count($lead);
        $s += $words >= 60 ? 2 : ($words >= 35 ? 1 : 0);
        if (preg_match('/\b(you|your|você|voce|sua|seu|te)\b/u', $l)) {
            $s += 2;       // speaks TO the reader, not about a phenomenon
        }
        if (preg_match('/\b(ozempic|mounjaro|wegovy|injection|injeç|needle|agulha|pharma|farmac|billion|bilh)\b|\$\d/u', $l)) {
            $s += 2;       // common enemy / failed solution
        }
        $mech = mb_strtolower(trim((string) preg_replace('/\s*\(.*$/u', '', (string) $asset->mechanism_name)));
        if ($mech !== '' && str_contains($l, mb_substr($mech, 0, 12))) {
            $s += 2;       // plants the named mechanism
        }
        if (preg_match('/\b(mirror|scale|clothes|photos?|espelho|balança|balanca|roupa|foto)\b/u', $l)) {
            $s += 1;       // concrete sensory scene, not abstraction
        }

        return $s;
    }

    /**
     * A real bridge has a headline + at least 3 body sections + substantial copy. Guards against the
     * model returning a near-empty JSON (which normalize would otherwise paper over with the angle).
     *
     * @param  array<string,mixed>  $bridge
     */
    private function isSubstantial(array $bridge): bool
    {
        $sections = is_array($bridge['body_sections'] ?? null) ? $bridge['body_sections'] : [];
        if (count($sections) < 3) {
            return false;
        }
        $words = str_word_count((string) ($bridge['lead_paragraph'] ?? ''));
        foreach ($sections as $s) {
            $words += str_word_count((string) (is_array($s) ? ($s['body'] ?? $s['text'] ?? '') : ''));
        }

        return $words >= 300;
    }

    /**
     * Converting keywords of OTHER niches — the cross-niche crime signal (a "keto" headline on a GLP-1
     * VSL matches the keto niche's keywords, not this offer).
     *
     * @return array<int,string>
     */
    private function otherNicheKeywords(?AiMarketingWinningPattern $pattern): array
    {
        $currentNiche = $pattern?->niche;
        $terms = [];
        $rows = AiMarketingWinningPattern::query()
            ->when($currentNiche !== null, fn ($q) => $q->where('niche', '!=', $currentNiche))
            ->get(['converting_keywords']);
        foreach ($rows as $row) {
            if (is_array($row->converting_keywords)) {
                foreach ($row->converting_keywords as $k) {
                    $term = trim((string) (is_array($k) ? ($k['term'] ?? '') : $k));
                    if ($term !== '') {
                        $terms[] = $term;
                    }
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * The concrete emotional + proof ammunition from THIS VSL the copy must weave in — names, enemy,
     * mechanism, and the avatar's exact pains/desires/emotions. This is what makes the copy feel human
     * and specific instead of generic AI mush.
     */
    private function ammunitionBlock(AiMarketingVslAsset $asset): string
    {
        $avatar = is_array($asset->avatar) ? $asset->avatar : [];
        $pains = $this->shortList($avatar['dores'] ?? $avatar['pains'] ?? [], 6);
        $desires = $this->shortList($avatar['desejos'] ?? $avatar['desires'] ?? [], 5);
        $emotions = $this->shortList($avatar['emocoes'] ?? $avatar['emotions'] ?? [], 6);
        $failed = $this->shortList($avatar['solucoes_que_falharam'] ?? $avatar['failed'] ?? [], 5);

        $devices = is_array($asset->persuasion_devices) ? $asset->persuasion_devices : [];
        $authority = $this->shortList(array_map(fn ($n) => $this->firstName($n), (array) ($devices['authority'] ?? [])), 8);
        $proof = $this->shortList(array_map(fn ($n) => $this->firstName($n), (array) ($devices['social_proof'] ?? [])), 8);
        $enemy = trim((string) ($devices['conspiracy'] ?? ''));
        $hooks = implode(' | ', array_slice($this->copyGate->concreteHooks($asset), 0, 18));

        $whoBlock = trim((string) ($avatar['quem'] ?? ''));

        return <<<TXT

=== MUNIÇÃO CONCRETA DESTA VSL (USE — é o que faz a copy parecer humana e específica, não IA genérica) ===
QUEM É A LEITORA: {$whoBlock}
DORES REAIS (agite com cena/sensação, não em abstrato): {$pains}
DESEJOS (faça-a viver isso — future pacing emocional, sem usar esse termo): {$desires}
EMOÇÕES a tocar: {$emotions}
O QUE JÁ FALHOU pra ela (valide a frustração): {$failed}
NOMES/AUTORIDADE pra gancho de curiosidade ("o que [nome] revelou", como notícia, sem afirmar como fato médico): {$authority}
PROVA SOCIAL (mulheres reais com nome — cite-as): {$proof}
INIMIGO COMUM (tire a culpa dela, jogue a culpa aqui): {$enemy}
GANCHOS NOMEÁVEIS obrigatórios (use ao menos 4 literalmente na copy): {$hooks}
TXT;
    }

    /**
     * @param  array<int,mixed>|mixed  $items
     */
    private function shortList(mixed $items, int $max): string
    {
        $items = is_array($items) ? $items : [];
        $out = [];
        foreach (array_slice($items, 0, $max) as $i) {
            $s = trim((string) (is_array($i) ? ($i['name'] ?? reset($i)) : $i));
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return implode('; ', $out);
    }

    private function firstName(mixed $v): string
    {
        $s = trim((string) (is_array($v) ? ($v['name'] ?? reset($v)) : $v));
        $s = (string) preg_replace('/\s*[\(\[].*$/u', '', $s);

        return trim((string) preg_replace('/\s*[:\-—].*$/u', '', $s));
    }

    /**
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $blueprint
     */
    private function blueprintBlock(array $profile, array $blueprint): string
    {
        if ($profile === [] || $blueprint === []) {
            return '';
        }
        $gender = (string) ($profile['gender'] ?? 'neutral');
        $pb = is_array($profile['playbook'] ?? null) ? $profile['playbook'] : [];
        $tone = (string) ($pb['tone'] ?? '');
        $triggers = implode(', ', is_array($pb['triggers'] ?? null) ? $pb['triggers'] : []);
        $words = (string) ($blueprint['word_target'] ?? '');
        $foldCount = (string) ($blueprint['fold_count'] ?? '');
        $lead = (string) ($blueprint['lead_move'] ?? '');
        // compact: names only — too much detail drowns the generation and collapses the output.
        $mechs = implode(', ', array_map(static fn (array $m): string => $m['name'], $this->patterns->brutalMechanisms()));

        return <<<TXT

=== 3) MOLDE PARA ESTE PÚBLICO (gênero={$gender}) ===
TOM: {$tone}
GATILHOS deste público: {$triggers}
LEAD: {$lead}
TAMANHO: ~{$foldCount} seções, {$words}, cada seção com open-loop.
MECANISMOS de venda a aplicar com força: {$mechs}.
TXT;
    }

    // ---- grounding helpers -----------------------------------------------------------------

    /**
     * THIS VSL's own keywords — the clusters the extractor derived from what the VSL actually says
     * (e.g. "triple hormone drops", "retatrutide alternative", "lipo bliss"). This is the ONLY
     * legitimate keyword source for the bridge; the niche winning-pattern is another offer's pool.
     *
     * @return array<int,string>
     */
    private function vslKeywords(AiMarketingVslAsset $asset): array
    {
        $terms = [];
        $kw = is_array($asset->keywords) ? $asset->keywords : [];
        $clusters = is_array($kw['clusters'] ?? null) ? $kw['clusters'] : (isset($kw[0]) ? $kw : []);
        foreach ($clusters as $c) {
            $cterms = is_array($c['terms'] ?? null) ? $c['terms'] : (is_array($c) ? $c : []);
            foreach ($cterms as $t) {
                $t = trim((string) (is_array($t) ? ($t['term'] ?? '') : $t), " []\t\n\"'");
                if ($t !== '') {
                    $terms[] = mb_strtolower($t);
                }
            }
        }
        // seed the offer's core named vocabulary so it is always present
        foreach ([$asset->mechanism_name, $asset->sub_niche] as $extra) {
            $e = trim((string) $extra);
            if ($e !== '') {
                $terms[] = mb_strtolower($e);
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }

    private function resolvePattern(AiMarketingVslAsset $asset, ?string $explicitNiche): ?AiMarketingWinningPattern
    {
        $candidates = array_values(array_filter([
            $explicitNiche,
            $asset->niche,
            $this->nicheFromText((string) $asset->niche.' '.$asset->sub_niche.' '.$asset->big_idea),
        ]));
        foreach ($candidates as $niche) {
            $hit = AiMarketingWinningPattern::query()->where('niche', $niche)->orderByDesc('computed_at')->first();
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    private function nicheFromText(string $text): ?string
    {
        $text = strtolower($text);
        $map = [
            'weight_loss' => ['weight', 'peso', 'emagre', 'fat', 'glp-1', 'glp 1', 'obesidade', 'retatrutide', 'gelatin', 'jello'],
            'diabetes' => ['diabet', 'glicose', 'blood sugar', 'açúcar no sangue'],
            'prostate' => ['prostat', 'próstata'],
            'ed' => ['erectile', 'ereção', 'libido'],
            'vision' => ['vision', 'visão', 'eyesight'],
            'tinnitus' => ['tinnitus', 'zumbido'],
            'blood_pressure' => ['blood pressure', 'pressão'],
            'gut' => ['gut', 'intestino', 'bloating', 'digest'],
        ];
        foreach ($map as $niche => $needles) {
            foreach ($needles as $n) {
                if (str_contains($text, $n)) {
                    return $niche;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function topKeywords(?AiMarketingWinningPattern $pattern, int $limit): array
    {
        if ($pattern === null || ! is_array($pattern->converting_keywords)) {
            return [];
        }
        $kw = $pattern->converting_keywords;
        usort($kw, static fn ($a, $b): int => (int) ($b['conversions'] ?? 0) <=> (int) ($a['conversions'] ?? 0));

        return array_values(array_filter(array_map(
            static fn ($k): string => is_array($k) ? (string) ($k['term'] ?? '') : (string) $k,
            array_slice($kw, 0, $limit),
        )));
    }

    private function primaryKeyword(?AiMarketingWinningPattern $pattern): string
    {
        return $this->topKeywords($pattern, 1)[0] ?? '';
    }

    /**
     * Converting keywords ranked by RELEVANCE to this offer's angle/mechanism first, then by raw
     * conversions — so a "drops/GLP-1" offer surfaces "natural glp-1" ahead of the niche's #1 "jello
     * diet", while still keeping the umbrella winners in the mix.
     *
     * @return array<int,string>
     */
    private function relevantKeywords(?AiMarketingWinningPattern $pattern, AiMarketingVslAsset $asset, int $limit): array
    {
        $all = $this->topKeywords($pattern, 40);
        if ($all === []) {
            return [];
        }
        $offerTokens = $this->tokenize(implode(' ', [
            (string) $asset->mechanism_name, (string) $asset->big_idea, (string) $asset->angle,
            (string) $asset->trick, (string) $asset->solution_mechanism, (string) $asset->core_promise,
        ]));

        $scored = [];
        foreach ($all as $i => $term) {
            $overlap = count(array_intersect($this->tokenize($term), $offerTokens));
            $scored[] = ['term' => $term, 'overlap' => $overlap, 'rank' => $i];
        }
        usort($scored, static fn ($a, $b): int => ($b['overlap'] <=> $a['overlap']) ?: ($a['rank'] <=> $b['rank']));

        // keep the most-relevant, but guarantee at least one umbrella (highest-conversion) winner.
        $picked = array_values(array_unique(array_map(static fn ($s): string => $s['term'], array_slice($scored, 0, $limit))));
        if (! in_array($all[0], $picked, true) && count($picked) >= $limit && $limit > 1) {
            $picked[$limit - 1] = $all[0];
        }

        return $picked;
    }

    /**
     * Recall of the buying-traffic vocabulary: share of the top converting keywords whose core term
     * appears above the fold. The honest "does the headline speak the searcher's language?" number.
     *
     * @param  array<int,string>  $keywords
     * @return array<string,mixed>
     */
    private function keywordCoverage(string $aboveFold, array $keywords): array
    {
        if ($keywords === []) {
            return ['score' => 0, 'covered' => [], 'total' => 0, 'note' => 'sem padrão de keywords para este nicho'];
        }
        $hay = ' '.implode(' ', $this->tokenize($aboveFold)).' ';
        $covered = [];
        foreach ($keywords as $kw) {
            $core = array_filter($this->tokenize($kw), static fn (string $t): bool => ! in_array($t, ['weight', 'loss', 'recipe', 'diet', 'for'], true));
            foreach (($core ?: $this->tokenize($kw)) as $tok) {
                if (str_contains($hay, ' '.$tok.' ')) {
                    $covered[] = $kw;
                    break;
                }
            }
        }
        $covered = array_values(array_unique($covered));
        $score = (int) round(count($covered) / count($keywords) * 100);

        return [
            'score' => $score,
            'covered' => $covered,
            'total' => count($keywords),
            'note' => $score >= 40
                ? 'A headline/above-fold fala a língua das keywords que VENDEM — congruência de busca forte.'
                : 'A headline cobre poucas keywords campeãs — aproximar do vocabulário de busca pode subir o CTR/EPC.',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $s): array
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9\s-]/u', ' ', $s) ?? $s;
        $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'on', 'with', 'is', 'de', 'da', 'do', 'e', 'que', 'para', 'com', 'um', 'uma', 'natural', 'home'];

        return array_values(array_unique(array_filter(
            preg_split('/\s+/', trim($s)) ?: [],
            static fn (string $w): bool => strlen($w) > 2 && ! in_array($w, $stop, true),
        )));
    }

    private function resolveLanguage(AiMarketingVslAsset $asset, ?string $explicit): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return trim($explicit);
        }
        $geo = strtolower((string) $asset->target_geo.' '.$asset->language);
        if (str_contains($geo, 'en') || str_contains($geo, 'us') || str_contains($geo, 'english') || str_contains($geo, 'uk')) {
            return 'English (US)';
        }
        if (str_contains($geo, 'pt') || str_contains($geo, 'br') || str_contains($geo, 'portugu')) {
            return 'Português (BR)';
        }

        return 'English (US)';
    }

    private function defaultAngle(AiMarketingVslAsset $asset): string
    {
        return trim((string) $asset->mechanism_name)
            ?: trim((string) $asset->big_idea)
            ?: trim((string) $asset->core_promise)
            ?: 'o mecanismo único da oferta';
    }

    private function defaultBrand(AiMarketingVslAsset $asset): string
    {
        return match ($asset->niche) {
            'weight_loss' => 'The Daily Wellness Report',
            'diabetes' => 'Metabolic Health Today',
            default => 'Health Insider',
        };
    }

    /**
     * Guarantee every slot the renderer/guard expects exists, and force all CTAs to the VSL.
     *
     * @param  array<string,mixed>  $bridge
     * @return array<string,mixed>
     */
    private function normalize(array $bridge, AiMarketingVslAsset $asset, string $angle, string $language): array
    {
        $bridge['schema_version'] = 'atlas.vsl.bridge.v1';
        $bridge['meta'] = is_array($bridge['meta'] ?? null) ? $bridge['meta'] : [];
        $bridge['meta']['lang'] = $bridge['meta']['lang'] ?? $language;
        $bridge['meta']['slug'] = $bridge['meta']['slug'] ?? Str::slug(Str::limit((string) ($bridge['headline'] ?? $angle), 60, ''));
        $bridge['headline'] = (string) ($bridge['headline'] ?? $angle);
        $bridge['angle_used'] = $bridge['angle_used'] ?? $angle;
        $bridge['awareness_target'] = $bridge['awareness_target'] ?? $asset->awareness_level;

        foreach (['trust_bar', 'body_sections', 'objection_flips', 'cta_blocks'] as $listKey) {
            if (! is_array($bridge[$listKey] ?? null)) {
                $bridge[$listKey] = [];
            }
        }

        // ONE goal: every CTA target is the VSL embed anchor.
        $bridge['hero_cta'] = is_array($bridge['hero_cta'] ?? null) ? $bridge['hero_cta'] : ['label' => 'Watch the free presentation →'];
        $bridge['hero_cta']['target'] = '#vsl';
        $bridge['cta_blocks'] = array_map(static function ($c): array {
            $c = is_array($c) ? $c : ['label' => (string) $c];
            $c['target'] = '#vsl';

            return $c;
        }, $bridge['cta_blocks']);
        if ($bridge['cta_blocks'] === []) {
            $bridge['cta_blocks'][] = ['label' => (string) $bridge['hero_cta']['label'], 'target' => '#vsl'];
        }

        return $bridge;
    }

    // ---- provider plumbing (same governed pattern as the extractor) ------------------------

    /**
     * @return array<string,mixed>
     */
    private function callModel(string $system, string $user, string $schemaVersion, int $timeoutSeconds = 600): array
    {
        $lastError = 'unknown error';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $payload = $attempt === 1
                ? $user
                : $user."\n\nIMPORTANTE: a resposta anterior não pôde ser lida. Responda SOMENTE com UM bloco JSON VÁLIDO e COMPLETO (sem truncar, sem texto fora do bloco).";

            $job = new AiJob([
                'trace_id' => (string) Str::ulid(),
                'kind' => 'vsl_bridge_page_composition',
                'status' => 'pending',
                'input_text' => $payload,
                'prompt' => $system,
                'payload' => ['model_identity_source' => 'provider_default_identity'],
                'timeout_seconds' => $timeoutSeconds,
            ]);
            $job->id = (string) Str::uuid();

            $result = $this->providers->get()->run($job, $system."\n\n".$payload);
            if (! $result->ok) {
                $lastError = $result->errorMessage ?: ($result->stderr ?: 'provider error');

                continue;
            }
            $this->lastModel = (is_string($result->metadata['model'] ?? null) ? $result->metadata['model'] : null) ?? $this->lastModel ?? 'hermes_cli';

            $parsed = $this->parseJson($result->output, $schemaVersion);
            if ($parsed !== null) {
                return $parsed;
            }
            $lastError = "could not parse JSON ({$schemaVersion})";
        }

        throw new RuntimeException('Bridge composition failed: '.$lastError);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseJson(string $output, string $schemaVersion): ?array
    {
        $blocks = [];
        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/isu', $output, $m)) {
            foreach ($m[1] as $b) {
                $blocks[] = trim($b);
            }
        }
        $trimmed = trim($output);
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $blocks[] = $trimmed;
        }
        $first = strpos($output, '{');
        $last = strrpos($output, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $blocks[] = substr($output, $first, $last - $first + 1);
        }

        $fallback = null;
        foreach (array_values(array_unique($blocks)) as $block) {
            try {
                $decoded = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['schema_version'] ?? null) === $schemaVersion) {
                return $decoded;
            }
            $fallback ??= $decoded;
        }

        return $fallback;
    }
}
