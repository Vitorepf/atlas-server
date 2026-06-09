<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Routing;

use App\Services\Ai\Vox\VoxSchema;

/**
 * Atlas Vox V4 — Auto Mode Router (Contextual Operator).
 *
 * Lê um VoxTranscript (e contexto opcional) e devolve qual modo o Kernel
 * deveria usar por padrão, para que o operador não precise escolher
 * manualmente entre Ditado / Melhorar / Criar prompt / Executar.
 *
 * Hard contract (Leis 0 / 0.5 / 0.75):
 *   - Determinístico. Sem rede, sem LLM, sem provider call, sem random.
 *   - NUNCA escolhe `governed_execute` sem `needs_confirmation=true`.
 *   - Conservador: quando a confiança fica abaixo do auto-threshold ou a
 *     diferença entre o top e o runner-up é pequena, devolve alternativas
 *     para a UI perguntar antes de executar qualquer coisa.
 *   - NÃO substitui os modos manuais. O Desktop pode ignorar a sugestão
 *     e enviar `mode_requested` explicitamente para /ai/vox/intent.
 *
 * Output schema canônico: `atlas.vox.auto_mode_decision.v1`.
 *
 * O classificador é puro (sem deps) — propositadamente independente do
 * VoxCompiler para que possa rodar antes do compile e propor o modo sem
 * precisar passar pelo pipeline R0 completo. Para evitar drift, ele
 * compartilha a lista de marcadores R4 com {@see \App\Services\Ai\Vox\VoxIntentExtractor}
 * via a constante {@see self::R4_HARD_VETO} (cópia auditada).
 */
final class VoxAutoModeRouter
{
    public const SCHEMA = VoxSchema::AUTO_MODE_DECISION;

    /** Acima disso o router emite a decisão sem pedir confirmação. */
    private const CONFIDENCE_AUTO = 0.72;

    /** Margem mínima sobre o runner-up para considerar a decisão "clara". */
    private const CONFIDENCE_GAP_CLEAR = 0.18;

    /** Margem para considerar a decisão "muito ambígua" (força alternativas). */
    private const CONFIDENCE_GAP_AMBIGUOUS = 0.12;

    /** Texto muito curto: damos um empurrão em `governed_execute` porque
     * fala curta tipicamente é comando ("roda os testes"). */
    private const SHORT_TEXT_TOKENS = 6;

    /**
     * Marcadores destrutivos. Espelha a tabela `R4_HARD_VETO` do
     * `VoxIntentExtractor`. Drift é controlado por teste: o teste do
     * router carrega os mesmos casos canônicos de R4 do extractor.
     *
     * @var list<array{label: string, pattern: string}>
     */
    private const R4_HARD_VETO = [
        ['label' => 'rm_rf',           'pattern' => '/\brm\s+-rf\b/iu'],
        ['label' => 'sudo',            'pattern' => '/\bsudo\b/iu'],
        // V6-FPG-C · transcrição falada não tem "=". Aceita "dd if=" e "dd if ".
        ['label' => 'dd_if',           'pattern' => '/\bdd\s+if(?:=|\s)/iu'],
        ['label' => 'mkfs',            'pattern' => '/\bmkfs\b/iu'],
        // V6-AUTO-MODE-FINAL · fala natural raramente diz "--force"; aceita
        // "git push force", "git push --force", "git push -f".
        ['label' => 'git_push_force',  'pattern' => '/\bgit\s+push\s+(?:--force|-f|force)\b/iu'],
        ['label' => 'git_reset_hard',  'pattern' => '/\bgit\s+reset\s+--hard\b/iu'],
        ['label' => 'drop_database',   'pattern' => '/\bdrop\s+database\b/iu'],
        ['label' => 'truncate_table',  'pattern' => '/\btruncate(?:\s+table)?\b/iu'],
        ['label' => 'curl_pipe_shell', 'pattern' => '/\bcurl\b[^\n]*\|\s*(?:sh|bash|zsh)\b/iu'],
        ['label' => 'wget_pipe_shell', 'pattern' => '/\bwget\b[^\n]*\|\s*(?:sh|bash|zsh)\b/iu'],
        ['label' => 'apagar_tudo',     'pattern' => '/\bapag(?:a|ar)\s+tudo\b/iu'],
        ['label' => 'deletar_projeto', 'pattern' => '/\bdelet(?:a|ar)\s+(?:o\s+)?projeto\b/iu'],
        ['label' => 'deploy',          'pattern' => '/\b(?:fazer\s+)?deploy\b/iu'],
        ['label' => 'force_push',      'pattern' => '/\bforce[\s\-]push\b/iu'],
    ];

    /**
     * Frases que pedem para uma IA externa fazer algo. Casamento por
     * substring (texto já em lowercase). Pesos somam até o limite de 1.0.
     *
     * @var list<array{pattern: string, weight: float, kind: 'phrase'|'regex'}>
     */
    private const INTENT_COMPILE_TRIGGERS = [
        // Invocação direta da IA via verbo + nome.
        ['pattern' => 'manda pro codex',         'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'manda pro claude',        'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'manda pra ia',            'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'manda essa pra ia',       'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'manda pro chatgpt',       'weight' => 0.85, 'kind' => 'phrase'],
        // V6-AUTO-MODE-FINAL · pronomes neutros + IA. "esse trem" já vira
        // "isso" no normalise(), então cobrimos goianês + falar coloquial.
        ['pattern' => 'manda isso pro codex',    'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'manda isso pro claude',   'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'manda isso pra ia',       'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'manda essa pro codex',    'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'manda essa pro claude',   'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'manda aquilo pro codex',  'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'manda aquilo pro claude', 'weight' => 0.86, 'kind' => 'phrase'],
        // Catch-all em regex: "manda/joga/passa <pron> <pro|pra> <ia>".
        ['pattern' => '/\b(?:manda|joga|passa|leva)\s+(?:isso|essa|aquilo|aquela)\s+(?:pr[oa]|para\s+(?:o|a)?\s*)(?:codex|claude|gpt|chatgpt|ia)\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => 'pergunta pro codex',      'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'pergunta pro claude',     'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'pergunta pra ia',         'weight' => 0.85, 'kind' => 'phrase'],
        ['pattern' => 'pede pro codex',          'weight' => 0.85, 'kind' => 'phrase'],
        ['pattern' => 'pede pro claude',         'weight' => 0.85, 'kind' => 'phrase'],
        ['pattern' => 'pede pra ia',             'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'fala pro codex',          'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'fala pro claude',         'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'joga pro codex',          'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'joga pro claude',         'weight' => 0.82, 'kind' => 'phrase'],
        // Construção de prompt.
        ['pattern' => 'cria um prompt',          'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'cria uma prompt',         'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'criar um prompt',         'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'monta um prompt',         'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'monta uma prompt',        'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'gera um prompt',          'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'gera uma prompt',         'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'transforma em prompt',    'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'vira prompt',             'weight' => 0.78, 'kind' => 'phrase'],
        ['pattern' => 'prompt pro codex',        'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'prompt pro claude',       'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'prompt pra ia',           'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'briefing pro codex',      'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'briefing pro claude',     'weight' => 0.82, 'kind' => 'phrase'],
        // Pedidos de ajuda para formular.
        ['pattern' => 'me ajuda a pedir',        'weight' => 0.74, 'kind' => 'phrase'],
        ['pattern' => 'me ajuda a montar',       'weight' => 0.70, 'kind' => 'phrase'],
        ['pattern' => 'me ajuda a escrever um prompt', 'weight' => 0.86, 'kind' => 'phrase'],
        // V6-AUTO-MODE-FINAL · pedido "olha esse <coisa>": Vitor quase
        // sempre quer que a IA olhe pra ele — peso médio porque "olha"
        // sozinho também é dictation; o confirm cobre o resto.
        ['pattern' => '/\bolha\s+(?:esse|este|essa|esta|o|a)\s+(?:arquivo|texto|trecho|conteudo|conteúdo|c[óo]digo|prompt|m[óo]dulo|servi[çc]o|controller|model|teste|migration|comando|fluxo)\b/iu', 'weight' => 0.74, 'kind' => 'regex'],
        ['pattern' => '/\b(?:olha|d[áa]\s+uma\s+olhada)\s+(?:isso|aquilo)\s+pra\s+mim\b/iu', 'weight' => 0.70, 'kind' => 'regex'],
        // "só analisa esse trecho" / "só lê pra mim" → diagnostic puro,
        // ainda quer ajuda da IA. Confirma porque sinal fraco.
        ['pattern' => '/\b(?:s[óo]|somente|apenas)\s+(?:analisa|analise|investiga|investigue|l[êe]|leia|olha)\s+(?:isso|esse|essa|aquilo|esses|essas|aquele|aquela|o|a)\b/iu', 'weight' => 0.72, 'kind' => 'regex'],
        ['pattern' => '/\bme\s+ajuda\s+a\s+pensar\b/iu', 'weight' => 0.74, 'kind' => 'regex'],
        ['pattern' => '/\bme\s+ajuda\s+com\s+(?:isso|essa|esse|aquilo)\b/iu', 'weight' => 0.62, 'kind' => 'regex'],
        // V6-FPG · pedidos canônicos do brief.
        ['pattern' => 'faz um prompt poderoso',  'weight' => 0.92, 'kind' => 'phrase'],
        ['pattern' => 'faz um prompt forte',     'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'estruture para ia',       'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'estruture pra ia',        'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'estrutura isso pra ia',   'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'estrutura pra ia',        'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'transforma em prompt pra', 'weight' => 0.86, 'kind' => 'phrase'],
        // Nome da IA + verbo de pesquisa/implementação (regex preserva ordem).
        ['pattern' => '/\b(?:codex|claude|gpt|ia)\b[^\n]{0,40}\b(?:investiga(?:r|)|investigue|analisa(?:r|)|analise|planeja(?:r|)|planeje|implementa(?:r|)|implemente|diagnostica(?:r|)|diagnostique|estuda(?:r|)|estude)\b/iu', 'weight' => 0.80, 'kind' => 'regex'],
        ['pattern' => '/\b(?:investiga(?:r|)|investigue|analisa(?:r|)|analise|planeja(?:r|)|planeje|implementa(?:r|)|implemente|diagnostica(?:r|)|diagnostique)\b[^\n]{0,40}\b(?:codex|claude|gpt|ia)\b/iu', 'weight' => 0.80, 'kind' => 'regex'],
    ];

    /**
     * @var list<array{pattern: string, weight: float, kind: 'phrase'|'regex'}>
     */
    private const PROMPT_POLISH_TRIGGERS = [
        ['pattern' => 'melhora isso',            'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'melhora essa',            'weight' => 0.80, 'kind' => 'phrase'],
        ['pattern' => 'melhora esse texto',      'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'melhora esse prompt',     'weight' => 0.92, 'kind' => 'phrase'],
        ['pattern' => 'melhore isso',            'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'melhore esse texto',      'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'melhore esse prompt',     'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'deixa esse prompt mais forte', 'weight' => 0.92, 'kind' => 'phrase'],
        ['pattern' => 'deixa esse texto mais',   'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais claro',        'weight' => 0.80, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais forte',        'weight' => 0.80, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais limpo',        'weight' => 0.78, 'kind' => 'phrase'],
        ['pattern' => 'organiza esse texto',     'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'organiza esse prompt',    'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'organiza isso',           'weight' => 0.74, 'kind' => 'phrase'],
        ['pattern' => 'organiza essa',           'weight' => 0.72, 'kind' => 'phrase'],
        ['pattern' => 'limpa esse texto',        'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'limpa esse prompt',       'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'limpa isso',              'weight' => 0.72, 'kind' => 'phrase'],
        ['pattern' => 'reescreve isso',          'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'reescreve esse',          'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'reescreva isso',          'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'reescreva esse',          'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'corrige a pontuacao',     'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'corrige a pontuação',     'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'corrige a gramatica',     'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'corrige a gramática',     'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'corrige o portugues',     'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'corrige o português',     'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'formata esse texto',      'weight' => 0.80, 'kind' => 'phrase'],
        ['pattern' => 'polir esse texto',        'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'polir esse prompt',       'weight' => 0.82, 'kind' => 'phrase'],
        ['pattern' => 'polir essa fala',         'weight' => 0.80, 'kind' => 'phrase'],
        ['pattern' => 'arruma esse texto',       'weight' => 0.78, 'kind' => 'phrase'],
        ['pattern' => 'arruma esse prompt',      'weight' => 0.78, 'kind' => 'phrase'],
        ['pattern' => 'só limpa',                'weight' => 0.66, 'kind' => 'phrase'],
        ['pattern' => 'so limpa',                'weight' => 0.66, 'kind' => 'phrase'],
        // V6-FPG · canônicos do brief: "deixa mais profissional", etc.
        ['pattern' => 'deixa mais profissional', 'weight' => 0.92, 'kind' => 'phrase'],
        ['pattern' => 'deixa esse texto mais profissional', 'weight' => 0.94, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais formal',       'weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais elegante',     'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'mais profissional',       'weight' => 0.74, 'kind' => 'phrase'],
        // V6-DOGFOOD · #10 caiu em dictation porque "deixa mais firme" não
        // tinha trigger. Adjetivos de tom plausíveis que o operador usa.
        ['pattern' => 'deixa mais firme',        'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais sério',        'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais serio',        'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais direto',       'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais assertivo',    'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'deixa mais educado',      'weight' => 0.82, 'kind' => 'phrase'],
        // V6-AUTO-MODE-FINAL · "deixa isso mais X" / "deixa essa fala mais X".
        ['pattern' => 'deixa isso mais profissional', 'weight' => 0.92, 'kind' => 'phrase'],
        ['pattern' => 'deixa isso mais formal',  'weight' => 0.90, 'kind' => 'phrase'],
        ['pattern' => 'deixa isso mais elegante','weight' => 0.88, 'kind' => 'phrase'],
        ['pattern' => 'deixa isso mais claro',   'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'deixa isso mais forte',   'weight' => 0.84, 'kind' => 'phrase'],
        ['pattern' => 'deixa essa fala mais',    'weight' => 0.82, 'kind' => 'phrase'],
        // "melhora isso" / "organiza isso" já existem; cobrimos a forma
        // sem pronome explícito também: "melhora aqui pra mim".
        ['pattern' => 'melhora aqui pra mim',    'weight' => 0.74, 'kind' => 'phrase'],
        ['pattern' => 'organiza aqui pra mim',   'weight' => 0.72, 'kind' => 'phrase'],
        ['pattern' => 'corrige isso',            'weight' => 0.80, 'kind' => 'phrase'],
        ['pattern' => 'corrige esse texto',      'weight' => 0.86, 'kind' => 'phrase'],
        ['pattern' => 'organiza esse pensamento','weight' => 0.84, 'kind' => 'phrase'],
    ];

    /**
     * @var list<array{pattern: string, weight: float, kind: 'phrase'|'regex'}>
     */
    private const GOVERNED_EXECUTE_TRIGGERS = [
        // Terminal e execução.
        ['pattern' => '/\b(?:roda|rode|rodar|executa(?:r|)|execute)\s+(?:os?\s+)?(?:teste|testes|specs?|pest|phpunit|composer|npm|pnpm|yarn|migration|migrations)\b/iu', 'weight' => 0.92, 'kind' => 'regex'],
        ['pattern' => '/\b(?:roda|rode|rodar)\s+(?:no|do|esse)\s+terminal\b/iu', 'weight' => 0.90, 'kind' => 'regex'],
        ['pattern' => '/\bno\s+terminal\b/iu',                       'weight' => 0.74, 'kind' => 'regex'],
        ['pattern' => '/\babre\s+(?:o\s+)?terminal\b/iu',           'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => '/\bexecuta\s+esse\s+comando\b/iu',           'weight' => 0.92, 'kind' => 'regex'],
        ['pattern' => '/\bexecutar?\s+esse\s+comando\b/iu',         'weight' => 0.90, 'kind' => 'regex'],
        ['pattern' => '/\bexecuta\s+o\s+comando\b/iu',              'weight' => 0.90, 'kind' => 'regex'],
        // V6-DOGFOOD · #17 caiu em dictation: "propõe um comando para ver
        // arquivos modificados". Vitor pediu PROPOSTA, não execução — o
        // executor terminal_propose existe pra isso e nunca roda nada
        // automaticamente, então governed_execute com confirmação é seguro.
        ['pattern' => '/\b(?:prop[õo]e|prop[õo]r|propor|prop[õo]nha|sugere|sugir|sugerir|sugira)\s+(?:um|o)\s+comando\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => '/\bme\s+(?:d[áa]|d[êe]|d[ar])\s+(?:um|o)\s+comando\b/iu', 'weight' => 0.78, 'kind' => 'regex'],
        // Verbos operacionais frequentes.
        ['pattern' => '/\b(?:edita|edite|editar)\s+(?:o\s+|a\s+|esse\s+|essa\s+|esses\s+|essas\s+)?(?:arquivo|arquivos|pasta|diretorio|diretório|linha)\b/iu', 'weight' => 0.88, 'kind' => 'regex'],
        ['pattern' => '/\b(?:cria|crie|criar)\s+(?:o\s+|um\s+|uma\s+)?(?:arquivo|pasta|diretorio|diretório|branch|teste|migration|seed)\b/iu', 'weight' => 0.88, 'kind' => 'regex'],
        ['pattern' => '/\b(?:abre|abra|abrir)\s+(?:o\s+|a\s+|esse\s+|essa\s+)?(?:arquivo|pasta|projeto|repositorio|repositório|terminal|workspace)\b/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\b(?:apaga|apague|apagar|deleta|delete|deletar|remove|remova|remover)\s+(?:o\s+|a\s+|esse\s+|essa\s+|esses\s+|essas\s+)?(?:arquivo|arquivos|pasta|branch|linha|migracao|migração)\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => '/\b(?:renomeia|renomeie|renomear|move|mover|mova)\s+(?:o\s+|a\s+|esse\s+|essa\s+)?(?:arquivo|pasta|diretorio|diretório)\b/iu', 'weight' => 0.82, 'kind' => 'regex'],
        ['pattern' => '/\b(?:faz|faça|fazer|fa[zç]a)\s+(?:o\s+)?commit\b/iu', 'weight' => 0.90, 'kind' => 'regex'],
        ['pattern' => '/\bcomit(?:a|e|ar)\b/iu',                    'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\bfaz\s+(?:o\s+)?push\b/iu',                'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => '/\bgit\s+(?:status|add|commit|push|pull|fetch|merge|rebase|checkout|branch|stash)\b/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\baplica\s+(?:essa|esse|o|a)\s+(?:mudanc|mudanç|altera|patch|diff|fix|correc|correç)/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\baplica\s+isso\b/iu',                      'weight' => 0.72, 'kind' => 'regex'],
        ['pattern' => '/\binstala(?:r|)\s+(?:o\s+|a\s+|esse\s+|essa\s+)?(?:pacote|dependencia|dependência|lib|biblioteca|composer|npm|pnpm)/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\bbuild\s+(?:do|da|de)\b/iu',               'weight' => 0.78, 'kind' => 'regex'],
        ['pattern' => '/\bderruba\s+(?:o\s+|a\s+)?(?:servidor|server|container|docker)\b/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\b(?:sobe|subir|levanta|levantar)\s+(?:o\s+|a\s+)?(?:servidor|server|container|docker|laravel)\b/iu', 'weight' => 0.80, 'kind' => 'regex'],
        ['pattern' => '/\bphp\s+artisan\b/iu',                      'weight' => 0.78, 'kind' => 'regex'],
        ['pattern' => '/\bnpm\s+(?:run|test|build|install)\b/iu',  'weight' => 0.80, 'kind' => 'regex'],
        ['pattern' => '/\bpnpm\s+(?:run|test|build|install)\b/iu', 'weight' => 0.80, 'kind' => 'regex'],
        ['pattern' => '/\b(?:roda|rode|executa|execute)\s+(?:o\s+)?(?:script|comando)\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        // V6-FPG · verbos operacionais canônicos do brief: altera/edita/aplica/cria arquivo.
        ['pattern' => '/\b(?:altera|altere|alterar)\s+(?:o\s+|a\s+|os\s+|as\s+|esse\s+|essa\s+|esses\s+|essas\s+)?(?:arquivo|valor|campo|config|configuração|linha|coluna|trecho|migration|seed|teste|prompt)\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => '/\b(?:edita|edite|editar)\s+(?:o\s+|a\s+|esse\s+|essa\s+)?(?:trecho|valor|linha|coluna|config|configuração|migration|seed)\b/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\b(?:aplica|aplique|aplicar)\s+(?:o\s+|a\s+|esse\s+|essa\s+|esses\s+|essas\s+)?(?:patch|diff|fix|correção|correcao|altera[çc][ãa]o|sugest[ãa]o|mudan[çc]a)\b/iu', 'weight' => 0.88, 'kind' => 'regex'],
        ['pattern' => '/\b(?:cria|crie|criar)\s+(?:o\s+|um\s+|uma\s+|os\s+|umas\s+)?arquivo\b/iu', 'weight' => 0.90, 'kind' => 'regex'],
        ['pattern' => '/\babre\s+(?:o\s+|esse\s+)?(?:atlas|atlas\s+code|c[óo]digo|arquivo)\b/iu', 'weight' => 0.80, 'kind' => 'regex'],
        // V6-AUTO-MODE-FINAL · verbo destrutivo + pronome neutro. Confirmação
        // obrigatória (governed_execute sempre exige). Sem isso, "apaga isso
        // aí" caía em dictation → risco real de o operador achar que falou
        // pra IA mas a UI ignorou. R4 propriamente dito continua vencendo
        // via detectR4() (rm -rf, drop database, …).
        ['pattern' => '/\b(?:apaga|apague|apagar|deleta|delete|deletar|remove|remova|remover)\s+(?:isso|essa|esse|esses|essas|aquilo|aquele|aquela|aqui)\b/iu', 'weight' => 0.84, 'kind' => 'regex'],
        // Verbo de execução + pronome neutro: "roda isso", "executa isso aí".
        ['pattern' => '/\b(?:roda|rode|rodar|executa|execute|executar)\s+(?:isso|essa|esse|esses|essas|aquilo|aqui)\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        // "faz isso" sozinho é ambíguo (pode virar polish ou intent_compile).
        // Mantemos peso baixo pra forçar `needs_confirmation` em vez de
        // disparar execução. Brief V6-AUTO-MODE-FINAL: nunca executar direto.
        ['pattern' => '/\b(?:faz|faça|fazer)\s+(?:isso|essa|esse|aquilo)\s+(?:aqui|agora|pra\s+mim|por\s+favor)?\b/iu', 'weight' => 0.52, 'kind' => 'regex'],
    ];

    /**
     * @var list<array{pattern: string, weight: float, kind: 'phrase'|'regex'}>
     */
    private const DICTATION_TRIGGERS = [
        ['pattern' => '/\banota\s+(?:isso|que|aqui|essa|esse|o\s+seguinte)\b/iu', 'weight' => 0.88, 'kind' => 'regex'],
        ['pattern' => '/\banote\s+(?:isso|que|aqui|essa|esse|o\s+seguinte)\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => '/\bescreve\s+(?:isso|aqui|essa|esse)\b/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\bescreva\s+(?:isso|aqui|essa|esse)\b/iu', 'weight' => 0.82, 'kind' => 'regex'],
        ['pattern' => '/\bcoloca\s+(?:esse\s+texto|isso|aqui|esse\s+conteudo|esse\s+conteúdo)\b/iu', 'weight' => 0.86, 'kind' => 'regex'],
        ['pattern' => '/\bcola\s+(?:isso|aqui|esse\s+texto)\b/iu', 'weight' => 0.78, 'kind' => 'regex'],
        ['pattern' => '/\bsalva\s+(?:isso|essa|esse|esses)\s+(?:como\s+)?(?:nota|inbox|captura)\b/iu', 'weight' => 0.84, 'kind' => 'regex'],
        ['pattern' => '/\binsere\s+(?:esse\s+texto|isso|aqui)\b/iu', 'weight' => 0.80, 'kind' => 'regex'],
        ['pattern' => '/\binsira\s+(?:esse\s+texto|isso|aqui)\b/iu', 'weight' => 0.80, 'kind' => 'regex'],
        ['pattern' => '/\bdita(?:r|)\s+(?:isso|essa)\b/iu',         'weight' => 0.78, 'kind' => 'regex'],
        ['pattern' => '/\bcaptura\s+(?:isso|essa\s+ideia|esse\s+pensamento)\b/iu', 'weight' => 0.82, 'kind' => 'regex'],
    ];

    /**
     * Entry point.
     *
     * @param  array<string,mixed>  $transcript  VoxTranscript-like payload
     *                                           (only `text` is required).
     * @param  array<string,mixed>  $context     Reservado para V4+ (active
     *                                           window, selection, surface).
     *                                           Inalterado em V4 W1; presente
     *                                           para preservar a assinatura.
     * @return array{
     *   schema: string,
     *   selected_mode: string,
     *   confidence: float,
     *   reason_pt_br: string,
     *   needs_confirmation: bool,
     *   alternatives: list<array{mode: string, confidence: float, reason_pt_br: string}>,
     *   signals: array<string, float>,
     *   markers: array<string,mixed>,
     *   router_version: string
     * }
     */
    public function decide(array $transcript, array $context = []): array
    {
        $raw = (string) ($transcript['text'] ?? '');
        $normalised = $this->normalise($raw);

        // 1) R4 hard-veto wins everything else: route to governed_execute
        //    with high confidence but always require confirmation.
        $r4 = $this->detectR4($normalised, $raw);
        if ($r4 !== null) {
            return $this->makeDecision(
                mode: VoxSchema::MODE_GOVERNED_EXECUTE,
                confidence: 0.95,
                reason: "Detectei algo potencialmente destrutivo (\"{$r4}\"). Vou sugerir Executar e pedir confirmação explícita.",
                needsConfirmation: true,
                alternatives: [
                    [
                        'mode' => VoxSchema::MODE_INTENT_COMPILE,
                        'confidence' => 0.30,
                        'reason_pt_br' => 'Se você só quer descrever o pedido para a IA sem executar nada.',
                    ],
                    [
                        'mode' => VoxSchema::MODE_DICTATION,
                        'confidence' => 0.20,
                        'reason_pt_br' => 'Se é só uma fala livre que você quer inserir como texto.',
                    ],
                ],
                signals: [
                    VoxSchema::MODE_GOVERNED_EXECUTE => 0.95,
                    VoxSchema::MODE_INTENT_COMPILE => 0.30,
                    VoxSchema::MODE_PROMPT_POLISH => 0.05,
                    VoxSchema::MODE_DICTATION => 0.20,
                ],
                markers: ['r4_marker' => $r4],
            );
        }

        // 2) Score each mode independently.
        $scores = [
            VoxSchema::MODE_INTENT_COMPILE => $this->scoreMatches($normalised, self::INTENT_COMPILE_TRIGGERS),
            VoxSchema::MODE_PROMPT_POLISH => $this->scoreMatches($normalised, self::PROMPT_POLISH_TRIGGERS),
            VoxSchema::MODE_GOVERNED_EXECUTE => $this->scoreMatches($normalised, self::GOVERNED_EXECUTE_TRIGGERS),
            VoxSchema::MODE_DICTATION => $this->scoreMatches($normalised, self::DICTATION_TRIGGERS),
        ];

        // 3) Disambiguation: "cria/monta um prompt" must beat
        //    "cria/monta um arquivo". When the intent-compile and
        //    governed-execute triggers both fired for the same verb,
        //    keep only the stronger of the two.
        $scores = $this->disambiguatePromptVsFile($normalised, $scores);

        // 4) Dictation default floor. Even with zero triggers we still
        //    keep a small score so the fallback is "tratar como texto livre".
        if ($scores[VoxSchema::MODE_DICTATION] < 0.30) {
            $scores[VoxSchema::MODE_DICTATION] = max($scores[VoxSchema::MODE_DICTATION], 0.30);
        }

        // 5) Length nudge: very short transcripts that mention an
        //    operational verb get a small boost on governed_execute,
        //    and long prose with no triggers boosts dictation.
        $tokens = $this->countTokens($normalised);
        if ($tokens >= 25
            && $scores[VoxSchema::MODE_GOVERNED_EXECUTE] < 0.45
            && $scores[VoxSchema::MODE_INTENT_COMPILE] < 0.45
            && $scores[VoxSchema::MODE_PROMPT_POLISH] < 0.45
        ) {
            $scores[VoxSchema::MODE_DICTATION] = min(0.84, $scores[VoxSchema::MODE_DICTATION] + 0.20);
        }
        if ($tokens <= self::SHORT_TEXT_TOKENS
            && $scores[VoxSchema::MODE_GOVERNED_EXECUTE] >= 0.55
        ) {
            $scores[VoxSchema::MODE_GOVERNED_EXECUTE] = min(0.95, $scores[VoxSchema::MODE_GOVERNED_EXECUTE] + 0.05);
        }

        // 6) Pick top + decide on confirmation / alternatives.
        arsort($scores);
        /** @var list<string> $modesOrdered */
        $modesOrdered = array_keys($scores);
        $top = $modesOrdered[0];
        $topScore = $scores[$top];
        $secondMode = $modesOrdered[1] ?? null;
        $secondScore = $secondMode !== null ? $scores[$secondMode] : 0.0;
        $gap = $topScore - $secondScore;

        $needsConfirmation = false;
        // Always confirm governed_execute (Lei 0.9): we never execute on auto.
        if ($top === VoxSchema::MODE_GOVERNED_EXECUTE) {
            $needsConfirmation = true;
        }
        if ($topScore < self::CONFIDENCE_AUTO) {
            $needsConfirmation = true;
        }
        if ($gap < self::CONFIDENCE_GAP_AMBIGUOUS && $secondScore >= 0.40) {
            $needsConfirmation = true;
        }

        $alternatives = [];
        if ($gap < self::CONFIDENCE_GAP_CLEAR || $needsConfirmation) {
            $alternatives = $this->buildAlternatives($top, $scores);
        }
        // When we're going to ask the operator for confirmation, we must
        // surface at least one alternative even if no other mode scored
        // above the visibility floor — otherwise the overlay would offer
        // a yes/no question with no actual choice. The complementary
        // alternative is chosen by complement (a dictation top falls back
        // to intent_compile; a polish top falls back to dictation; etc.).
        if ($needsConfirmation && $alternatives === []) {
            $alternatives = [[
                'mode' => $this->complementaryMode($top),
                'confidence' => 0.30,
                'reason_pt_br' => $this->alternativeReason($this->complementaryMode($top)),
            ]];
        }

        $reason = $this->reasonFor($top, $normalised, $topScore, $needsConfirmation);

        return $this->makeDecision(
            mode: $top,
            confidence: $this->round($topScore),
            reason: $reason,
            needsConfirmation: $needsConfirmation,
            alternatives: $alternatives,
            signals: $scores,
            markers: [],
        );
    }

    /**
     * Lowercase + normalise whitespace. Diacritics are kept on purpose so
     * regexes can match both "pontuação" and "pontuacao" explicitly.
     *
     * V6-AUTO-MODE-FINAL · normaliza fillers goianos/coloquiais que
     * funcionalmente equivalem a pronomes neutros:
     *   - "esse trem" / "este trem" / "esse troço" / "essa coisa" → "isso"
     *   - "aquele trem" / "aquela coisa" → "aquilo"
     * Sem isso, "manda esse trem pro codex" cai em dictation porque o
     * substring matcher procura "manda isso pro codex".
     */
    private function normalise(string $text): string
    {
        $t = mb_strtolower($text);
        $t = (string) preg_replace('/\b(?:esses?|estes?)\s+(?:trem|troço|troco|tro[çc]o|neg[óo]cio|bagulho|treco)\b/iu', 'isso', $t);
        $t = (string) preg_replace('/\b(?:essas?|estas?)\s+(?:coisa|parada)\b/iu', 'isso', $t);
        $t = (string) preg_replace('/\b(?:aquele|aquela)\s+(?:trem|troço|troco|tro[çc]o|coisa|neg[óo]cio)\b/iu', 'aquilo', $t);
        $t = (string) preg_replace('/\s+/u', ' ', $t);

        return trim($t);
    }

    private function detectR4(string $normalised, string $raw): ?string
    {
        $haystack = $normalised."\n".$raw;
        foreach (self::R4_HARD_VETO as $entry) {
            if (preg_match($entry['pattern'], $haystack) === 1) {
                return $entry['label'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{pattern: string, weight: float, kind: 'phrase'|'regex'}>  $triggers
     */
    private function scoreMatches(string $text, array $triggers): float
    {
        $score = 0.0;
        foreach ($triggers as $t) {
            if ($t['kind'] === 'phrase') {
                if ($t['pattern'] !== '' && str_contains($text, $t['pattern'])) {
                    $score = max($score, $t['weight']);
                }
                continue;
            }
            if (preg_match($t['pattern'], $text) === 1) {
                $score = max($score, $t['weight']);
            }
        }

        return $score;
    }

    /**
     * When the operator says "cria um prompt pro codex" we want
     * intent_compile, not governed_execute (cria + arquivo would
     * win on the file regex). When they say "cria o arquivo X" we
     * want governed_execute. This helper hard-caps the loser when the
     * winner has unambiguous lexical evidence.
     *
     * @param  array<string,float>  $scores
     * @return array<string,float>
     */
    private function disambiguatePromptVsFile(string $text, array $scores): array
    {
        $mentionsPrompt = str_contains($text, 'prompt');
        $mentionsAiName = (bool) preg_match('/\b(?:codex|claude|gpt|chatgpt|ia)\b/u', $text);
        $mentionsFileVerb = (bool) preg_match('/\b(?:arquivo|arquivos|pasta|diretorio|diretório|branch|migration|seed|teste|testes|commit)\b/u', $text);

        if ($mentionsPrompt && ! $mentionsFileVerb) {
            // "cria um prompt", "monta um prompt" — strip governed_execute
            // unless an explicit operational verb (roda/executa/edita) is
            // also present and a file target is mentioned.
            if ($scores[VoxSchema::MODE_GOVERNED_EXECUTE] > 0.50) {
                $scores[VoxSchema::MODE_GOVERNED_EXECUTE] = min($scores[VoxSchema::MODE_GOVERNED_EXECUTE], 0.40);
            }
            if ($mentionsAiName) {
                $scores[VoxSchema::MODE_INTENT_COMPILE] = max(
                    $scores[VoxSchema::MODE_INTENT_COMPILE],
                    0.84,
                );
            }
        }

        // "limpa esse texto pra inserir no inbox" — polish, not dictation,
        // even though "inbox" is mentioned.
        if (preg_match('/\b(?:limpa|organiza|melhora|reescreve|reescreva|formata|polir|arruma)\s+(?:esse|essa|isso|esses)\s+(?:texto|prompt|fala)\b/u', $text) === 1
            && $scores[VoxSchema::MODE_DICTATION] > $scores[VoxSchema::MODE_PROMPT_POLISH]
        ) {
            $scores[VoxSchema::MODE_PROMPT_POLISH] = max($scores[VoxSchema::MODE_PROMPT_POLISH], 0.84);
        }

        return $scores;
    }

    private function countTokens(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $parts = preg_split('/\s+/u', $text) ?: [];

        return count(array_filter($parts, static fn ($p) => $p !== ''));
    }

    /**
     * @param  array<string,float>  $scores
     * @return list<array{mode: string, confidence: float, reason_pt_br: string}>
     */
    private function buildAlternatives(string $top, array $scores): array
    {
        $alts = [];
        arsort($scores);
        foreach ($scores as $mode => $score) {
            if ($mode === $top) {
                continue;
            }
            if ($score < 0.30) {
                continue;
            }
            $alts[] = [
                'mode' => $mode,
                'confidence' => $this->round($score),
                'reason_pt_br' => $this->alternativeReason($mode),
            ];
            if (count($alts) === 2) {
                break;
            }
        }

        return $alts;
    }

    private function complementaryMode(string $top): string
    {
        return match ($top) {
            VoxSchema::MODE_DICTATION => VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_PROMPT_POLISH => VoxSchema::MODE_DICTATION,
            VoxSchema::MODE_INTENT_COMPILE => VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::MODE_GOVERNED_EXECUTE => VoxSchema::MODE_INTENT_COMPILE,
            default => VoxSchema::MODE_DICTATION,
        };
    }

    private function alternativeReason(string $mode): string
    {
        return match ($mode) {
            VoxSchema::MODE_DICTATION => 'Se for só inserir/colar como texto livre.',
            VoxSchema::MODE_PROMPT_POLISH => 'Se for limpar/organizar um texto existente.',
            VoxSchema::MODE_INTENT_COMPILE => 'Se for descrever pedido para a IA (Codex/Claude).',
            VoxSchema::MODE_GOVERNED_EXECUTE => 'Se for ação no sistema (terminal/arquivo) — pede confirmação.',
            default => 'Modo alternativo.',
        };
    }

    private function reasonFor(string $mode, string $text, float $confidence, bool $needsConfirmation): string
    {
        $base = match ($mode) {
            VoxSchema::MODE_DICTATION => 'Parece texto livre para inserir/colar — sem pedido de transformação ou execução.',
            VoxSchema::MODE_PROMPT_POLISH => 'Detectei pedido de limpar/melhorar um texto existente, sem invocar IA externa.',
            VoxSchema::MODE_INTENT_COMPILE => 'Detectei pedido para a IA (Codex/Claude) — vou compilar como prompt antes de qualquer execução.',
            VoxSchema::MODE_GOVERNED_EXECUTE => 'Detectei verbo de ação no sistema (terminal/arquivo) — vou tratar como Executar e pedir confirmação.',
            default => 'Sem sinal forte. Tratando como ditado por padrão.',
        };

        if ($needsConfirmation && $mode !== VoxSchema::MODE_GOVERNED_EXECUTE && $confidence < self::CONFIDENCE_AUTO) {
            $base .= ' Confiança baixa, confirme antes de seguir.';
        }

        return $base;
    }

    private function round(float $v): float
    {
        $v = max(0.0, min(1.0, $v));

        return round($v, 2);
    }

    /**
     * @param  list<array{mode: string, confidence: float, reason_pt_br: string}>  $alternatives
     * @param  array<string,float>  $signals
     * @param  array<string,mixed>  $markers
     * @return array{
     *   schema: string,
     *   selected_mode: string,
     *   confidence: float,
     *   reason_pt_br: string,
     *   needs_confirmation: bool,
     *   alternatives: list<array{mode: string, confidence: float, reason_pt_br: string}>,
     *   signals: array<string, float>,
     *   markers: array<string,mixed>,
     *   router_version: string
     * }
     */
    private function makeDecision(
        string $mode,
        float $confidence,
        string $reason,
        bool $needsConfirmation,
        array $alternatives,
        array $signals,
        array $markers,
    ): array {
        $roundedSignals = [];
        foreach ($signals as $k => $v) {
            $roundedSignals[$k] = $this->round((float) $v);
        }

        // V6-FPG · `reasons_pt_br[]` expande a explicação curta em até 3 frases
        // humanas. A primeira é a razão principal (=`reason_pt_br` legacy);
        // depois vem o sinal de confiança e (quando aplicável) o motivo da
        // confirmação. Tudo em PT-BR.
        $reasons = $this->buildReasons($mode, $confidence, $needsConfirmation, $markers, $reason);

        // V6-FPG · `fallback_mode` é o modo que o operador provavelmente
        // escolheria se rejeitasse a sugestão. Usado pela UI pra mostrar
        // "ou prefere X" sem precisar abrir o seletor completo.
        $fallback = $this->pickFallback($mode, $alternatives);

        // V6-FPG · `risk_signal` é binário e cresce o `needs_confirmation`
        // pra cima quando há marcador destrutivo. NUNCA libera execução
        // sozinho — só sinaliza pra UI mostrar "ação sensível".
        $riskSignal = isset($markers['r4_marker']) ? 'high' : (
            $mode === VoxSchema::MODE_GOVERNED_EXECUTE ? 'medium' : 'low'
        );

        return [
            'schema' => self::SCHEMA,
            'selected_mode' => $mode,
            'confidence' => $this->round($confidence),
            'reason_pt_br' => $reason,              // compatibility single-line
            'reasons_pt_br' => $reasons,            // V6-FPG · array humano
            'fallback_mode' => $fallback,           // V6-FPG · modo alternativo seguro
            'risk_signal' => $riskSignal,           // V6-FPG · low|medium|high
            'needs_confirmation' => $needsConfirmation,
            'requires_confirmation' => $needsConfirmation, // alias canon do brief
            'alternatives' => $alternatives,
            'signals' => $roundedSignals,
            'markers' => $markers,
            'router_version' => VoxSchema::AUTO_MODE_ROUTER_VERSION,
        ];
    }

    /**
     * V6-FPG · monta `reasons_pt_br` em até 3 frases curtas.
     *
     * @param  array<string,mixed>  $markers
     * @return list<string>
     */
    private function buildReasons(
        string $mode,
        float $confidence,
        bool $needsConfirmation,
        array $markers,
        string $primaryReason,
    ): array {
        $reasons = [];
        if ($primaryReason !== '') {
            $reasons[] = $primaryReason;
        }
        // Sinal de confiança humanizado.
        $confLabel = $confidence >= 0.80
            ? 'Confiança alta na sugestão.'
            : ($confidence >= self::CONFIDENCE_AUTO
                ? 'Confiança boa, mas vale conferir.'
                : 'Confiança baixa — Vitor pode trocar de modo se quiser.');
        $reasons[] = $confLabel;

        if (isset($markers['r4_marker'])) {
            $reasons[] = 'Marcador potencialmente destrutivo detectado — confirmação obrigatória.';
        } elseif ($needsConfirmation && $mode === VoxSchema::MODE_GOVERNED_EXECUTE) {
            $reasons[] = 'Executar nunca roda no automático — peço confirmação humana.';
        }
        return $reasons;
    }

    /**
     * @param  list<array{mode: string, confidence: float, reason_pt_br: string}>  $alternatives
     */
    private function pickFallback(string $top, array $alternatives): string
    {
        foreach ($alternatives as $alt) {
            if (($alt['mode'] ?? null) !== $top
                && in_array(
                    $alt['mode'] ?? '',
                    [
                        VoxSchema::MODE_DICTATION,
                        VoxSchema::MODE_PROMPT_POLISH,
                        VoxSchema::MODE_INTENT_COMPILE,
                        VoxSchema::MODE_GOVERNED_EXECUTE,
                    ],
                    true,
                )
            ) {
                return (string) $alt['mode'];
            }
        }
        return $this->complementaryMode($top);
    }
}
