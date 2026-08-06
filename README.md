![Larameili](art/banner.png)

# Larameili

<p align="center">
    <a href="https://github.com/edulazaro/larameili/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/larameili/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/larameili"><img src="https://img.shields.io/packagist/v/edulazaro/larameili" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/larameili"><img src="https://img.shields.io/packagist/dt/edulazaro/larameili" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/larameili"><img src="https://img.shields.io/packagist/php-v/edulazaro/larameili" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/larameili/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/larameili" alt="License"></a>
</p>

**Eloquent-style models for Meilisearch.** Treat a Meilisearch index as your database: find, search (keyword or hybrid), save and delete documents through a model class, with the index settings declared in code.

Laravel Scout mirrors an Eloquent model into a search index. Larameili is for the other case: documents that live in Meilisearch as their primary store, with an Active Record API on top. The two do not overlap, and you can use both.

### When you want this

The case it was built for is chunked text searched semantically: legal articles, documentation, transcripts, support tickets, anything you split into passages and retrieve by meaning. Those chunks have no life of their own in your relational schema. Nothing joins them, nothing updates them one by one, and the only question you ever ask is "which passages are relevant to this query, within this filter".

Put them in Postgres and you own two copies of the same data plus the job of keeping them in step: a sync command, a queue worker, drift when a job fails, and a reindex script for when the embedder changes. None of that work makes the search better, because the answer still comes out of Meilisearch. Treating the index as the source of truth removes the second copy and everything written to maintain it. You keep your relational schema for the entities that deserve it, and the chunks point at them by foreign key ([Relations to Eloquent models](#relations-to-eloquent-models)).

The trade is real and worth stating: no transactions, no joins, no constraints. For chunks that is not a loss. For orders and invoices it would be, so leave those in your database.

## Requirements

- PHP 8.4+ and Laravel 11, 12 or 13.
- A Meilisearch server. The core (documents, filtering, sorting, pagination, geo) works on Meilisearch 1.x. Hybrid search (`semantic()`) needs the version where vector search is stable, Meilisearch 1.10 or newer.

## Installation

```bash
composer require edulazaro/larameili
```

Publish the config if you want to change the host, key or prefix:

```bash
php artisan vendor:publish --tag=larameili-config
```

By default it reads the same `MEILISEARCH_HOST` and `MEILISEARCH_KEY` environment variables Laravel Scout uses, so an app that already talks to Meilisearch needs nothing else.

## Define a model

A model maps to one index. Declare the index name, the primary key if it is not `id`, and the settings the index should have.

```php
namespace App\Meili;

use EduLazaro\Larameili\Meilie;

/**
 * @property string $id
 * @property string $title
 * @property string $status
 * @property int    $author_id
 */
class Article extends Meilie
{
    protected static string $index = 'articles';

    protected static string $primaryKey = 'id';

    protected static array $searchable = ['title', 'body'];
    protected static array $filterable = ['status', 'author_id'];
    protected static array $sortable   = ['published_at'];
}
```

Generate one with the artisan command, which fills the index name from the class:

```bash
php artisan make:meilie Article
```

## Push the settings

The `$searchable`, `$filterable`, `$sortable`, `$ranking`, `$synonyms` and `$embedders` you declare live in code, not in the engine, until you sync them. List your models in `config/larameili.php` and run the command:

```php
// config/larameili.php
'indexes' => [
    App\Meili\Article::class,
],
```

```bash
php artisan meili:sync            # create indexes + apply settings for all listed models
php artisan meili:sync "App\Meili\Article"   # just one
```

`meili:sync` creates the index if it does not exist and is safe to run repeatedly.

## Read and write

It reads like Eloquent.

```php
use App\Meili\Article;

$article = Article::find('abc');      // by primary key, or null
$article->title = 'New title';
$article->save();                     // insert or update

Article::create(['id' => 'xyz', 'title' => 'Hello']);

$article->delete();

Article::all(limit: 50);              // browse without searching
```

`find()` returns `null` only when the document genuinely does not exist. A missing index, a bad key or an unreachable host raise the Meilisearch client's own exception rather than being hidden as a `null`, and Larameili's own misconfiguration errors raise a `LarameiliException`.

## Casts

Meilisearch returns raw JSON, so a `$casts` map turns attributes back into the types you want as you read them: dates into `Carbon`, JSON into arrays, strings into backed enums.

```php
class Article extends Meilie
{
    protected static array $casts = [
        'published_at' => 'datetime',
        'view_count'   => 'int',
        'tags'         => 'array',
        'status'       => Status::class,   // a backed enum
    ];
}

$article = Article::find('abc');
$article->published_at;   // Carbon
$article->status;         // Status enum
```

Supported casts: `int`, `float`, `bool`, `string`, `array`, `collection`, `date`, `datetime`, and any backed enum class. Casting is applied on read.

## Query

`query()` returns a fluent builder that compiles into Meilisearch parameters and hydrates the hits back into models.

```php
$hits = Article::query()
    ->where('status', 'published')
    ->whereIn('author_id', [1, 2, 3])
    ->orderBy('published_at', 'desc')
    ->limit(20)
    ->search('meilisearch');
```

Filter helpers map to Meilisearch's filter syntax:

```php
Article::query()->where('year', '>', 2020)->get();   // year > 2020
Article::query()->whereNot('status', 'draft')->get();
Article::query()->whereRaw('rating >= 4 OR featured = true')->get();
```

`get()` is `search('')` for a pure filter/browse, `first()` returns one model, and `raw()` gives you the untouched search result when you need facets or totals:

```php
$result = Article::query()->facets(['status'])->raw('laravel');
$result->getFacetDistribution();
$result->getEstimatedTotalHits();
```

## Hybrid search

This is the part worth reading. If the index has an embedder, `semantic()` turns the query into a hybrid one: Meilisearch runs the keyword search and the vector search together and fuses the rankings. The ratio is `0` for keyword-only, `1` for vector-only, and `0.5` for an even blend.

```php
Article::query()
    ->where('status', 'published')
    ->semantic(0.5)                 // uses config('larameili.embedder')
    ->search('how do I cancel a subscription');
```

Neither half is enough on its own. Keyword search misses "cancel a subscription" when the document says "terminate your plan". Vector search misses exact tokens, which is exactly what invoice numbers, article references, error codes and proper nouns are. Hybrid retrieves both, so a query that mixes a code and a paraphrase still lands.

The ratio is the knob. Lean towards `0.2` when your users type identifiers and exact phrases, towards `0.8` for natural-language questions, and start at `0.5` when you do not know yet. It is per query, so a search box and a RAG retriever can use the same index with different ratios.

### The whole retrieval step in one query

Filters, hybrid ranking and the hop back into your database compose, so what is usually a retrieval pipeline is one statement:

```php
$chunks = LawChunk::query()
    ->where('territorial_scope', 'es')     // hard filter, pre-search
    ->where('year', '>=', 2020)
    ->semantic(0.7)                        // ranked by meaning
    ->with('law')                          // one Eloquent query for every hit
    ->limit(8)
    ->search($question);

foreach ($chunks as $chunk) {
    $chunk->text;         // what you put in the prompt
    $chunk->law->title;   // the citation, from your database
}
```

The filters are applied by the engine before ranking, so scoping to a tenant, a language or a date range costs nothing and cannot leak. `with()` batches every hit's foreign key into a single Eloquent query, which is what keeps citations cheap: the passage comes from Meilisearch, the entity it belongs to comes from your database, and there is no N+1 in between.

Declare the embedder on the model in `$embedders` and run `meili:sync` to push it. Meilisearch generates and stores the vectors, so nothing in your application queues embedding jobs or holds an embedding client.

## Pagination

`paginate()` returns a Laravel `LengthAwarePaginator`, so `->links()` and the rest of the paginator API work in Blade. It uses Meilisearch's page mode, which reports an exact total.

```php
$articles = Article::query()
    ->where('status', 'published')
    ->orderBy('published_at', 'desc')
    ->paginate(15);

$articles->total();   // exact
$articles->links();   // in a Blade view
```

## Geo search

Set `protected static bool $geo = true;` on the model and `meili:sync` adds `_geo` to the filterable and sortable attributes for you. Store the point on each document with `setGeo()`.

```php
$place->setGeo(41.3874, 2.1686)->save();
```

Then filter by radius or bounding box and sort by distance. Every hit carries a `_geoDistance` in meters when you sort or filter by geo.

```php
Place::query()
    ->near(41.3874, 2.1686, 5000)          // within 5 km
    ->orderByDistance(41.3874, 2.1686)     // nearest first
    ->search('coffee');

Place::query()
    ->withinBox([41.45, 2.23], [41.32, 2.10])
    ->get();
```

## Bulk operations

For importers and backfills, work in batches.

```php
Article::insert($rows);                 // add or replace many documents

Article::updateMany([                   // partial update: only these fields change
    ['id' => 'abc', 'status' => 'archived'],
]);

Article::destroy(['abc', 'xyz']);       // delete by primary key
Article::deleteWhere('status = "spam"'); // delete by filter (field must be filterable)
Article::truncate();                    // empty the index
```

## Relations to Eloquent models

Meilisearch has no joins, so relations are resolvers, not joins: a document holds a foreign key, and the package looks the Eloquent model up by it. Declare one with `belongsToEloquent` on the model, typed `: BelongsToEloquent`.

```php
use EduLazaro\Larameili\Meilie;
use EduLazaro\Larameili\Relations\BelongsToEloquent;

class LawChunk extends Meilie
{
    protected static string $index = 'law_chunks';

    public function law(): BelongsToEloquent
    {
        return $this->belongsToEloquent(Law::class, foreignKey: 'law_id', ownerKey: 'external_id');
    }
}
```

Access it as a property and it resolves the Eloquent model lazily, the way an Eloquent relationship does. From there it is a normal Eloquent model, so its own relations work as usual.

```php
$chunk->law;              // the Law row from your database
$chunk->law->relations;   // plain Eloquent from here on
```

Eager-load with `with()` on the query to batch every hit's lookup into one Eloquent query instead of one per hit.

```php
LawChunk::query()
    ->where('territorial_scope', 'es')
    ->with('law')            // one whereIn('external_id', [...]) for all hits
    ->search('data protection');
```

## Configuration

```php
return [
    'host'     => env('MEILISEARCH_HOST', 'http://localhost:7700'),
    'key'      => env('MEILISEARCH_KEY'),
    'prefix'   => env('MEILISEARCH_PREFIX', ''),   // prepended to index names
    'embedder' => env('MEILISEARCH_EMBEDDER', 'default'),
    'indexes'  => [
        // App\Meili\Article::class,
    ],
];
```

A model can opt out of the prefix with `protected static bool $prefixed = false;`.

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Larameili is open-sourced software licensed under the [MIT license](LICENSE.md).
