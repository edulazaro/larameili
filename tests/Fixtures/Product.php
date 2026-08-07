<?php

namespace EduLazaro\Larameili\Tests\Fixtures;

use EduLazaro\Larameili\Meili;

class Product extends Meili
{
    protected static string $index = 'products';

    protected static string $primaryKey = 'id';

    protected static bool $geo = true;

    protected static array $searchable = ['name'];
    protected static array $filterable = ['category', 'price'];
    protected static array $sortable = ['price'];

    protected static array $casts = ['price' => 'float'];
}
