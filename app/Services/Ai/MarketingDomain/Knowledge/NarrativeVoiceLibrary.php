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
