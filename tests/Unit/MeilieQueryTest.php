<?php

namespace EduLazaro\Larameili\Tests\Unit;

use EduLazaro\Larameili\MeilieQuery;
use EduLazaro\Larameili\Tests\Fixtures\Article;
use EduLazaro\Larameili\Tests\TestCase;

class MeilieQueryTest extends TestCase
{
    protected function query(): MeilieQuery
    {
        return new MeilieQuery(Article::class);
    }

    public function test_equals_filter(): void
    {
        $this->assertSame(
            'status = "published"',
            $this->query()->where('status', 'published')->compile()['filter']
        );
    }

    public function test_operator_filter_on_number(): void
    {
        $this->assertSame(
            'year > 2020',
            $this->query()->where('year', '>', 2020)->compile()['filter']
        );
    }

    public function test_where_in(): void
    {
        $this->assertSame(
            'author_id IN [1, 2, 3]',
            $this->query()->whereIn('author_id', [1, 2, 3])->compile()['filter']
        );
    }

    public function test_where_not(): void
    {
        $this->assertSame(
            'status != "draft"',
            $this->query()->whereNot('status', 'draft')->compile()['filter']
        );
    }

    public function test_where_raw_is_wrapped(): void
    {
        $this->assertSame(
            '(a = 1 OR b = 2)',
            $this->query()->whereRaw('a = 1 OR b = 2')->compile()['filter']
        );
    }

    public function test_multiple_filters_joined_with_and(): void
    {
        $filter = $this->query()
            ->where('status', 'published')
            ->whereIn('author_id', [1, 2])
            ->compile()['filter'];

        $this->assertSame('status = "published" AND author_id IN [1, 2]', $filter);
    }

    public function test_order_by(): void
    {
        $this->assertSame(
            ['published_at:desc'],
            $this->query()->orderBy('published_at', 'desc')->compile()['sort']
        );
    }

    public function test_limit_and_offset(): void
    {
        $compiled = $this->query()->limit(20)->offset(40)->compile();

        $this->assertSame(20, $compiled['limit']);
        $this->assertSame(40, $compiled['offset']);
    }

    public function test_semantic_sets_hybrid(): void
    {
        $hybrid = $this->query()->semantic(0.5)->compile()['hybrid'];

        $this->assertSame(0.5, $hybrid['semanticRatio']);
        $this->assertSame('default', $hybrid['embedder']);
    }

    public function test_boolean_value_is_not_quoted(): void
    {
        $this->assertSame(
            'featured = true',
            $this->query()->where('featured', true)->compile()['filter']
        );
    }

    public function test_empty_query_compiles_to_empty_options(): void
    {
        $this->assertSame([], $this->query()->compile());
    }

    public function test_near_compiles_geo_radius(): void
    {
        $this->assertSame(
            '_geoRadius(41.38, 2.17, 5000)',
            $this->query()->near(41.38, 2.17, 5000)->compile()['filter']
        );
    }

    public function test_order_by_distance(): void
    {
        $this->assertSame(
            ['_geoPoint(41.38, 2.17):asc'],
            $this->query()->orderByDistance(41.38, 2.17)->compile()['sort']
        );
    }

    public function test_within_box(): void
    {
        $this->assertSame(
            '_geoBoundingBox([41.5, 2.3], [41.3, 2.1])',
            $this->query()->withinBox([41.5, 2.3], [41.3, 2.1])->compile()['filter']
        );
    }
}
