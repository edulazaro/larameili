<?php

namespace EduLazaro\Larameili\Tests\Fixtures;

use EduLazaro\Larameili\Meili;
use EduLazaro\Larameili\Relations\BelongsToEloquent;

class Chunk extends Meili
{
    protected static string $index = 'chunks';

    protected static string $primaryKey = 'chunk_id';

    public function law(): BelongsToEloquent
    {
        return $this->belongsToEloquent(Law::class, foreignKey: 'law_id', ownerKey: 'external_id');
    }
}
