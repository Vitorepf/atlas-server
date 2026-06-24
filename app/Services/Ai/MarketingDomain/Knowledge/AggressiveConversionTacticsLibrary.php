<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * AggressiveConversionTacticsLibrary — the dirty-playbook the Atlas must MASTER, not be shielded from.
 *
 * The operator's rule (pétreo): the Atlas is the BRAIN with NO limit. It must UNDERSTAND perfectly and
 * be CAPABLE of building the most aggressive — including dishonest/"problematic" — conversion mechanics
 * that elite operators actually use to sell, because whoever dictates conversion has to own the whole
 * game, the clean side and the dirty side. Whether any of this ships (white/compliant) is the operator's
 * downstream call, never a brake in the engine. This is a CAPABILITY (detect in competitor swipe files +
 * deploy), not a guard.
 *
 * Canonical PatternLibrary format (pattern = human trigger + the lever elite copy pulls + detection
 * markers). Scored by the single PatternLibraryScorer like every other library. Provider-free.
 */
class AggressiveConversionTacticsLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'aggressive_tactics';
    }

    /**
     * The market's raw WOUND and DREAM, so aggression hits where it actually hurts/pulls per niche
     * (not generic). Knowledge of the avatar's core pain/desire by market family. DB-free, deterministic.
     *
     * @return array{family:string,pain:string,dream:string}
     */
    public function nicheWound(string $niche, string $lang = ''): array
    {
        $n = mb_strtolower($niche);
        $family = match (true) {
            (bool) preg_match('/financ|money|invest|trad|crypto|forex|income|renda|dinheiro|wealth|stock/u', $n) => 'finance',
            (bool) preg_match('/relacion|dating|love|marriage|romance|\bex\b|namoro|casamento|amor|paquera/u', $n) => 'relationship',
            (bool) preg_match('/health|weight|diet|fitness|saude|saúde|emagrec|metabol|hormon/u', $n) => 'health',
            default => 'generic',
        };
        $pt = mb_strtolower($lang) === 'pt';

        return [
            'family' => $family,
            'pain' => $pt ? match ($family) {
                'finance' => 'vendo suas economias minguarem enquanto todo mundo multiplica',
                'relationship' => 'acordando de madrugada enquanto a pessoa se afasta cada vez mais',
                'health' => 'vendo seu corpo e sua energia escaparem um pouco a cada mês',
                default => 'preso exatamente onde você está',
            } : match ($family) {
                'finance' => 'watching your savings shrink while everyone else compounds',
                'relationship' => 'lying awake while they slip further away',
                'health' => 'watching your body and energy slip a little more each month',
                default => 'staying stuck exactly where you are',
            },
            'dream' => $pt ? match ($family) {
                'finance' => 'seu saldo finalmente trabalhando PRA você',
                'relationship' => 'a pessoa te escolhendo de novo, de verdade dessa vez',
                'health' => 'acordar mais leve, mais afiado, no controle do seu corpo',
                default => 'a versão de você que já resolveu isso',
            } : match ($family) {
                'finance' => 'your balance finally working FOR you',
                'relationship' => 'them choosing you again, for real this time',
                'health' => 'waking up lighter, sharper, in control of your body',
                default => 'the version of you that already solved this',
            },
        ];
    }

    public function categories(): array
    {
        return ['scarcity_pressure', 'fear_pressure', 'social_pressure', 'authority_pressure', 'value_pressure', 'identity_pressure', 'forbidden_pressure'];
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── SCARCITY / URGENCY pressure ───────────────────────────────────────────────────────
            ['key' => 'manufactured_scarcity', 'name' => 'Escassez manufaturada', 'category' => 'scarcity_pressure', 'weight' => 5,
                'trigger' => 'Medo de perder (FOMO) — o cérebro superavalia o que pode acabar.',
                'lever' => 'Quantidade limitada explícita e visível ("só 100 vagas", estoque que cai); a escassez não precisa ser natural, é construída.',
                'markers' => ['only', 'apenas', 'somente', 'restam', 'spots left', 'vagas', 'limited', 'limitad', 'while supplies last', 'enquanto durar', 'last chance', 'última chance', 'sold out', 'esgotad']],
            ['key' => 'false_deadline', 'name' => 'Prazo / contagem regressiva', 'category' => 'scarcity_pressure', 'weight' => 5,
                'trigger' => 'Deadline força decisão agora — adiar vira perder.',
                'lever' => 'Contador regressivo, "fecha hoje à meia-noite", carrinho que expira; o relógio é a alavanca, evergreen pode usar deadline rotativo por visitante.',
                'markers' => ['ends tonight', 'termina hoje', 'midnight', 'meia-noite', 'expires', 'expira', 'countdown', 'timer', 'cart closes', 'doors close', 'enrollment closes', 'last day', 'último dia', '24 hours', '24 horas']],

            // ── FEAR pressure ─────────────────────────────────────────────────────────────────────
            ['key' => 'fear_amplification', 'name' => 'Amplificação de medo', 'category' => 'fear_pressure', 'weight' => 5,
                'trigger' => 'Medo visceral (morte, ruína, abandono) domina a atenção e a ação.',
                'lever' => 'Pintar a consequência terrível em cena concreta e sensorial antes de oferecer a saída — agita a ferida fundo.',
                'markers' => ['before it is too late', 'antes que seja tarde', 'silent killer', 'assassino silencioso', 'warning', 'aviso', 'danger', 'perigo', 'destroying', 'destruindo', 'time bomb', 'bomba-relógio', 'you could lose', 'você pode perder']],
            ['key' => 'guilt_shame_trigger', 'name' => 'Gatilho de culpa/vergonha', 'category' => 'fear_pressure', 'weight' => 4,
                'trigger' => 'Culpa e vergonha movem ação para aliviar a dor moral.',
                'lever' => '"Você deve isso aos seus filhos / a si mesma"; mira a auto-imagem e o dever — pressão emocional pesada.',
                'markers' => ['you owe it to', 'você deve isso', 'your family deserves', 'sua família merece', 'still struggling', 'ainda sofrendo', "don't let them down", 'não decepcione', 'what kind of', 'que tipo de']],

            // ── SOCIAL pressure ───────────────────────────────────────────────────────────────────
            ['key' => 'social_proof_pressure', 'name' => 'Pressão de prova social', 'category' => 'social_pressure', 'weight' => 5,
                'trigger' => 'Manada — "todo mundo está fazendo" reduz risco percebido e ativa pertencimento.',
                'lever' => 'Contagem grande e específica ("47.000 já entraram"), notificações de compra ao vivo, "junte-se a milhares"; o número é a alavanca.',
                'markers' => ['already joined', 'já entraram', 'people are', 'pessoas estão', 'join thousands', 'junte-se a', 'others bought', 'outros compraram', 'trending', 'most popular', 'mais vendido', 'everyone is', 'todo mundo']],
            ['key' => 'rival_loss', 'name' => 'Medo do rival / ficar pra trás', 'category' => 'social_pressure', 'weight' => 4,
                'trigger' => 'Inveja/comparação — ver o outro ganhando o que você não tem dói e move.',
                'lever' => '"Enquanto você hesita, eles já estão na frente"; ativa status e perda relativa.',
                'markers' => ['while you wait', 'enquanto você espera', 'others are getting', 'outros estão', "don't get left behind", 'não fique pra trás', 'ahead of you', 'na sua frente', 'they already', 'eles já']],

            // ── AUTHORITY pressure ────────────────────────────────────────────────────────────────
            ['key' => 'authority_borrowing', 'name' => 'Empréstimo de autoridade', 'category' => 'authority_pressure', 'weight' => 5,
                'trigger' => 'Heurística de autoridade — endosso de especialista/mídia transfere credibilidade.',
                'lever' => 'Citar médico/veículo/instituição, jaleco, selos; o endosso implícito ("visto na mídia") carrega a confiança.',
                'markers' => ['as seen on', 'visto na', 'doctor', 'médic', 'dr.', 'scientist', 'cientista', 'study shows', 'estudo mostra', 'clinically', 'clinicamente', 'fda', 'harvard', 'expert', 'especialista', 'endorsed', 'recomendado por']],
            ['key' => 'conspiracy_enemy', 'name' => 'Inimigo comum / conspiração', 'category' => 'authority_pressure', 'weight' => 4,
                'trigger' => 'Inimigo externo explica o fracasso sem culpar o leitor e cria urgência de "saber antes que tirem do ar".',
                'lever' => '"Big Pharma/o sistema esconde isso de você"; une leitor+autor contra um vilão poderoso.',
                'markers' => ['they don\'t want you to know', 'não querem que você saiba', 'big pharma', 'the industry hides', 'a indústria esconde', 'banned', 'banido', 'censored', 'censurado', 'before it is taken down', 'antes que tirem do ar', 'cover-up', 'acobertam']],

            // ── VALUE pressure ────────────────────────────────────────────────────────────────────
            ['key' => 'price_anchoring_extreme', 'name' => 'Ancoragem de preço extrema', 'category' => 'value_pressure', 'weight' => 5,
                'trigger' => 'Ancoragem — o primeiro número molda a percepção do que é caro/barato.',
                'lever' => 'Empilhar "valor total" absurdo ($4.997) e cortar pra uma fração ($47); o desconto gigante é a alavanca.',
                'markers' => ['value', 'valor de', 'normally', 'normalmente', 'worth', 'vale', 'today only', 'só hoje', 'regular price', 'preço normal', '/\$\d{3,}/', 'a fraction of', 'uma fração', 'save', 'economize']],
            ['key' => 'risk_reversal_aggressive', 'name' => 'Reversão de risco agressiva', 'category' => 'value_pressure', 'weight' => 4,
                'trigger' => 'Aversão à perda — tirar o risco do comprador derruba a última barreira.',
                'lever' => '"Garantia 200%, te pago pra tentar"; quanto mais ousada a reversão, mais a objeção de risco some.',
                'markers' => ['money-back', 'garantia', 'guarantee', 'refund', 'reembolso', 'no questions', 'sem perguntas', 'double your money back', 'risk-free', 'sem risco', 'or it is free', 'ou é grátis', 'i will pay you', 'eu te pago']],

            // ── IDENTITY pressure ─────────────────────────────────────────────────────────────────
            ['key' => 'identity_threat', 'name' => 'Ameaça de identidade', 'category' => 'identity_pressure', 'weight' => 4,
                'trigger' => 'Identidade — pessoas agem pra confirmar quem acham que são (ou temem não ser).',
                'lever' => '"Pessoas inteligentes já sabem disso / não seja o último"; comprar vira prova de identidade.',
                'markers' => ['smart people', 'pessoas inteligentes', "don't be the last", 'não seja o último', 'people like you', 'pessoas como você', 'are you the type', 'você é do tipo', 'winners', 'vencedores', 'serious about', 'que leva a sério']],
            ['key' => 'future_pacing_vivid', 'name' => 'Future pacing vívido', 'category' => 'identity_pressure', 'weight' => 4,
                'trigger' => 'Simulação mental — viver o resultado no presente cria desejo e posse antecipada.',
                'lever' => '"Imagine acordar em 30 dias e..."; coloca o leitor dentro da transformação como se já fosse real.',
                'markers' => ['imagine', 'imagine só', 'picture yourself', 'se veja', 'in 30 days you', 'em 30 dias você', 'wake up', 'acordar', 'how it feels', 'como é sentir', 'your new', 'sua nova']],

            // ── FORBIDDEN pressure — as táticas que NÃO são liberadas, aqui como CONHECIMENTO PURO ────
            // O Atlas precisa entender e DETECTAR como/por que essas mecânicas vendem (espiar o
            // concorrente, mapear o jogo inteiro). Sem guarda, sem julgamento. O que SOBE é a versão
            // que o operador libera por cima — esta camada é só o conhecimento extremo do campo.
            ['key' => 'celebrity_face_borrowing', 'name' => 'Empréstimo de rosto de famoso', 'category' => 'forbidden_pressure', 'weight' => 5,
                'trigger' => 'Familiaridade + autoridade — um rosto famoso transfere confiança e atenção instantânea.',
                'lever' => 'Associar a oferta a uma celebridade (foto, nome, "como visto com", endosso implícito) — converte muito; é o que muitos rodam mesmo sem liberação.',
                'markers' => ['celebrity', 'celebridade', 'famous', 'famoso', 'as endorsed by', 'endossado por', 'as seen with', 'visto com', 'shark tank', 'oprah', 'elon', 'celebrity secret', 'segredo das famosas', 'the stars use', 'as famosas usam']],
            ['key' => 'fabricated_proof_pattern', 'name' => 'Padrão de prova fabricada', 'category' => 'forbidden_pressure', 'weight' => 5,
                'trigger' => 'Crença concreta — antes/depois e depoimento específico fazem o leitor acreditar no resultado.',
                'lever' => 'Foto antes/depois, depoimento nominal com número, "comprador verificado" — converte pela concretude; muito usado mesmo sem ser real (a versão real vem do produtor).',
                'markers' => ['before and after', 'antes e depois', 'real customer', 'cliente real', 'verified buyer', 'comprador verificado', 'real results', 'resultados reais', 'actual photo', 'foto real', 'unedited', 'sem edição', 'screenshot of', 'print do']],
            ['key' => 'authority_impersonation', 'name' => 'Personificação de autoridade/mídia', 'category' => 'forbidden_pressure', 'weight' => 4,
                'trigger' => 'O formato de notícia/órgão oficial empresta credibilidade e baixa a guarda do leitor.',
                'lever' => 'Imitar layout de portal de notícias, "advertorial" disfarçado de matéria, selo de órgão; converte por parecer jornalismo/oficial.',
                'markers' => ['breaking news', 'última hora', 'health news', 'special report', 'reportagem especial', 'official notice', 'aviso oficial', 'investigation reveals', 'investigação revela', 'this site is not', 'advertorial']],
            ['key' => 'extreme_outcome_claim', 'name' => 'Promessa de resultado extremo', 'category' => 'forbidden_pressure', 'weight' => 4,
                'trigger' => 'O cérebro fisga em promessas absolutas/curativas mesmo sabendo que são boas demais.',
                'lever' => 'Cura implícita, garantia de resultado absoluto, "funciona pra todos em X dias"; converte pelo tamanho da promessa (área não-liberada).',
                'markers' => ['cure', 'cura', 'guaranteed results', 'resultado garantido', 'works for everyone', 'funciona pra todos', 'eliminate', 'elimina', 'reverse', 'reverte', 'overnight', 'da noite pro dia', '100% effective', '100% eficaz', 'permanent', 'permanente']],
        ];
    }
}
