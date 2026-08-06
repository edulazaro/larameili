<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The Meilisearch host and master/API key. These default to the same
    | environment variables Laravel Scout uses, so an app that already has
    | Meilisearch configured needs no extra setup.
    |
    */

    'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),

    'key' => env('MEILISEARCH_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Index prefix
    |--------------------------------------------------------------------------
    |
    | Prepended to every model's index name. Useful to keep environments
    | apart (e.g. "staging_"). A model can opt out with `protected static
    | bool $prefixed = false;`.
    |
    */

    'prefix' => env('MEILISEARCH_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Default embedder
    |--------------------------------------------------------------------------
    |
    | The embedder used by hybrid search when a query calls ->semantic()
    | without naming one. Must match a key declared in a model's $embedders.
    |
    */

    'embedder' => env('MEILISEARCH_EMBEDDER', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Indexes
    |--------------------------------------------------------------------------
    |
    | The Meilie model classes that `php artisan meili:sync` should create and
    | push settings for.
    |
    */

    'indexes' => [
        // App\Meili\Article::class,
    ],

];
