<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceItem;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Resources\InvoiceItems\Pages\CreateInvoiceItem;
use App\Filament\Resources\Invoices\Resources\InvoiceItems\Pages\EditInvoiceItem;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->owner = User::where('email', 'demo@invoiceshelf.com')->firstOrFail();
    $this->company = $this->owner->companies()->firstOrFail();
});

test('the table lists invoices', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id]);

    Livewire::actingAs($this->owner)
        ->test(ListInvoices::class)
        ->assertCanSeeTableRecords([$invoice]);
});

test('an invoice is created with an auto-generated number, a default status, a due date 10 days out, and live-calculated totals', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->owner)
        ->test(CreateInvoice::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'invoice_date' => '2026-01-01',
            'items' => [
                [
                    'name' => 'Consulting hours',
                    'price' => 5000,
                    'quantity' => 2,
                    'discount_type' => 'fixed',
                    'discount' => 0,
                    'tax' => 500,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::where('customer_id', $customer->id)->firstOrFail();

    expect($invoice->company_id)->toBe($this->company->id);
    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT);
    expect($invoice->paid_status)->toBe(Invoice::STATUS_UNPAID);
    expect($invoice->invoice_number)->not->toBeEmpty();
    expect($invoice->sequence_number)->not->toBeNull();
    expect($invoice->due_date)->toBe('2026-01-11');

    // price*qty - discount + tax = (5000*2) - 0 + 500
    expect($invoice->sub_total)->toBe(10000);
    expect($invoice->tax)->toBe(500);
    expect($invoice->total)->toBe(10500);
    expect($invoice->due_amount)->toBe(10500);

    $item = $invoice->items()->firstOrFail();
    expect($item->name)->toBe('Consulting hours');
    expect($item->company_id)->toBe($this->company->id);
    expect($item->discount_val)->toBe(0);
    expect($item->total)->toBe(10500);
});

test('changing the invoice date on the form bumps the due date to 10 days later', function () {
    Livewire::actingAs($this->owner)
        ->test(CreateInvoice::class)
        ->fillForm(['invoice_date' => '2026-03-01'])
        ->assertFormSet(['due_date' => '2026-03-11']);
});

test('editing a line item live-recalculates its total and the invoice totals on screen', function () {
    Livewire::actingAs($this->owner)
        ->test(CreateInvoice::class)
        ->fillForm([
            'items' => [
                [
                    'name' => 'Line',
                    'price' => 5000,
                    'quantity' => 2,
                    'discount_type' => 'fixed',
                    'discount' => 0,
                    'tax' => 500,
                ],
            ],
        ])
        // Line total (105.00) and invoice total/due (also 105.00, one item) both render.
        ->assertSee('105.00');
});

test('status and paid status are not directly editable form fields, and surface as badges in the edit title instead', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);
    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'status' => Invoice::STATUS_SENT,
        'paid_status' => Invoice::STATUS_PARTIALLY_PAID,
    ]);

    Livewire::actingAs($this->owner)
        ->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertFormFieldDoesNotExist('status')
        ->assertFormFieldDoesNotExist('paid_status');

    $page = Livewire::actingAs($this->owner)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])->instance();

    $title = (string) $page->getTitle();

    expect($title)->toContain($invoice->invoice_number)
        ->toContain('Sent')
        ->toContain('Partially Paid');
});

test('invoice line items can be added and edited through the nested resource', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id]);

    Livewire::actingAs($this->owner)
        ->test(CreateInvoiceItem::class, ['parentRecord' => $invoice])
        ->fillForm([
            'name' => 'Extra line',
            'price' => 2500,
            'quantity' => 1,
            'discount_type' => 'fixed',
            'discount' => 0,
            'discount_val' => 0,
            'tax' => 0,
            'total' => 2500,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = InvoiceItem::where('name', 'Extra line')->firstOrFail();

    expect($item->invoice_id)->toBe($invoice->id);
    expect($item->company_id)->toBe($this->company->id);

    Livewire::actingAs($this->owner)
        ->test(EditInvoiceItem::class, ['record' => $item->getRouteKey(), 'parentRecord' => $invoice])
        ->assertFormSet(['name' => 'Extra line'])
        ->fillForm(['name' => 'Updated line'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->refresh()->name)->toBe('Updated line');
});

test('a non-super-admin cannot resolve another company\'s invoice as the parent of the nested resource', function () {
    $other = User::factory()->create(['role' => 'owner']);
    $other->companies()->attach($this->company->id);

    $ownInvoice = Invoice::factory()->create(['company_id' => $this->company->id]);

    $foreignCompany = Company::factory()->create();
    $foreignInvoice = Invoice::factory()->create(['company_id' => $foreignCompany->id]);

    $this->actingAs($other);

    // The nested resource's create/edit pages resolve the {invoice} route
    // parameter through InvoiceResource::getEloquentQuery(), so this is the
    // same lookup a request for `/fila/invoices/{invoice}/invoice-items/create`
    // performs before it will let the page mount.
    expect(InvoiceResource::resolveRecordRouteBinding($foreignInvoice->id))->toBeNull();
    expect(InvoiceResource::resolveRecordRouteBinding($ownInvoice->id)?->is($ownInvoice))->toBeTrue();
});
