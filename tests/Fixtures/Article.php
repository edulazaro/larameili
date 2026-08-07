<?php

namespace EduLazaro\Larameili\Tests\Fixtures;

use EduLazaro\Larameili\Meili;

class Article extends Meili
{
    protected static string $index = 'articles';

    protected static array $searchable = ['title', 'body'];
    protected static array $filterable = ['status', 'author_id'];
    protected static array $sortable = ['published_at'];
    protected static array $synonyms = ['js' => ['javascript']];

    protected static array $casts = [
        'published_at' => 'datetime',
        'view_count' => 'int',
        'tags' => 'array',
        'active' => 'bool',
        'status' => Status::class,
    ];
}
