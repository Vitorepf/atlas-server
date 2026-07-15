<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Código · qual é a trunk deste repositório.
 *
 * O motor de regras nasceu com `main` escrito na pedra, porque o canon do
 * Atlas diz "toda IA trabalha SEMPRE na branch local main". Mas o Atlas Código
 * lê a frota inteira do operador, e fora do Atlas a trunk tem outro nome:
 *
 *   - nivor-back-end   → não TEM `main`; a trunk é `production`.
 *     `git rev-parse main` falhava e a varredura inteira morria: o app dizia
 *     "não consegui varrer as regras" para metade da frota.
 *   - blackink-website → tem `main` E `production`, e a trunk é `production`.
 *     Este era o pior caso: comparar contra `main` faria o Atlas ACUSAR
 *     trabalho correto. Falso positivo numa ferramenta de governança é pior
 *     que falha — falha o operador vê; acusação falsa ele acredita.
 *
 * A regra não muda de significado: "trabalhe na trunk". Muda o nome da trunk,
 * e o nome é fato do repositório, não da nossa opinião.
 *
 * Ordem: o que o host declara → nomes de trunk conhecidos → a branch atual
 * (sem trunk conhecida, nenhuma regra pode acusar ninguém).
 */
final class AtlasCodeTrunkResolver
{
    /** Convenção forte: onde existe, `main`/`master` É a trunk. */
    private const CONVENTIONAL = ['main', 'master'];

    /** Convenção fraca: só valem quando não há main/master E não há dúvida. */
    private const FALLBACK = ['develop', 'production', 'trunk'];

    public function __construct(private readonly int $timeoutSeconds = 10) {}

    public function resolve(string $path): string
    {
        // 1 · O host declarou a trunk: é a resposta mais confiável que existe,
        // e é o caso de TODOS os repositórios reais do operador.
        $declared = trim($this->git($path, ['git', 'symbolic-ref', 'refs/remotes/origin/HEAD']));
        if ($declared !== '') {
            $name = (string) preg_replace('#^refs/remotes/origin/#', '', $declared);
            if ($name !== '' && $this->branchExists($path, $name)) {
                return $name;
            }
        }

        // 2 · Sem declaração: main/master é convenção forte o bastante.
        foreach (self::CONVENTIONAL as $candidate) {
            if ($this->branchExists($path, $candidate)) {
                return $candidate;
            }
        }

        // 3 · Nem main nem master. Se existe UM só nome de trunk plausível, é
        // ele. Se existem vários, qualquer escolha é chute — e chute aqui vira
        // acusação falsa contra trabalho correto.
        $candidates = array_values(array_filter(
            self::FALLBACK,
            fn (string $candidate): bool => $this->branchExists($path, $candidate)
        ));
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // 4 · Sem trunk reconhecível: a branch atual. Assim nenhuma regra
        // dispara — silêncio é melhor que acusação falsa.
        return trim($this->git($path, ['git', 'branch', '--show-current']));
    }

    public function branchExists(string $path, string $branch): bool
    {
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $branch) !== 1) {
            return false;
        }

        foreach ([$branch, 'origin/'.$branch] as $ref) {
            $process = new Process(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}'], $path, null, null, $this->timeoutSeconds);
            $process->run();
            if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,string> $command */
    private function git(string $path, array $command): string
    {
        try {
            $process = new Process($command, $path, null, null, $this->timeoutSeconds);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : '';
        } catch (Throwable) {
            return '';
        }
    }
}
