<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasPoweredByCredit;
use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Enums\Rounding;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource;
use AlfaSix\AlfakitInvoices\Resources\TaxResource;
use AlfaSix\AlfakitInvoices\Support\Branding;
use AlfaSix\AlfakitInvoices\Support\Numbering;
use AlfaSix\AlfakitInvoices\Widgets\InvoiceAgingWidget;
use AlfaSix\AlfakitInvoices\Widgets\InvoiceStatsWidget;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Invoices, quotes, credit notes and receipts in a panel.
 *
 * ```php
 * $panel->plugin(
 *     InvoicesPlugin::make()
 *         ->authorize(fn (User $user): bool => $user->can_bill)
 *         ->customers(fn (?Team $tenant) => Customer::query()->whereBelongsTo($tenant), titleAttribute: 'company')
 *         ->numbering(InvoiceType::Invoice, prefix: 'INV-', format: '{YYYY}{MM}-{SEQ:4}')
 *         ->rounding(Rounding::PerTotal)
 *         ->seller(fn (?Team $tenant) => ['name' => $tenant?->name, 'address' => $tenant?->address, 'tax_id' => $tenant?->vat_number])
 *         ->branding(logo: asset('images/logo.svg'), color: '#0f766e'),
 * );
 * ```
 *
 * Without the plugin on a panel (or outside a panel), `InvoicesPlugin::get()` returns one
 * with the defaults of `config/alfakit-invoices.php`.
 */
class InvoicesPlugin implements Plugin
{
    use EvaluatesClosures;
    use HasPoweredByCredit;

    public const ID = 'invoices';

    /** @var class-string<InvoiceResource> */
    protected string $invoiceResource = InvoiceResource::class;

    /** @var class-string<TaxResource> */
    protected string $taxResource = TaxResource::class;

    protected bool $hasTaxResource = true;

    protected bool $hasDashboardWidgets = false;

    protected bool|Closure|null $authorizeUsing = null;

    protected ?Closure $customersUsing = null;

    protected string $customerTitleAttribute = 'name';

    /** @var array<int, string> */
    protected array $customerSearchColumns = [];

    /** @var array<string, array{prefix?: string|Closure|null, format?: string|Closure|null}> */
    protected array $numbering = [];

    protected ?bool $assignsNumbersOnCreate = null;

    protected Rounding|Closure|null $rounding = null;

    protected string|Closure|null $currency = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $currencies = null;

    protected int|Closure|null $dueDays = null;

    protected int|Closure|null $quoteValidDays = null;

    /** @var array<string, string|null>|Closure|null */
    protected array|Closure|null $seller = null;

    /** @var array<string, string|Closure|null> */
    protected array $branding = [];

    protected string|Closure|null $defaultNotes = null;

    protected string|Closure|null $defaultTerms = null;

    protected ?Closure $payNowUsing = null;

    protected string|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin of the current panel (or of the invoices panel outside panels), or one
     * with the default options.
     */
    public static function get(?Invoice $invoice = null): static
    {
        $plugin = AlfakitInvoices::plugin($invoice);

        return $plugin instanceof static ? $plugin : static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $resources = [$this->invoiceResource];

        if ($this->hasTaxResource) {
            $resources[] = $this->taxResource;
        }

        $panel->resources($resources);

        if ($this->hasDashboardWidgets) {
            $panel->widgets([InvoiceStatsWidget::class, InvoiceAgingWidget::class]);
        }
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentPanel() ?? AlfakitInvoices::panel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel?->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Resources

    /**
     * @param  class-string<InvoiceResource>  $resource
     */
    public function invoiceResource(string $resource): static
    {
        if (! is_a($resource, InvoiceResource::class, true)) {
            throw new InvalidArgumentException("The invoices resource [{$resource}] must extend [".InvoiceResource::class.'].');
        }

        $this->invoiceResource = $resource;

        return $this;
    }

    /**
     * @return class-string<InvoiceResource>
     */
    public function getInvoiceResource(): string
    {
        return $this->invoiceResource;
    }

    /**
     * @param  class-string<TaxResource>  $resource
     */
    public function taxResource(string $resource): static
    {
        if (! is_a($resource, TaxResource::class, true)) {
            throw new InvalidArgumentException("The taxes resource [{$resource}] must extend [".TaxResource::class.'].');
        }

        $this->taxResource = $resource;

        return $this;
    }

    /**
     * @return class-string<TaxResource>
     */
    public function getTaxResource(): string
    {
        return $this->taxResource;
    }

    /** Register the tax rates resource (default). */
    public function taxes(bool $condition = true): static
    {
        $this->hasTaxResource = $condition;

        return $this;
    }

    /** Add the stats and aging widgets to the panel's dashboard. */
    public function dashboardWidgets(bool $condition = true): static
    {
        $this->hasDashboardWidgets = $condition;

        return $this;
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) && $group !== '' ? $group : null;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Who may work with invoices in the panel: `fn (User $user): bool`. Everyone who can
     * use the panel by default. The default `InvoicePolicy` checks it for every ability;
     * your own policy (`Gate::policy(Invoice::class, …)`) replaces that.
     */
    public function authorize(bool|Closure|null $callback = true): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function canManage(?Model $user): bool
    {
        if ($this->authorizeUsing === null) {
            return $user !== null;
        }

        if ($user === null) {
            return false;
        }

        return (bool) $this->evaluate($this->authorizeUsing, ['user' => $user], [Model::class => $user, $user::class => $user]);
    }

    // ---------------------------------------------------------------- Customers

    /**
     * The customers documents can be billed to: `fn (?Model $tenant): Builder`. The form
     * then has a searchable customer picker that copies the customer's details (see
     * `InvoiceRecipient`); without it the customer details are typed in.
     *
     * @param  array<int, string>  $searchColumns  defaults to the title attribute
     */
    public function customers(?Closure $query, string $titleAttribute = 'name', array $searchColumns = []): static
    {
        $this->customersUsing = $query;
        $this->customerTitleAttribute = $titleAttribute;
        $this->customerSearchColumns = array_values($searchColumns);

        return $this;
    }

    public function hasCustomers(): bool
    {
        return $this->customersUsing !== null;
    }

    /**
     * @return Builder<Model>|null
     */
    public function getCustomersQuery(?Model $tenant): ?Builder
    {
        if ($this->customersUsing === null) {
            return null;
        }

        $query = $this->evaluate($this->customersUsing, ['tenant' => $tenant], $tenant === null ? [] : [Model::class => $tenant, $tenant::class => $tenant]);

        return $query instanceof Builder ? $query : null;
    }

    public function getCustomerTitleAttribute(): string
    {
        return $this->customerTitleAttribute;
    }

    /**
     * @return array<int, string>
     */
    public function getCustomerSearchColumns(): array
    {
        return $this->customerSearchColumns !== [] ? $this->customerSearchColumns : [$this->customerTitleAttribute];
    }

    // ---------------------------------------------------------------- Numbering

    /**
     * The number of a type: `prefix` + `format` (tokens `{YYYY}`, `{YY}`, `{MM}`, `{DD}`,
     * `{SEQ}`, `{SEQ:5}`). Either may be a closure receiving `$tenant` and `$type`.
     */
    public function numbering(InvoiceType|string $type, string|Closure|null $prefix = null, string|Closure|null $format = null): static
    {
        $type = $type instanceof InvoiceType ? $type : InvoiceType::from($type);

        if (is_string($format)) {
            Numbering::validateFormat($format);
        }

        $this->numbering[$type->value] = array_filter(['prefix' => $prefix, 'format' => $format], static fn (mixed $value): bool => $value !== null);

        return $this;
    }

    public function getNumberPrefix(InvoiceType $type, ?Model $tenant = null): string
    {
        $prefix = array_key_exists('prefix', $this->numbering[$type->value] ?? [])
            ? $this->evaluate($this->numbering[$type->value]['prefix'], ['tenant' => $tenant, 'type' => $type])
            : config("alfakit-invoices.numbering.types.{$type->value}.prefix", '');

        return is_string($prefix) ? $prefix : '';
    }

    public function getNumberFormat(InvoiceType $type, ?Model $tenant = null): string
    {
        $format = array_key_exists('format', $this->numbering[$type->value] ?? [])
            ? $this->evaluate($this->numbering[$type->value]['format'], ['tenant' => $tenant, 'type' => $type])
            : config("alfakit-invoices.numbering.types.{$type->value}.format", Numbering::DEFAULT_FORMAT);

        if (! is_string($format) || $format === '') {
            return Numbering::DEFAULT_FORMAT;
        }

        Numbering::validateFormat($format);

        return $format;
    }

    /**
     * Give drafts their number only when they are issued (sent, paid, converted), so
     * deleted drafts never leave gaps. By default drafts are numbered when created.
     */
    public function assignNumbersOnIssue(bool $condition = true): static
    {
        $this->assignsNumbersOnCreate = ! $condition;

        return $this;
    }

    public function assignsNumbersOnCreate(): bool
    {
        return $this->assignsNumbersOnCreate ?? config('alfakit-invoices.numbering.assign', 'create') !== 'issue';
    }

    // ---------------------------------------------------------------- Money

    public function rounding(Rounding|string|Closure $rounding): static
    {
        $this->rounding = is_string($rounding) ? Rounding::from($rounding) : $rounding;

        return $this;
    }

    public function getRounding(): Rounding
    {
        $rounding = $this->evaluate($this->rounding) ?? config('alfakit-invoices.rounding', Rounding::PerLine->value);

        return $rounding instanceof Rounding ? $rounding : (Rounding::tryFrom(is_string($rounding) ? $rounding : '') ?? Rounding::PerLine);
    }

    /**
     * The currency of new documents (the panel's currency by default).
     */
    public function currency(string|Closure|null $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getDefaultCurrency(): string
    {
        $currency = $this->evaluate($this->currency) ?? config('alfakit-invoices.currency');

        return Currency::normalize(is_string($currency) && $currency !== '' ? $currency : alfakit()->getCurrency());
    }

    /**
     * Limit the currency picker to these codes (every currency in circulation by default).
     *
     * @param  array<int, string>|Closure|null  $currencies
     */
    public function currencies(array|Closure|null $currencies): static
    {
        $this->currencies = $currencies;

        return $this;
    }

    /**
     * @return array<int, string>|null
     */
    public function getCurrencies(): ?array
    {
        $currencies = $this->evaluate($this->currencies);

        return is_array($currencies) && $currencies !== [] ? array_values(array_map(static fn (mixed $code): string => Currency::normalize((string) $code), $currencies)) : null;
    }

    public function dueDays(int|Closure|null $days): static
    {
        $this->dueDays = $days;

        return $this;
    }

    public function getDueDays(): ?int
    {
        $days = $this->evaluate($this->dueDays) ?? config('alfakit-invoices.due_days', 30);

        return is_numeric($days) ? max(0, (int) $days) : null;
    }

    public function quoteValidDays(int|Closure|null $days): static
    {
        $this->quoteValidDays = $days;

        return $this;
    }

    public function getQuoteValidDays(): ?int
    {
        $days = $this->evaluate($this->quoteValidDays) ?? config('alfakit-invoices.quote_valid_days', 30);

        return is_numeric($days) ? max(0, (int) $days) : null;
    }

    // ---------------------------------------------------------------- Documents

    /**
     * The seller printed on documents: `['name', 'address', 'email', 'phone', 'tax_id',
     * 'website']`, or `fn (?Model $tenant): array`. Defaults to `config('alfakit-invoices.seller')`.
     *
     * @param  array<string, string|null>|Closure|null  $seller
     */
    public function seller(array|Closure|null $seller): static
    {
        $this->seller = $seller;

        return $this;
    }

    /**
     * @return array{name: string|null, address: string|null, email: string|null, phone: string|null, tax_id: string|null, website: string|null}
     */
    public function getSeller(?Model $tenant = null): array
    {
        $config = config('alfakit-invoices.seller', []);
        $seller = $this->evaluate($this->seller, ['tenant' => $tenant], $tenant === null ? [] : [Model::class => $tenant, $tenant::class => $tenant]);
        $seller = [...(is_array($config) ? $config : []), ...(is_array($seller) ? array_filter($seller, static fn (mixed $value): bool => $value !== null) : [])];

        $details = [];

        foreach (['name', 'address', 'email', 'phone', 'tax_id', 'website'] as $key) {
            $value = $seller[$key] ?? null;
            $details[$key] = is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        }

        return $details;
    }

    /**
     * Override the panel's branding on documents. Each value may be a closure.
     */
    public function branding(string|Closure|null $name = null, string|Closure|null $logo = null, string|Closure|null $color = null, string|Closure|null $font = null): static
    {
        $this->branding = array_filter(compact('name', 'logo', 'color', 'font'), static fn (mixed $value): bool => $value !== null);

        return $this;
    }

    public function getBranding(?Invoice $invoice = null): Branding
    {
        $tenant = $invoice?->getTenant();
        $overrides = [];

        foreach ($this->branding as $key => $value) {
            $resolved = $this->evaluate($value, ['tenant' => $tenant, 'invoice' => $invoice]);
            $overrides[$key] = is_string($resolved) ? $resolved : null;
        }

        /** @var array{name?: string|null, logo?: string|null, color?: string|null, font?: string|null} $overrides */
        return Branding::fromPanel(AlfakitInvoices::panel($invoice), $overrides);
    }

    public function defaultNotes(string|Closure|null $notes): static
    {
        $this->defaultNotes = $notes;

        return $this;
    }

    public function getDefaultNotes(?InvoiceType $type = null): ?string
    {
        $notes = $this->evaluate($this->defaultNotes, ['type' => $type]);

        return is_string($notes) && $notes !== '' ? $notes : null;
    }

    public function defaultTerms(string|Closure|null $terms): static
    {
        $this->defaultTerms = $terms;

        return $this;
    }

    public function getDefaultTerms(?InvoiceType $type = null): ?string
    {
        $terms = $this->evaluate($this->defaultTerms, ['type' => $type]);

        return is_string($terms) && $terms !== '' ? $terms : null;
    }

    // ---------------------------------------------------------------- Pay now

    /**
     * Where the customer page's "Pay now" button goes: `fn (Invoice $invoice): ?string`.
     * Takes precedence over the bound `PaymentLinkProvider`.
     */
    public function payNowUsing(?Closure $callback): static
    {
        $this->payNowUsing = $callback;

        return $this;
    }

    public function getPayNowUsing(): ?Closure
    {
        return $this->payNowUsing;
    }
}
