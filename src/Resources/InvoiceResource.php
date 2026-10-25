<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources;

use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\MoneyInput;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Repeater\TableColumn;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry\TableColumn as EntryTableColumn;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Flex;
use AlfaSix\Alfakit\Schemas\Components\FusedGroup;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitInvoices\Actions\InvoiceActions;
use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Enums\DiscountType;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\InvoiceItem;
use AlfaSix\AlfakitInvoices\Models\Payment;
use AlfaSix\AlfakitInvoices\Models\Tax;
use AlfaSix\AlfakitInvoices\Policies\InvoicePolicy;
use AlfaSix\AlfakitInvoices\Resources\Concerns\IsInvoicesResource;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages;
use AlfaSix\AlfakitInvoices\Support\FormTotals;
use AlfaSix\AlfakitInvoices\Support\Money\Calculator;
use AlfaSix\AlfakitInvoices\Support\Money\Totals;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Invoices, quotes, credit notes and receipts: the list (tabs per type, stats), the
 * editor with its line items (totals recomputed on the server as you type) and the
 * document page with its actions. Extend it and pass your class to
 * `InvoicesPlugin::make()->invoiceResource(...)`.
 */
class InvoiceResource extends Resource
{
    use IsInvoicesResource;

    protected static ?string $slug = 'invoices';

    protected static ?string $recordTitleAttribute = 'number';

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'file-text';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    /** How many tax rates the form's totals panel lists (the saved document lists all). */
    protected static int $formTaxRows = 6;

    /**
     * The document's state is a business rule, not a permission: a paid or void document stays
     * locked for users a `Gate::before()` allows everything (Shield's super admin) too.
     */
    public static function can(string $action, ?Model $record = null): bool
    {
        if ($record instanceof Invoice && ! InvoicePolicy::stateAllows($action, $record)) {
            return false;
        }

        return parent::can($action, $record);
    }

    public static function getModel(): string
    {
        return AlfakitInvoices::invoiceModel();
    }

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.resource.plural');
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Invoice ? $record->getTitle() : parent::getRecordTitle($record);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer_name', 'customer_email', 'reference'];
    }

    // ---------------------------------------------------------------- Form

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Grid::make(1)
                ->columnSpan(['default' => 1, 'lg' => 2])
                ->schema([
                    Section::make(__('alfakit-invoices::invoices.sections.customer'))
                        ->key('customer')
                        ->icon('building-2')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema(static::customerFields()),
                    Section::make(__('alfakit-invoices::invoices.sections.items'))
                        ->key('lines')
                        ->icon('list')
                        ->schema([static::itemsRepeater()]),
                    Section::make(__('alfakit-invoices::invoices.sections.notes'))
                        ->key('notes')
                        ->icon('notebook-pen')
                        ->collapsible()
                        ->columns(['default' => 1, 'sm' => 2])
                        ->schema([
                            Textarea::make('notes')
                                ->label(__('alfakit-invoices::invoices.fields.notes'))
                                ->helperText(__('alfakit-invoices::invoices.help.notes'))
                                ->default(static fn (): ?string => static::getPlugin()->getDefaultNotes(static::typeFromRequest()))
                                ->rows(4)
                                ->maxLength(5000),
                            Textarea::make('terms')
                                ->label(__('alfakit-invoices::invoices.fields.terms'))
                                ->default(static fn (): ?string => static::getPlugin()->getDefaultTerms(static::typeFromRequest()))
                                ->rows(4)
                                ->maxLength(5000),
                        ]),
                ]),
            Grid::make(1)
                ->columnSpan(['default' => 1, 'lg' => 1])
                ->schema([
                    Section::make(__('alfakit-invoices::invoices.sections.document'))
                        ->key('document')
                        ->icon('file-cog')
                        ->schema(static::documentFields()),
                    Section::make(__('alfakit-invoices::invoices.sections.totals'))
                        ->key('totals')
                        ->icon('calculator')
                        ->schema(static::totalsComponents()),
                ]),
        ]);
    }

    /**
     * @return array<int, Component>
     */
    protected static function customerFields(): array
    {
        $plugin = static::getPlugin();
        $fields = [];

        if ($plugin->hasCustomers()) {
            $fields[] = Select::make('billable_id')
                ->label(__('alfakit-invoices::invoices.fields.customer'))
                ->helperText(__('alfakit-invoices::invoices.help.customer'))
                ->searchable()
                ->getSearchResultsUsing(static function (string $search) use ($plugin): array {
                    $query = $plugin->getCustomersQuery(alfakit()->getTenant());

                    if ($query === null) {
                        return [];
                    }

                    $query->where(static function (Builder $query) use ($plugin, $search): void {
                        foreach ($plugin->getCustomerSearchColumns() as $column) {
                            $query->orWhere($column, 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
                        }
                    });

                    return $query->limit(50)->get()
                        ->mapWithKeys(static fn (Model $customer): array => [(string) $customer->getKey() => (string) $customer->getAttribute($plugin->getCustomerTitleAttribute())])
                        ->all();
                })
                ->getOptionLabelUsing(static function (mixed $value) use ($plugin): ?string {
                    $customer = is_scalar($value) ? $plugin->getCustomersQuery(alfakit()->getTenant())?->whereKey($value)->first() : null;

                    return $customer === null ? null : (string) $customer->getAttribute($plugin->getCustomerTitleAttribute());
                })
                ->live()
                ->afterStateUpdated(static function (mixed $state, Set $set) use ($plugin): void {
                    $customer = is_scalar($state) && (string) $state !== '' ? $plugin->getCustomersQuery(alfakit()->getTenant())?->whereKey($state)->first() : null;

                    if ($customer === null) {
                        return;
                    }

                    $details = (new Invoice)->fillCustomerFrom($customer);

                    foreach (['customer_name', 'customer_email', 'customer_phone', 'customer_address', 'customer_tax_id'] as $field) {
                        $set($field, $details->getAttribute($field));
                    }
                })
                ->columnSpanFull();
        }

        return [
            ...$fields,
            TextInput::make('customer_name')
                ->label(__('alfakit-invoices::invoices.fields.customer_name'))
                ->required()
                ->maxLength(255),
            TextInput::make('customer_email')
                ->label(__('alfakit-invoices::invoices.fields.customer_email'))
                ->email()
                ->maxLength(255),
            TextInput::make('customer_phone')
                ->label(__('alfakit-invoices::invoices.fields.customer_phone'))
                ->tel()
                ->maxLength(64),
            TextInput::make('customer_tax_id')
                ->label(__('alfakit-invoices::invoices.fields.customer_tax_id'))
                ->maxLength(64),
            Textarea::make('customer_address')
                ->label(__('alfakit-invoices::invoices.fields.customer_address'))
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected static function documentFields(): array
    {
        $plugin = static::getPlugin();

        return [
            Select::make('type')
                ->label(__('alfakit-invoices::invoices.fields.type'))
                ->options(static fn (string $operation): array => $operation === 'create'
                    ? array_diff_key(InvoiceType::options(), [InvoiceType::CreditNote->value => true])
                    : InvoiceType::options())
                ->default(static fn (): string => static::typeFromRequest()->value)
                ->required()
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(static function (mixed $state, Get $get, Set $set) use ($plugin): void {
                    $type = InvoiceType::tryFrom(is_string($state) ? $state : '') ?? InvoiceType::Invoice;
                    $days = match ($type) {
                        InvoiceType::Invoice => $plugin->getDueDays(),
                        InvoiceType::Quote => $plugin->getQuoteValidDays(),
                        default => null,
                    };

                    try {
                        $issued = Carbon::parse(is_string($get('issue_date')) ? $get('issue_date') : 'today');
                    } catch (Throwable) {
                        $issued = Carbon::today();
                    }

                    $set('due_date', $days === null ? null : $issued->addDays($days)->format('Y-m-d'));
                })
                ->disabledOn('edit'),
            Text::make(static fn (?Model $record): string => $record instanceof Invoice ? (string) __('alfakit-invoices::invoices.labels.number', ['number' => $record->getDisplayNumber()]) : (string) __('alfakit-invoices::invoices.help.number'))
                ->key('number-hint')
                ->color('neutral')
                ->size('sm'),
            DatePicker::make('issue_date')
                ->label(__('alfakit-invoices::invoices.fields.issue_date'))
                ->default(static fn (): string => Carbon::today()->format('Y-m-d'))
                ->required(),
            DatePicker::make('due_date')
                ->label(static fn (Get $get): string => (string) __($get('type') === InvoiceType::Quote->value ? 'alfakit-invoices::invoices.fields.valid_until' : 'alfakit-invoices::invoices.fields.due_date'))
                ->default(static function () use ($plugin): ?string {
                    $days = match (static::typeFromRequest()) {
                        InvoiceType::Invoice => $plugin->getDueDays(),
                        InvoiceType::Quote => $plugin->getQuoteValidDays(),
                        default => null,
                    };

                    return $days === null ? null : Carbon::today()->addDays($days)->format('Y-m-d');
                })
                ->afterOrEqual('issue_date')
                ->hidden(static fn (Get $get): bool => in_array($get('type'), [InvoiceType::Receipt->value, InvoiceType::CreditNote->value], true)),
            Select::make('currency')
                ->label(__('alfakit-invoices::invoices.fields.currency'))
                ->options(static fn (): array => Currency::options($plugin->getCurrencies()))
                ->default(static fn (): string => $plugin->getDefaultCurrency())
                ->searchable()
                ->required()
                ->selectablePlaceholder(false)
                ->live(),
            TextInput::make('reference')
                ->label(__('alfakit-invoices::invoices.fields.reference'))
                ->helperText(__('alfakit-invoices::invoices.help.reference'))
                ->maxLength(255),
        ];
    }

    protected static function itemsRepeater(): Repeater
    {
        $tenant = static fn (): ?Model => alfakit()->getTenant();

        return Repeater::make('items')
            ->hiddenLabel()
            // The only component of its section: use its whole width, not one of two columns.
            ->columnSpanFull()
            ->relationship()
            ->orderColumn('sort')
            ->table([
                TableColumn::make(__('alfakit-invoices::invoices.fields.description'))->width('16rem'),
                TableColumn::make(__('alfakit-invoices::invoices.fields.quantity'))->width('9rem')->alignment(Alignment::End),
                TableColumn::make(__('alfakit-invoices::invoices.fields.unit_price'))->width('11rem')->alignment(Alignment::End),
                TableColumn::make(__('alfakit-invoices::invoices.fields.discount'))->width('12rem'),
                TableColumn::make(__('alfakit-invoices::invoices.fields.taxes'))->width('13rem'),
                TableColumn::make(__('alfakit-invoices::invoices.fields.amount'))->width('9rem')->alignment(Alignment::End),
            ])
            ->schema([
                TextInput::make('description')
                    ->label(__('alfakit-invoices::invoices.fields.description'))
                    ->placeholder(__('alfakit-invoices::invoices.help.description'))
                    ->required()
                    ->maxLength(2000),
                TextInput::make('quantity')
                    ->label(__('alfakit-invoices::invoices.fields.quantity'))
                    ->numeric()
                    ->minValue(0)
                    ->rule('decimal:0,'.Calculator::quantityDecimals())
                    ->default('1')
                    ->required()
                    ->live(onBlur: true),
                MoneyInput::make('unit_price')
                    ->label(__('alfakit-invoices::invoices.fields.unit_price'))
                    ->currency(static fn (Get $get): string => static::currencyOf($get('../../currency')))
                    ->minorUnits()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->live(onBlur: true),
                FusedGroup::make([
                    TextInput::make('discount_value')
                        ->label(__('alfakit-invoices::invoices.fields.discount'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(static fn (Get $get): ?int => $get('discount_type') === DiscountType::Percent->value ? 100 : null)
                        ->placeholder('0')
                        ->live(onBlur: true)
                        ->columnSpan(2),
                    Select::make('discount_type')
                        ->label(__('alfakit-invoices::invoices.fields.discount_type'))
                        ->options(static fn (Get $get): array => [
                            DiscountType::Percent->value => '%',
                            DiscountType::Fixed->value => static::currencyOf($get('../../currency')),
                        ])
                        ->default(DiscountType::Percent->value)
                        ->selectablePlaceholder(false)
                        ->live(),
                ])
                    ->key('discount')
                    ->label(__('alfakit-invoices::invoices.fields.discount'))
                    ->columns(3),
                Select::make('tax_ids')
                    ->label(__('alfakit-invoices::invoices.fields.taxes'))
                    ->multiple()
                    ->options(static fn (): array => Tax::options($tenant()))
                    ->default(static fn (): array => Tax::defaultIds($tenant()))
                    ->placeholder(__('alfakit-invoices::invoices.help.no_tax'))
                    ->live(),
                Text::make(static function (Get $get) use ($tenant): string {
                    // Relative paths resolve from the item, like the item's fields.
                    $currency = static::currencyOf($get('../../currency'));

                    try {
                        $line = FormTotals::line([
                            'quantity' => $get('quantity'),
                            'unit_price' => $get('unit_price'),
                            'discount_type' => $get('discount_type'),
                            'discount_value' => $get('discount_value'),
                            'tax_ids' => $get('tax_ids'),
                        ], $currency, $tenant());

                        return Currency::format(Calculator::calculate([$line], $currency)->lines[0]->total, $currency, app()->getLocale(), inMinorUnits: true);
                    } catch (Throwable) {
                        return '—';
                    }
                })
                    ->key('line-total')
                    ->weight('medium'),
            ])
            ->defaultItems(1)
            ->minItems(1)
            ->maxItems(500)
            ->reorderable()
            ->cloneable()
            ->compact()
            ->addActionLabel(__('alfakit-invoices::invoices.actions.add_line'));
    }

    /**
     * The totals panel. Its texts are computed on the server from the form state on every
     * refresh (the line fields are live).
     *
     * @return array<int, Component>
     */
    public static function totalsComponents(): array
    {
        $money = static fn (Get $get, int $amount): string => Currency::format($amount, static::currencyOf($get('currency', isAbsolute: true)), app()->getLocale(), inMinorUnits: true);

        $rows = [
            static::totalRow('subtotal', __('alfakit-invoices::invoices.totals.subtotal'), static fn (Get $get, ?Model $record, Component $component): string => $money($get, static::formAmount($get, $record, $component, 'subtotal'))),
            static::totalRow('discount', __('alfakit-invoices::invoices.totals.discount'), static fn (Get $get, ?Model $record, Component $component): string => '−'.$money($get, static::formAmount($get, $record, $component, 'discount')))
                ->visible(static fn (Get $get, ?Model $record, Component $component): bool => static::formAmount($get, $record, $component, 'discount') !== 0),
        ];

        for ($index = 0; $index < static::$formTaxRows; $index++) {
            $tax = static fn (Get $get, ?Model $record, Component $component): ?array => static::formTotals($get, $record, $component)?->taxes[$index] ?? null;

            $rows[] = static::totalRow(
                'tax-'.$index,
                static function (Get $get, ?Model $record, Component $component) use ($tax): string {
                    $rate = $tax($get, $record, $component);

                    return $rate === null ? '' : $rate['name'].' '.$rate['rate'].'%'.($rate['inclusive'] ? ' ('.__('alfakit-invoices::invoices.taxes.included').')' : '');
                },
                static fn (Get $get, ?Model $record, Component $component): string => $money($get, (int) ($tax($get, $record, $component)['amount'] ?? 0)),
            )->visible(static fn (Get $get, ?Model $record, Component $component): bool => $tax($get, $record, $component) !== null);
        }

        $rows[] = static::totalRow('total', __('alfakit-invoices::invoices.totals.total'), static fn (Get $get, ?Model $record, Component $component): string => $money($get, static::formAmount($get, $record, $component, 'total')), emphasis: true);

        return $rows;
    }

    /**
     * The totals of the form state (tenant's tax rates only, the document's rounding).
     */
    public static function formTotals(Get $get, ?Model $record = null, ?Component $component = null): ?Totals
    {
        $items = $get('items', isAbsolute: true);
        $calculate = static fn (): ?Totals => FormTotals::calculate(
            is_array($items) ? $items : [],
            static::currencyOf($get('currency', isAbsolute: true)),
            $record instanceof Invoice ? $record->rounding : static::getPlugin()->getRounding(),
            alfakit()->getTenant(),
        );

        // Computed once per rendering of the form (and again when its state changes).
        return $component === null ? $calculate() : FormTotals::remember($component->getContainer()->getRootSchema(), [$items, $get('currency', isAbsolute: true)], $calculate);
    }

    protected static function formAmount(Get $get, ?Model $record, Component $component, string $key): int
    {
        $totals = static::formTotals($get, $record, $component);
        $amount = $totals?->toArray()[$key] ?? 0;

        return is_int($amount) ? $amount : 0;
    }

    protected static function totalRow(string $key, string|Closure $label, string|Closure $value, bool $emphasis = false): Flex
    {
        return Flex::make([
            Text::make($label)->key('totals-'.$key.'-label')->color($emphasis ? null : 'neutral')->weight($emphasis ? 'semibold' : null),
            Text::make($value)->key('totals-'.$key)->weight($emphasis ? 'bold' : 'medium')->size($emphasis ? 'lg' : null),
        ])
            ->key('totals-row-'.$key)
            ->alignBetween()
            ->verticallyAlignCenter()
            ->from('default');
    }

    protected static function currencyOf(mixed $currency): string
    {
        try {
            return Currency::normalize(is_string($currency) && $currency !== '' ? $currency : static::getPlugin()->getDefaultCurrency());
        } catch (Throwable) {
            return alfakit()->getCurrency();
        }
    }

    /**
     * The type of a new document: `?type=quote` on the create page, else an invoice.
     */
    public static function typeFromRequest(): InvoiceType
    {
        $type = request()->query('type');

        return InvoiceType::tryFrom(is_string($type) ? $type : '') ?? InvoiceType::Invoice;
    }

    // ---------------------------------------------------------------- Document page

    public static function infolist(Schema $schema): Schema
    {
        $money = static fn (string $name): TextEntry => TextEntry::make($name)
            ->money(static fn (Invoice $record): string => $record->currency, inMinorUnits: true);

        return $schema->columns(['default' => 1, 'lg' => 3])->components([
            Grid::make(1)
                ->columnSpan(['default' => 1, 'lg' => 2])
                ->schema([
                    Section::make(static fn (Invoice $record): string => $record->getTitle())
                        ->key('summary')
                        ->icon(static fn (Invoice $record): string => $record->type->getIcon())
                        ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('status')
                                ->label(__('alfakit-invoices::invoices.fields.status'))
                                ->badge(),
                            TextEntry::make('customer_name')
                                ->label(__('alfakit-invoices::invoices.fields.customer'))
                                ->state(static fn (Invoice $record): string => $record->getCustomerLabel())
                                ->helperText(static fn (Invoice $record): ?string => $record->customer_name !== null ? $record->customer_email : null),
                            TextEntry::make('issue_date')
                                ->label(__('alfakit-invoices::invoices.fields.issue_date'))
                                ->date(),
                            TextEntry::make('due_date')
                                ->label(static fn (Invoice $record): string => (string) __($record->type === InvoiceType::Quote ? 'alfakit-invoices::invoices.fields.valid_until' : 'alfakit-invoices::invoices.fields.due_date'))
                                ->date()
                                ->color(static fn (Invoice $record): ?string => $record->status === InvoiceStatus::Overdue ? 'danger' : null)
                                ->placeholder('—'),
                        ]),
                    Section::make(__('alfakit-invoices::invoices.sections.items'))
                        ->key('items')
                        ->schema([
                            RepeatableEntry::make('items')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->state(static fn (Invoice $record): mixed => $record->items()->with('invoice')->get())
                                ->table([
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.description'))->width('40%'),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.quantity'))->alignment(Alignment::End),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.unit_price'))->alignment(Alignment::End),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.discount'))->alignment(Alignment::End),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.tax'))->alignment(Alignment::End),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.amount'))->alignment(Alignment::End),
                                ])
                                ->schema([
                                    TextEntry::make('description')
                                        ->helperText(static fn (mixed $record): ?string => $record instanceof InvoiceItem && ($record->taxes ?? []) !== [] ? $record->getTaxLabel() : null),
                                    TextEntry::make('quantity')
                                        ->state(static fn (mixed $record): string => $record instanceof InvoiceItem ? $record->invoice->formatter()->quantity((string) $record->quantity) : ''),
                                    TextEntry::make('unit_price')
                                        ->state(static fn (mixed $record): string => $record instanceof InvoiceItem ? $record->invoice->formatMoney((int) $record->unit_price) : ''),
                                    TextEntry::make('discount_amount')
                                        ->state(static fn (mixed $record): string => ! $record instanceof InvoiceItem || (int) $record->discount_amount === 0 ? '—' : '−'.$record->invoice->formatMoney((int) $record->discount_amount)),
                                    TextEntry::make('tax_amount')
                                        ->state(static fn (mixed $record): string => $record instanceof InvoiceItem ? $record->invoice->formatMoney((int) $record->tax_amount) : ''),
                                    TextEntry::make('total')
                                        ->state(static fn (mixed $record): string => $record instanceof InvoiceItem ? $record->invoice->formatMoney((int) $record->total) : '')
                                        ->weight('medium'),
                                ]),
                        ]),
                    Section::make(__('alfakit-invoices::invoices.sections.payments'))
                        ->key('payments')
                        ->visible(static fn (Invoice $record): bool => $record->type->isPayable() || $record->payments()->exists())
                        ->schema([
                            RepeatableEntry::make('payments')
                                ->hiddenLabel()
                                ->state(static fn (Invoice $record): mixed => $record->payments()->with('invoice')->get())
                                ->placeholder(__('alfakit-invoices::invoices.labels.no_payments'))
                                ->table([
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.paid_at')),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.method')),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.payment_reference')),
                                    EntryTableColumn::make(__('alfakit-invoices::invoices.fields.amount'))->alignment(Alignment::End),
                                ])
                                ->schema([
                                    TextEntry::make('paid_at')->date(),
                                    TextEntry::make('method')->state(static fn (mixed $record): string => $record instanceof Payment ? $record->getMethodLabel() : ''),
                                    TextEntry::make('reference')->placeholder('—'),
                                    TextEntry::make('amount')->state(static fn (mixed $record): string => $record instanceof Payment ? $record->invoice->formatMoney((int) $record->amount) : '')->alignEnd(),
                                ]),
                        ]),
                    Section::make(__('alfakit-invoices::invoices.sections.notes'))
                        ->key('notes')
                        ->columns(['default' => 1, 'sm' => 2])
                        ->visible(static fn (Invoice $record): bool => $record->notes !== null || $record->terms !== null)
                        ->schema([
                            TextEntry::make('notes')->label(__('alfakit-invoices::invoices.fields.notes'))->placeholder('—'),
                            TextEntry::make('terms')->label(__('alfakit-invoices::invoices.fields.terms'))->placeholder('—'),
                        ]),
                ]),
            Grid::make(1)
                ->columnSpan(['default' => 1, 'lg' => 1])
                ->schema([
                    Section::make(__('alfakit-invoices::invoices.sections.totals'))
                        ->key('totals')
                        ->icon('calculator')
                        ->schema([
                            $money('subtotal')->label(__('alfakit-invoices::invoices.totals.subtotal'))->inlineLabel(),
                            $money('discount_total')->label(__('alfakit-invoices::invoices.totals.discount'))->inlineLabel()
                                ->visible(static fn (Invoice $record): bool => (int) $record->discount_total !== 0),
                            TextEntry::make('tax_breakdown')
                                ->label(__('alfakit-invoices::invoices.totals.taxes'))
                                ->inlineLabel()
                                ->state(static fn (Invoice $record): array => array_map(static fn (array $tax): string => ($tax['name'] ?? '').' '.($tax['rate'] ?? '').'%: '.$record->formatMoney((int) ($tax['amount'] ?? 0)).(! empty($tax['inclusive']) ? ' ('.__('alfakit-invoices::invoices.taxes.included').')' : ''), $record->tax_breakdown ?? []))
                                ->listWithLineBreaks()
                                ->visible(static fn (Invoice $record): bool => ($record->tax_breakdown ?? []) !== []),
                            $money('total')->label(__('alfakit-invoices::invoices.totals.total'))->inlineLabel()->weight('bold')->size('lg'),
                            $money('amount_paid')->label(__('alfakit-invoices::invoices.totals.paid'))->inlineLabel()
                                ->visible(static fn (Invoice $record): bool => (int) $record->amount_paid > 0),
                            $money('amount_due')->label(__('alfakit-invoices::invoices.totals.due'))->inlineLabel()->weight('bold')
                                ->color(static fn (Invoice $record): ?string => $record->status === InvoiceStatus::Overdue ? 'danger' : ((int) $record->amount_due === 0 ? 'success' : null))
                                ->visible(static fn (Invoice $record): bool => $record->type->isPayable() && $record->status !== InvoiceStatus::Void),
                        ]),
                    Section::make(__('alfakit-invoices::invoices.sections.activity'))
                        ->key('activity')
                        ->icon('history')
                        ->schema([
                            TextEntry::make('reference')->label(__('alfakit-invoices::invoices.fields.reference'))->inlineLabel()->placeholder('—'),
                            TextEntry::make('source')
                                ->label(static fn (Invoice $record): string => (string) __($record->type === InvoiceType::CreditNote ? 'alfakit-invoices::invoices.document.credits' : 'alfakit-invoices::invoices.document.from_quote'))
                                ->inlineLabel()
                                ->state(static fn (Invoice $record): ?string => $record->source?->getTitle())
                                ->url(static fn (Invoice $record): ?string => $record->source !== null && static::can('view', $record->source) ? static::getUrl('view', ['record' => $record->source]) : null)
                                ->visible(static fn (Invoice $record): bool => $record->source_id !== null),
                            TextEntry::make('derived')
                                ->label(__('alfakit-invoices::invoices.fields.related'))
                                ->inlineLabel()
                                ->state(static fn (Invoice $record): array => $record->derived()->get()->map(static fn (Invoice $related): string => $related->getTitle())->all())
                                ->listWithLineBreaks()
                                ->visible(static fn (Invoice $record): bool => $record->derived()->exists()),
                            TextEntry::make('sent_at')->label(__('alfakit-invoices::invoices.fields.sent_at'))->inlineLabel()->dateTime()->placeholder('—'),
                            TextEntry::make('viewed_at')->label(__('alfakit-invoices::invoices.fields.viewed_at'))->inlineLabel()->dateTime()->placeholder('—'),
                            TextEntry::make('paid_at')->label(__('alfakit-invoices::invoices.fields.paid_on'))->inlineLabel()->dateTime()
                                ->visible(static fn (Invoice $record): bool => $record->paid_at !== null),
                            TextEntry::make('voided_at')->label(__('alfakit-invoices::invoices.fields.voided_at'))->inlineLabel()->dateTime()
                                ->visible(static fn (Invoice $record): bool => $record->voided_at !== null),
                        ]),
                ]),
        ]);
    }

    // ---------------------------------------------------------------- List

    public static function table(Table $table): Table
    {
        $actions = InvoiceActions::all();

        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('alfakit-invoices::invoices.fields.number'))
                    ->state(static fn (Invoice $record): string => $record->getDisplayNumber())
                    ->description(static fn (Invoice $record): string => $record->type->getLabel())
                    ->searchable(['number', 'reference'])
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('customer_name')
                    ->label(__('alfakit-invoices::invoices.fields.customer'))
                    ->state(static fn (Invoice $record): string => $record->getCustomerLabel())
                    ->description(static fn (Invoice $record): ?string => $record->customer_name !== null ? $record->customer_email : null)
                    ->searchable(['customer_name', 'customer_email'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('issue_date')
                    ->label(__('alfakit-invoices::invoices.fields.issue_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label(__('alfakit-invoices::invoices.fields.due_date'))
                    ->date()
                    ->sortable()
                    ->color(static fn (Invoice $record): ?string => $record->status === InvoiceStatus::Overdue ? 'danger' : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('total')
                    ->label(__('alfakit-invoices::invoices.totals.total'))
                    ->money(static fn (Invoice $record): string => $record->currency, inMinorUnits: true)
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('amount_due')
                    ->label(__('alfakit-invoices::invoices.totals.due'))
                    ->money(static fn (Invoice $record): string => $record->currency, inMinorUnits: true)
                    ->alignment(Alignment::End)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('alfakit-invoices::invoices.fields.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('alfakit-invoices::invoices.fields.status'))
                    ->options(InvoiceStatus::options())
                    ->multiple(),
                SelectFilter::make('currency')
                    ->label(__('alfakit-invoices::invoices.fields.currency'))
                    ->options(static fn (): array => static::getEloquentQuery()->distinct()->orderBy('currency')->pluck('currency', 'currency')->all()),
                Filter::make('overdue')
                    ->label(__('alfakit-invoices::invoices.filters.overdue'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->where('status', InvoiceStatus::Overdue->value)),
                Filter::make('unpaid')
                    ->label(__('alfakit-invoices::invoices.filters.unpaid'))
                    ->toggle()
                    ->query(static fn (Builder $query): Builder => $query->whereIn('status', array_map(static fn (InvoiceStatus $status): string => $status->value, InvoiceStatus::open()))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    $actions['send'],
                    $actions['recordPayment'],
                    $actions['download'],
                    $actions['duplicate'],
                    $actions['convert'],
                    $actions['void'],
                ])->label(__('alfakit-invoices::invoices.actions.more'))->icon('ellipsis')->color('gray'),
            ])
            ->defaultSort('issue_date', 'desc')
            ->stackedOnMobile()
            ->emptyStateIcon('file-text')
            ->emptyStateHeading(__('alfakit-invoices::invoices.empty.heading'))
            ->emptyStateDescription(__('alfakit-invoices::invoices.empty.description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'view' => Pages\ViewInvoice::route('/{record}'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
