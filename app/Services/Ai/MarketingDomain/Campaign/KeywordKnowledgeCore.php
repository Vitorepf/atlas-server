<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordKnowledgeCore (L0) — the canonical, VERSIONED knowledge body of the keyword discipline,
 * crystallized in deterministic provider-free code. It is what the operator asked for literally:
 * "juntar TODO o conhecimento do mundo que faz vender mais na busca, destilar o extrato mais poderoso".
 * Every other engine in the OS can CITE a law by id, giving each decision PROVENANCE (the seed of the
 * L12 Decision-Receipt) — so a verdict is never a black box, it traces to a sourced, dated law.
 *
 * Each entry carries its SOURCE + verified date, so when Google's mechanics change the entry can be
 * re-validated against the same URL — the knowledge is a living body, not static prose. Facts here were
 * web-verified in the keyword-decision report (docs/affiliate-mastery/search-network-keyword-decision-report.md).
 *
 * Kinds: google_mechanic (official docs) · master_principle (the fathers) · decision_rule (math/law).
 */
class KeywordKnowledgeCore
{
    public const VERSION = '2026-06-24'; // +ai-max-keywordless-2025 (pesquisa web verificada na doc Google)

    /** @return array<int,array{id:string,layer:string,kind:string,topic:array<int,string>,statement:string,source:string,verified:string}> */
    public function entries(): array
    {
        return [
            ['id' => 'qs-not-auction-input', 'layer' => 'L3', 'kind' => 'google_mechanic', 'topic' => ['quality_score', 'bidding'],
                'statement' => 'Quality Score (1-10) NÃO é input do leilão; o Ad Rank usa qualidade auction-time (expected CTR + ad relevance + landing page experience) recalculada a cada busca. Otimizar o número do painel = Goodhart.',
                'source' => 'https://support.google.com/google-ads/answer/6167118', 'verified' => '2025'],
            ['id' => 'priority-exact-identical', 'layer' => 'L7', 'kind' => 'google_mechanic', 'topic' => ['match_type', 'structure'],
                'statement' => 'Priorização: keyword exact IDÊNTICA à query > phrase/broad idêntica (2021) > relevância-AI do ad group > Ad Rank. Keywords do mesmo domínio NÃO competem entre si.',
                'source' => 'https://support.google.com/google-ads/answer/2756257', 'verified' => '2025'],
            ['id' => 'exact-by-intent', 'layer' => 'L7', 'kind' => 'google_mechanic', 'topic' => ['match_type'],
                'statement' => 'Exact match casa por SIGNIFICADO/INTENÇÃO (close variants: sinônimos, função-words, paráfrases), não literal; intenção divergente quebra o match. A intenção é a unidade de match, não a string.',
                'source' => 'https://support.google.com/google-ads/answer/9342105', 'verified' => '2025'],
            ['id' => 'broad-default-2024', 'layer' => 'L7', 'kind' => 'google_mechanic', 'topic' => ['match_type', 'bidding'],
                'statement' => 'Broad é o DEFAULT em campanhas novas desde jul/2024 e exige Smart Bidding; a expansão usa sinais do usuário + a landing + as outras keywords do ad group.',
                'source' => 'https://support.google.com/google-ads/answer/7478529', 'verified' => '2025'],
            ['id' => 'negatives-dumber', 'layer' => 'L6', 'kind' => 'google_mechanic', 'topic' => ['negatives'],
                'statement' => 'Negativas NÃO casam close variants/sinônimos/plurais (enumere as formas à mão) e não atuam após a 16ª palavra. Negativa broad é a mais forte; exact a mais fraca.',
                'source' => 'https://support.google.com/google-ads/answer/2453972', 'verified' => '2025'],
            ['id' => 'smart-bidding-threshold', 'layer' => 'L8', 'kind' => 'google_mechanic', 'topic' => ['bidding', 'scale'],
                'statement' => 'Smart Bidding precisa ~30 conv/mês (tCPA) / ~50 (tROAS) pra avaliar. Abaixo disso, broad+Smart Bidding queima 30-50% do budget em modo exploratório; afiliado começa ABAIXO → exact/phrase controlado.',
                'source' => 'https://support.google.com/google-ads/answer/7065882', 'verified' => '2025'],
            ['id' => 'value-based-bidding-2026', 'layer' => 'L8', 'kind' => 'google_mechanic', 'topic' => ['bidding', 'measurement', 'scale'],
                'statement' => 'Value-Based Bidding (2026, pesquisa web verificada): tROAS virou o DEFAULT/preferido sobre tCPA pra contas com dado de conversão (jun/2026 "Max conv value c/ tROAS" → renomeado só "Target ROAS"; update do sistema 17/ago/2026 pode causar flutuação temporária). O jogo: feedar VALOR (receita por venda), NÃO só CONTAGEM de conversão — o Smart Bidding otimiza o valor TOTAL (+14% conv-value migrando tCPA→tROAS). "Revenue by keyword" (sort por conversion value) = o relatório que mostra EXATAMENTE quais search-terms trazem dinheiro real (o norte). Conversion value rules ajustam valor por geo/device/audiência em auction-time. Pré-condição: GCLID→CRM→upload GCLID+VALOR; ramp ~4 semanas; tROAS inicial 20% abaixo do histórico. Afiliado: feedar o payout REAL por venda (não flat) faz o algoritmo caçar a keyword de maior RECEITA, não de maior contagem — é onde o KeywordRevenueProjector entrega o valor projetado.',
                'source' => 'https://support.google.com/google-ads/answer/6268637', 'verified' => '2026'],
            ['id' => 'long-tail-aggregate', 'layer' => 'L5', 'kind' => 'master_principle', 'topic' => ['volume', 'scale'],
                'statement' => 'O long-tail = ~70% de TODO o volume de busca, mas NÃO concentra por termo: o volume mora na SOMA de MILHARES de termos de baixo volume individual, não em poucas head terms; e converte ~36% vs broad no chão (pesquisa web 2026). Logo a receita MÁXIMA não vem de caçar head terms — vem de GERAR e capturar a cauda INTEIRA (cone de mistype + matriz de descritor + variantes), cujo AGREGADO é onde o dinheiro está. É o que destrava "escalar MILHÕES": o OS é uma FÁBRICA de long-tail (PhoneticMistypeForge/DescriptorMatrixForge geram centenas de variantes por âncora) e o KeywordRevenueProjector soma o LUCRO do agregado, não de 5 keywords. Corolário: nunca podar a cauda por "volume baixo por termo" — o valor é a soma.',
                'source' => 'https://almcorp.com/blog/keyword-search-volume/', 'verified' => '2026'],
            ['id' => 'dda-default-2023', 'layer' => 'L9', 'kind' => 'google_mechanic', 'topic' => ['attribution'],
                'statement' => 'Data-Driven Attribution é o default desde mid-2023 (sem mínimo de dados). Comparar last-click vs DDA por keyword revela a subvalorizada (assist real) — não cortar quem alimenta a campeã.',
                'source' => 'https://blog.google/products/ads-commerce/data-driven-attribution-new-default/', 'verified' => '2025'],
            ['id' => 'restricted-drug-suspension', 'layer' => 'L11', 'kind' => 'google_mechanic', 'topic' => ['account_risk', 'policy'],
                'statement' => 'Restricted drug terms (semaglutide/tirzepatide/retatrutide/GLP-1) em keyword/copy/landing sem LegitScript = SUSPENSÃO de conta (escala a domain-flagging). A keyword de maior intenção do nicho é a mais perigosa.',
                'source' => 'https://support.google.com/adspolicy/answer/176031', 'verified' => '2025'],
            ['id' => 'offline-conversion-upstream', 'layer' => 'L9', 'kind' => 'decision_rule', 'topic' => ['measurement', 'scale'],
                'statement' => 'PRÉ-CONDIÇÃO upstream de TUDO: a venda acontece na página do anunciante; sem GCLID→postback→offline import (Enhanced Conversions for Leads), nenhuma keyword tem dado e o Smart Bidding aprende com lixo. ATUALIZADO 2026: desde 15/jun/2026 a Google Ads API NÃO aceita NOVOS adotantes de offline import via UploadClickConversions — só developer-tokens que já importavam (dez/2025–mai/2026) seguem, temporário, enquanto migram; novo adotante recebe erro CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE. Caminho de ingestão agora = Data Manager API (a MEDIÇÃO não acabou, só o path). Afiliado começando hoje vai DIRETO pra Data Manager API.',
                'source' => 'https://ads-developers.googleblog.com/2026/05/changes-to-offline-click-conversion.html', 'verified' => '2026'],
            ['id' => 'rule-of-three', 'layer' => 'L4', 'kind' => 'decision_rule', 'topic' => ['investment', 'significance'],
                'statement' => 'Rule of three: com 0 conversões em n cliques, 95% de confiança que o CVR < 3/n. Corte = ceil(3 / breakeven_cvr) cliques de prova sem venda → GASTO.',
                'source' => 'https://en.wikipedia.org/wiki/Rule_of_three_(statistics)', 'verified' => 'clássico'],
            ['id' => 'breakeven-epc', 'layer' => 'L4', 'kind' => 'decision_rule', 'topic' => ['investment', 'economics'],
                'statement' => 'Breakeven CVR = CPC / net_payout. Keyword é INVESTIMENTO sse EPC > CPC (supera o tCPA, que ignora o payout). Janela 7-30d / amostra estável antes de decidir.',
                'source' => 'https://www.clickbank.com/blog/what-is-earnings-per-click-aka-epc/', 'verified' => '2025'],
            ['id' => 'exclusion-over-attraction', 'layer' => 'L6', 'kind' => 'decision_rule', 'topic' => ['negatives', 'economics'],
                'statement' => 'Sob budget-cap, REMOVER o desqualificado rende MAIS que atrair (devolve o CPC inteiro pro budget recomprar urna boa) E protege o Smart Bidding de aprender conversão barata-errada. Negativa é infraestrutura, não faxina.',
                'source' => 'docs/affiliate-mastery/search-network-keyword-decision-report.md#4', 'verified' => '2025'],
            ['id' => 'expected-ctr-king', 'layer' => 'L3', 'kind' => 'master_principle', 'topic' => ['quality_score'],
                'statement' => 'Brad Geddes: expected CTR é DE LONGE o fator mais importante do Quality Score; a única alavanca que o anunciante controla pra baratear a keyword é o CTR, que sobe quando o anúncio espelha a keyword (message-match).',
                'source' => 'Brad Geddes — Advanced Google AdWords', 'verified' => 'obra'],
            ['id' => 'pareto-peel-stick', 'layer' => 'L7', 'kind' => 'master_principle', 'topic' => ['structure', 'scale'],
                'statement' => 'Perry Marshall: 80/20 fractal (20% das keywords geram 80% das conversões → isolar e blindar a campeã) + peel-and-stick com negative-sculpting (descascar a vencedora pra ad group próprio + negative-exact na origem).',
                'source' => 'Perry Marshall — Ultimate Guide to Google Ads', 'verified' => 'obra'],
            ['id' => 'schwartz-awareness', 'layer' => 'L3', 'kind' => 'master_principle', 'topic' => ['intent', 'mind'],
                'statement' => 'Eugene Schwartz: 5 estágios de consciência (unaware→problem→solution→product→most-aware). Na busca, a keyword É o awareness pré-classificado de graça — comprar a keyword certa = comprar o estágio certo.',
                'source' => 'Eugene Schwartz — Breakthrough Advertising', 'verified' => 'obra'],
            ['id' => 'text-intent-ceiling', 'layer' => 'L3', 'kind' => 'decision_rule', 'topic' => ['intent'],
                'statement' => 'Classificar intenção só pelo texto tem teto de ~74% vs humano (Jansen/Booth/Spink 2008, 1,5M queries). O rótulo é PRIOR fraco; a SERP ao vivo é o oráculo. Intenção é DISTRIBUIÇÃO, não rótulo único.',
                'source' => 'https://dl.acm.org/doi/10.1016/j.ipm.2007.07.015', 'verified' => '2008'],
            ['id' => 'ai-max-keywordless-2025', 'layer' => 'L2', 'kind' => 'google_mechanic', 'topic' => ['match_type', 'account_risk', 'scale'],
                'statement' => 'AI Max for Search (mai/2025): upgrade 1-clique que liga search-term matching = broad + KEYWORDLESS — expande além das keywords explícitas analisando a landing/VSL + criativos + URL (+ final-URL-expansion + text-customization). Uplift típico 27% em contas mais exact/phrase. PORÉM cede o controle de keyword e a LANDING passa a dirigir a expansão. Para afiliado: (a) keywordless gasta como broad abaixo do limiar 30-conv (queima exploração); (b) a VSL dirigindo a expansão ALARGA a superfície de droga-restrita (Google expande pra termos do conteúdo). Controles que viram a alavanca (verificado na doc): desligar search-term-matching (no ad group) e final-URL-expansion; brand inclusions/exclusions; URL inclusions/exclusions; locations of interest; negativas ainda valem, mas o sistema empurra exclusões de brand/URL como a precisão "que antes era keyword". Opt-in de olhos abertos, NÃO default p/ afiliado sub-limiar.',
                'source' => 'https://support.google.com/google-ads/answer/15910187', 'verified' => '2025'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function byLayer(string $layer): array
    {
        return array_values(array_filter($this->entries(), fn ($e) => $e['layer'] === $layer));
    }

    /** @return array<int,array<string,mixed>> */
    public function byTopic(string $topic): array
    {
        $t = mb_strtolower(trim($topic));

        return array_values(array_filter($this->entries(), fn ($e) => in_array($t, $e['topic'], true)));
    }

    /** Cite a law by id for a Decision-Receipt (provenance). @return array<string,mixed>|null */
    public function cite(string $id): ?array
    {
        foreach ($this->entries() as $e) {
            if ($e['id'] === $id) {
                return ['id' => $e['id'], 'statement' => $e['statement'], 'source' => $e['source'], 'verified' => $e['verified'], 'core_version' => self::VERSION];
            }
        }

        return null;
    }

    /**
     * Gate "fonte-mudou" (L0 — a peça que faltava) — o corpo de conhecimento é VIVO: mecânicas DATADAS do
     * Google envelhecem e precisam ser re-validadas contra a doc oficial; leis de math/psicologia (rule-of-
     * three, Schwartz, 'clássico'/'obra') são ATEMPORAIS e nunca entram na fila de revisão. Surfacia o que
     * checar, mais velho primeiro — é o que mantém o L0 honesto ao longo do tempo, não foto fixa.
     *
     * @return array<int,array{id:string,verified:string,years_old:int}>
     */
    public function needsReview(int $currentYear, int $staleAfterYears = 2): array
    {
        $out = [];
        foreach ($this->entries() as $e) {
            $year = (int) preg_replace('/\D/', '', (string) $e['verified']);
            if ($e['kind'] === 'google_mechanic' && $year > 0 && ($currentYear - $year) > $staleAfterYears) {
                $out[] = ['id' => $e['id'], 'verified' => $e['verified'], 'years_old' => $currentYear - $year];
            }
        }
        usort($out, fn ($a, $b) => $b['years_old'] <=> $a['years_old']);

        return $out;
    }
}
