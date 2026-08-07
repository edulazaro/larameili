<?php

namespace EduLazaro\Larameili\Relations;

use EduLazaro\Larameili\Meili;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a link from a Meilisearch document to an Eloquent (SQL) model.
 *
 * Meilisearch has no joins, so this is a resolver, not a join: given the value
 * of a foreign key stored on the document, it looks the Eloquent model up by an
 * owner column. Batched via the query builder's with() to avoid N+1.
 *
 *   class LawChunk extends Meili
 *   {
 *       public function law(): BelongsToEloquent
 *       {
 *           return $this->belongsToEloquent(Law::class, foreignKey: 'law_id', ownerKey: 'external_id');
 *       }
 *   }
 */
class BelongsToEloquent
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(
        protected Meili $parent,
        protected string $related,
        protected string $foreignKey,
        protected string $ownerKey,
    ) {}

    /** Resolve the single related Eloquent model, or null. */
    public function resolve(): ?Model
    {
        $value = $this->parent->{$this->foreignKey} ?? null;

        if ($value === null) {
            return null;
        }

        return $this->related::query()->where($this->ownerKey, $value)->first();
    }

    public function relatedClass(): string
    {
        return $this->related;
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function ownerKey(): string
    {
        return $this->ownerKey;
    }
}
