<?php

namespace App\Services\Sis;

/** For tests: a pretend SIS that knows tokens, parents and links, and can be "switched off". */
class FakeSisIdentity implements SisAuthClient, SisParentClient
{
    public bool $down = false;

    /** @var array<string, SisParent> */
    private array $parents = [];

    /**
     * @param  array<string, string>  $tokens   token => SIS user id
     * @param  array<int, SisParent>  $parents
     * @param  array<string, array<int, string>>  $links   parent id => SIS student ids
     */
    public function __construct(private array $tokens = [], array $parents = [], private array $links = [])
    {
        foreach ($parents as $parent) {
            $this->parents[$parent->id] = $parent;
        }
    }

    public function userForToken(string $token): ?SisTokenUser
    {
        $this->checkUp();

        $id = $this->tokens[$token] ?? null;

        return $id !== null ? new SisTokenUser($id) : null;
    }

    public function parent(string $parentId): ?SisParent
    {
        $this->checkUp();

        return $this->parents[$parentId] ?? null;
    }

    public function childrenOf(string $parentId): array
    {
        $this->checkUp();

        return array_map(fn (string $id) => new SisChildLink($id, null), $this->links[$parentId] ?? []);
    }

    /** @param array<int, string> $studentIds */
    public function setLinks(string $parentId, array $studentIds): void
    {
        $this->links[$parentId] = $studentIds;
    }

    public function deactivate(string $parentId): void
    {
        $p = $this->parents[$parentId];
        $this->parents[$parentId] = new SisParent($p->id, $p->name, false, $p->branchId, $p->phone);
    }

    public function setPhone(string $parentId, ?string $phone): void
    {
        $p = $this->parents[$parentId];
        $this->parents[$parentId] = new SisParent($p->id, $p->name, $p->active, $p->branchId, $phone);
    }

    private function checkUp(): void
    {
        if ($this->down) {
            throw new SisUnavailableException('The fake SIS is switched off.');
        }
    }
}
