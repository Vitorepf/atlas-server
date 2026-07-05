<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Repair;

/**
 * Engineering Kernel mechanism (OBRA #4 S3): o estágio de DIAGNÓSTICO que roda ANTES de qualquer
 * regeneração — o "entender por que falhou" que separa um repair de elite de um retry cego.
 *
 * Determinístico-PRIMEIRO (padrão Obra #2): as regras abaixo classificam por evidência objetiva na
 * saída da falha; um modelo só entra pelo seam de advisor (CONTEST-only, nunca decide sozinho) e
 * APENAS quando as regras devolvem UNKNOWN. Sem advisor wired, UNKNOWN segue a estratégia default
 * (regenerar com hint) — fail-open na classificação, nunca na segurança: TEST_WRONG e SPEC_WRONG
 * só saem de sinais EXPLÍCITOS (ambiguidade jamais autoriza mexer em teste/spec).
 */
final class RepairDiagnosisStage
{
    /** Advisor CONTEST-only: pode contestar um UNKNOWN com uma classe, nunca sobrescrever regra. */
    public function __construct(private readonly ?RepairDiagnosisAdvisor $advisor = null) {}

    /**
     * @param  array{failure_output:string, failure_signature?:string, origin?:string,
     *                test_provenance?:string, explicit_signals?:list<string>}  $context
     * @return array{class:string, strategy:string, hint:string, decided_by:string}
     */
    public function diagnose(array $context): array
    {
        $output = (string) ($context['failure_output'] ?? '');
        $signals = array_map('strval', (array) ($context['explicit_signals'] ?? []));

        [$class, $hint, $decidedBy] = $this->deterministicClass($output, $signals);

        // Advisor contest-only: só quando as regras não decidiram, e NUNCA para classes de parada
        // sensível (test_wrong/spec_wrong exigem sinal explícito — um palpite de modelo não
        // autoriza tocar em teste nem spec).
        if ($class === FailureTaxonomy::UNKNOWN && $this->advisor !== null) {
            $contested = $this->advisor->contest($context);
            if (is_string($contested) && in_array($contested, [
                FailureTaxonomy::IMPL_BUG, FailureTaxonomy::ENV_FLAKE,
                FailureTaxonomy::DEPENDENCY_BROKEN, FailureTaxonomy::SCOPE_MISS,
            ], true)) {
                $class = $contested;
                $decidedBy = 'advisor_contest';
            }
        }

        return [
            'class' => $class,
            'strategy' => FailureTaxonomy::strategyFor($class),
            'hint' => $hint,
            'decided_by' => $decidedBy,
        ];
    }

    /**
     * @param  list<string>  $signals
     * @return array{0:string,1:string,2:string}
     */
    private function deterministicClass(string $output, array $signals): array
    {
        // 1. Sinais EXPLÍCITOS do caller vencem (único caminho para test_wrong/spec_wrong —
        //    ambiguidade nunca autoriza mexer na régua).
        if (in_array('test_contradicts_frozen_spec', $signals, true)) {
            return [FailureTaxonomy::TEST_WRONG, 'o teste contradiz a spec congelada — PARAR e escalar', 'explicit_signal'];
        }
        if (in_array('spec_ambiguous_or_wrong', $signals, true)) {
            return [FailureTaxonomy::SPEC_WRONG, 'devolver ao spec-adversary para contestar a spec', 'explicit_signal'];
        }

        // 2. Regras determinísticas sobre a saída real da falha, da mais específica à mais geral.
        if (preg_match('/timed? ?out|connection refused|deadlock|SQLSTATE\[40|segmentation fault|signal 9|exit code 137|memory (limit|exhausted)|curl error/i', $output)) {
            return [FailureTaxonomy::ENV_FLAKE, 're-rodar sem provider; se persistir, tratar como impl_bug', 'deterministic_rule'];
        }
        if (preg_match('/class ["\']?[A-Za-z0-9_\\\\]+["\']? not found|vendor\/autoload|cannot redeclare|undefined (function|constant)|composer/i', $output)) {
            return [FailureTaxonomy::DEPENDENCY_BROKEN, 'dependência/autoload quebrado — abortar com blocker nomeado', 'deterministic_rule'];
        }
        if (preg_match('/no such file or directory|path .* (not allowed|outside)|scope_guard|blocked_paths/i', $output)) {
            return [FailureTaxonomy::SCOPE_MISS, 'alvo fora do escopo declarado — decompor ou re-escopar', 'deterministic_rule'];
        }
        if (preg_match('/failures?:\s*[1-9]|failed asserting|assertion|expects? .* got/i', $output)) {
            return [FailureTaxonomy::IMPL_BUG, $this->implHint($output), 'deterministic_rule'];
        }

        return [FailureTaxonomy::UNKNOWN, 'sem regra determinística; regenerar com o output cru', 'no_rule_matched'];
    }

    /** Hint dirigido: a(s) primeira(s) linha(s) de asserção falha, não o log inteiro. */
    private function implHint(string $output): string
    {
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/failed asserting|assertion|expects? .* got/i', $line)) {
                return 'foque nesta asserção: '.trim(mb_substr($line, 0, 240));
            }
        }

        return 'corrija a implementação para satisfazer o teste que falhou (não toque no teste)';
    }
}
