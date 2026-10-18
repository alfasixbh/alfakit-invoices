<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices;

use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\InvoiceItem;
use AlfaSix\AlfakitInvoices\Models\NumberSequence;
use AlfaSix\AlfakitInvoices\Models\Payment;
use AlfaSix\AlfakitInvoices\Models\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Configuration that doesn't depend on a panel request: the models, the public routes,
 * and the panel (and plugin) whose options apply to an invoice outside the panel.
 */
final class AlfakitInvoices
{
    /**
     * @return class-string<Invoice>
     */
    public static function invoiceModel(): string
    {
        return self::model('invoice', Invoice::class);
    }

    /**
     * @return class-string<InvoiceItem>
     */
    public static function itemModel(): string
    {
        return self::model('item', InvoiceItem::class);
    }

    /**
     * @return class-string<Payment>
     */
    public static function paymentModel(): string
    {
        return self::model('payment', Payment::class);
    }

    /**
     * @return class-string<Tax>
     */
    public static function taxModel(): string
    {
        return self::model('tax', Tax::class);
    }

    /**
     * @return class-string<NumberSequence>
     */
    public static function sequenceModel(): string
    {
        return self::model('sequence', NumberSequence::class);
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    private static function model(string $key, string $base): string
    {
        $model = config('alfakit-invoices.models.'.$key, $base);

        if (! is_string($model) || ! is_a($model, $base, true)) {
            throw new InvalidArgumentException("The invoices {$key} model must extend [{$base}].");
        }

        return $model;
    }

    // ---------------------------------------------------------------- Panel and plugin

    /**
     * The panel whose options apply: the current panel when it has the plugin, else the
     * invoice's own panel, else `config('alfakit-invoices.panel')`, else the first panel
     * with the plugin.
     */
    public static function panel(?Invoice $invoice = null): ?Panel
    {
        $current = alfakit()->getCurrentPanel();

        if ($current !== null && $current->hasPlugin(InvoicesPlugin::ID) && ($invoice === null || $invoice->panel_id === null || $invoice->panel_id === $current->getId())) {
            return $current;
        }

        foreach ([$invoice?->panel_id, config('alfakit-invoices.panel')] as $id) {
            if (is_string($id) && $id !== '' && alfakit()->hasPanel($id) && alfakit()->getPanel($id)->hasPlugin(InvoicesPlugin::ID)) {
                return alfakit()->getPanel($id);
            }
        }

        foreach (alfakit()->getPanels() as $panel) {
            if ($panel->hasPlugin(InvoicesPlugin::ID)) {
                return $panel;
            }
        }

        return null;
    }

    public static function plugin(?Invoice $invoice = null): InvoicesPlugin
    {
        $plugin = self::panel($invoice)?->getPlugin(InvoicesPlugin::ID);

        return $plugin instanceof InvoicesPlugin ? $plugin : InvoicesPlugin::make();
    }

    /**
     * A view of the package: `alfakit-invoices::{name}`.
     *
     * @return view-string
     */
    public static function view(string $name): string
    {
        return 'alfakit-invoices::'.$name;
    }

    // ---------------------------------------------------------------- Routes

    public static function routeName(string $name): string
    {
        return (string) config('alfakit-invoices.route.name', 'alfakit-invoices.').$name;
    }

    public static function hasRoute(string $name): bool
    {
        return Route::has(self::routeName($name));
    }

    public static function prefix(): string
    {
        return trim((string) config('alfakit-invoices.route.prefix', 'invoices'), '/');
    }

    /**
     * The scope key of a tenant (`App\Models\Team:5`), or `''` without one.
     */
    public static function tenantScope(?Model $tenant): string
    {
        return $tenant === null ? '' : $tenant->getMorphClass().':'.$tenant->getKey();
    }
}
