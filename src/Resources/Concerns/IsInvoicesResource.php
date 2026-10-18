<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Resources\Concerns;

use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Shared by the invoices resources: the plugin's navigation group, and polymorphic
 * tenancy (records belong to a tenant of any model through the `tenant` morph).
 *
 * @mixin \AlfaSix\Alfakit\Resources\Resource
 */
trait IsInvoicesResource
{
    public static function getPlugin(): InvoicesPlugin
    {
        return InvoicesPlugin::get();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup() ?? (string) __('alfakit-invoices::invoices.navigation.group');
    }

    public static function scopeEloquentQueryToTenant(Builder $query, Model $tenant): Builder
    {
        $relationship = $query->getModel()->{static::getTenantOwnershipRelationshipName()}();

        if ($relationship instanceof MorphTo) {
            return $query
                ->where($query->qualifyColumn($relationship->getMorphType()), $tenant->getMorphClass())
                ->where($query->qualifyColumn($relationship->getForeignKeyName()), $tenant->getKey());
        }

        return parent::scopeEloquentQueryToTenant($query, $tenant);
    }
}
