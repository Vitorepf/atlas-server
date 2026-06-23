<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * HookLeadLibrary — the opening machine. Two halves: the HOOK (the 3-second pattern-interrupt
 * formulas that stop the scroll) and the LEAD (the 6 canonical opening archetypes from John Carlton
 * / Bencivenga that earn the right to be read). Get this wrong and nothing else fires — get it right
 * and the rest of the funnel has a chance. Content-independent: hook formulas and lead archetypes
 * sell ANY product if matched to the awareness level (pairs with AwarenessRouter).
 */
class HookLeadLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'hook_lead';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── HOOK formulas (the first 3 seconds) ─────────────────────────────────────────────
            ['key' => 'hook_callout_specific', 'name' => 'Callout específico (Halbert)', 'category' => 'hook', 'weight' => 5,
                'trigger' => 'O leitor para quando reconhece a si mesmo no anúncio — calling out the specific avatar.',
                'lever' => '"If you are X over Y who tried Z…" — quanto mais específico, mais para o scroll.',
                'markers' => ['if you are', 'se você é', 'attention ', 'atenção ', 'this is for', 'isto é para', 'who tried', 'que tentou', 'over 40', 'acima dos 40', 'struggling with', 'lutando com']],
            ['key' => 'hook_warning', 'name' => 'Aviso/alerta', 'category' => 'hook', 'weight' => 4,
                'trigger' => 'Linguagem de alerta dispara atenção de sobrevivência — não dá pra ignorar.',
                'lever' => '"AVISO:", "PARE:", "Antes de fazer X, leia…" — autoridade implícita.',
                'markers' => ['warning', 'aviso', 'stop ', 'pare ', 'before you ', 'antes de você ', 'do not ', 'não faça ', 'urgent', 'urgente', 'red flag', 'sinal vermelho']],
            ['key' => 'hook_question', 'name' => 'Pergunta provocativa', 'category' => 'hook', 'weight' => 4,
                'trigger' => 'Pergunta direta força o cérebro a buscar resposta — abre loop instantâneo.',
                'lever' => 'Pergunta que pinta a dor + sugere que tem resposta nova.',
                'markers' => ['do you ', 'você ', 'have you ever', 'você já', 'what if', 'e se', 'why does', 'por que', 'ever wonder', 'já se perguntou', 'tired of', 'cansad']],
            ['key' => 'hook_shocking_stat', 'name' => 'Estatística chocante', 'category' => 'hook', 'weight' => 4,
                'trigger' => 'Número grande/contra-intuitivo gera reação cognitiva — "isso não pode ser".',
                'lever' => '"X% das mulheres..." — número específico não-redondo + contexto chocante.',
                'markers' => ['/\b\d{1,2}%\s+of\s+(women|men|people)/iu', '/\b\d{1,2}%\s+das\s+(mulheres|pessoas)/iu', '9 out of 10', '9 em cada 10', 'studies show', 'estudos mostram', 'more than half', 'mais da metade']],
            ['key' => 'hook_contrarian', 'name' => 'Afirmação contrária', 'category' => 'hook', 'weight' => 5,
                'trigger' => 'Afirmação que contradiz o senso comum cria choque cognitivo — "espera, o quê?".',
                'lever' => '"Tudo que você sabe sobre X está errado" / "X não é o problema".',
                'markers' => ['is wrong', 'está errado', 'is a lie', 'é mentira', 'never been about', 'nunca foi sobre', 'forget everything', 'esqueça tudo', "isn't the problem", 'não é o problema', 'opposite of']],
            ['key' => 'hook_news_event', 'name' => 'Gancho de notícia/evento', 'category' => 'hook', 'weight' => 3,
                'trigger' => 'Tom de notícia/breaking dispara curiosidade jornalística (advertorial sweet spot).',
                'lever' => '"Vazou", "Reportagem revela", "Anunciado hoje", data específica.',
                'markers' => ['breaking', 'urgente', 'just announced', 'anunciado hoje', 'this week', 'esta semana', 'leaked', 'vazad', 'report reveals', 'reportagem revela', 'new study', 'novo estudo']],
            ['key' => 'hook_story_open', 'name' => 'Abertura de história (in media res)', 'category' => 'hook', 'weight' => 4,
                'trigger' => 'Começar no meio da cena tensa puxa o leitor para dentro antes de explicar nada.',
                'lever' => '"Estava na sala do médico quando…", "Foi às 3 da manhã quando…" — cena+tensão.',
                'markers' => ['it was ', 'era ', 'i was ', 'eu estava ', 'when it happened', 'quando aconteceu', 'one day', 'um dia', 'that morning', 'naquela manhã', "i'll never forget", 'nunca vou esquecer']],

            // ── LEAD archetypes (Bencivenga's 6, expanded) ──────────────────────────────────────
            ['key' => 'lead_offer', 'name' => 'Lead de oferta', 'category' => 'lead', 'weight' => 4,
                'trigger' => 'Tráfego most-aware/product-aware compra com a oferta direta — não precisa de história.',
                'lever' => 'Lidere com o que ela ganha, preço, garantia, escassez. Curto, direto.',
                'markers' => ['get the ', 'leve o ', 'today only', 'só hoje', 'claim your', 'garanta seu', 'order now', 'compre agora', 'special offer', 'oferta especial', 'limited deal']],
            ['key' => 'lead_promise', 'name' => 'Lead de promessa (big benefit)', 'category' => 'lead', 'weight' => 4,
                'trigger' => 'Solution-aware quer ver o benefício claro logo — promessa específica + crível.',
                'lever' => '"Como [resultado específico] em [prazo específico] sem [dor]" — fórmula clássica.',
                'markers' => ['/how to .+ in \d+ /iu', '/como .+ em \d+ /iu', 'finally ', 'finalmente ', 'lose ', 'perca ', 'discover the', 'descubra como', 'the secret to', 'o segredo de']],
            ['key' => 'lead_problem_solution', 'name' => 'Lead problema-solução', 'category' => 'lead', 'weight' => 4,
                'trigger' => 'Problem-aware se identifica com a dor; revelar a solução depois é o salto natural.',
                'lever' => 'Agitação da dor → reframe da causa → revelação do mecanismo.',
                'markers' => ['the real reason', 'a verdadeira razão', "here's what", 'eis o que', 'turns out', 'acontece que', 'the truth is', 'a verdade é que', 'breakthrough', 'descoberta']],
            ['key' => 'lead_secret', 'name' => 'Lead do segredo', 'category' => 'lead', 'weight' => 5,
                'trigger' => 'Curiosidade + exclusividade — "o segredo dos que conseguem" é a fórmula mais conversiva do direto.',
                'lever' => 'Prometa revelar o segredo/método/truque + faça o leitor sentir que ele NÃO sabe.',
                'markers' => ['the secret', 'o segredo', 'hidden ', 'oculto ', 'unknown ', 'desconhecido ', 'what the wealthy', 'o que os ricos', 'insiders ', 'de dentro ', 'never told', 'nunca contaram']],
            ['key' => 'lead_story', 'name' => 'Lead de história', 'category' => 'lead', 'weight' => 5,
                'trigger' => 'Tráfego cético/unaware baixa a guarda numa história — entra pela emoção, não pelo argumento.',
                'lever' => 'Conte a queda + reviravolta de alguém igual ao leitor; a revelação vem no meio.',
                'markers' => ['my story', 'minha história', "she was", 'ela era', 'rock bottom', 'fundo do poço', "that's when ", 'foi quando ', 'changed everything', 'mudou tudo', "didn't believe it", 'não acreditei']],
            ['key' => 'lead_proclamation', 'name' => 'Lead da proclamação', 'category' => 'lead', 'weight' => 3,
                'trigger' => 'Declaração ousada/audaciosa posiciona como líder — só quem tem prova faz isso.',
                'lever' => '"Vou te mostrar como…", "Acabou a era de…", "A maior descoberta em décadas".',
                'markers' => ['biggest breakthrough', 'maior descoberta', 'never before', 'pela primeira vez', 'i guarantee', 'eu garanto', 'the end of', 'o fim de', 'a new era', 'uma nova era', 'in just minutes']],
            ['key' => 'lead_proof', 'name' => 'Lead de prova', 'category' => 'lead', 'weight' => 4,
                'trigger' => 'Em mercados saturados de claim, abrir com prova esmagadora vira o ângulo único.',
                'lever' => 'Estatística + autoridade + caso na primeira linha — antes de qualquer promessa.',
                'markers' => ['proven by', 'comprovado por', 'study showed', 'estudo mostrou', 'patients in ', 'pacientes em ', 'doctors at', 'médicos em', 'published in', 'publicado em', 'as reported']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['hook', 'lead'];
    }
}
