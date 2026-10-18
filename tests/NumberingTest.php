<?php

declare(strict_types=1);

use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Models\NumberSequence;
use AlfaSix\AlfakitInvoices\Support\Numbering;
use AlfaSix\AlfakitInvoices\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function AlfaSix\AlfakitInvoices\Tests\invoice;
use function AlfaSix\AlfakitInvoices\Tests\team;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('numbers each type with its own prefix and sequence', function (): void {
    Carbon::setTestNow('2026-03-15 10:00:00');

    expect(invoice()->number)->toBe('INV-2026-00001')
        ->and(invoice()->number)->toBe('INV-2026-00002')
        ->and(invoice(['type' => InvoiceType::Quote])->number)->toBe('QUO-2026-00001')
        ->and(invoice(['type' => InvoiceType::Receipt])->number)->toBe('RCT-2026-00001');
});

it('renders the format tokens and restarts sequences with the period', function (): void {
    $date = Carbon::parse('2026-07-04');

    expect(Numbering::render('{YYYY}{MM}{DD}-{SEQ:4}', $date, 7))->toBe('20260704-0007')
        ->and(Numbering::render('{YY}/{SEQ}', $date, 1234))->toBe('26/1234')
        ->and(Numbering::period('{YYYY}-{SEQ:5}', $date))->toBe('2026')
        ->and(Numbering::period('{YYYY}{MM}-{SEQ}', $date))->toBe('2026-07')
        ->and(Numbering::period('{SEQ:6}', $date))->toBe('');

    Carbon::setTestNow('2026-12-31');
    expect(invoice()->number)->toBe('INV-2026-00001');

    Carbon::setTestNow('2027-01-01');
    expect(invoice()->number)->toBe('INV-2027-00001')
        ->and(invoice(['issue_date' => '2026-12-30'])->number)->toBe('INV-2026-00002');
});

it('uses the plugin numbering options', function (): void {
    Carbon::setTestNow('2026-05-01');
    InvoicesPlugin::get()->numbering(InvoiceType::Invoice, prefix: 'ACME/', format: '{YYYY}{MM}-{SEQ:3}');

    expect(invoice()->number)->toBe('ACME/202605-001')
        ->and(fn () => InvoicesPlugin::get()->numbering(InvoiceType::Quote, format: '{YYYY}'))->toThrow(InvalidArgumentException::class);
});

it('keeps a separate sequence per tenant', function (): void {
    Carbon::setTestNow('2026-01-10');
    $alpha = team('Alpha');
    $beta = team('Beta');

    expect(invoice(tenant: $alpha)->number)->toBe('INV-2026-00001')
        ->and(invoice(tenant: $alpha)->number)->toBe('INV-2026-00002')
        ->and(invoice(tenant: $beta)->number)->toBe('INV-2026-00001')
        ->and(invoice()->number)->toBe('INV-2026-00001')
        ->and(NumberSequence::query()->count())->toBe(3)
        ->and(Numbering::current(InvoiceType::Invoice, $alpha))->toBe(2);
});

it('can number drafts only when they are issued', function (): void {
    Carbon::setTestNow('2026-02-01');
    InvoicesPlugin::get()->assignNumbersOnIssue();

    $draft = invoice();
    $other = invoice();

    expect($draft->number)->toBeNull()
        ->and($draft->getDisplayNumber())->toBe('Draft');

    $other->markAsSent();
    $draft->markAsSent();

    expect($other->number)->toBe('INV-2026-00001')->and($draft->number)->toBe('INV-2026-00002');
});

it('never hands out the same number to concurrent processes', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to run processes in parallel.');
    }

    // Real processes against a shared SQLite file (an in-memory database can't be shared).
    $database = tempnam(sys_get_temp_dir(), 'alfakit-invoices-');
    $results = tempnam(sys_get_temp_dir(), 'alfakit-invoices-numbers-');
    config()->set('database.connections.numbering', ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'busy_timeout' => 10000, 'journal_mode' => 'wal']);
    DB::connection('numbering')->getSchemaBuilder()->create('alfakit_invoice_sequences', static function ($table): void {
        $table->id();
        $table->string('scope', 191)->default('');
        $table->string('type', 20);
        $table->string('period', 16)->default('');
        $table->unsignedBigInteger('value')->default(0);
        $table->timestamps();
        $table->unique(['scope', 'type', 'period']);
    });
    DB::purge('numbering');

    $model = new class extends NumberSequence
    {
        protected $connection = 'numbering';
    };
    config()->set('alfakit-invoices.models.sequence', $model::class);

    $children = [];

    for ($process = 0; $process < 4; $process++) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge('numbering');
            $numbers = [];

            for ($i = 0; $i < 25; $i++) {
                $numbers[] = Numbering::next(InvoiceType::Invoice, date: Carbon::parse('2026-06-01'));
            }

            file_put_contents($results, implode("\n", $numbers)."\n", FILE_APPEND | LOCK_EX);

            // Leave without running the test runner's shutdown handlers.
            posix_kill(getmypid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $numbers = array_values(array_filter(explode("\n", (string) file_get_contents($results))));
    @unlink($database);
    @unlink($results);

    expect($numbers)->toHaveCount(100)
        ->and(array_unique($numbers))->toHaveCount(100)
        ->and(collect($numbers)->sort()->values()->first())->toBe('INV-2026-00001')
        ->and(collect($numbers)->sort()->values()->last())->toBe('INV-2026-00100');
});
