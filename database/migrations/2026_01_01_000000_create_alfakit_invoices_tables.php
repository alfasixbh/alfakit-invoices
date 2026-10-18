<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_invoice_taxes', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('tenant');
            $table->string('name');
            $table->decimal('rate', 9, 4);
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('alfakit_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('tenant');
            $table->string('panel_id')->nullable();
            $table->string('type', 20)->default('invoice');
            $table->string('status', 20)->default('draft');
            $table->string('number')->nullable();
            $table->string('reference')->nullable();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3);
            $table->string('rounding', 16)->default('per_line');
            $table->string('locale', 16)->nullable();
            $table->nullableMorphs('billable');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_tax_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_due')->default(0);
            $table->json('tax_breakdown')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('alfakit_invoices')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'number']);
            $table->index(['type', 'status', 'due_date']);
        });

        Schema::create('alfakit_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('alfakit_invoices')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->text('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->nullable();
            $table->json('tax_ids')->nullable();
            $table->json('taxes')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->timestamps();
        });

        Schema::create('alfakit_invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('alfakit_invoices')->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->date('paid_at');
            $table->string('method', 32);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('credit_note_id')->nullable()->constrained('alfakit_invoices')->nullOnDelete();
            $table->nullableMorphs('recorded_by');
            $table->timestamps();
        });

        Schema::create('alfakit_invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 191)->default('');
            $table->string('type', 20);
            $table->string('period', 16)->default('');
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_invoice_sequences');
        Schema::dropIfExists('alfakit_invoice_payments');
        Schema::dropIfExists('alfakit_invoice_items');
        Schema::dropIfExists('alfakit_invoices');
        Schema::dropIfExists('alfakit_invoice_taxes');
    }
};
