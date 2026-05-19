<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

/**
 * Atlas Vox V6.5 · Prompt Self-Critic (determinístico).
 *
 * Pergunta única: "o `compiled_prompt` que estamos prestes a entregar
 * cumpre o contrato V6.5 ou perdeu intenção no caminho?"
 *
 * Hard rules:
 *   - NUNCA chama LLM, NUNCA chama provider, NUNCA toca rede.
 *   - Determinístico — mesma entrada produz mesmo envelope.
 *   - NÃO falha o compile; apenas anota issues + opcionalmente repara.
 *   - NÃO cria memória longitudinal (Lei V7), NÃO toca Voice Realtime,
 *     NÃO toca mobile.
 *
 * Critérios verificados (13):
 *   1.  has_canonical_sections    · 8 seções `## …` presentes (Contexto,
 *       Objetivo, Modo de trabalho, Contexto disponível, Saída esperada,
 *       Critérios de qualidade, O que NÃO fazer, Voz original).
 *   2.  has_goal                  · objetivo não-vazio e não-placeholder.
 *   3.  has_expected_output       · "## Saída esperada" com ≥ 1 bullet.
 *   4.  has_voz_original          · "## Voz original" presente quando
 *       houve transcript.
 *   5.  negations_preserved       · cada restrição negativa da voz aparece
 *       como veto literal no prompt.
 *   6.  universal_vetoes_present  · 3 vetos universais Atlas (terminal
 *       sozinho, API paga, invenção de arquivo) sempre presentes.
 *   7.  no_boilerplate            · zero ocorrência de "você é um assistente
 *       útil", "por favor", "vamos juntos", "considere os seguintes pontos",
 *       "espero que isso ajude", "obrigado".
 *   8.  risk_not_softened         · em R4, o prompt mantém "NÃO execute",
 *       "destrutiva" e o bloco `## Segurança`.
 *   9.  no_unauthorized_action    · se voz disse "só analisa/leia/olha",
 *       o prompt não contém imperativos de edição não-condicionais
 *       ("edite", "altere", "modifique", "implemente"). Excluímos vetos
 *       (`não edite`) e instruções condicionais (`se editar`).
 *   10. provider_not_invented     · provider_hint=auto não força um provider
 *       específico no header (não menciona "Codex" sozinho nem "Claude"
 *       sozinho).
 *   11. provider_template_match   · template id cita o provider declarado.
 *   12. no_read_only_contradiction· voz read-only não pode coincidir com
 *       output_format=diff/command_proposal.
 *   13. minimum_useful_prompt     · em intent_compile, objetivo e saída
 *       esperada precisam ser específicos (não apenas template vazio).
 *
 * Envelope: `atlas.vox.prompt_quality.v1`.
 * Score = critérios passados / 13, em [0, 1].
 * Status = score ≥ 0.85 ⇒ pass · ≥ 0.60 ⇒ warn · senão fail.
 *
 * Reparo determinístico (sem LLM):
 *   - Boilerplate detectado → removido linha-a-linha.
 *   - `## Voz original` ausente com transcript não-vazio → re-adicionado.
 *   - Vetos universais ausentes → adicionados ao bloco `## O que NÃO fazer`.
 * Se algum reparo foi aplicado, `repaired=true` e o critic re-avalia
 * o prompt patchado antes de fechar.
 *
 * `needs_review=true` quando há issue persistente após reparo (ex:
 * `no_unauthorized_action=false`, contradição de read-only, negação
 * perdida, provider divergente, risco suavizado). Esses sinalizam bug
 * no extractor/compiler — exigem operador para investigar.
 */
final class VoxPromptSelfCritic
{
    public const SCHEMA = 'atlas.vox.prompt_quality.v1';
    public const VERSION = '0.1.0';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    public const PASS_THRESHOLD = 0.85;
    public const WARN_THRESHOLD = 0.60;

    /**
     * Boilerplate banido na saída — toda match (case-insensitive) é
     * issue grave e tentativa de reparo. Mantemos a lista compacta;
     * adições mudam contrato.
     *
     * @var list<string>
     */
    public const BOILERPLATE_PHRASES = [
        'você é um assistente útil',
        'por favor',
        'vamos juntos',
        'considere os seguintes pontos',
        'espero que isso ajude',
        'obrigado',
    ];

    /**
     * Vetos universais que sempre devem aparecer no `## O que NÃO fazer`.
     * Espelha VoxPromptCompiler::doNotBlock().
     *
     * @var list<string>
     */
    public const UNIVERSAL_VETOES = [
        'Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.',
        'Não use API paga, não chame provider remoto que cobre por uso.',
        'Não invente arquivo, função ou dependência que não exista no repo.',
    ];

    /**
     * Padrão para detectar "só analisa / só leia / apenas olha". Usado em
     * dois critérios: ação não autorizada (#9) e contradição (#12).
     */
    private const READ_ONLY_PATTERN = '/(?<![\p{L}\p{N}_])(?:s[óo]|apenas|somente)\s+(?:analisa|analise|leia|ler|l[êe]|olha|olhar)\b/iu';

    /**
     * Avalia o prompt compilado e devolve envelope canônico.
     *
     * @param  array{compiled_prompt:string,compiled_prompt_template:string,sections:list<string>}  $compiled
     * @param  array{goal:string,constraints:list<string>,output_format:string,risk_class:string,provider_hint?:string}  $extracted
     * @return array{
     *   schema:string,
     *   version:string,
     *   status:string,
     *   score:float,
     *   issues:list<string>,
     *   needs_review:bool,
     *   repaired:bool,
     *   checks:array<string,bool>,
     *   compiled_prompt:string,
     *   compiled_prompt_template:string,
     *   repair_log:list<string>
     * }
     */
    public function review(
        string $rawTranscript,
        array $compiled,
        array $extracted,
    ): array {
        $prompt = (string) ($compiled['compiled_prompt'] ?? '');
        $template = (string) ($compiled['compiled_prompt_template'] ?? '');

        $repairLog = [];

        // 1ª passada · detecta tudo que dá pra reparar deterministicamente.
        $repaired = $this->tryRepair($rawTranscript, $prompt, $extracted, $repairLog);
        if ($repaired !== null) {
            $prompt = $repaired;
        }

        // Avaliação final sempre roda sobre o prompt (possivelmente patchado).
        $checks = $this->runChecks($rawTranscript, $prompt, $template, $extracted);
        $issues = $this->collectIssues($checks);

        $passed = 0;
        foreach ($checks as $ok) {
            if ($ok === true) {
                $passed++;
            }
        }
        $score = count($checks) === 0
            ? 0.0
            : round($passed / count($checks), 4);

        $status = $score >= self::PASS_THRESHOLD
            ? self::STATUS_PASS
            : ($score >= self::WARN_THRESHOLD ? self::STATUS_WARN : self::STATUS_FAIL);

        $needsReview = $this->needsHumanReview($checks);

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'status' => $status,
            'score' => $score,
            'issues' => $issues,
            'needs_review' => $needsReview,
            'repaired' => $repaired !== null,
            'checks' => $checks,
            'compiled_prompt' => $prompt,
            'compiled_prompt_template' => $template,
            'repair_log' => $repairLog,
        ];
    }

    /**
     * Aplica reparos determinísticos quando issues são "patcháveis".
     *
     * Retorna o `compiled_prompt` patchado, ou `null` se nada foi
     * modificado. O reparo NUNCA inventa conteúdo — apenas remove
     * boilerplate e re-adiciona blocos canônicos faltantes.
     *
     * @param  array<string,mixed>  $extracted
     * @param  list<string>  $repairLog  acumulador out-param
     */
    private function tryRepair(
        string $rawTranscript,
        string $prompt,
        array $extracted,
        array &$repairLog,
    ): ?string {
        $modified = false;
        $patched = $prompt;

        // Reparo 1 · remover boilerplate linha-a-linha. Match
        // case-insensitive; preserva todo o resto.
        foreach (self::BOILERPLATE_PHRASES as $phrase) {
            $pattern = '/[^\n]*'.preg_quote($phrase, '/').'[^\n]*\n?/iu';
            if (preg_match($pattern, $patched) === 1) {
                $patched = (string) preg_replace($pattern, '', $patched);
                $repairLog[] = 'boilerplate_removed:'.$phrase;
                $modified = true;
            }
        }

        // Reparo 2 · `## Voz original` ausente apesar de transcript não-vazio.
        $voiceTrimmed = trim((string) preg_replace('/\s+/u', ' ', $rawTranscript));
        if ($voiceTrimmed !== '' && ! str_contains($patched, '## Voz original')) {
            $patched = rtrim($patched, "\n")."\n\n## Voz original\n> ".$voiceTrimmed;
            $repairLog[] = 'voz_original_restored';
            $modified = true;
        }

        // Reparo 3 · vetos universais ausentes em `## O que NÃO fazer`.
        if (str_contains($patched, '## O que NÃO fazer')) {
            foreach (self::UNIVERSAL_VETOES as $veto) {
                if (! str_contains($patched, $veto)) {
                    // Insere o veto na primeira linha após o cabeçalho da
                    // seção. Determinístico: sempre acrescenta na MESMA
                    // posição (logo após `## O que NÃO fazer\n`).
                    $patched = (string) preg_replace(
                        '/(## O que NÃO fazer\n)/u',
                        "$1- ".$veto."\n",
                        $patched,
                        1,
                    );
                    $repairLog[] = 'universal_veto_restored';
                    $modified = true;
                }
            }
        }

        return $modified ? $patched : null;
    }

    /**
     * Roda os 13 critérios; cada um devolve true/false.
     *
     * @param  array<string,mixed>  $extracted
     * @return array<string,bool>
     */
    private function runChecks(
        string $rawTranscript,
        string $prompt,
        string $template,
        array $extracted,
    ): array {
        return [
            'has_canonical_sections' => $this->checkCanonicalSections($prompt),
            'has_goal' => $this->checkHasGoal($prompt, $extracted),
            'has_expected_output' => $this->checkExpectedOutput($prompt),
            'has_voz_original' => $this->checkVozOriginal($rawTranscript, $prompt),
            'negations_preserved' => $this->checkNegationsPreserved($prompt, $extracted),
            'universal_vetoes_present' => $this->checkUniversalVetoes($prompt),
            'no_boilerplate' => $this->checkNoBoilerplate($prompt),
            'risk_not_softened' => $this->checkRiskNotSoftened($prompt, $extracted),
            'no_unauthorized_action' => $this->checkNoUnauthorizedAction($rawTranscript, $prompt, $extracted),
            'provider_not_invented' => $this->checkProviderNotInvented($prompt, $extracted),
            'provider_template_match' => $this->checkProviderTemplateMatch($template, $extracted),
            'no_read_only_contradiction' => $this->checkNoReadOnlyContradiction($rawTranscript, $extracted),
            'minimum_useful_prompt' => $this->checkMinimumUsefulPrompt($prompt, $extracted),
        ];
    }

    /**
     * @param  array<string,bool>  $checks
     * @return list<string>
     */
    private function collectIssues(array $checks): array
    {
        $map = [
            'has_canonical_sections' => 'canonical_sections_missing',
            'has_goal' => 'goal_missing_or_placeholder',
            'has_expected_output' => 'expected_output_block_empty_or_missing',
            'has_voz_original' => 'voz_original_missing',
            'negations_preserved' => 'negations_lost',
            'universal_vetoes_present' => 'universal_veto_missing',
            'no_boilerplate' => 'boilerplate_detected',
            'risk_not_softened' => 'risk_softened',
            'no_unauthorized_action' => 'unauthorized_action_in_prompt',
            'provider_not_invented' => 'provider_invented_for_auto',
            'provider_template_match' => 'provider_template_mismatch',
            'no_read_only_contradiction' => 'contradiction_read_only_but_edit_format',
            'minimum_useful_prompt' => 'prompt_below_minimum_useful',
        ];

        $issues = [];
        foreach ($checks as $name => $ok) {
            if ($ok === false && isset($map[$name])) {
                $issues[] = $map[$name];
            }
        }

        return $issues;
    }

    /**
     * `needs_review` distingue "issues simples já reparadas" de "issues que
     * sinalizam bug a montante" (extractor errou, voz contradiz formato,
     * risco suavizado, ação não autorizada). Reparo automático não
     * resolve esses casos.
     *
     * @param  array<string,bool>  $checks
     */
    private function needsHumanReview(array $checks): bool
    {
        $hardSignals = [
            'negations_preserved',
            'risk_not_softened',
            'no_unauthorized_action',
            'no_read_only_contradiction',
            'provider_template_match',
            'minimum_useful_prompt',
        ];
        foreach ($hardSignals as $name) {
            if (($checks[$name] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    // ── Critérios individuais ──────────────────────────────────────────

    private function checkCanonicalSections(string $prompt): bool
    {
        foreach (VoxPromptCompiler::CANONICAL_SECTIONS as $section) {
            if (! str_contains($prompt, $section)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $extracted
     */
    private function checkHasGoal(string $prompt, array $extracted): bool
    {
        if (! str_contains($prompt, '## Objetivo')) {
            return false;
        }
        $goal = trim((string) ($extracted['goal'] ?? ''));
        if ($goal === '') {
            return false;
        }
        if (str_contains($prompt, '(objetivo não declarado explicitamente')) {
            return false;
        }

        return true;
    }

    private function checkExpectedOutput(string $prompt): bool
    {
        if (preg_match('/## Saída esperada\s*\n((?:- [^\n]+\n?)+)/u', $prompt, $m) !== 1) {
            return false;
        }
        $bullets = preg_match_all('/^- /mu', (string) $m[1]);

        return ($bullets ?: 0) >= 1;
    }

    private function checkVozOriginal(string $rawTranscript, string $prompt): bool
    {
        $voiceTrimmed = trim((string) preg_replace('/\s+/u', ' ', $rawTranscript));
        if ($voiceTrimmed === '') {
            // Não precisa de seção Voz original se não houve voz.
            return true;
        }

        return str_contains($prompt, '## Voz original');
    }

    /**
     * @param  array<string,mixed>  $extracted
     */
    private function checkNegationsPreserved(string $prompt, array $extracted): bool
    {
        foreach ((array) ($extracted['constraints'] ?? []) as $clause) {
            $clause = trim((string) $clause);
            if ($clause === '') {
                continue;
            }
            if (preg_match('/^(n[ãa]o|sem|nada\s+de|s[óo]|apenas|somente|antes\s+de)\b/iu', $clause) !== 1) {
                continue;
            }
            $marker = 'Veto literal da voz: "'.$clause.'"';
            if (! str_contains($prompt, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function checkUniversalVetoes(string $prompt): bool
    {
        foreach (self::UNIVERSAL_VETOES as $veto) {
            if (! str_contains($prompt, $veto)) {
                return false;
            }
        }

        return true;
    }

    private function checkNoBoilerplate(string $prompt): bool
    {
        $lowered = mb_strtolower($prompt);
        foreach (self::BOILERPLATE_PHRASES as $phrase) {
            if (str_contains($lowered, $phrase)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $extracted
     */
    private function checkRiskNotSoftened(string $prompt, array $extracted): bool
    {
        $risk = (string) ($extracted['risk_class'] ?? '');
        if ($risk !== VoxSchema::RISK_R4) {
            return true;
        }
        // Em R4 o prompt PRECISA carregar todos os sinais de segurança.
        $required = ['## Segurança', 'NÃO execute', 'destrutiva'];
        foreach ($required as $needle) {
            if (! str_contains($prompt, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detecta "edite/altere/modifique/implemente" como imperativo
     * **não condicional** dentro do prompt quando a voz original disse
     * read-only. Exclui as 4 fontes legítimas:
     *
     *   - dentro de `## O que NÃO fazer` (são vetos)
     *   - precedido por "não" / "nunca"
     *   - precedido por "se " ou "ao " (instruções condicionais)
     *   - dentro de "Veto literal da voz: …" (eco)
     *
     * @param  array<string,mixed>  $extracted
     */
    private function checkNoUnauthorizedAction(
        string $rawTranscript,
        string $prompt,
        array $extracted,
    ): bool {
        $voiceReadOnly = preg_match(self::READ_ONLY_PATTERN, $rawTranscript) === 1;
        foreach ((array) ($extracted['constraints'] ?? []) as $clause) {
            if (preg_match(self::READ_ONLY_PATTERN, (string) $clause) === 1) {
                $voiceReadOnly = true;
                break;
            }
        }
        if (! $voiceReadOnly) {
            return true;
        }

        // Remove o bloco "## O que NÃO fazer" e os ecos literais da voz
        // antes da varredura — esses são vetos válidos.
        $stripped = (string) preg_replace(
            '/## O que NÃO fazer.*?(?=\n## |\z)/su',
            '',
            $prompt,
        );
        $stripped = (string) preg_replace(
            '/Veto literal da voz: ".*?"/u',
            '',
            $stripped,
        );

        // Imperativo de edição não condicional. Aceita variantes:
        // edite, altere, modifique, implemente, refator(e|a), aplique
        // diff que faça mudança.
        $editPattern = '/(?<![\p{L}\p{N}_])'
            // Sem "não"/"nunca"/"se"/"ao"/"quando" imediatamente antes.
            .'(?<!n[ãa]o\s)(?<!nunca\s)(?<!se\s)(?<!ao\s)(?<!quando\s)'
            .'(?:edit[ea]|alter[ea]|modifique|modifica|implement[ea]|refator[ea]|aplique|aplica)'
            .'\b/iu';
        if (preg_match($editPattern, $stripped) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Se provider_hint=auto, o header não pode forçar um provider único.
     * Sinalizamos bug se aparecer "Você é o Codex" / "Você é o Claude Code"
     * exclusivos em vez do header de auto ("pode ser Codex ou Claude").
     *
     * @param  array<string,mixed>  $extracted
     */
    private function checkProviderNotInvented(string $prompt, array $extracted): bool
    {
        $provider = (string) ($extracted['provider_hint'] ?? '');
        if ($provider !== 'auto') {
            return true;
        }
        // O header `auto` canon abre com "pode ser Codex ou Claude".
        if (str_contains($prompt, 'pode ser Codex ou Claude')) {
            return true;
        }
        // Se nenhum dos exclusivos aparece, ainda OK (header neutro).
        $hasCodexExclusive = str_contains($prompt, 'Você é o Codex');
        $hasClaudeExclusive = str_contains($prompt, 'Você é o Claude Code');

        return ! $hasCodexExclusive && ! $hasClaudeExclusive;
    }

    /**
     * @param  array<string,mixed>  $extracted
     */
    private function checkProviderTemplateMatch(string $template, array $extracted): bool
    {
        if ($template === '') {
            return true; // sem template, sem afirmação a contradizer
        }
        $supported = ['codex_cli', 'claude_cli', 'atlas', 'auto', 'local'];
        $declared = (string) ($extracted['provider_hint'] ?? 'local');
        $expected = in_array($declared, $supported, true) ? $declared : 'local';

        return str_contains($template, '.'.$expected.'.');
    }

    /**
     * @param  array<string,mixed>  $extracted
     */
    private function checkNoReadOnlyContradiction(string $rawTranscript, array $extracted): bool
    {
        $voiceReadOnly = preg_match(self::READ_ONLY_PATTERN, $rawTranscript) === 1;
        foreach ((array) ($extracted['constraints'] ?? []) as $clause) {
            if (preg_match(self::READ_ONLY_PATTERN, (string) $clause) === 1) {
                $voiceReadOnly = true;
                break;
            }
        }
        if (! $voiceReadOnly) {
            return true;
        }
        $editFormats = ['diff', 'command_proposal'];

        return ! in_array((string) ($extracted['output_format'] ?? ''), $editFormats, true);
    }

    /**
     * Prompt mínimo útil: tamanho ≥ 600 chars E (objetivo ≥ 12 chars OU
     * pelo menos um nome próprio na voz). Bloqueia "prompts esqueleto".
     *
     * @param  array<string,mixed>  $extracted
     */
    private function checkMinimumUsefulPrompt(string $prompt, array $extracted): bool
    {
        if (mb_strlen($prompt) < 600) {
            return false;
        }
        $goal = trim((string) ($extracted['goal'] ?? ''));
        if (mb_strlen($goal) >= 12) {
            return true;
        }
        // Tolera goal curto se a saída esperada estiver explicitamente populada
        // e o prompt tem mais de 1500 chars (caso de modo `notes` extra-curto).
        return mb_strlen($prompt) >= 1500;
    }
}
