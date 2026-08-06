<?php

namespace EduLazaro\Larameili\Tests\Integration;

use EduLazaro\Larameili\Tests\Fixtures\Product;

class EngineTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropIndex();
        Product::sync();       // creates the index and applies settings (waits)
        $this->seedProducts();
    }

    protected function tearDown(): void
    {
        $this->dropIndex();

        parent::tearDown();
    }

    protected function dropIndex(): void
    {
        try {
            $task = Product::client()->deleteIndex(Product::indexName());
            Product::waitForTask($task, 10000);
        } catch (\Throwable) {
            // Index did not exist; nothing to drop.
        }
    }

    protected function seedProducts(): void
    {
        $task = Product::insert([
            ['id' => 1, 'name' => 'Red Widget',   'category' => 'tools', 'price' => 10.0, '_geo' => ['lat' => 41.38, 'lng' => 2.17]],
            ['id' => 2, 'name' => 'Blue Widget',  'category' => 'tools', 'price' => 20.0, '_geo' => ['lat' => 41.40, 'lng' => 2.16]],
            ['id' => 3, 'name' => 'Green Gadget', 'category' => 'toys',  'price' => 30.0, '_geo' => ['lat' => 48.85, 'lng' => 2.35]],
        ]);

        Product::waitForTask($task, 20000);
    }

    public function test_sync_applies_the_declared_settings(): void
    {
        $settings = Product::index()->getSettings();

        $this->assertContains('category', $settings['filterableAttributes']);
        $this->assertContains('_geo', $settings['filterableAttributes']);
    }

    public function test_find_returns_the_document_or_null(): void
    {
        $this->assertSame('Red Widget', Product::find(1)->name);
        $this->assertNull(Product::find(999));
    }

    public function test_filter_and_sort(): void
    {
        $tools = Product::query()
            ->where('category', 'tools')
            ->orderBy('price', 'asc')
            ->get();

        $this->assertSame([1, 2], $tools->map(fn ($p) => $p->id)->all());
    }

    public function test_count_and_exists(): void
    {
        $this->assertSame(2, Product::query()->where('category', 'tools')->count());
        $this->assertTrue(Product::query()->where('category', 'toys')->exists());
        $this->assertFalse(Product::query()->where('category', 'nope')->exists());
    }

    public function test_paginate_reports_an_exact_total(): void
    {
        $page = Product::query()->paginate(2);

        $this->assertSame(3, $page->total());
        $this->assertCount(2, $page->items());
    }

    public function test_geo_radius_excludes_far_documents(): void
    {
        $near = Product::query()
            ->near(41.39, 2.17, 5000)
            ->orderByDistance(41.39, 2.17)
            ->get();

        $ids = $near->map(fn ($p) => $p->id)->all();

        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertNotContains(3, $ids);
    }

    public function test_cast_is_applied_on_read(): void
    {
        $this->assertIsFloat(Product::find(1)->price);
    }

    public function test_partial_update_preserves_other_fields(): void
    {
        $task = Product::updateMany([['id' => 1, 'price' => 99.0]]);
        Product::waitForTask($task, 20000);

        $this->assertSame(99.0, Product::find(1)->price);
        $this->assertSame('Red Widget', Product::find(1)->name);
    }

    public function test_delete_where_removes_matching_documents(): void
    {
        $task = Product::deleteWhere('category = "toys"');
        Product::waitForTask($task, 20000);

        $this->assertNull(Product::find(3));
        $this->assertNotNull(Product::find(1));
    }
}
