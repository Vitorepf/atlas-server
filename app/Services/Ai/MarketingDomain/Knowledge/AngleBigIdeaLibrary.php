<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * AngleBigIdeaLibrary — the highest-leverage library in the Conversion Pattern OS. The ANGLE (the big
 * idea) is what Schwartz proved separates the #1 offer from the rest: same product, new angle, 10×
 * the conversion. These are the canonical big-idea archetypes elite direct-response uses — each with
 * the human trigger, how to construct it, and detection markers. They are content-independent: the
 * right angle sells almost anything because it reframes WHAT the reader believes before the pitch.
 */
class AngleBigIdeaLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'angle_big_idea';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            ['key' => 'hidden_cause', 'name' => 'A causa oculta', 'category' => 'cause', 'weight' => 5,
                'trigger' => 'Reposiciona o problema: não é o que você pensa, é uma causa-raiz escondida. Mata o "já tentei tudo" porque tudo atacou o alvo errado.',
                'lever' => 'Revele uma causa nova, nomeada e específica que ninguém contou ao leitor.',
                'markers' => ['hidden cause', 'real reason', 'causa oculta', 'verdadeiro motivo', 'o que ninguém', 'root cause', 'not what you think', 'real culprit', 'a verdadeira causa', 'never told you']],
            ['key' => 'common_enemy', 'name' => 'O inimigo comum', 'category' => 'enemy', 'weight' => 4,
                'trigger' => 'Cria um vilão que lucra com o problema e esconde a solução — canaliza a raiva e une o leitor numa causa.',
                'lever' => 'Nomeie quem ganha com o problema continuar e sabota a saída.',
                'markers' => ['big pharma', 'the industry', 'a indústria', "they don't want", 'não querem que', 'hide', 'esconde', 'conspiracy', 'conspiração', 'lobby', 'o sistema', 'billion-dollar']],
            ['key' => 'forbidden_discovery', 'name' => 'A descoberta proibida', 'category' => 'discovery', 'weight' => 5,
                'trigger' => 'Curiosidade + exclusividade + vilão: o que tentaram esconder, banir ou derrubar.',
                'lever' => 'Enquadre a informação como vazada/censurada/proibida — algo que querem apagar.',
                'markers' => ['leaked', 'vazad', 'banned', 'proibid', 'censored', 'censur', 'taken down', 'suppressed', 'they tried to', 'tentaram', "before it's removed", 'forbidden', 'saiu do ar']],
            ['key' => 'contrarian_truth', 'name' => 'A verdade contra-intuitiva', 'category' => 'discovery', 'weight' => 4,
                'trigger' => 'Inverte a crença dominante — choque cognitivo e posiciona quem fala como dono da verdade.',
                'lever' => 'Diga que tudo que ensinaram está errado e mostre o oposto.',
                'markers' => ['everything you know', 'tudo que você', 'is wrong', 'está errado', 'the truth about', 'a verdade sobre', 'lie', 'mentira', 'myth', 'mito', 'opposite', 'o contrário']],
            ['key' => 'new_opportunity', 'name' => 'A nova oportunidade', 'category' => 'opportunity', 'weight' => 4,
                'trigger' => 'Não é consertar o que falhou — é uma CATEGORIA NOVA. Escapa do ceticismo acumulado com o velho.',
                'lever' => 'Apresente como um tipo/descoberta novo, não como "melhor que X".',
                'markers' => ['new way', 'nova forma', 'breakthrough', 'descoberta', 'never before', 'pela primeira vez', 'finally', 'finalmente', 'the first', 'introducing', 'novo método', 'new class']],
            ['key' => 'shortcut_secret', 'name' => 'O segredo/atalho', 'category' => 'opportunity', 'weight' => 4,
                'trigger' => 'O que os que conseguem sabem e o resto não — inveja + esperança, encurta o esforço percebido.',
                'lever' => 'Revele o que o 1% / os de dentro sabem e o público não.',
                'markers' => ['secret', 'segredo', 'shortcut', 'atalho', 'what the', 'o que os', 'insiders', 'de dentro', 'trick', 'truque', 'hack', 'the 1%', 'os ricos', 'the wealthy']],
            ['key' => 'third_option', 'name' => 'A terceira via', 'category' => 'opportunity', 'weight' => 3,
                'trigger' => 'Nem A (caro/difícil) nem B (não funciona) — a terceira via resolve o dilema do leitor.',
                'lever' => 'Mostre as duas opções ruins e posicione a sua como a 3ª saída.',
                'markers' => ['instead of', 'em vez de', 'without having to', 'sem precisar', 'neither', 'the third', 'a terceira', 'no need to', "there's a better", 'uma alternativa']],
            ['key' => 'us_vs_them', 'name' => 'Nós contra eles', 'category' => 'identity', 'weight' => 3,
                'trigger' => 'Identidade de grupo e rebelião — pertencer a "nós" (os que sabem) contra a massa enganada.',
                'lever' => 'Crie a tribo dos que descobriram vs o mainstream que segue o engano.',
                'markers' => ['people like us', 'gente como', 'the few who', 'os poucos que', 'wake up', 'acorde', 'join', 'junte-se', 'smart women', 'mulheres espertas', 'the herd', 'a manada']],
            ['key' => 'ticking_threat', 'name' => 'A ameaça iminente', 'category' => 'urgency', 'weight' => 4,
                'trigger' => 'Algo está piorando e há uma janela se fechando — medo + urgência real.',
                'lever' => 'Mostre a deterioração em curso e o prazo que se esgota.',
                'markers' => ["before it's too late", 'antes que seja tarde', 'getting worse', 'piorando', 'running out', 'acabando', 'warning', 'alerta', 'the clock', 'deadline', 'cada dia que passa']],
            ['key' => 'transformation_story', 'name' => 'A história de virada', 'category' => 'story', 'weight' => 4,
                'trigger' => 'Queda e ascensão de alguém igual ao leitor — identificação + prova viva.',
                'lever' => 'Conte o fundo do poço e a virada de uma pessoa espelho do avatar.',
                'markers' => ['i was', 'eu era', 'until one day', 'até que um dia', 'rock bottom', 'fundo do poço', "that's when", 'foi quando', 'my story', 'minha história', 'changed everything', 'mudou tudo']],
            ['key' => 'loophole_hack', 'name' => 'A brecha do sistema', 'category' => 'opportunity', 'weight' => 3,
                'trigger' => 'Uma falha do sistema explorável — esperteza + sensação de vantagem (quase injusta).',
                'lever' => 'Apresente como uma brecha (biológica/legal/financeira) que dá vantagem.',
                'markers' => ['loophole', 'brecha', 'glitch', 'falha', 'exploit', 'workaround', 'backdoor', 'porta dos fundos', 'the system', 'o sistema', 'legal hack', 'aproveitar']],
            ['key' => 'trend_prophecy', 'name' => 'A onda que vem', 'category' => 'urgency', 'weight' => 3,
                'trigger' => 'A mudança inevitável que está chegando — FOMO + visão privilegiada de entrar antes.',
                'lever' => 'Mostre a tendência que vai virar tudo e como entrar antes de todos.',
                'markers' => ['coming', 'vindo', 'the future', 'o futuro', 'next big', 'próxima grande', 'before everyone', 'antes de todos', 'shift', 'mudança', 'on the verge', 'prestes a']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['cause', 'enemy', 'discovery', 'opportunity', 'identity', 'urgency', 'story'];
    }
}
