<?php

namespace EduLazaro\Larameili\Tests\Fixtures;

use EduLazaro\Larameili\Meilie;

class Place extends Meilie
{
    protected static string $index = 'places';

    protected static bool $geo = true;

    protected static array $filterable = ['category'];
    protected static array $sortable = ['name'];
}
