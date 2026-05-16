<?php

declare(strict_types=1);

namespace App\Domain\Users;

final class AtlasUserService
{
    /** @var array<string,array{id:string,name:string}> */
    private array $users = [
        'u1' => ['id' => 'u1', 'name' => 'Avery'],
        'u2' => ['id' => 'u2', 'name' => 'Kim'],
    ];

    /**
     * @return array{id:string,name:string}|null
     */
    public function getById(string $id): ?array
    {
        return $this->users[$id] ?? null;
    }
}
