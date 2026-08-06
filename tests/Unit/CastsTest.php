<?php

namespace EduLazaro\Larameili\Tests\Unit;

use EduLazaro\Larameili\Tests\Fixtures\Article;
use EduLazaro\Larameili\Tests\Fixtures\Status;
use EduLazaro\Larameili\Tests\TestCase;
use Illuminate\Support\Carbon;

class CastsTest extends TestCase
{
    public function test_int_and_bool_casts(): void
    {
        $article = new Article(['view_count' => '42', 'active' => 1]);

        $this->assertSame(42, $article->view_count);
        $this->assertTrue($article->active);
    }

    public function test_array_cast_from_json_string(): void
    {
        $article = new Article(['tags' => '["a","b"]']);

        $this->assertSame(['a', 'b'], $article->tags);
    }

    public function test_datetime_cast(): void
    {
        $article = new Article(['published_at' => '2026-01-15T10:00:00Z']);

        $this->assertInstanceOf(Carbon::class, $article->published_at);
        $this->assertSame('2026-01-15', $article->published_at->toDateString());
    }

    public function test_enum_cast(): void
    {
        $article = new Article(['status' => 'published']);

        $this->assertSame(Status::Published, $article->status);
    }

    public function test_null_and_uncast_values_pass_through(): void
    {
        $article = new Article(['title' => 'Hello', 'view_count' => null]);

        $this->assertSame('Hello', $article->title);
        $this->assertNull($article->view_count);
    }
}
