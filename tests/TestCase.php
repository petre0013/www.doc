<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * A fresh RefreshDatabase has no users, which the first-run gate
     * (EnsureSetupComplete) would treat as an unconfigured install and funnel to
     * the setup wizard — breaking every test that exercises the app proper. Mark
     * setup complete by default (after the schema is migrated, on the test DB);
     * the setup-wizard tests clear this to exercise the fresh-install path.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('settings')) {
            Setting::put('setup', ['completed_at' => now()->toIso8601String()]);
        }
    }

    /**
     * The dev `app` container exports APP_ENV, DB_*, SESSION_DRIVER, etc. as real
     * environment variables. Those shadow phpunit.xml's <env> entries (PHPUnit's
     * `force` only updates getenv(), not the $_ENV array Laravel reads), and they
     * also win over any .env file because Dotenv is immutable. So we pin the test
     * environment at runtime here — after the app boots, before the database
     * traits run — to keep the suite isolated on the dedicated test database and
     * out of "production" CSRF behaviour.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->app['env'] = 'testing';

        $this->app['config']->set([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'wwwdoc_test',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        // Provider boot may already have resolved the pgsql connection against the
        // pre-override (dev) database — e.g. AppServiceProvider reads settings at
        // boot. Purge it so the next resolution uses wwwdoc_test, not wwwdoc.
        $this->app['db']->purge('pgsql');
    }
}
