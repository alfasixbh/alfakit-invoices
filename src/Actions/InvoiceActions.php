<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\MoneyInput;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\AlfakitInvoices\Enums\InvoiceStatus;
use AlfaSix\AlfakitInvoices\Enums\InvoiceType;
use AlfaSix\AlfakitInvoices\Exceptions\InvalidPayment;
use AlfaSix\AlfakitInvoices\Exceptions\InvalidStatusTransition;
use AlfaSix\AlfakitInvoices\InvoicesPlugin;
use AlfaSix\AlfakitInvoices\Mail\InvoiceMail;
use AlfaSix\AlfakitInvoices\Models\Invoice;
use AlfaSix\AlfakitInvoices\Models\Payment;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actions of an invoice, for its page and the table. Each one is authorised by an
 * ability of the invoice policy (`send`, `recordPayment`, …) and only shown when the
 * document's state allows it. Domain errors (a payment larger than the balance, a
 * transition that isn't allowed) become validation errors or danger notifications.
 */
final class InvoiceActions
{
    /**
     * @return array<string, Action>
     */
    public static function all(): array
    {
        return [
            'send' => self::send(),
            'recordPayment' => self::recordPayment(),
            'markPaid' => self::markPaid(),
            'preview' => self::preview(),
            'download' => self::download(),
            'copyLink' => self::copyLink(),
            'markSent' => self::markSent(),
            'duplicate' => self::duplicate(),
            'convert' => self::convert(),
            'creditNote' => self::creditNote(),
            'void' => self::void(),
        ];
    }

    public static function send(): Action
    {
        return Action::make('send')
            ->label(static fn (?Model $record): string => (string) __($record instanceof Invoice && $record->sent_at !== null ? 'alfakit-invoices::invoices.actions.resend' : 'alfakit-invoices::invoices.actions.send'))
            ->icon('send')
            ->modalHeading(static fn (?Model $record): string => (string) __('alfakit-invoices::invoices.actions.send_heading', ['title' => $record instanceof Invoice ? $record->getTitle() : '']))
            ->modalSubmitActionLabel(__('alfakit-invoices::invoices.actions.send_submit'))
            ->modalWidth('lg')
            ->authorize(static fn (?Model $record): bool => self::can('send', $record))
            ->fillForm(static fn (?Model $record): array => $record instanceof Invoice ? [
                'to' => $record->customer_email,
                'cc' => [],
                'subject' => null,
                'message' => null,
                'attach' => true,
            ] : [])
            ->schema([
                TextInput::make('to')
                    ->label(__('alfakit-invoices::invoices.fields.to'))
                    ->email()
                    ->required()
                    ->maxLength(255),
                TagsInput::make('cc')
                    ->label(__('alfakit-invoices::invoices.fields.cc'))
                    ->nestedRecursiveRules(['email', 'max:255'])
                    ->placeholder(__('alfakit-invoices::invoices.fields.cc_placeholder')),
                TextInput::make('subject')
                    ->label(__('alfakit-invoices::invoices.fields.subject'))
                    ->placeholder(static fn (?Model $record): string => $record instanceof Invoice ? (string) __('alfakit-invoices::invoices.mail.subject', ['type' => $record->type->getLabel(), 'number' => $record->getDisplayNumber(), 'name' => InvoicesPlugin::get($record)->getBranding($record)->name]) : '')
                    ->maxLength(255),
                Textarea::make('message')
                    ->label(__('alfakit-invoices::invoices.fields.message'))
                    ->helperText(__('alfakit-invoices::invoices.actions.send_message_help'))
                    ->rows(4)
                    ->maxLength(5000),
                Toggle::make('attach')
                    ->label(__('alfakit-invoices::invoices.fields.attach_document'))
                    ->default(true),
            ])
            ->action(static function (array $data, ?Model $record): void {
                $invoice = self::invoice($record);

                self::guard(static function () use ($invoice, $data): void {
                    $invoice->markAsSent();

                    $cc = array_values(array_filter(is_array($data['cc'] ?? null) ? $data['cc'] : [], is_string(...)));
                    $mail = Mail::to((string) $data['to']);

                    if ($cc !== []) {
                        $mail->cc($cc);
                    }

                    $mail->queue(new InvoiceMail(
                        $invoice->refresh(),
                        is_string($data['subject'] ?? null) ? $data['subject'] : null,
                        is_string($data['message'] ?? null) ? $data['message'] : null,
                        (bool) ($data['attach'] ?? true),
                    ));
                });

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.sent', ['email' => (string) $data['to']]))->send();
            });
    }

    public static function markSent(): Action
    {
        return Action::make('markSent')
            ->label(__('alfakit-invoices::invoices.actions.mark_sent'))
            ->icon('mail-check')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('alfakit-invoices::invoices.actions.mark_sent_description'))
            ->authorize(static fn (?Model $record): bool => self::can('send', $record))
            ->visible(static fn (?Model $record): bool => $record instanceof Invoice && $record->status === InvoiceStatus::Draft)
            ->action(static function (?Model $record): void {
                self::guard(static fn (): Invoice => self::invoice($record)->markAsSent());

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.marked_sent'))->send();
            });
    }

    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label(__('alfakit-invoices::invoices.actions.record_payment'))
            ->icon('banknote')
            ->color('success')
            ->modalHeading(__('alfakit-invoices::invoices.actions.record_payment'))
            ->modalDescription(static fn (?Model $record): ?string => $record instanceof Invoice ? (string) __('alfakit-invoices::invoices.actions.balance', ['amount' => $record->formatMoney((int) $record->amount_due)]) : null)
            ->modalSubmitActionLabel(__('alfakit-invoices::invoices.actions.record_payment_submit'))
            ->modalWidth('md')
            ->authorize(static fn (?Model $record): bool => self::can('recordPayment', $record))
            ->fillForm(static fn (?Model $record): array => $record instanceof Invoice ? [
                // The field uses minorUnits(): its state is in the smallest unit, like amount_due.
                'amount' => (int) $record->amount_due,
                'paid_at' => Carbon::today()->format('Y-m-d'),
                'method' => array_key_first(Payment::methodOptions()),
            ] : [])
            ->schema([
                MoneyInput::make('amount')
                    ->label(__('alfakit-invoices::invoices.fields.amount'))
                    ->currency(static fn (?Model $record): string => $record instanceof Invoice ? $record->currency : alfakit()->getCurrency())
                    ->minorUnits()
                    ->required(),
                DatePicker::make('paid_at')
                    ->label(__('alfakit-invoices::invoices.fields.paid_at'))
                    ->required()
                    ->maxDate(Carbon::today()->addDay()),
                Select::make('method')
                    ->label(__('alfakit-invoices::invoices.fields.method'))
                    ->options(static fn (): array => Payment::methodOptions())
                    ->required()
                    ->selectablePlaceholder(false),
                TextInput::make('reference')
                    ->label(__('alfakit-invoices::invoices.fields.payment_reference'))
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label(__('alfakit-invoices::invoices.fields.notes'))
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(static function (array $data, ?Model $record): void {
                $invoice = self::invoice($record);
                $amount = is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : 0;

                try {
                    $invoice->recordPayment(
                        $amount,
                        Carbon::parse(is_string($data['paid_at'] ?? null) ? $data['paid_at'] : 'today'),
                        is_string($data['method'] ?? null) ? $data['method'] : 'other',
                        is_string($data['reference'] ?? null) && $data['reference'] !== '' ? $data['reference'] : null,
                        is_string($data['notes'] ?? null) && $data['notes'] !== '' ? $data['notes'] : null,
                        recordedBy: self::user(),
                    );
                } catch (InvalidPayment $exception) {
                    throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
                }

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.payment_recorded'))->send();
            });
    }

    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label(__('alfakit-invoices::invoices.actions.mark_paid'))
            ->icon('circle-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(static fn (?Model $record): ?string => $record instanceof Invoice ? (string) __('alfakit-invoices::invoices.actions.mark_paid_description', ['amount' => $record->formatMoney((int) $record->amount_due)]) : null)
            ->schema([
                Select::make('method')
                    ->label(__('alfakit-invoices::invoices.fields.method'))
                    ->options(static fn (): array => Payment::methodOptions())
                    ->default(static fn (): ?string => array_key_first(Payment::methodOptions()))
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->authorize(static fn (?Model $record): bool => self::can('recordPayment', $record))
            ->action(static function (array $data, ?Model $record): void {
                $invoice = self::invoice($record);

                self::guard(static fn (): Payment => $invoice->markAsPaid(method: is_string($data['method'] ?? null) ? $data['method'] : 'other', recordedBy: self::user()));

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.paid'))->send();
            });
    }

    public static function preview(): Action
    {
        return Action::make('preview')
            ->label(__('alfakit-invoices::invoices.actions.preview'))
            ->icon('eye')
            ->color('gray')
            ->authorize(static fn (?Model $record): bool => self::can('view', $record))
            ->url(static fn (?Model $record): ?string => $record instanceof Invoice ? $record->getPreviewUrl() : null, shouldOpenInNewTab: true);
    }

    public static function download(): Action
    {
        return Action::make('download')
            ->label(__('alfakit-invoices::invoices.actions.download'))
            ->icon('download')
            ->color('gray')
            ->authorize(static fn (?Model $record): bool => self::can('view', $record))
            ->url(static fn (?Model $record): ?string => $record instanceof Invoice ? $record->getPreviewUrl(download: true) : null, shouldOpenInNewTab: true);
    }

    public static function copyLink(): Action
    {
        return Action::make('copyLink')
            ->label(__('alfakit-invoices::invoices.actions.customer_link'))
            ->icon('link')
            ->color('gray')
            ->modalHeading(__('alfakit-invoices::invoices.actions.customer_link'))
            ->modalDescription(__('alfakit-invoices::invoices.actions.customer_link_description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('alfakit-invoices::invoices.actions.close'))
            ->authorize(static fn (?Model $record): bool => self::can('send', $record))
            ->visible(static fn (?Model $record): bool => $record instanceof Invoice && $record->status !== InvoiceStatus::Draft && $record->status !== InvoiceStatus::Void)
            ->fillForm(static fn (?Model $record): array => ['url' => $record instanceof Invoice ? $record->getCustomerUrl() : null])
            ->schema([
                TextInput::make('url')
                    ->label(__('alfakit-invoices::invoices.fields.link'))
                    ->readOnly()
                    ->copyable(),
            ]);
    }

    public static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->label(__('alfakit-invoices::invoices.actions.duplicate'))
            ->icon('copy')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('alfakit-invoices::invoices.actions.duplicate_description'))
            ->authorize(static fn (?Model $record): bool => self::can('duplicate', $record))
            ->action(static function (?Model $record): Response {
                $copy = self::guard(static fn (): Invoice => self::invoice($record)->duplicate());

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.duplicated', ['title' => $copy->getTitle()]))->send();

                return self::redirectTo($copy, 'edit');
            });
    }

    public static function convert(): Action
    {
        return Action::make('convert')
            ->label(__('alfakit-invoices::invoices.actions.convert'))
            ->icon('file-output')
            ->requiresConfirmation()
            ->modalDescription(__('alfakit-invoices::invoices.actions.convert_description'))
            ->authorize(static fn (?Model $record): bool => self::can('convert', $record))
            ->visible(static fn (?Model $record): bool => $record instanceof Invoice && $record->type === InvoiceType::Quote)
            ->action(static function (?Model $record): Response {
                $invoice = self::guard(static fn (): Invoice => self::invoice($record)->convertToInvoice());

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.converted', ['title' => $invoice->getTitle()]))->send();

                return self::redirectTo($invoice, 'edit');
            });
    }

    public static function creditNote(): Action
    {
        return Action::make('creditNote')
            ->label(__('alfakit-invoices::invoices.actions.credit_note'))
            ->icon('file-minus')
            ->color('warning')
            ->modalHeading(__('alfakit-invoices::invoices.actions.credit_note'))
            ->modalDescription(__('alfakit-invoices::invoices.actions.credit_note_description'))
            ->modalSubmitActionLabel(__('alfakit-invoices::invoices.actions.credit_note_submit'))
            ->modalWidth('md')
            ->authorize(static fn (?Model $record): bool => self::can('creditNote', $record))
            ->visible(static fn (?Model $record): bool => $record instanceof Invoice && $record->type === InvoiceType::Invoice)
            ->schema([
                Toggle::make('apply')
                    ->label(__('alfakit-invoices::invoices.fields.apply_credit'))
                    ->helperText(__('alfakit-invoices::invoices.actions.apply_credit_help'))
                    ->default(true),
            ])
            ->action(static function (array $data, ?Model $record): Response {
                $creditNote = self::guard(static fn (): Invoice => self::invoice($record)->createCreditNote((bool) ($data['apply'] ?? true)));

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.credited', ['title' => $creditNote->getTitle()]))->send();

                return self::redirectTo($creditNote, $creditNote->isEditable() ? 'edit' : 'view');
            });
    }

    public static function void(): Action
    {
        return Action::make('void')
            ->label(__('alfakit-invoices::invoices.actions.void'))
            ->icon('ban')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('alfakit-invoices::invoices.actions.void_description'))
            ->modalSubmitActionLabel(__('alfakit-invoices::invoices.actions.void'))
            ->authorize(static fn (?Model $record): bool => self::can('void', $record))
            ->action(static function (?Model $record): void {
                self::guard(static fn (): Invoice => self::invoice($record)->void());

                Notification::make()->success()->title(__('alfakit-invoices::invoices.notifications.voided'))->send();
            });
    }

    // ---------------------------------------------------------------- Helpers

    public static function can(string $ability, ?Model $record): bool
    {
        if (! $record instanceof Invoice) {
            return false;
        }

        $resource = InvoicesPlugin::get($record)->getInvoiceResource();

        return $resource::can($ability, $record);
    }

    private static function invoice(?Model $record): Invoice
    {
        abort_unless($record instanceof Invoice, 404);

        return $record;
    }

    private static function user(): ?Model
    {
        $user = alfakit()->auth();

        return $user instanceof Model ? $user : null;
    }

    /**
     * Run a domain operation; its errors become a danger notification and stop the action.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function guard(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidStatusTransition|InvalidPayment $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        } catch (DomainException $exception) {
            report($exception);

            Notification::make()->danger()->title(__('alfakit-invoices::invoices.errors.failed'))->send();

            throw new Halt;
        }
    }

    private static function redirectTo(Invoice $invoice, string $page): Response
    {
        $resource = InvoicesPlugin::get($invoice)->getInvoiceResource();
        $page = $resource::hasPage($page) && $resource::can($page === 'edit' ? 'update' : 'view', $invoice) ? $page : 'view';

        return redirect()->to($resource::getUrl($page, ['record' => $invoice]));
    }
}
