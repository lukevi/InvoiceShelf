<?php

use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->owner = User::where('email', 'demo@invoiceshelf.com')->firstOrFail();
    $this->company = $this->owner->companies()->firstOrFail();
});

test('the table lists customers', function () {
    $customer = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Umbrella Corp']);

    Livewire::actingAs($this->owner)
        ->test(ListCustomers::class)
        ->assertCanSeeTableRecords([$customer])
        ->assertTableColumnStateSet('name', 'Umbrella Corp', record: $customer);
});

test('a customer can be added through the create form', function () {
    Livewire::actingAs($this->owner)
        ->test(CreateCustomer::class)
        ->fillForm([
            'name' => 'Wayne Enterprises',
            'email' => 'billing@wayne.example',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::where('name', 'Wayne Enterprises')->firstOrFail();

    expect($customer->email)->toBe('billing@wayne.example');
    expect($customer->company_id)->toBe($this->company->id);
});
