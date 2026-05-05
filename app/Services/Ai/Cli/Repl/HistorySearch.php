<?php

namespace App\Services\Ai\Cli\Repl;

/**
 * Maquina de estado pra busca reversa estilo readline (Ctrl+R).
 *
 * Mantem query parcial, percorre o history mais recente -> mais antigo, e
 * expoe o "match atual" pra renderer pintar acima do prompt.
 */
class HistorySearch
{
    /** @var array<int,string> */
    private array $history = [];

    private string $query = '';

    private ?int $cursor = null;

    /** @param array<int,string> $history */
    public function __construct(array $history = [])
    {
        $this->history = array_values($history);
    }

    public function appendQueryChar(string $char): self
    {
        $this->query .= $char;
        $this->cursor = $this->findMatch($this->query, 0);

        return $this;
    }

    public function deleteQueryChar(): self
    {
        if ($this->query === '') {
            return $this;
        }
        $this->query = mb_substr($this->query, 0, mb_strlen($this->query) - 1);
        $this->cursor = $this->query === '' ? null : $this->findMatch($this->query, 0);

        return $this;
    }

    public function findNext(): self
    {
        if ($this->query === '') {
            return $this;
        }
        $start = $this->cursor === null ? 0 : $this->cursor + 1;
        $next = $this->findMatch($this->query, $start);
        if ($next !== null) {
            $this->cursor = $next;
        }

        return $this;
    }

    public function query(): string
    {
        return $this->query;
    }

    public function currentMatch(): ?string
    {
        if ($this->cursor === null) {
            return null;
        }
        $reversed = array_reverse($this->history);

        return $reversed[$this->cursor] ?? null;
    }

    public function statusLine(): string
    {
        $match = $this->currentMatch();
        if ($match !== null) {
            return "(reverse-i-search) '".$this->query."': ".$match;
        }
        if ($this->query !== '') {
            return "(failing reverse-i-search) '".$this->query."'";
        }

        return '(reverse-i-search): ';
    }

    private function findMatch(string $query, int $startIndex): ?int
    {
        $reversed = array_reverse($this->history);
        for ($i = $startIndex; $i < count($reversed); $i++) {
            if (str_contains($reversed[$i], $query)) {
                return $i;
            }
        }

        return null;
    }
}
