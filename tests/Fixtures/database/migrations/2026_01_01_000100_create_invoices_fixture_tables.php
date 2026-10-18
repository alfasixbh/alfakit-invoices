<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('company');
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('vat')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->timestamps();
        });
    }
};
