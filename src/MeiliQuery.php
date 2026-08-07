<?php

namespace EduLazaro\Larameili;

use EduLazaro\Larameili\Relations\BelongsToEloquent;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Fluent query builder for a Meili model. It compiles where()/orderBy()/...
 * into Meilisearch search parameters and hydrates the hits back into model
 * instances.
 *
 *   Article::query()
 *       ->where('status', 'published')
 *       ->whereIn('author_id', [1, 2, 3])
 *       ->orderBy('published_at', 'desc')
 *       ->semantic(0.5)
 *       ->limit(20)
 *       ->search('vector search');
 */
class MeiliQuery
{
    /** @var class-string<Meili> */
    protected string $model;

    /** @var list<string> */
    protected array $filters = [];

    /** @var list<string> */
    protected array $sort = [];

    protected ?int $limit = null;
    protected ?int $offset = null;
    protected ?array $hybrid = null;
    protected ?array $retrieve = null;
    protected ?array $facets = null;
    protected ?string $matchingStrategy = null;
    protected array $with = [];

    /**
     * @param class-string<Meili> $model
     */
    public function __construct(string $model)
    {
        $this->model = $model;
    }

    /** Eager-load one or more Eloquent relations declared on the model. */
    public function with(string ...$relations): static
    {
        $this->with = array_merge($this->with, $relations);

        return $this;
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * Add a filter. Two forms:
     *
     *   where('status', 'published')   // status = "published"
     *   where('year', '>', 2000)       // year > 2000
     */
    public function where(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = (string) $operatorOrValue;
        }

        $this->filters[] = "{$field} {$operator} " . $this->quote($value);

        return $this;
    }

    /**
     * @param array<int, scalar> $values
     */
    public function whereIn(string $field, array $values): static
    {
        $list = implode(', ', array_map(fn ($value) => $this->quote($value), $values));
        $this->filters[] = "{$field} IN [{$list}]";

        return $this;
    }

    public function whereNot(string $field, mixed $value): static
    {
        $this->filters[] = "{$field} != " . $this->quote($value);

        return $this;
    }

    /** Add a raw Meilisearch filter expression, wrapped in parentheses. */
    public function whereRaw(string $expression): static
    {
        $this->filters[] = "({$expression})";

        return $this;
    }

    // ------------------------------------------------------------------
    // Geo (documents need a `_geo` field; see Meili::$geo)
    // ------------------------------------------------------------------

    /** Restrict results to within $meters of a point. */
    public function near(float $lat, float $lng, float $meters): static
    {
        $this->filters[] = "_geoRadius({$lat}, {$lng}, {$meters})";

        return $this;
    }

    /** Restrict results to a bounding box, given its top-right and bottom-left corners as [lat, lng]. */
    public function withinBox(array $topRight, array $bottomLeft): static
    {
        [$trLat, $trLng] = $topRight;
        [$blLat, $blLng] = $bottomLeft;
        $this->filters[] = "_geoBoundingBox([{$trLat}, {$trLng}], [{$blLat}, {$blLng}])";

        return $this;
    }

    /** Sort by distance from a point; each hit then carries a `_geoDistance` in meters. */
    public function orderByDistance(float $lat, float $lng, string $direction = 'asc'): static
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->sort[] = "_geoPoint({$lat}, {$lng}):{$direction}";

        return $this;
    }

    // ------------------------------------------------------------------
    // Sort & paging
    // ------------------------------------------------------------------

    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->sort[] = "{$field}:{$direction}";

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    // ------------------------------------------------------------------
    // Hybrid / vector search
    // ------------------------------------------------------------------

    /**
     * Enable hybrid search. $ratio is the semantic ratio: 0 is keyword only,
     * 1 is vector only. $embedder defaults to config('larameili.embedder').
     */
    public function semantic(float $ratio = 0.5, ?string $embedder = null): static
    {
        $this->hybrid = [
            'semanticRatio' => $ratio,
            'embedder' => $embedder ?? (string) config('larameili.embedder', 'default'),
        ];

        return $this;
    }

    /**
     * @param list<string> $attributes
     */
    public function retrieve(array $attributes): static
    {
        $this->retrieve = $attributes;

        return $this;
    }

    /**
     * @param list<string> $attributes
     */
    public function facets(array $attributes): static
    {
        $this->facets = $attributes;

        return $this;
    }

    public function matchingStrategy(string $strategy): static
    {
        $this->matchingStrategy = $strategy;

        return $this;
    }

    // ------------------------------------------------------------------
    // Terminals
    // ------------------------------------------------------------------

    /**
     * Run the search and return hydrated models.
     *
     * @return Collection<int, Meili>
     */
    public function search(string $query = ''): Collection
    {
        $model = $this->model;

        return $this->eagerLoad($model::hydrate(
            $model::index()->search($query, $this->compile())->getHits()
        ));
    }

    /**
     * Alias of search('') for a pure filter/browse query.
     *
     * @return Collection<int, Meili>
     */
    public function get(): Collection
    {
        return $this->search('');
    }

    /** The first matching model, or null. */
    public function first(string $query = ''): ?Meili
    {
        return $this->limit(1)->search($query)->first();
    }

    /**
     * Paginate the results as a Laravel LengthAwarePaginator, so `->links()`
     * and the rest of the paginator API work in Blade. Uses Meilisearch's
     * page mode, which returns an exact total.
     */
    public function paginate(int $perPage = 15, ?int $page = null, string $query = ''): LengthAwarePaginator
    {
        $page = $page ?: Paginator::resolveCurrentPage();

        $options = $this->compile();
        unset($options['limit'], $options['offset']);
        $options['hitsPerPage'] = $perPage;
        $options['page'] = $page;

        $result = $this->model::index()->search($query, $options);
        $total = (int) ($result->toArray()['totalHits'] ?? 0);

        return new LengthAwarePaginator(
            $this->eagerLoad($this->model::hydrate($result->getHits())),
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page']
        );
    }

    /**
     * The raw Meilisearch search result (hits, facetDistribution, totals),
     * for when you need the metadata and not just hydrated models.
     */
    public function raw(string $query = '')
    {
        $model = $this->model;

        return $model::index()->search($query, $this->compile());
    }

    /** Count the matching documents. */
    public function count(string $query = ''): int
    {
        $options = $this->compile();
        unset($options['limit'], $options['offset']);
        $options['page'] = 1;
        $options['hitsPerPage'] = 1;

        $response = $this->model::index()->search($query, $options)->toArray();

        return (int) ($response['totalHits'] ?? $response['estimatedTotalHits'] ?? 0);
    }

    /** Whether any document matches. */
    public function exists(string $query = ''): bool
    {
        $options = $this->compile();
        $options['limit'] = 1;
        unset($options['offset']);

        return $this->model::index()->search($query, $options)->getHits() !== [];
    }

    /** Build the Meilisearch search options array. */
    public function compile(): array
    {
        return array_filter([
            'filter' => $this->filters !== [] ? implode(' AND ', $this->filters) : null,
            'sort' => $this->sort ?: null,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'hybrid' => $this->hybrid,
            'attributesToRetrieve' => $this->retrieve,
            'facets' => $this->facets,
            'matchingStrategy' => $this->matchingStrategy,
        ], fn ($value) => $value !== null);
    }

    /**
     * Attach the relations named in with() to a collection of models, batching
     * each relation into a single Eloquent query (no N+1).
     */
    public function eagerLoad(Collection $models): Collection
    {
        if ($this->with === [] || $models->isEmpty()) {
            return $models;
        }

        $sample = new $this->model();

        foreach ($this->with as $name) {
            if (! method_exists($sample, $name)) {
                continue;
            }

            $relation = $sample->{$name}();
            if (! $relation instanceof BelongsToEloquent) {
                continue;
            }

            $foreignKey = $relation->foreignKey();
            $ownerKey = $relation->ownerKey();
            $related = $relation->relatedClass();

            $keys = $models->map(fn ($m) => $m->{$foreignKey})->filter()->unique()->values()->all();
            if ($keys === []) {
                continue;
            }

            $map = $related::query()->whereIn($ownerKey, $keys)->get()->keyBy($ownerKey);

            $models->each(fn ($m) => $m->setRelation($name, $map->get($m->{$foreignKey})));
        }

        return $models;
    }

    protected function quote(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '"' . str_replace('"', '\"', (string) $value) . '"';
    }
}
