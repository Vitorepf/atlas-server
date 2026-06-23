<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * NarrativeVoiceLibrary — what makes copy SLIDE. Same information, different voice = 3× time on page.
 * This is the layer of storytelling, rhythm, sensory specificity, the "bucket brigade" connectors
 * that drag the reader forward (Sugarman's slippery slide), conversational voice, sentence-length
 * variation, the "you-focused" lens, and the cadence techniques pros use to make a sales letter
 * unputdownable. Content-independent: voice/rhythm work in ANY niche.
 */
class NarrativeVoiceLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'narrative_voice';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── Slippery slide (the bucket brigades that drag the reader to the next line) ──────
            ['key' => 'bucket_brigade', 'name' => 'Bucket brigade (Sugarman)', 'category' => 'flow', 'weight' => 5,
                'trigger' => 'Frase curta + ":" / "—" / "?" force os olhos pra próxima linha — a slippery slide.',
                'lever' => 'Use "Aqui está o porquê:" / "Mas há um problema." / "Acontece o seguinte —" entre blocos.',
                'markers' => ["here's why", 'eis o porquê', "here's the thing", 'eis a questão', 'but wait', 'mas espera', 'and that', 'e isso', 'now, ', 'agora, ', "here's what i mean", 'olha só:']],
            ['key' => 'one_idea_per_sentence', 'name' => 'Uma ideia por frase', 'category' => 'flow', 'weight' => 4,
                'trigger' => 'Frases longas perdem o leitor; frases curtas mantêm o ritmo cardíaco do scroll.',
                'lever' => 'Quebre. Cada ideia. Em sua. Própria. Linha. Variação propositada.',
                'markers' => ['/(?:^|[.!?]\s+)\w[\w\s,]{1,40}[.!?](?:\s|$)/u', '/\n\s*\w[\w\s,]{1,30}\.\s/u']],
            ['key' => 'sentence_variation', 'name' => 'Variação de comprimento', 'category' => 'flow', 'weight' => 4,
                'trigger' => 'Frases iguais entopem. Alternar curta-longa-curta gera ritmo musical que prende.',
                'lever' => 'Frase muito curta após uma longa cria pausa dramática — "E mudou tudo." depois de um parágrafo.',
                'markers' => ['/[.!?]\s+\w[\w\s]{1,15}[.!?]\s/u', '. and ', '. but ', '. mas ', '. e ', '. then ', '. então ']],

            // ── Direct address (the "you" voice) ─────────────────────────────────────────────────
            ['key' => 'you_focus', 'name' => 'Foco em "você"', 'category' => 'voice', 'weight' => 5,
                'trigger' => 'Copy que fala "eu/nosso" perde — copy de elite fala "você" o tempo todo.',
                'lever' => 'Densidade alta de "você/seu" vs "eu/nós/nosso". Cada frase pensa na cabeça dela.',
                'markers' => ['you ', 'você ', 'your ', 'seu ', 'sua ', "you've", 'você tem', "you'll", 'você vai', "you're", 'você está', 'imagine you', 'imagine que']],
            ['key' => 'conversational', 'name' => 'Tom de conversa', 'category' => 'voice', 'weight' => 4,
                'trigger' => 'Texto formal soa institucional e morre; texto de conversa cria intimidade e baixa a guarda.',
                'lever' => 'Use contrações, frases incompletas, "olha", "sabe", "honestamente", "vou ser direto".',
                'markers' => ['look,', 'olha,', 'honestly', 'honestamente', 'frankly', 'francamente', 'between you and me', 'cá entre nós', "i'll be straight", 'vou ser direto', "let's be real", 'vamos ser reais', "i'm not gonna"]],
            ['key' => 'empathy_mirror', 'name' => 'Espelhar a dor', 'category' => 'voice', 'weight' => 4,
                'trigger' => 'Quando o leitor sente "ele/ela me entende", a venda já foi metade feita — identificação.',
                'lever' => 'Reflita exatamente o que ela pensa em silêncio ("eu sei o que você está pensando…").',
                'markers' => ['i know what', 'eu sei o que', "you're probably thinking", 'você deve estar pensando', "i get it", 'eu entendo', "i've been there", 'eu já passei', "you're not alone", 'você não está sozinha']],

            // ── Sensory & concrete (paint pictures, not concepts) ───────────────────────────────
            ['key' => 'sensory_detail', 'name' => 'Detalhe sensorial', 'category' => 'imagery', 'weight' => 5,
                'trigger' => 'Detalhes concretos sensoriais (visão/som/tato/cheiro) constroem cena na mente; abstrato evapora.',
                'lever' => 'Em vez de "ela ficou triste", "ela viu o reflexo no espelho do elevador e desviou os olhos".',
                'markers' => ['the smell', 'o cheiro', 'the sound', 'o som', 'the way', 'o jeito', 'in the mirror', 'no espelho', 'the floor', 'o chão', 'the feel of', 'a sensação', 'across the room', 'na cozinha', 'in her kitchen']],
            ['key' => 'specific_numbers', 'name' => 'Especificidade radical', 'category' => 'imagery', 'weight' => 4,
                'trigger' => 'Números específicos e detalhes ímpares parecem mais verdade que arredondados ou genéricos.',
                'lever' => '"3:47 da manhã" > "de madrugada". "11.847 mulheres" > "milhares". "Casa em Naperville" > "casa".',
                'markers' => ['/\b\d{1,2}:\d{2}\s*(am|pm|da manhã|da tarde)\b/iu', '/\b\d{2,3}(,\d{3})+/u', '/\d{1,2}[.,]\d{1,2}\s+(months|years|meses|anos)/iu']],
            ['key' => 'show_dont_tell', 'name' => 'Mostre, não diga', 'category' => 'imagery', 'weight' => 4,
                'trigger' => 'Dizer "transformador" não transforma ninguém; mostrar a cena onde a transformação acontece, sim.',
                'lever' => 'Substitua adjetivo por cena: não "incrível" — "ela chorou quando o jeans fechou".',
                'markers' => ['she cried', 'ela chorou', 'tears', 'lágrimas', 'her husband said', 'o marido dela disse', 'looked at the', 'olhou para o', 'put on the', 'colocou o', 'finally fit', 'finalmente serviu']],

            // ── Pacing & momentum (the cadence that won't let you stop) ─────────────────────────
            ['key' => 'rule_of_three', 'name' => 'Regra de três', 'category' => 'rhythm', 'weight' => 3,
                'trigger' => 'Três itens criam ritmo cantado que o cérebro completa — fica grudado.',
                'lever' => '"Sem dieta, sem academia, sem agulha." / "Vergonha, culpa, frustração."',
                'markers' => ['/\b\w+,\s+\w+,\s+(?:and |e )\w+/u', '/no \w+, no \w+, no \w+/iu', '/sem \w+, sem \w+, sem \w+/iu']],
            ['key' => 'parallelism', 'name' => 'Paralelismo', 'category' => 'rhythm', 'weight' => 3,
                'trigger' => 'Estruturas paralelas criam musicalidade que o ouvido reconhece como poder retórico.',
                'lever' => '"Antes ela X. Agora ela Y." / "Eles dizem A. Eu digo B."',
                'markers' => ['before, ', 'antes, ', 'now, ', 'agora, ', 'they say', 'eles dizem', 'i say', 'eu digo', 'not because', 'não porque', 'but because', 'mas porque']],
            ['key' => 'momentum_words', 'name' => 'Palavras de momentum', 'category' => 'rhythm', 'weight' => 2,
                'trigger' => 'Conectores que empurram pra frente ("e", "então", "porque", "olha") mantêm energia.',
                'lever' => 'Use conectores ativos no início de parágrafos — não conjunções acadêmicas.',
                'markers' => ['/\n\s*and /iu', '/\n\s*but /iu', '/\n\s*so /iu', '/\n\s*because /iu', '/\n\s*e /iu', '/\n\s*mas /iu', '/\n\s*então /iu', '/\n\s*porque /iu']],

            // ── Voice signatures (the polish of pros) ──────────────────────────────────────────
            ['key' => 'callback', 'name' => 'Callback / amarração', 'category' => 'voice', 'weight' => 3,
                'trigger' => 'Voltar a uma imagem/frase plantada antes dá satisfação cognitiva (anel) — encerra com poder.',
                'lever' => 'Plantar uma imagem no início e ressuscitar no final ("o espelho que ela evitava").',
                'markers' => ['remember when', 'lembra quando', 'remember the', 'lembra do', 'back to the', 'voltando ao', 'that mirror', 'aquele espelho', 'as i said', 'como eu disse']],
            ['key' => 'metaphor_anchor', 'name' => 'Metáfora-âncora', 'category' => 'imagery', 'weight' => 3,
                'trigger' => 'Uma metáfora forte (porta dos fundos hormonal, interruptor do metabolismo) vira slogan da venda.',
                'lever' => 'Encontre UMA metáfora central e ressuscite a cada seção.',
                'markers' => ['it is like', 'é como', 'think of it as', 'pense nisso como', 'a switch', 'um interruptor', 'a backdoor', 'porta dos fundos', 'a key', 'uma chave', 'a code', 'um código']],

            // ── Aprofundamento Volta 2: voz dos mestres + técnicas raras ───────────────────────
            ['key' => 'conversational_hypnosis', 'name' => 'Hipnose conversacional (você/agora/imagine)', 'category' => 'voice', 'weight' => 4,
                'trigger' => 'Combinação de pronome direto + verbo presente + comando suave coloca a leitora em transe leve — Milton Erickson na copy.',
                'lever' => '"Imagine agora… você sente… olha pra…" — comandos suaves no presente, ela executa internamente.',
                'markers' => ['imagine for a moment', 'imagine por um momento', 'right now', 'agora mesmo', 'as you read', 'enquanto você lê', 'feel the', 'sinta a', 'notice how', 'note como', 'allow yourself', 'permita-se']],
            ['key' => 'micro_cliffhanger', 'name' => 'Micro-cliffhanger de linha', 'category' => 'flow', 'weight' => 5,
                'trigger' => 'Terminar parágrafo com "mas…" / "e foi aí que…" abre loop micro que força próximo parágrafo — Sugarman + Eugene Schwartz.',
                'lever' => 'Cada parágrafo termina apontando pro próximo. Nunca fechar tudo de uma vez.',
                'markers' => ['but ', 'mas ', '/[.!?]\s*and that\'s when\b/iu', '/[.!?]\s*e foi aí que/iu', 'until ', 'até que ', '/[.!?]\s*then\b/iu', 'wait — there', 'espera — tem', "here's where", 'é aqui que']],
            ['key' => 'anti_climax_humor', 'name' => 'Anti-clímax cômico', 'category' => 'voice', 'weight' => 3,
                'trigger' => 'Construir tensão grave e quebrar com leveza inesperada cria alívio cognitivo + diferenciação do mar de gravidade.',
                'lever' => 'Após bloco pesado, frase leve/inesperada: "spoiler: não morri".',
                'markers' => ['spoiler', 'spoiler', '(yes really)', '(sim, sério)', "i'll spare you", 'vou poupar você', 'long story short', 'resumo da ópera', 'no joke', 'sem brincadeira', "i'm not making this up"]],
            ['key' => 'voice_halbert', 'name' => 'Voz Halbert (cru, direto, populista)', 'category' => 'voice', 'weight' => 4,
                'trigger' => 'Linguagem chã, palavrões leves, atitude "eu também desconfiei" — destrói barreira de elite vs leitor.',
                'lever' => '"Olha, é o seguinte. Vou parar de enrolar. Aqui está o que importa…".',
                'markers' => ["look, here's the deal", 'olha, é o seguinte', 'cut the crap', 'cortando a besteira', 'straight up', 'sem rodeios', 'no bs', 'sem enrolação', "let's not waste", "não vamos perder", 'plain english', 'em português claro']],
            ['key' => 'voice_bencivenga', 'name' => 'Voz Bencivenga (educada, crível, autoridade calma)', 'category' => 'voice', 'weight' => 4,
                'trigger' => 'Tom de cientista calmo que confia tanto na evidência que não precisa gritar — autoridade implícita.',
                'lever' => 'Frases longas, prova densa, "como você sabe…", "como qualquer profissional confirma".',
                'markers' => ['as you know', 'como você sabe', 'any reasonable', 'qualquer pessoa razoável', 'the evidence shows', 'a evidência mostra', 'as anyone in the field', 'como qualquer profissional', "it's no secret that", 'não é segredo que', 'measured', 'mensurado']],
            ['key' => 'voice_carlton', 'name' => 'Voz Carlton (íntimo, espirituoso, conspiracional)', 'category' => 'voice', 'weight' => 4,
                'trigger' => '"Vou te contar uma coisa que ninguém mais te conta" — voz de amigo no bar, baixa a guarda total.',
                'lever' => 'Coloquialismos + "veja bem" + cumplicidade + leve transgressão.',
                'markers' => ["i'll tell you a secret", 'vou te contar um segredo', 'between you and me', 'cá entre nós', "here's the kicker", 'eis a pegadinha', 'get this', 'olha só', 'check this out', 'presta atenção nisto', 'no kidding', 'sem brincadeira']],
            ['key' => 'present_tense_immersion', 'name' => 'Tempo presente imersivo', 'category' => 'imagery', 'weight' => 4,
                'trigger' => 'Narrar no presente ("ela ABRE a porta") em vez de passado ("ela abriu") coloca a leitora dentro da cena — mais imersivo.',
                'lever' => 'Cena-chave sempre no presente. Detalhes em corte de filme.',
                'markers' => ['/\b(she|he|i|ela|ele|eu)\s+(walks|opens|sees|reaches|stares|feels|hears|abre|olha|sente|ouve|entra)\s+/iu', 'is walking', 'está caminhando', 'is staring', 'está encarando']],
            ['key' => 'rhythm_of_three_climax', 'name' => 'Regra de três com clímax', 'category' => 'rhythm', 'weight' => 4,
                'trigger' => 'Três itens em escalada (pequeno → médio → grande) cria ritmo retórico irresistível — Lincoln, MLK, Churchill.',
                'lever' => '"Sem dieta. Sem academia. Sem injeção." → cada item progressivamente mais radical.',
                'markers' => ['/\bno \w+\.\s+no \w+\.\s+no \w+\b/iu', '/\bsem \w+\.\s+sem \w+\.\s+sem \w+\b/iu', '/\bnot \w+,?\s+not \w+,?\s+(but |não )?\w+/iu']],
            ['key' => 'forbidden_aside', 'name' => 'Aparte proibido (parêntese íntimo)', 'category' => 'voice', 'weight' => 3,
                'trigger' => 'Parêntese com confissão íntima ("isso eu não devia estar contando") quebra 4ª parede — leitor se sente especial.',
                'lever' => '"(Olha, isso aqui eu juro que ninguém devia ler — mas como você chegou até aqui…)" — em parênteses.',
                'markers' => ['/\(.{0,80}between us.{0,40}\)/iu', '/\(.{0,80}entre nós.{0,40}\)/iu', "(don't tell anyone", '(não conta pra ninguém', '(off the record', '(sem oficial', "(i shouldn't say this", '(não devia dizer isso']],
            ['key' => 'narrative_arc_complete', 'name' => 'Arco narrativo completo (Save the Cat)', 'category' => 'flow', 'weight' => 5,
                'trigger' => 'História com início (mundo normal) + incitante + crise + clímax + resolução prende cérebro story-shaped — Blake Snyder.',
                'lever' => 'Cada bloco da página segue mini-arco: situação → conflito → revelação.',
                'markers' => ['used to ', 'costumava ', 'then one day', 'então um dia', 'everything changed', 'tudo mudou', "after that, ", 'depois disso, ', 'finally found', 'finalmente encontrou', 'happily ever', 'felizes para sempre']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['flow', 'voice', 'imagery', 'rhythm'];
    }
}
