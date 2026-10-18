<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last number handed out for a document type, per tenant (`scope`) and period
 * (`2026`, `2026-10` or `''`). Rows are only changed by `Numbering::next()`.
 *
 * @property int $id
 * @property string $scope
 * @property string $type
 * @property string $period
 * @property int $value
 */
class NumberSequence extends Model
{
    protected $table = 'alfakit_invoice_sequences';

    protected $guarded = [];

    protected $casts = [
        'value' => 'integer',
    ];
}
