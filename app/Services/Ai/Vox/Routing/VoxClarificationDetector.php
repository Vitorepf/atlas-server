<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Routing;

use App\Services\Ai\Vox\VoxSchema;

/**
 * CLARIFICATION / AMBIGUITY-DETECTION concern, extracted from the god-class
 * {@see VoxFlowOrchestrator}.
 *
 * Owns the detect* family (ambiguous reference, missing polish target, missing
 * objective for AI, missing execute target, generic "faz", double intent) plus
 * the supporting heuristics (mentionsNoteIntent, hasSpecificIdentifier).
 *
 * The orchestrator keeps guessProviderFromText (only used by detectMissingObjectiveForAi
 * for provider-specific phrasing) — it's a 5-line helper that's part of the orchestrator's
 * provider-routing surface, not the clarification engine.
 */
class VoxClarificationDetector
{
    public function detectAmbiguousReference(
        string $normalizedText,
        string $rawText,
        bool $hasContext,
    ): ?string {
        if ($hasContext || $normalizedText === '') {
            return null;
        }

        // Se a fala carrega um identificador específico (Codex, Vox,
        // VoxAutoModeRouter.php, /Users/.../foo), o operador já apontou o
        // alvo — não pedimos clarificação.
        if ($this->hasSpecificIdentifier($rawText)) {
            return null;
        }

        $text = $normalizedText;

        // Verbo de inspeção/edição/execução + "<esse|este|o> arquivo/módulo/...".
        // V6.5-CLARIFICATION-ENGINE · adicionado abre/corrige/conserta/arruma/
        // coloca/p[õo]e + nouns erro/bug/problema/coisa/negocio.
        if (preg_match(
            '/\b(?:olha|olhe|olhar|analisa|analise|analisar|investiga|investigue|investigar|edita|edite|editar|executa|execute|executar|roda|rode|rodar|apaga|apague|apagar|deleta|delete|deletar|remove|remova|remover|manda|mande|mandar|abre|abra|abrir|corrige|corrija|corrigir|conserta|conserte|consertar|arruma|arrume|arrumar|coloca|coloque|colocar|p[õo]e|p[õo]r|por)\s+(?:o\s+|a\s+|esse\s+|este\s+|essa\s+|esta\s+|aquele\s+|aquela\s+)(?:arquivo|pasta|diret[óo]rio|trecho|c[óo]digo|m[óo]dulo|conte[úu]do|comando|prompt|texto|fluxo|fun[çc][ãa]o|m[ée]todo|classe|migration|seed|teste|erro|bug|problema|coisa|neg[óo]cio|tro[çc]o|trem)\b/iu',
            $text,
        ) === 1) {
            return 'Qual arquivo / trecho / módulo exatamente? Selecione no editor antes de gravar.';
        }

        // Verbo de execução/exclusão + pronome neutro "isso/aquilo/aqui/lá".
        // V6.5-CLARIFICATION-ENGINE · adicionado manda/coloca/abre/p[õo]e/faz/
        // arruma + pronomes aqui/ali/lá pra cobrir "coloca isso lá", "faz isso".
        if (preg_match(
            '/\b(?:executa|execute|executar|roda|rode|rodar|apaga|apague|apagar|deleta|delete|deletar|edita|edite|editar|manda|mande|mandar|abre|abra|abrir|coloca|coloque|colocar|p[õo]e|p[õo]r|arruma|arrume|arrumar|conserta|conserte|consertar)\s+(?:isso|isto|aquilo)(?:\s+(?:aqui|ali|l[áa]))?\b/iu',
            $text,
        ) === 1) {
            return 'Executar/Mandar/Apagar o quê? Diga o alvo concreto (arquivo, comando, link).';
        }

        // Verbo de inspeção + pronome neutro.
        // V6.5-CLARIFICATION-ENGINE · adicionado verbo "olha aqui/ali/lá".
        if (preg_match(
            '/\b(?:olha|olhe|olhar|analisa|analise|analisar|investiga|investigue|investigar)\s+(?:isso|isto|aquilo|aqui|ali|l[áa])\b/iu',
            $text,
        ) === 1) {
            return 'O que é "isso" exatamente? Cole o texto ou aponte o arquivo no editor.';
        }

        return null;
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · mode=prompt_polish sem payload concreto.
     * "melhora isso", "corrige esse erro" sem texto/colon-conteúdo → ASK.
     * "melhora esse texto: <conteúdo>", "deixa esse texto mais profissional
     * pra mandar pro cliente" (texto longo) → não dispara.
     */
    public function detectMissingPolishTarget(
        string $rawText,
        string $mode,
        bool $hasContext,
    ): ?string {
        if ($mode !== VoxSchema::MODE_PROMPT_POLISH) {
            return null;
        }
        if ($hasContext) {
            return null;
        }
        // Payload explícito após dois-pontos.
        if (preg_match('/:\s*\S{3,}/u', $rawText) === 1) {
            return null;
        }
        // Texto entre aspas (payload).
        if (preg_match('/["\'][^"\']{3,}["\']/u', $rawText) === 1) {
            return null;
        }
        $tokens = preg_split('/\s+/u', trim($rawText)) ?: [];
        $tokenCount = count(array_filter($tokens, static fn ($t) => $t !== ''));

        // Polish verb + pronome PURO (isso/isto/aquilo) + ≤ 3 tokens = ASK.
        // "melhora esse texto" (3 tokens com noun) NÃO dispara — operador
        // já nomeou a categoria. "deixa isso mais profissional" (4 tokens)
        // também passa — tem qualificador suficiente. Só dispara em fala
        // verdadeiramente vazia tipo "melhora isso", "limpa isso", "corrige
        // isso", "arruma isso", "reescreve isso".
        $polishVerb = preg_match('/\b(?:melhora|melhore|melhorar|corrige|corrija|corrigir|organiza|organize|organizar|polir|reescreve|reescreva|reescrever|limpa|limpe|limpar|arruma|arrume|arrumar|deixa|deixe|formata|formate|formatar)\b/iu', $rawText) === 1;
        $purePronoun = preg_match('/\b(?:isso|isto|aquilo)\b/iu', $rawText) === 1;
        $hasContentNoun = preg_match('/\b(?:texto|trecho|fala|prompt|c[óo]digo|conte[úu]do|frase|par[áa]grafo|mensagem|email|t[íi]tulo|legenda|descri[çc][ãa]o|coment[áa]rio|t[óo]pico)\b/iu', $rawText) === 1;

        if ($polishVerb && $purePronoun && ! $hasContentNoun && $tokenCount <= 3) {
            return 'Qual texto você quer melhorar? Cole o texto ou selecione antes de gravar.';
        }

        return null;
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · mode=intent_compile sem objetivo claro.
     * "manda pro Codex" sem verbo de objetivo (investigar/analisar/criar/...)
     * e sem payload → ASK "O que pedir pra IA?". Casos com objetivo OU
     * texto longo passam direto.
     */
    public function detectMissingObjectiveForAi(
        string $rawText,
        string $mode,
        bool $hasContext,
        string $provider = 'ai',
    ): ?string {
        if ($mode !== VoxSchema::MODE_INTENT_COMPILE) {
            return null;
        }
        if ($hasContext) {
            return null;
        }
        // Verbo de objetivo presente → operador disse O QUE pedir.
        if (preg_match('/\b(?:investiga(?:r|)|investigue|analisa(?:r|)|analise|revisa(?:r|)|revise|cria(?:r|)|crie|gera(?:r|)|gere|monta(?:r|)|monte|desenvolve(?:r|)|desenvolva|escrev(?:e|er|a)|explica(?:r|)|explique|planeja(?:r|)|planeje|diagnosti(?:ca|que)|audita(?:r|)|audite|avalia(?:r|)|avalie|estuda(?:r|)|estude|verifica(?:r|)|verifique|implementa(?:r|)|implemente|reescreve(?:r|)|reescreva|pesquisa(?:r|)|pesquise|conserta(?:r|)|conserte|prop[õo]e|prop[õo]r|proponha|sugere|sugira|comenta(?:r|)|comente|opina(?:r|)|opine|descrev(?:e|er|a)|esboça(?:r|)|esboce|estima(?:r|)|estime)\b/iu', $rawText) === 1) {
            return null;
        }
        // Payload após dois-pontos.
        if (preg_match('/:\s*\S{3,}/u', $rawText) === 1) {
            return null;
        }

        // Conta palavras de conteúdo após remover stopwords/IA names.
        $stopWords = [
            'pro', 'pra', 'para', 'codex', 'claude', 'gpt', 'chatgpt', 'ia',
            'manda', 'mande', 'mandar', 'pergunta', 'pergunte', 'perguntar',
            'pede', 'peça', 'pedir', 'fala', 'fale', 'falar',
            'joga', 'jogue', 'jogar', 'passa', 'passe', 'passar',
            'leva', 'leve', 'levar', 'a', 'o', 'um', 'uma', 'os', 'as',
            'esse', 'essa', 'este', 'esta', 'isso', 'isto', 'aquilo',
            'que', 'de', 'do', 'da', 'no', 'na', 'em', 'me', 'eu', 'tu', 'aí',
        ];
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($rawText))) ?: [];
        $content = array_filter($tokens, static function (string $w) use ($stopWords): bool {
            if ($w === '') {
                return false;
            }
            if (mb_strlen($w) <= 2) {
                return false;
            }

            return ! in_array($w, $stopWords, true);
        });
        if (count($content) >= 2) {
            return null;
        }

        // Provider explícito → personalizar a pergunta.
        $providerLabel = $provider === 'codex' ? 'ao Codex' : ($provider === 'claude' ? 'ao Claude' : 'à IA');

        return "O que exatamente devo pedir {$providerLabel}? Diga o objetivo, ex.: investigar lentidão.";
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · mode=governed_execute sem comando
     * concreto. "executa no terminal" sem `:` e sem nome de script/teste.
     */
    public function detectMissingExecuteTarget(string $rawText, string $mode): ?string
    {
        if ($mode !== VoxSchema::MODE_GOVERNED_EXECUTE) {
            return null;
        }

        $isTerminalGeneric = preg_match(
            '/\b(?:executa|execute|executar|roda|rode|rodar)\s+(?:no|do|esse)\s+terminal\b/iu',
            $rawText,
        ) === 1;
        if (! $isTerminalGeneric) {
            return null;
        }
        // Payload colon ("executa no terminal: ls -la") → ok.
        if (preg_match('/:\s*\S{2,}/u', $rawText) === 1) {
            return null;
        }
        // Mention of a concrete script/test/binary → ok.
        if (preg_match(
            '/\b(?:os?\s+)?(?:teste|testes|spec|specs|migration|migrations|build|composer|npm|pnpm|yarn|pest|phpunit|artisan|comando|script|tarefa|task)\b/iu',
            $rawText,
        ) === 1) {
            return null;
        }
        // Nome próprio ou filename mencionado → ok (operador apontou alvo).
        if ($this->hasSpecificIdentifier($rawText)) {
            return null;
        }

        return 'Qual comando rodar no terminal? Diga: "executa no terminal: <comando>" ou cite o script.';
    }

    /**
     * V6.5-CLARIFICATION-ENGINE · "faz isso" genérico sem verbo concreto.
     * O router classifica como governed_execute com peso baixo; aqui
     * pedimos verbo concreto antes de tratar como ação.
     */
    public function detectGenericFaz(string $rawText, bool $hasContext): ?string
    {
        if ($hasContext) {
            return null;
        }
        if (preg_match(
            '/^\s*(?:fa[zç]a?|fazer)\s+(?:isso|isto|aquilo|aqui)(?:\s+(?:aqui|agora|pra\s+mim|por\s+favor))?\s*\.?\s*$/iu',
            $rawText,
        ) === 1) {
            return 'O que devo fazer? Diga o verbo concreto: rodar, editar, mandar pro Codex.';
        }

        return null;
    }

    /**
     * V6.5 · detecta duas intenções no mesmo enunciado SOMENTE quando a
     * primeira intenção não tem alvo claro (verbo + conjunção imediata).
     * Frases com alvo concreto na primeira parte ("melhora esse texto e
     * cria prompt pro Codex") são delegadas pro Composite Splitter, que
     * monta um plano em steps com `single_safe_step` policy.
     *
     * V6.5-CLARIFICATION-ENGINE · scoped pra resolver conflito com composite.
     */
    public function detectDoubleIntent(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        // Polish verb IMEDIATAMENTE seguido de "e/depois/então" — sinal de
        // que o polish veio sem alvo e a frase emendou outra intenção.
        // "melhora e cria prompt pro Codex" → match.
        // "melhora esse texto e cria prompt pro Codex" → NÃO match (tem alvo).
        if (preg_match(
            '/\b(?:melhora|melhore|melhorar|organiza|organize|polir|reescreve|reescreva|limpa|limpe|arruma|arrume|corrige|corrija)\s+(?:e|depois|ent[ãa]o)\s+(?:cria|crie|criar|monta|monte|montar|faz|faça|fazer|gera|gere|gerar)\b/iu',
            $text,
        ) === 1
            && preg_match('/\b(?:codex|claude|gpt|chatgpt|ia)\b/iu', $text) === 1
        ) {
            return 'Você quer melhorar o texto antes ou pedir o prompt direto pro Codex/Claude? Vou seguir um caminho de cada vez.';
        }

        return null;
    }

    /**
     * V6.5 · detecta intenção explícita de captura como nota/inbox.
     * Cobre "salva no inbox", "salva isso no inbox", "salva como nota",
     * "cria uma nota", "joga no inbox" — variantes coloquiais reais.
     */
    public function mentionsNoteIntent(string $rawText): bool
    {
        if ($rawText === '') {
            return false;
        }

        return preg_match(
            '/\b(?:salva|salve|guarda|guarde|joga|jogue|joga[r]?|p[õo]e|coloca|capture|captura)\b[^\n]{0,20}\b(?:no\s+inbox|na\s+inbox|como\s+nota|na\s+nota|na\s+captura)\b|\bcria(?:r)?\s+(?:uma\s+)?nota\b|\b(?:em|na|no)\s+(?:nota|inbox)\b/iu',
            $rawText,
        ) === 1;
    }

    /**
     * Heurística de identificador específico: nome próprio TitleCase
     * (≥ 2 caracteres pra evitar palavras como "Um"/"Eu"/"Já"), arquivo
     * com extensão conhecida, ou path absoluto/relativo. Tudo isso é
     * sinal suficiente de que o alvo está nomeado.
     */
    public function hasSpecificIdentifier(string $rawText): bool
    {
        if ($rawText === '') {
            return false;
        }
        // TitleCase ≥ 3 chars, excluindo início de frase comum em PT-BR.
        if (preg_match('/(?<![\.\?\!]\s)(?<!^)\b[A-Z][a-zA-Z][a-zA-Z0-9_]+\b/u', $rawText) === 1) {
            return true;
        }
        // Captura proper noun no início também (ex.: "Codex investiga isso").
        if (preg_match('/^\s*[A-Z][a-zA-Z][a-zA-Z0-9_]+\b/u', $rawText) === 1) {
            return true;
        }
        // Filename com extensão conhecida.
        if (preg_match('/\b[a-zA-Z0-9_-]+\.(?:php|ts|tsx|js|jsx|rs|py|md|json|html|css|yml|yaml|sh|sql|toml|lock)\b/iu', $rawText) === 1) {
            return true;
        }
        // Path absoluto ou ./relativo.
        if (preg_match('/(?:\.\/|\/)[a-zA-Z0-9_\-\/.]+/u', $rawText) === 1) {
            return true;
        }

        return false;
    }
}
