<?php

use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Purchases\Models\ExpenseCategory;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceItem;
use App\Domains\WorkLog\Models\WorkLog;
use App\Filament\Resources\WorkLogs\Pages\CreateWorkLog;
use App\Filament\Resources\WorkLogs\Pages\ListWorkLogs;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->owner = User::where('email', 'demo@invoiceshelf.com')->firstOrFail();
    $this->company = $this->owner->companies()->firstOrFail();
});

test('the table shows the customer and whether the entry has been billed', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Northwind Traders']);
    $category = ExpenseCategory::factory()->create(['company_id' => $this->company->id]);
    $invoice = Invoice::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id]);
    $invoiceItem = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id]);

    $billed = WorkLog::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'charge_category_id' => $category->id,
        'invoice_item_id' => $invoiceItem->id,
    ]);
    $unbilled = WorkLog::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'charge_category_id' => $category->id,
        'invoice_item_id' => null,
    ]);

    Livewire::actingAs($this->owner)
        ->test(ListWorkLogs::class)
        ->assertCanSeeTableRecords([$billed, $unbilled])
        ->assertTableColumnStateSet('customer.name', 'Northwind Traders', record: $billed)
        ->assertTableColumnStateSet('is_billed', true, record: $billed)
        ->assertTableColumnStateSet('is_billed', false, record: $unbilled);
});

test('a work log can be added through the create form', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);
    $category = ExpenseCategory::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->owner)
        ->test(CreateWorkLog::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'charge_category_id' => $category->id,
            'description' => 'Migrated the reporting queries.',
            'duration_hours' => 2.5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $workLog = WorkLog::where('description', 'Migrated the reporting queries.')->firstOrFail();

    expect($workLog->customer_id)->toBe($customer->id);
    expect((float) $workLog->duration_hours)->toBe(2.5);
    expect($workLog->company_id)->toBe($this->company->id);
    expect($workLog->isBilled())->toBeFalse();
});

test('a non-super-admin only sees work logs belonging to their own company', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $owner->companies()->attach($this->company->id);

    $category = ExpenseCategory::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);

    $mine = WorkLog::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'charge_category_id' => $category->id,
    ]);
    $someoneElses = WorkLog::factory()->create([
        'company_id' => $this->company->id + 999,
        'customer_id' => $customer->id,
        'charge_category_id' => $category->id,
    ]);

    Livewire::actingAs($owner)
        ->test(ListWorkLogs::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});
