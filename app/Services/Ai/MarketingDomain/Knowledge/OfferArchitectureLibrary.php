<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * OfferArchitectureLibrary — the architecture of an irresistible offer (Hormozi's Grand Slam Offer +
 * pricing psychology). Hormozi's central claim: a great offer overcomes mediocre copy; a mediocre
 * offer wastes great copy. These are the levers that compose a Grand Slam: dream outcome, proof of
 * likelihood, time delay collapsed, effort/sacrifice reduced + bonus stack, risk reversal, scarcity,
 * urgency, naming the package, payment terms, free trial / tripwire, money-back-plus, premium
 * positioning. Each fires regardless of the product — the construction of the offer is the conversion.
 */
class OfferArchitectureLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'offer_architecture';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── Value equation (numerator: dream × likelihood ÷ time × effort) ─────────────────
            ['key' => 'dream_outcome_concrete', 'name' => 'Resultado dos sonhos concreto', 'category' => 'value', 'weight' => 5,
                'trigger' => 'O valor sobe quando o resultado é específico, vívido e desejado — não abstrato.',
                'lever' => 'Pinte o resultado em cena: número específico, prazo, imagem do "depois".',
                'markers' => ['/\b\d{2,3}\s*(lbs|pounds|kg|libras|days|dias|weeks|semanas|months|meses)\b/u', 'fit into', 'roupas antigas', 'in the mirror', 'no espelho', 'wake up', 'acordar', 'by ', 'until ', 'within ']],
            ['key' => 'proof_stack', 'name' => 'Empilhamento de prova', 'category' => 'value', 'weight' => 5,
                'trigger' => 'Múltiplas formas de prova juntas multiplicam credibilidade (estudo + depoimento + autoridade + mídia).',
                'lever' => 'Empilhe estudo + depoimento + autoridade + selo de mídia + número de usuários.',
                'markers' => ['study', 'estudo', 'doctor', 'médic', 'as seen on', 'visto na', 'reviews', 'avaliações', 'verified', 'verificad', 'university', 'universidade', 'clinical']],
            ['key' => 'collapse_time', 'name' => 'Tempo encolhido', 'category' => 'value', 'weight' => 4,
                'trigger' => 'Quanto menor o tempo até o resultado, maior o valor — denominador da equação.',
                'lever' => '"Em X dias/semanas", "primeiros resultados em Y", "instantâneo".',
                'markers' => ['in just', 'em apenas', 'within ', 'em ', 'overnight', 'da noite pro dia', 'instant', 'instantâneo', 'first results', 'primeiros resultados', '24 hours', 'fast results']],
            ['key' => 'collapse_effort', 'name' => 'Esforço encolhido', 'category' => 'value', 'weight' => 4,
                'trigger' => 'Quanto menor o esforço/sacrifício percebido, maior o valor.',
                'lever' => '"Sem dieta/academia/agulha/aprender", "3 segundos", "não precisa fazer X".',
                'markers' => ['no diet', 'sem dieta', 'no workout', 'sem academia', 'no needle', 'sem agulha', 'done-for-you', 'feito pra você', 'effortless', 'sem esforço', 'just take', 'basta tomar']],

            // ── Stack & framing ────────────────────────────────────────────────────────────────
            ['key' => 'bonus_stack', 'name' => 'Pilha de bônus', 'category' => 'stack', 'weight' => 5,
                'trigger' => 'Bônus empilhados com valor ancorado fazem o preço parecer ridículo perto do total.',
                'lever' => 'Liste 4-7 bônus, cada um com nome forte + valor ancorado + total somado.',
                'markers' => ['bonus #', 'bônus #', 'bonus 1', 'plus you get', 'mais você ganha', 'free gift', 'brinde grátis', 'value:', 'valor:', 'total value', 'valor total', 'free training', 'free guide']],
            ['key' => 'named_package', 'name' => 'Pacote nomeado', 'category' => 'stack', 'weight' => 3,
                'trigger' => 'Nomear o pacote o transforma em "coisa", não em commodity — vira diferente do mercado.',
                'lever' => 'Nome próprio pro kit ("Triple Hormone Protocol Kit", "Founder Pack").',
                'markers' => ['kit', 'protocol', 'protocolo', 'system', 'sistema', 'pack', 'pacote', 'bundle', 'bundle', 'founder', 'starter', 'elite', 'premium edition']],
            ['key' => 'value_anchored', 'name' => 'Valor ancorado vs preço', 'category' => 'stack', 'weight' => 4,
                'trigger' => '"Total $1.997, hoje $97" — a diferença gigante muda a percepção do que vale.',
                'lever' => 'Mostre valor total alto somado e o preço atual pequeno em contraste visual.',
                'markers' => ['total value', 'valor total', 'normally', 'normalmente', 'worth ', 'vale ', 'instead of paying', 'em vez de pagar', '/\$\d{3,4}.*\$\d{1,3}\b/u', 'today only', 'hoje por apenas']],

            // ── Risk reversal & guarantee ──────────────────────────────────────────────────────
            ['key' => 'risk_reversal_strong', 'name' => 'Reversão de risco forte', 'category' => 'risk', 'weight' => 5,
                'trigger' => 'Tirar todo o risco do comprador remove a última objeção — quanto mais audaciosa, mais converte.',
                'lever' => '"60 dias, 100%, sem perguntas" ou maior — "double-your-money", "keep the bonus".',
                'markers' => ['60-day', '60 dias', '90-day', '90 dias', 'money-back', 'reembolso', 'no questions', 'sem perguntas', '100%', 'risk-free', 'sem risco', 'or your money', 'ou seu dinheiro', 'keep the bonus', 'fique com o bônus']],
            ['key' => 'better_than_free', 'name' => 'Melhor que grátis', 'category' => 'risk', 'weight' => 3,
                'trigger' => 'Garantia "mais que devolução" (ganha algo a mais se não funcionar) parece insano-bom.',
                'lever' => '"Devolvemos + um bônus de $X", "se não funcionar, te pagamos".',
                'markers' => ['plus we pay', 'pagamos a mais', "we'll pay you", 'te pagamos', 'double your money', 'dobro do dinheiro', 'extra bonus if', 'bônus extra se', 'on top of']],

            // ── Scarcity & urgency real ───────────────────────────────────────────────────────
            ['key' => 'scarcity_quantity', 'name' => 'Escassez de quantidade', 'category' => 'urgency', 'weight' => 4,
                'trigger' => 'Estoque/vagas limitadas com motivo crível dispara FOMO real.',
                'lever' => '"Apenas X unidades/vagas", "primeiros Y compradores ganham Z".',
                'markers' => ['only', 'apenas', 'first ', 'primeiros', 'limited', 'limitad', 'spots', 'vagas', 'left in stock', 'em estoque', 'while supplies', 'enquanto durar']],
            ['key' => 'scarcity_time', 'name' => 'Escassez de tempo', 'category' => 'urgency', 'weight' => 4,
                'trigger' => 'Deadline real + visível força ação agora — adiar é perder.',
                'lever' => 'Contador, data, "até X", "hoje à noite", "fim do mês".',
                'markers' => ['expires', 'expira', 'tonight', 'hoje à noite', 'midnight', 'meia-noite', 'ends ', 'termina ', 'this week', 'esta semana', 'countdown', 'contador', 'deadline']],
            ['key' => 'fast_action_bonus', 'name' => 'Bônus de ação rápida', 'category' => 'urgency', 'weight' => 3,
                'trigger' => 'Bônus que some se demorar — incentivo positivo + medo de perder.',
                'lever' => '"Os primeiros 100 ganham", "se comprar hoje recebe X extra".',
                'markers' => ['fast-action', 'first 100', 'primeiros 100', 'today you also get', 'hoje você ganha também', 'act now and', 'aja agora e', 'instant bonus']],

            // ── Premium positioning & terms ─────────────────────────────────────────────────────
            ['key' => 'premium_signals', 'name' => 'Sinais de premium', 'category' => 'positioning', 'weight' => 3,
                'trigger' => 'Sinais de premium (selo, "elite", made in X) elevam valor percebido e atraem o avatar certo.',
                'lever' => 'Selos de qualidade, "made in USA", "premium", "doctor-formulated".',
                'markers' => ['premium', 'elite', 'made in', 'fabricado', 'doctor-formulated', 'formulado por médico', 'certified', 'certificado', 'lab-tested', 'testado em lab', 'pharmaceutical-grade']],
            ['key' => 'payment_terms', 'name' => 'Termos de pagamento', 'category' => 'positioning', 'weight' => 3,
                'trigger' => 'Parcelamento/teste reduz a barreira inicial de compra — pequeno "sim" agora.',
                'lever' => '3x sem juros, "comece por $1", trial gratuito.',
                'markers' => ['installments', 'parcelas', '3x', '12x', 'no interest', 'sem juros', 'start for $1', 'comece por r$', 'free trial', 'teste grátis', 'first month free', 'primeiro mês grátis']],
            ['key' => 'tripwire', 'name' => 'Tripwire / oferta de entrada', 'category' => 'positioning', 'weight' => 2,
                'trigger' => 'Uma oferta de entrada quase grátis converte tráfego frio em comprador — o primeiro sim destrava o resto.',
                'lever' => '$1-$7-$27 só para entrar, depois upsell.',
                'markers' => ['$7', '$27', '$1 trial', 'just $1', 'só r$', 'try it for', 'experimente por', 'getting started', 'comece com', 'no-brainer', 'starter offer']],
            ['key' => 'upsell_chain', 'name' => 'Cadeia de upsell', 'category' => 'positioning', 'weight' => 3,
                'trigger' => 'A oferta principal abre a porta — o lucro real vem dos upsells (kit maior, plano anual, add-on).',
                'lever' => 'Kit recomendado maior, anual com desconto, "se você quer X também".',
                'markers' => ['upgrade to', 'evolua para', '6-bottle', '6 frascos', 'best value kit', 'kit melhor custo', 'add to your order', 'adicione ao pedido', 'annual', 'anual', 'most chosen']],

            // ── Aprofundamento Volta 2: ofertas raras que separam killer de boa ─────────────────
            ['key' => 'value_explosion_ratio', 'name' => 'Razão valor:preço esmagadora', 'category' => 'value', 'weight' => 5,
                'trigger' => 'Hormozi: a oferta vira "no-brainer" quando o valor entregue é 10× o preço — não dá pra pensar.',
                'lever' => '"$5.000 de valor por $97" — razão 50:1 ou mais, com cada bônus precificado.',
                'markers' => ['/\$\d{4,}.*\$\d{1,3}\b/u', 'over \$1,000 in value', 'mais de r\$', '10x value', '10x o valor', 'total value:', 'valor total:', 'pay nothing close']],
            ['key' => 'reverse_risk', 'name' => 'Reversão de risco invertida (você ganha se falhar)', 'category' => 'risk', 'weight' => 4,
                'trigger' => 'Mais que devolver — pagar PRA usar. "Se não funcionar, te devolvo + $200 pelo seu tempo".',
                'lever' => 'Garantia que paga ao cliente se falhar. Insano-bom percebido.',
                'markers' => ['pay you $', 'te pagamos r$', 'paid to try', 'pago pra testar', 'we owe you', 'devemos a você', 'for your time', 'pelo seu tempo', 'reverse guarantee', 'garantia invertida', '$200 on top']],
            ['key' => 'naming_premium', 'name' => 'Nome proprietário do mecanismo', 'category' => 'stack', 'weight' => 4,
                'trigger' => 'Nomear o método ("Triple Hormone Protocol™", "5-Minute Money Method") transforma commodity em IP.',
                'lever' => 'Nome próprio + ™ + ano de criação + "primeira vez na história".',
                'markers' => ['method™', 'protocol™', 'system™', 'method™', 'introducing the', 'apresentando o', 'the only ', 'o único ', 'patented', 'patenteado', 'trademark', '/\b[A-Z][\w-]+\s+(method|protocol|formula|system)\b/u']],
            ['key' => 'bonus_supremacy', 'name' => 'Bônus mais valioso que o produto', 'category' => 'stack', 'weight' => 4,
                'trigger' => 'Quando o bônus parece valer mais que o produto principal, o leitor compra pelos bônus.',
                'lever' => '"O bônus #3 sozinho vale 3× o preço total" — eleva o bônus acima do produto.',
                'markers' => ['bonus alone', 'só o bônus', 'worth more than', 'vale mais que', 'even if just for', 'mesmo só pelo', 'this alone', 'só isto', 'just bonus 3', 'só o bônus 3']],
            ['key' => 'scarcity_logic', 'name' => 'Escassez com lógica crível', 'category' => 'urgency', 'weight' => 5,
                'trigger' => 'Escassez sem motivo soa falsa — escassez com lógica ("só 100 kits porque é a produção do mês") trava.',
                'lever' => 'Dê o motivo concreto: "fabricamos só X/mês", "só 50 acompanhamentos cabem na minha agenda".',
                'markers' => ['because we ', 'porque nós ', 'we only produce', 'só produzimos', 'limited capacity', 'capacidade limitada', "i can only take", 'só consigo atender', 'in my calendar', 'na minha agenda', 'small batch', 'lote pequeno']],
            ['key' => 'price_drop_reason', 'name' => 'Preço com justificativa', 'category' => 'value', 'weight' => 4,
                'trigger' => '"Por que está tão barato?" → resposta crível remove suspeita de armadilha.',
                'lever' => '"Estamos quebrando a barreira pra X", "campanha de lançamento", "missão pessoal".',
                'markers' => ['why so cheap', 'por que tão barato', 'mission to ', 'missão de ', 'launch price', 'preço de lançamento', 'because we believe', 'porque acreditamos', "i don't make money", 'eu não ganho dinheiro', 'covering costs', 'cobrindo o custo']],
            ['key' => 'split_payment', 'name' => 'Pagamento dividido inteligente', 'category' => 'positioning', 'weight' => 3,
                'trigger' => '"Hoje só X, paga o resto quando funcionar" — distribui risco psicológico no tempo.',
                'lever' => '"Pay nothing today, pay $X when you see results".',
                'markers' => ['nothing today', 'nada hoje', 'pay when ', 'pague quando ', 'after you see', 'depois que ver', 'pay-as-you-grow', 'pague conforme cresce', '/\d+\s*x\s*de\s*r\$/iu', 'first month free then']],
            ['key' => 'unfair_advantage_offer', 'name' => 'Vantagem injusta do operador', 'category' => 'positioning', 'weight' => 3,
                'trigger' => 'Operador menciona vantagem injusta dele (acesso, contato, dado proprietário) — eleva valor da oferta.',
                'lever' => '"Tenho acesso a X que ninguém tem — é isso que coloco na oferta".',
                'markers' => ['my private contact', 'meu contato particular', 'only i have access', 'só eu tenho acesso', 'proprietary data', 'dado proprietário', 'unfair advantage', 'vantagem injusta', 'exclusive deal with', 'acordo exclusivo']],
            ['key' => 'community_inclusion', 'name' => 'Acesso à comunidade fechada', 'category' => 'stack', 'weight' => 3,
                'trigger' => 'Comunidade privada é o bônus de maior LTV — vira identidade + retenção + viral.',
                'lever' => '"Grupo privado de 9.400 mulheres como você + Q&A semanal comigo".',
                'markers' => ['private community', 'comunidade privada', 'inner circle', 'círculo interno', 'mastermind', 'grupo privado de', 'q&a weekly', 'q&a semanal', 'live with me', 'ao vivo comigo', 'members-only']],
            ['key' => 'declared_decline_terms', 'name' => 'Termos declarados de fechamento', 'category' => 'urgency', 'weight' => 3,
                'trigger' => 'Declarar publicamente que a oferta vai sair do ar (com data) cria deadline real e força ação.',
                'lever' => '"Esta versão sai do ar dia X. Depois disso, preço sobe pra Y / não voltamos a vender".',
                'markers' => ['after that, ', 'depois disso, ', 'never again', 'nunca mais', 'this is the last', 'esta é a última', 'price goes up to', 'preço sobe para', 'closing forever', 'fechando para sempre', 'final cohort', 'última turma']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['value', 'stack', 'risk', 'urgency', 'positioning'];
    }
}
