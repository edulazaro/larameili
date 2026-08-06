<?php

namespace EduLazaro\Larameili\Tests\Unit;

use EduLazaro\Larameili\MeilieQuery;
use EduLazaro\Larameili\Relations\BelongsToEloquent;
use EduLazaro\Larameili\Tests\Fixtures\Chunk;
use EduLazaro\Larameili\Tests\Fixtures\Law;
use EduLazaro\Larameili\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class RelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('laws', function ($table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
        });

        Law::create(['external_id' => 'BOE-1', 'name' => 'Ley Uno']);
        Law::create(['external_id' => 'BOE-2', 'name' => 'Ley Dos']);
    }

    public function test_relation_descriptor_carries_the_keys(): void
    {
        $relation = (new Chunk(['law_id' => 'BOE-1']))->law();

        $this->assertInstanceOf(BelongsToEloquent::class, $relation);
        $this->assertSame(Law::class, $relation->relatedClass());
        $this->assertSame('law_id', $relation->foreignKey());
        $this->assertSame('external_id', $relation->ownerKey());
    }

    public function test_property_access_resolves_the_eloquent_model(): void
    {
        $chunk = new Chunk(['law_id' => 'BOE-1']);

        $this->assertInstanceOf(Law::class, $chunk->law);
        $this->assertSame('Ley Uno', $chunk->law->name);
    }

    public function test_missing_foreign_key_resolves_to_null(): void
    {
        $chunk = new Chunk(['law_id' => 'BOE-UNKNOWN']);

        $this->assertNull($chunk->law);
    }

    public function test_set_relation_is_read_from_cache(): void
    {
        $chunk = new Chunk(['law_id' => 'BOE-1']);
        $chunk->setRelation('law', Law::where('external_id', 'BOE-2')->first());

        $this->assertSame('Ley Dos', $chunk->law->name);
    }

    public function test_eager_load_batches_the_relation(): void
    {
        $chunks = collect([
            new Chunk(['law_id' => 'BOE-1']),
            new Chunk(['law_id' => 'BOE-2']),
            new Chunk(['law_id' => 'BOE-1']),
        ]);

        $loaded = (new MeilieQuery(Chunk::class))->with('law')->eagerLoad($chunks);

        $this->assertSame('Ley Uno', $loaded[0]->law->name);
        $this->assertSame('Ley Dos', $loaded[1]->law->name);
        $this->assertSame('Ley Uno', $loaded[2]->law->name);
    }
}
