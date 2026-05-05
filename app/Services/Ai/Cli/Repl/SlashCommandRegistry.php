<?php

namespace App\Services\Ai\Cli\Repl;

use Closure;

/**
 * Registro de slash commands com aliases declarativos.
 *
 * Substitui blocos lineares de if/else por uma tabela. Cada comando registra
 * um nome canonico, aliases e um handler que recebe o restante da linha como
 * argumento (string vazia quando sem argumento).
 *
 * Convencao do dispatch:
 *   - linha "/paste-image"            -> handler com args = ""
 *   - linha "/image foo bar"          -> handler com args = "foo bar"
 *   - linha sem prefixo "/" ou vazia  -> dispatch retorna false
 *   - comando desconhecido            -> dispatch retorna false
 */
class SlashCommandRegistry
{
    /** @var array<string,Closure> */
    private array $handlers = [];

    /** @var array<int,string> */
    private array $names = [];

    public function register(string $name, array $aliases, Closure $handler): self
    {
        $name = $this->normalizeName($name);
        if ($name === '') {
            return $this;
        }
        $this->handlers[$name] = $handler;
        $this->names[] = $name;
        foreach ($aliases as $alias) {
            $alias = $this->normalizeName((string) $alias);
            if ($alias === '' || isset($this->handlers[$alias])) {
                continue;
            }
            $this->handlers[$alias] = $handler;
        }

        return $this;
    }

    public function dispatch(string $line): bool
    {
        $parsed = $this->parse($line);
        if ($parsed === null) {
            return false;
        }
        [$name, $args] = $parsed;
        $handler = $this->handlers[$name] ?? null;
        if ($handler === null) {
            return false;
        }
        $handler($args);

        return true;
    }

    /** @return array<int,string> */
    public function registeredNames(): array
    {
        return $this->names;
    }

    public function knows(string $name): bool
    {
        return isset($this->handlers[$this->normalizeName($name)]);
    }

    /** @return array{0:string,1:string}|null */
    private function parse(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || $line[0] !== '/') {
            return null;
        }
        $line = substr($line, 1);
        $space = strpos($line, ' ');
        if ($space === false) {
            return [$this->normalizeName($line), ''];
        }
        $name = $this->normalizeName(substr($line, 0, $space));
        $args = trim(substr($line, $space + 1));

        return [$name, $args];
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim($name));
    }
}
