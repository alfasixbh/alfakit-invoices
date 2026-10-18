<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Tests\Fixtures\Models;

use AlfaSix\AlfakitInvoices\Contracts\InvoiceRecipient;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $company
 * @property string|null $email
 * @property string|null $address
 * @property string|null $vat
 * @property int|null $team_id
 */
class Customer extends Model implements InvoiceRecipient
{
    protected $table = 'invoices_customers';

    protected $guarded = [];

    public function getInvoiceRecipientDetails(): array
    {
        return ['name' => $this->company, 'email' => $this->email, 'address' => $this->address, 'tax_id' => $this->vat];
    }
}
