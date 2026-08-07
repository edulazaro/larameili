<?php

namespace EduLazaro\Larameili\Tests\Fixtures;

use EduLazaro\Larameili\Meili;

class GlobalDoc extends Meili
{
    protected static string $index = 'globals';

    protected static bool $prefixed = false;
}
