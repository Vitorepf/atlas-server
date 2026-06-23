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

            // ── Aprofundamento Volta 2: hooks/leads raros dos mestres ──────────────────────────
            ['key' => 'hook_one_sentence_movie', 'name' => 'Cena em uma frase (Bencivenga)', 'category' => 'hook', 'weight' => 5,
                'trigger' => 'Uma frase que filma a cena (cheiro/som/luz) na cabeça do leitor — Bencivenga chamava de "movie in a sentence".',
                'lever' => 'Substantivo concreto + verbo sensorial + 1 detalhe ímpar — "Ela apertou o cinto da poltrona 12C".',
                'markers' => ['opened the ', 'closed the ', 'stared at ', 'reached for ', 'stood at ', 'walked into ', 'abriu o ', 'fechou o ', 'encarou ', 'olhou para ', 'on a tuesday', 'numa terça', 'in seat ', 'no banco ', 'the smell of', 'o cheiro de']],
            ['key' => 'hook_unfinished_confession', 'name' => 'Confissão incompleta', 'category' => 'hook', 'weight' => 5,
                'trigger' => 'Confissão pessoal cortada no meio (cliffhanger íntimo) puxa o leitor pra dentro — "preciso saber o final".',
                'lever' => 'Comece a confessar algo grave, pare antes do clímax, prometa o resto no vídeo.',
                'markers' => ['i did something', 'eu fiz algo', "i'm about to admit", 'vou admitir', 'this is hard to', 'isto é difícil de', 'never told anyone', 'nunca contei a ninguém', "i'm not proud", 'não me orgulho']],
            ['key' => 'hook_strange_juxtaposition', 'name' => 'Justaposição estranha', 'category' => 'hook', 'weight' => 4,
                'trigger' => 'Duas coisas que não combinam ("milionário + sem-teto", "médico que come fast-food") forçam o cérebro a buscar a explicação.',
                'lever' => '"O endocrinologista que come pizza todo dia", "a dona de casa que ganha mais que o marido".',
                'markers' => ['the doctor who', 'o médico que', 'the millionaire who', 'o milionário que', 'the trainer who', 'o personal que', 'lives in ', 'mora num ', 'still ', 'ainda ', "you wouldn't believe", "você não acreditaria"]],
            ['key' => 'hook_named_avatar', 'name' => 'Avatar nomeado (Carlton)', 'category' => 'hook', 'weight' => 4,
                'trigger' => 'Nomear o avatar logo de cara ("Karen, 47, em Phoenix") cria identificação tribal instantânea.',
                'lever' => '"Karen, 47, in Phoenix, was about to give up when…" — nome + idade + cidade + verbo de tensão.',
                'markers' => ['/\b[A-Z][a-z]+,?\s+\d{2},?\s+(in|em|de)\s+[A-Z][a-z]+/u', '/\bMs\.?\s+[A-Z][a-z]+\b/u', '/like\s+[A-Z][a-z]+/u', 'meet ', 'conheça ', 'this is ', 'esta é ']],
            ['key' => 'lead_invitation_only', 'name' => 'Lead "só para quem"', 'category' => 'lead', 'weight' => 4,
                'trigger' => 'Convite condicional ("se você é X, isto é pra você. Se não, feche") expulsa quem não compra e cola quem compra.',
                'lever' => '"Se você ganha menos de R$ X / não tem Y / não acredita em Z, pare de ler agora".',
                'markers' => ['this is not for', 'isto não é para', 'only for ', 'apenas para ', 'if not, close', 'se não, feche', "if you don't", 'se você não', 'walk away if', 'vá embora se', 'reserved for ', 'reservado para ']],
            ['key' => 'lead_letter_format', 'name' => 'Lead em formato de carta', 'category' => 'lead', 'weight' => 4,
                'trigger' => 'Carta pessoal (Dear X, Sincerely Y) ativa o modo "isso é pessoal, vou ler" — Halbert.',
                'lever' => '"Dear friend," / "Caro leitor," + cabeçalho + assinatura no fim.',
                'markers' => ['dear ', 'caro ', 'cara ', 'sincerely', 'atenciosamente', 'your friend,', 'seu amigo,', "p.s. ", 'p.s.', "from my desk", 'da minha mesa']],
            ['key' => 'lead_diary_entry', 'name' => 'Lead em formato de diário', 'category' => 'lead', 'weight' => 3,
                'trigger' => 'Entrada de diário com data dá tom íntimo + voyeurístico — o leitor sente que invadiu.',
                'lever' => '"Tuesday, 11:47pm. Just came back from the doctor."',
                'markers' => ['/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|segunda|terça|quarta|quinta|sexta|sábado|domingo),?\s+\d{1,2}/iu', 'diary entry', 'entrada do diário', 'journal entry', 'tonight i ', 'esta noite eu ']],
            ['key' => 'hook_uncomfortable_truth', 'name' => 'Verdade desconfortável', 'category' => 'hook', 'weight' => 4,
                'trigger' => 'Dizer algo que o leitor secretamente sabe mas ninguém fala em voz alta cria "finalmente alguém disse".',
                'lever' => 'Diga o tabu: "depois dos 40, a maioria desiste em silêncio", "ninguém ama emagrecer".',
                'markers' => ['nobody talks about', 'ninguém fala sobre', 'the dirty secret', 'o segredo sujo', 'we all know', 'todos sabemos', "let's be honest", 'sejamos honestos', 'taboo', 'tabu', 'the unspoken']],
            ['key' => 'lead_question_chain', 'name' => 'Lead em cadeia de perguntas', 'category' => 'lead', 'weight' => 3,
                'trigger' => 'Sequência de 3-5 perguntas (todas SIM da avatar) cria momentum de compromisso — Cialdini consistência.',
                'lever' => '"Tem mais de 40? Já tentou X? E Y? Sente que Z? Então isto é pra você."',
                'markers' => ['/are you\s+\w[\w\s]{0,30}\?[\s\S]{0,80}are you/iu', '/você\s+\w[\w\s]{0,30}\?[\s\S]{0,80}você/iu', 'do you ', 'você ', 'have you tried', 'já tentou', 'do you feel', 'você sente']],
            ['key' => 'hook_curiosity_gap_specific', 'name' => 'Gap de curiosidade específico', 'category' => 'hook', 'weight' => 5,
                'trigger' => 'Prometer revelação ESPECÍFICA ("o motivo nº1") + ocultar = curiosidade insuportável (BuzzFeed).',
                'lever' => '"O ingrediente nº 3 da sua dieta sabotando você", "a hora exata que sabota seu metabolismo".',
                'markers' => ['/\bthe #?\d+\s+(reason|ingredient|mistake|food|word|hour)/iu', '/o\s+#?\d+\s+(motivo|ingrediente|erro|alimento|palavra)/iu', 'the one thing', 'a única coisa', 'the missing piece', 'a peça que falta']],
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
