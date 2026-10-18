<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitInvoices\AlfakitInvoicesServiceProvider;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Models\InvoicesUser;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Panels\InvoicesPanelProvider;
use AlfaSix\AlfakitInvoices\Tests\Fixtures\Panels\InvoicesTeamPanelProvider;
use RuntimeException;

abstract class TestCase extends AlfakitTestCase
{
    /**
     * The running test (Pest binds test closures to it).
     */
    public static function current(): static
    {
        $test = \Pest\TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new RuntimeException('No '.static::class.' is running.');
        }

        return $test;
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitInvoicesServiceProvider::class,
            InvoicesPanelProvider::class,
            InvoicesTeamPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', InvoicesUser::class);
        $app['config']->set('alfakit-invoices.panel', 'invoices');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('app.url', 'http://localhost');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/alfakit/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
    }
}
