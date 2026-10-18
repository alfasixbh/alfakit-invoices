<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitInvoices\AlfakitInvoices;
use AlfaSix\AlfakitInvoices\Models\Tax;
use AlfaSix\AlfakitInvoices\Resources\Concerns\IsInvoicesResource;
use AlfaSix\AlfakitInvoices\Resources\TaxResource\Pages;

/**
 * The tax rates offered on invoice lines, per tenant. Lines keep a snapshot of their
 * rates, so changes here only affect documents that are still editable.
 */
class TaxResource extends Resource
{
    use IsInvoicesResource;

    protected static ?string $slug = 'invoice-taxes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'percent';

    protected static ?string $tenantOwnershipRelationshipName = 'tenant';

    public static function getModel(): string
    {
        return AlfakitInvoices::taxModel();
    }

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.taxes.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-invoices::invoices.taxes.plural');
    }

    public static function getNavigationSort(): ?int
    {
        return (static::getPlugin()->getNavigationSort() ?? 0) + 1;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'sm' => 2])->components([
            TextInput::make('name')
                ->label(__('alfakit-invoices::invoices.taxes.name'))
                ->placeholder(__('alfakit-invoices::invoices.taxes.name_placeholder'))
                ->required()
                ->maxLength(100),
            TextInput::make('rate')
                ->label(__('alfakit-invoices::invoices.taxes.rate'))
                ->numeric()
                ->minValue(0)
                ->maxValue(1000)
                ->rule('decimal:0,4')
                ->suffix('%')
                ->required(),
            Toggle::make('is_inclusive')
                ->label(__('alfakit-invoices::invoices.taxes.inclusive'))
                ->helperText(__('alfakit-invoices::invoices.taxes.inclusive_help')),
            Toggle::make('is_compound')
                ->label(__('alfakit-invoices::invoices.taxes.compound'))
                ->helperText(__('alfakit-invoices::invoices.taxes.compound_help')),
            Toggle::make('is_default')
                ->label(__('alfakit-invoices::invoices.taxes.default'))
                ->helperText(__('alfakit-invoices::invoices.taxes.default_help')),
            Toggle::make('is_active')
                ->label(__('alfakit-invoices::invoices.taxes.active'))
                ->default(true),
            TextInput::make('sort')
                ->label(__('alfakit-invoices::invoices.taxes.sort'))
                ->integer()
                ->minValue(0)
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('alfakit-invoices::invoices.taxes.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('rate')
                    ->label(__('alfakit-invoices::invoices.taxes.rate'))
                    ->state(static fn (Tax $record): string => rtrim(rtrim((string) $record->rate, '0'), '.').'%')
                    ->alignment(Alignment::End)
                    ->sortable(),
                IconColumn::make('is_inclusive')->label(__('alfakit-invoices::invoices.taxes.inclusive'))->boolean(),
                IconColumn::make('is_compound')->label(__('alfakit-invoices::invoices.taxes.compound'))->boolean(),
                IconColumn::make('is_default')->label(__('alfakit-invoices::invoices.taxes.default'))->boolean(),
                IconColumn::make('is_active')->label(__('alfakit-invoices::invoices.taxes.active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('sort')
            ->emptyStateIcon('percent')
            ->emptyStateHeading(__('alfakit-invoices::invoices.taxes.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxes::route('/'),
            'create' => Pages\CreateTax::route('/create'),
            'edit' => Pages\EditTax::route('/{record}/edit'),
        ];
    }
}
