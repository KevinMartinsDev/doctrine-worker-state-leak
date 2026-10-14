<?php

namespace App\Doctrine;

use App\Entity\Note;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return Note::class === $targetEntity->getName() ? $targetTableAlias.'.deleted = false' : '';
    }
}
