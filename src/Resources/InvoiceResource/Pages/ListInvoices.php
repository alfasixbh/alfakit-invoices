<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\InvoiceResource\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Resources\InvoiceResource;
use AlfaSix\AlfakitInvoices\Widgets\InvoiceStatsWidget;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public static function getResource(): string
    {
        return InvoicesPlugin::get()->getInvoiceResource();
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('alfakit-invoices::invoices.tabs.all'))];

        foreach (InvoiceType::cases() as $type) {
            $tabs[$type->value] = Tab::make($type->getPluralLabel())
                ->icon($type->getIcon())
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where($query->qualifyColumn('type'), $type->value));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        $resource = static::getResource();

        return [
            CreateAction::make()->label(__('alfakit-invoices::invoices.actions.new_invoice')),
            ActionGroup::make(array_map(
                static fn (InvoiceType $type): Action => Action::make('create_'.$type->value)
                    ->label($type === InvoiceType::Quote ? __('alfakit-invoices::invoices.actions.new_quote') : __('alfakit-invoices::invoices.actions.new_receipt'))
                    ->icon($type->getIcon())
                    ->authorize(static fn (): bool => $resource::canCreate())
                    ->url($resource::getUrl('create', ['type' => $type->value])),
                [InvoiceType::Quote, InvoiceType::Receipt],
            ))
                ->label(__('alfakit-invoices::invoices.actions.new_other'))
                ->icon('chevron-down')
                ->color('gray')
                ->button(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [InvoiceStatsWidget::class];
    }
}
