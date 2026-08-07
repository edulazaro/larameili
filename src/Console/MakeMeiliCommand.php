<?php

namespace EduLazaro\Larameili\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeMeiliCommand extends GeneratorCommand
{
    protected $name = 'make:meili';

    protected $description = 'Create a new Meili model';

    protected $type = 'Meili';

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/meili.stub';
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
