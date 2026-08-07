<?php

namespace EduLazaro\Larameili\Console;

use EduLazaro\Larameili\Meili;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'meili:sync {model? : A single Meili model class to sync}';

    protected $description = 'Create Meilisearch indexes and push the settings declared on your Meili models.';

    public function handle(): int
    {
        $models = $this->argument('model')
            ? [$this->argument('model')]
            : (array) config('larameili.indexes', []);

        if ($models === []) {
            $this->warn('Nothing to sync. Pass a model class, or list your models in config/larameili.php under "indexes".');

            return self::SUCCESS;
        }

        foreach ($models as $model) {
            if (! is_string($model) || ! is_subclass_of($model, Meili::class)) {
                $this->error("{$model} is not a " . Meili::class . ' subclass.');

                continue;
            }

            $model::sync();
            $this->line("  <info>synced</info> {$model} -> {$model::indexName()}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
