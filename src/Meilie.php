<?php

namespace EduLazaro\Larameili;

use EduLazaro\Larameili\Exceptions\LarameiliException;
use EduLazaro\Larameili\Relations\BelongsToEloquent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Exceptions\ApiException;

/**
 * Eloquent-style base for Meilisearch documents.
 *
 * A subclass maps to one Meilisearch index and behaves like an Active Record,
 * except the backing store is a Meilisearch index rather than a SQL table:
 *
 *   class Article extends Meilie
 *   {
 *       protected static string $index = 'articles';
 *
 *       protected static array $filterable = ['status', 'author_id'];
 *       protected static array $sortable   = ['published_at'];
 *   }
 *
 *   $article = Article::find('abc');
 *   $article->title = 'New title';
 *   $article->save();
 *
 *   $hits = Article::query()
 *       ->where('status', 'published')
 *       ->orderBy('published_at', 'desc')
 *       ->search('meilisearch');
 *
 * The declared settings ($searchable, $filterable, ...) are pushed to the
 * engine with `php artisan meili:sync`.
 *
 * Writes to Meilisearch are asynchronous: every call returns a task and the
 * engine processes it in the background. Pass `wait: true`, or use
 * {@see waitForTask()}, when you need the change to be searchable immediately
 * (importers, tests).
 *
 * @property mixed $id
 */
abstract class Meilie
{
    /** Bare index name. The configured prefix is prepended unless $prefixed is false. */
    protected static string $index;

    /** Primary key attribute. */
    protected static string $primaryKey = 'id';

    /** Whether the configured prefix is prepended to the index name. */
    protected static bool $prefixed = true;

    /** Whether documents carry a `_geo` field; adds `_geo` to filterable + sortable on sync. */
    protected static bool $geo = false;

    // ------------------------------------------------------------------
    // Declared index settings (applied by `meili:sync`)
    // ------------------------------------------------------------------

    protected static array $searchable = [];
    protected static array $filterable = [];
    protected static array $sortable = [];
    protected static array $ranking = [];
    protected static array $stopWords = [];
    protected static array $synonyms = [];
    protected static array $embedders = [];
    protected static ?string $distinct = null;

    /** Attribute casts applied on read, e.g. ['published_at' => 'datetime', 'status' => Status::class]. */
    protected static array $casts = [];

    /** Attributes held by this instance. */
    protected array $attributes = [];

    /** Resolved relations cache. */
    protected array $relations = [];

    /** Single shared Meilisearch client. */
    private static ?Client $client = null;

    public function __construct(array|object $attributes = [])
    {
        $this->attributes = (array) $attributes;
    }

    // ------------------------------------------------------------------
    // Magic accessors (pair with @property docblocks in subclasses for
    // full IDE autocompletion).
    // ------------------------------------------------------------------

    public function __get(string $key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->castAttribute($key, $this->attributes[$key]);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Property access on a relation method (typed `: BelongsToEloquent`)
        // resolves and caches it, the way Eloquent resolves a relationship.
        if (method_exists($this, $key)
            && ($type = (new \ReflectionMethod($this, $key))->getReturnType()) instanceof \ReflectionNamedType
            && is_a($type->getName(), BelongsToEloquent::class, true)
        ) {
            return $this->relations[$key] = $this->{$key}()->resolve();
        }

        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /** The primary key value of this document. */
    public function getKey(): mixed
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    /** Set this document's geo point (lat/lng) for geosearch. */
    public function setGeo(float $lat, float $lng): static
    {
        $this->attributes['_geo'] = ['lat' => $lat, 'lng' => $lng];

        return $this;
    }

    /**
     * Define a link from this document to an Eloquent model. The document's
     * $foreignKey is matched against the Eloquent model's $ownerKey column.
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $related
     */
    protected function belongsToEloquent(string $related, ?string $foreignKey = null, string $ownerKey = 'id'): BelongsToEloquent
    {
        $foreignKey ??= Str::snake(class_basename($related)) . '_id';

        return new BelongsToEloquent($this, $related, $foreignKey, $ownerKey);
    }

    /** Set a resolved relation on the instance (used by eager loading). */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    /** Apply the cast declared in $casts for an attribute, if any. */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null || ! isset(static::$casts[$key])) {
            return $value;
        }

        $cast = static::$casts[$key];

        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array', 'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            'collection' => collect(is_array($value) ? $value : (json_decode((string) $value, true) ?? [])),
            'date', 'datetime' => $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : (is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value)),
            default => $this->castToEnum($cast, $value),
        };
    }

    /** Cast a value to a backed enum, or return it unchanged when $cast is not one. */
    protected function castToEnum(string $cast, mixed $value): mixed
    {
        if (enum_exists($cast) && is_subclass_of($cast, \BackedEnum::class)) {
            return $value instanceof \BackedEnum ? $value : $cast::tryFrom($value);
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // Query entrypoints
    // ------------------------------------------------------------------

    /** Start a fluent query on the index. */
    public static function query(): MeilieQuery
    {
        return new MeilieQuery(static::class);
    }

    /** Fetch a single document by primary key, or null if not found. */
    public static function find(int|string $id): ?static
    {
        try {
            return new static(static::index()->getDocument($id));
        } catch (ApiException $e) {
            // A genuinely missing document is null; anything else (a missing
            // index, an auth failure, a connection error) is a real problem
            // and must not be swallowed.
            if ($e->errorCode === 'document_not_found') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * List documents by limit/offset, without searching.
     *
     * @return Collection<int, static>
     */
    public static function all(int $limit = 50, int $offset = 0): Collection
    {
        $query = (new DocumentsQuery())->setLimit($limit)->setOffset($offset);

        return static::hydrate(static::index()->getDocuments($query)->getResults());
    }

    /**
     * Raw search passthrough. $options is forwarded to the engine as-is
     * (filter, sort, limit, offset, hybrid, facets, ...). For most cases the
     * fluent query() builder is nicer.
     *
     * @return Collection<int, static>
     */
    public static function search(string $query = '', array $options = []): Collection
    {
        return static::hydrate(static::index()->search($query, $options)->getHits());
    }

    /** Create and persist a document in one call. */
    public static function create(array $attributes): static
    {
        $instance = new static($attributes);
        $instance->save();

        return $instance;
    }

    // ------------------------------------------------------------------
    // Instance persistence
    // ------------------------------------------------------------------

    /** Insert or update this document. Pass wait: true to block until indexed. */
    public function save(bool $wait = false): bool
    {
        $task = static::index()->addDocuments([$this->attributes], static::$primaryKey);

        if ($wait) {
            static::waitForTask($task);
        }

        return true;
    }

    /** Delete this document. Pass wait: true to block until applied. */
    public function delete(bool $wait = false): bool
    {
        $id = $this->getKey();
        if ($id === null) {
            return false;
        }

        $task = static::index()->deleteDocument($id);

        if ($wait) {
            static::waitForTask($task);
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Bulk operations (each returns the Meilisearch task, or null on no-op)
    // ------------------------------------------------------------------

    /**
     * Insert or update many documents at once. Accepts arrays or Meilie
     * instances. For large sets prefer {@see import()}, which batches.
     */
    public static function insert(iterable $documents): ?array
    {
        $docs = static::normalizeDocuments($documents);

        return $docs === [] ? null : static::index()->addDocuments($docs, static::$primaryKey);
    }

    /**
     * Partially update many documents: only the given fields change and the
     * rest of each stored document is preserved. Each row must carry the
     * primary key.
     */
    public static function updateMany(iterable $documents): ?array
    {
        $docs = static::normalizeDocuments($documents);

        return $docs === [] ? null : static::index()->updateDocuments($docs, static::$primaryKey);
    }

    /** Delete documents by primary key. */
    public static function destroy(array $ids): ?array
    {
        return $ids === [] ? null : static::index()->deleteDocuments($ids);
    }

    /**
     * Delete every document matching a Meilisearch filter expression. The
     * fields used must be part of $filterable.
     */
    public static function deleteWhere(string $filter): array
    {
        return static::index()->deleteDocuments(['filter' => $filter]);
    }

    /** Delete all documents in the index (the index itself stays). */
    public static function truncate(): array
    {
        return static::index()->deleteAllDocuments();
    }

    /**
     * Stream documents into the index in batches. Memory-safe for generators
     * and large collections; waits for each batch by default so a long import
     * cannot outrun the engine. Returns the number of documents sent.
     */
    public static function import(iterable $documents, int $batchSize = 1000, bool $wait = true): int
    {
        $imported = 0;

        LazyCollection::make(static function () use ($documents) {
            yield from $documents;
        })
            ->chunk($batchSize)
            ->each(function ($batch) use (&$imported, $wait): void {
                $docs = static::normalizeDocuments($batch);
                if ($docs === []) {
                    return;
                }

                $task = static::index()->addDocuments($docs, static::$primaryKey);

                if ($wait) {
                    static::waitForTask($task, 120000);
                }

                $imported += count($docs);
            });

        return $imported;
    }

    // ------------------------------------------------------------------
    // Tasks
    // ------------------------------------------------------------------

    /**
     * Block until a Meilisearch task finishes. Accepts the task array returned
     * by any write call. Returns the resolved task.
     */
    public static function waitForTask(array $task, int $timeoutMs = 5000, int $intervalMs = 50): array
    {
        $uid = $task['taskUid'] ?? $task['uid'] ?? null;

        if ($uid === null) {
            return $task;
        }

        return static::client()->waitForTask($uid, $timeoutMs, $intervalMs);
    }

    // ------------------------------------------------------------------
    // Index & settings
    // ------------------------------------------------------------------

    /** Fully-qualified index name (with the configured prefix, when enabled). */
    public static function indexName(): string
    {
        if (! isset(static::$index)) {
            throw new LarameiliException(static::class . ' must declare protected static string $index.');
        }

        $prefix = static::$prefixed ? (string) config('larameili.prefix', '') : '';

        return $prefix . static::$index;
    }

    /** The Meilisearch index handle. */
    public static function index()
    {
        return static::client()->index(static::indexName());
    }

    /** The declared settings as a Meilisearch settings array. */
    public static function settings(): array
    {
        $filterable = static::$filterable;
        $sortable = static::$sortable;

        if (static::$geo) {
            $filterable = array_values(array_unique([...$filterable, '_geo']));
            $sortable = array_values(array_unique([...$sortable, '_geo']));
        }

        return array_filter([
            'searchableAttributes' => static::$searchable ?: null,
            'filterableAttributes' => $filterable ?: null,
            'sortableAttributes' => $sortable ?: null,
            'rankingRules' => static::$ranking ?: null,
            'stopWords' => static::$stopWords ?: null,
            'synonyms' => static::$synonyms ?: null,
            'distinctAttribute' => static::$distinct,
            'embedders' => static::$embedders ?: null,
        ], fn ($value) => $value !== null);
    }

    /** Ensure the index exists and push the declared settings to the engine. */
    public static function sync(): void
    {
        static::ensureIndex();

        $settings = static::settings();
        if ($settings !== []) {
            $task = static::index()->updateSettings($settings);
            static::waitForTask($task, 30000);
        }
    }

    /** Create the index if it does not exist yet, waiting until it is ready. */
    public static function ensureIndex(): void
    {
        try {
            static::client()->getIndex(static::indexName());
        } catch (\Throwable) {
            $task = static::client()->createIndex(static::indexName(), ['primaryKey' => static::$primaryKey]);
            static::waitForTask($task, 30000);
        }
    }

    /** The shared, lazily-built Meilisearch client. */
    public static function client(): Client
    {
        return self::$client ??= new Client(
            (string) config('larameili.host', 'http://localhost:7700'),
            config('larameili.key'),
        );
    }

    /** Reset the shared client (useful in tests). */
    public static function resetClient(): void
    {
        self::$client = null;
    }

    // ------------------------------------------------------------------
    // Serialization / hydration
    // ------------------------------------------------------------------

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->attributes, $options);
    }

    /**
     * Build a collection of instances from raw documents.
     *
     * @return Collection<int, static>
     */
    public static function hydrate(iterable $documents): Collection
    {
        return collect($documents)
            ->map(fn ($doc) => new static($doc))
            ->values();
    }

    /** The primary key attribute name. */
    public static function primaryKeyName(): string
    {
        return static::$primaryKey;
    }

    protected static function normalizeDocuments(iterable $documents): array
    {
        return collect($documents)
            ->map(fn ($doc) => $doc instanceof self ? $doc->toArray() : (array) $doc)
            ->values()
            ->all();
    }
}
