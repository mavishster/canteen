<?php

namespace App\Services\Sis;

interface SisParentClient
{
    /**
     * A parent account of the SIS. Null when the id is not a parent (a student or staff member, or unknown).
     *
     * @throws SisUnavailableException
     */
    public function parent(string $parentId): ?SisParent;

    /**
     * The children linked to this parent in the SIS.
     *
     * @return array<int, SisChildLink>
     *
     * @throws SisUnavailableException
     */
    public function childrenOf(string $parentId): array;
}
