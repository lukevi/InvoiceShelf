<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceItem;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\get;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--force' => true, '--class' => 'DatabaseSeeder']);
    Artisan::call('db:seed', ['--force' => true, '--class' => 'DemoSeeder']);

    $this->user = User::findOrFail(1);
    $this->company = $this->user->companies()->firstOrFail();
    Sanctum::actingAs($this->user, ['*']);
});

test('the report lists customers the user can pick from', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);

    get('/reports/customers/line-items')
        ->assertOk()
        ->assertSee($customer->name);
});

test('picking a customer shows their invoice line items across invoices', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id]);
    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'company_id' => $this->company->id,
        'name' => 'Consulting',
        'description' => 'Fixed the login bug',
    ]);

    get("/reports/customers/line-items?customer_id={$customer->id}")
        ->assertOk()
        ->assertSee('Fixed the login bug')
        ->assertSee($invoice->invoice_number);
});

test('a customer belonging to another company is forbidden', function () {
    $otherCompany = Company::factory()->create();
    $customer = Customer::factory()->create(['company_id' => $otherCompany->id]);

    get("/reports/customers/line-items?customer_id={$customer->id}")
        ->assertForbidden();
});
