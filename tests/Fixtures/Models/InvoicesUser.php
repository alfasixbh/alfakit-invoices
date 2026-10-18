<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;

class InvoicesUser extends TenantUser
{
    protected $table = 'users';
}
