<?php

namespace EduLazaro\Larameili\Tests\Integration;

use EduLazaro\Larameili\Meilie;
use EduLazaro\Larameili\Tests\TestCase;

/**
 * Base class for tests that talk to a real Meilisearch server.
 *
 * The server is a runtime service, not a Composer dependency: point the tests
 * at it with MEILISEARCH_HOST / MEILISEARCH_KEY. When no server is reachable
 * the tests skip, so the suite still runs anywhere without one.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('larameili.host', env('MEILISEARCH_HOST', 'http://localhost:7700'));
        $app['config']->set('larameili.key', env('MEILISEARCH_KEY'));
        $app['config']->set('larameili.prefix', 'larameili_test_');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Meilie::resetClient();

        try {
            Meilie::client()->health();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Meilisearch is not reachable: ' . $e->getMessage());
        }
    }
}
