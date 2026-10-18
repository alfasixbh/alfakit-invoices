<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices;

use AlfaSix\Alfakit\Mail\CoreMails;
use AlfaSix\Alfakit\Mail\MailDefinition;
use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\AlfakitInvoices\Commands\MarkOverdueCommand;
use AlfaSix\AlfakitInvoices\Contracts\PaymentLinkProvider;
use AlfaSix\AlfakitInvoices\Contracts\PdfRenderer;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Http\Controllers\InvoiceLinkController;
use AlfaSix\AlfakitInvoices\Mail\InvoiceMail;
use AlfaSix\AlfakitInvoices\Pdf\Renderers;
use AlfaSix\AlfakitInvoices\Support\BillingPaymentLink;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AlfakitInvoicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/alfakit-invoices.php', 'alfakit-invoices');

        if (! $this->app->bound(PdfRenderer::class)) {
            $this->app->bind(PdfRenderer::class, static fn (Container $app): PdfRenderer => Renderers::resolve($app));
        }

        if (! $this->app->bound(PaymentLinkProvider::class)) {
            $this->app->bind(PaymentLinkProvider::class, BillingPaymentLink::class);
        }
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'alfakit-invoices');
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'alfakit-invoices');

        // Your own `Gate::policy(...)` for these models (registered later) replaces these.
        foreach ([
            AlfakitInvoices::invoiceModel() => Policies\InvoicePolicy::class,
            AlfakitInvoices::taxModel() => Policies\TaxPolicy::class,
        ] as $model => $policy) {
            if (! array_key_exists($model, Gate::policies())) {
                Gate::policy($model, $policy);
            }
        }

        $this->registerRoutes();
        static::registerMails();

        if ($this->app->runningInConsole()) {
            $this->commands([MarkOverdueCommand::class]);

            $this->publishes([
                dirname(__DIR__).'/config/alfakit-invoices.php' => config_path('alfakit-invoices.php'),
            ], 'alfakit-invoices-config');

            $this->publishesMigrations([
                dirname(__DIR__).'/database/migrations' => database_path('migrations'),
            ], 'alfakit-invoices-migrations');

            $this->publishes([
                dirname(__DIR__).'/resources/views' => resource_path('views/vendor/alfakit-invoices'),
            ], 'alfakit-invoices-views');

            $this->publishes([
                dirname(__DIR__).'/resources/lang' => $this->app->langPath('vendor/alfakit-invoices'),
            ], 'alfakit-invoices-translations');
        }

        if ((bool) config('alfakit-invoices.overdue.schedule', true)) {
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $time = config('alfakit-invoices.overdue.time', '00:30');

                $schedule->command(MarkOverdueCommand::class)
                    ->dailyAt(is_string($time) && preg_match('/^\d{2}:\d{2}$/', $time) === 1 ? $time : '00:30')
                    ->name('alfakit-invoices:mark-overdue')
                    ->withoutOverlapping()
                    ->onOneServer();
            });
        }
    }

    protected function registerRoutes(): void
    {
        if (! (bool) config('alfakit-invoices.route.enabled', true) || $this->app->routesAreCached()) {
            return;
        }

        $middleware = config('alfakit-invoices.route.middleware', ['web']);
        $domain = config('alfakit-invoices.route.domain');

        $routes = Route::prefix(AlfakitInvoices::prefix())
            ->middleware([...(is_array($middleware) ? $middleware : ['web']), 'signed'])
            ->name((string) config('alfakit-invoices.route.name', 'alfakit-invoices.'));

        if (is_string($domain) && $domain !== '') {
            $routes->domain($domain);
        }

        $routes->group(static function (): void {
            Route::get('/{invoice}', [InvoiceLinkController::class, 'show'])->name('show');
            Route::get('/{invoice}/download', [InvoiceLinkController::class, 'download'])->name('download');
        });
    }

    /**
     * The document mail, for the mail preview page and editable templates.
     */
    protected static function registerMails(): void
    {
        Mails::register(
            MailDefinition::make(InvoiceMail::TEMPLATE)
                ->label(static fn (): string => (string) __('alfakit-invoices::invoices.mail_templates.document'))
                ->group(static fn (): string => (string) __('alfakit-invoices::invoices.mail_templates.group'))
                ->preview(static function (): InvoiceMail {
                    $model = AlfakitInvoices::invoiceModel();
                    $invoice = new $model([
                        'type' => InvoiceType::Invoice,
                        'status' => InvoiceStatus::Sent,
                        'number' => 'INV-2026-00042',
                        'currency' => 'USD',
                        'customer_name' => 'Ada Lovelace',
                        'customer_email' => 'ada@example.com',
                        'total' => 125000,
                        'amount_paid' => 0,
                        'amount_due' => 125000,
                        'issue_date' => now(),
                        'due_date' => now()->addDays(14),
                    ]);
                    $invoice->setAttribute('uuid', 'preview');

                    return new InvoiceMail($invoice, attachDocument: false);
                })
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['type', 'number', 'customer', 'total', 'amount_due', 'due_date', 'seller', 'button', 'url']),
                    subject: static fn (): string => (string) __('alfakit-invoices::invoices.mail.subject', ['type' => '{{ type }}', 'number' => '{{ number }}', 'name' => '{{ seller }}']),
                    body: static fn (): string => MailDefinition::paragraphs(
                        (string) __('alfakit-invoices::invoices.mail.greeting', ['name' => '{{ customer }}']),
                        (string) __('alfakit-invoices::invoices.mail.intro_invoice', ['number' => '{{ number }}']),
                        '{{ type }} {{ number }}: {{ total }}',
                        '{{ button }}',
                        (string) __('alfakit-invoices::invoices.mail.outro'),
                    ),
                ),
        );
    }
}
