<?php

namespace EduLazaro\Larameili\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeMeilieCommand extends GeneratorCommand
{
    protected $name = 'make:meilie';

    protected $description = 'Create a new Meilie model';

    protected $type = 'Meilie';

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/meilie.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Meili';
    }

    /**
     * Fill in the {{ index }} placeholder with a snake_case, pluralized name.
     */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $index = Str::snake(Str::pluralStudly(class_basename($name)));

        return str_replace('{{ index }}', $index, $stub);
    }
}
