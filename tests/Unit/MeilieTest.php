<?php

namespace EduLazaro\Larameili\Tests\Unit;

use EduLazaro\Larameili\Tests\Fixtures\Article;
use EduLazaro\Larameili\Tests\Fixtures\GlobalDoc;
use EduLazaro\Larameili\Tests\Fixtures\Place;
use EduLazaro\Larameili\Tests\TestCase;

class MeilieTest extends TestCase
{
    public function test_settings_are_assembled_from_declared_properties(): void
    {
        $settings = Article::settings();

        $this->assertSame(['title', 'body'], $settings['searchableAttributes']);
        $this->assertSame(['status', 'author_id'], $settings['filterableAttributes']);
        $this->assertSame(['published_at'], $settings['sortableAttributes']);
        $this->assertSame(['js' => ['javascript']], $settings['synonyms']);
        $this->assertArrayNotHasKey('rankingRules', $settings);
        $this->assertArrayNotHasKey('distinctAttribute', $settings);
    }

    public function test_index_name_has_no_prefix_by_default(): void
    {
        config(['larameili.prefix' => '']);

        $this->assertSame('articles', Article::indexName());
    }

    public function test_index_name_applies_configured_prefix(): void
    {
        config(['larameili.prefix' => 'test_']);

        $this->assertSame('test_articles', Article::indexName());
    }

    public function test_model_can_opt_out_of_prefix(): void
    {
        config(['larameili.prefix' => 'test_']);

        $this->assertSame('globals', GlobalDoc::indexName());
    }

    public function test_primary_key_defaults_to_id(): void
    {
        $this->assertSame('id', Article::primaryKeyName());
    }

    public function test_attributes_are_accessible_as_properties(): void
    {
        $article = new Article(['id' => 'abc', 'title' => 'Hello']);

        $this->assertSame('abc', $article->id);
        $this->assertSame('Hello', $article->title);
        $this->assertSame('abc', $article->getKey());
        $this->assertNull($article->missing);
    }

    public function test_geo_model_adds_geo_to_filterable_and_sortable(): void
    {
        $settings = Place::settings();

        $this->assertContains('_geo', $settings['filterableAttributes']);
        $this->assertContains('category', $settings['filterableAttributes']);
        $this->assertContains('_geo', $settings['sortableAttributes']);
        $this->assertContains('name', $settings['sortableAttributes']);
    }

    public function test_set_geo_sets_the_geo_attribute(): void
    {
        $place = (new Place())->setGeo(41.38, 2.17);

        $this->assertSame(['lat' => 41.38, 'lng' => 2.17], $place->_geo);
    }
}
