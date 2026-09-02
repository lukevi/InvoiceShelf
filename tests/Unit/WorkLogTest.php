<?php

use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Purchases\Models\ExpenseCategory;
use App\Domains\WorkLog\Models\WorkLog;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('work log belongs to a customer and a charge category', function () {
    $customer = Customer::factory()->create();
    $category = ExpenseCategory::factory()->create();

    $workLog = WorkLog::factory()->create([
        'customer_id' => $customer->id,
        'charge_category_id' => $category->id,
    ]);

    expect($workLog->customer->is($customer))->toBeTrue();
    expect($workLog->chargeCategory->is($category))->toBeTrue();
});

test('a customer has many work logs', function () {
    $customer = Customer::factory()->create();

    WorkLog::factory()->count(3)->create(['customer_id' => $customer->id]);

    expect($customer->workLogs()->count())->toBe(3);
});

test('work log scope restricts to the company on the request header', function () {
    $demoCompanyId = User::find(1)->companies()->first()->id;

    $matching = WorkLog::factory()->create(['company_id' => $demoCompanyId]);
    $other = WorkLog::factory()->create(['company_id' => $demoCompanyId + 999]);

    request()->headers->set('company', $demoCompanyId);

    $results = WorkLog::whereCompany()->get();

    expect($results->pluck('id'))->toContain($matching->id);
    expect($results->pluck('id'))->not->toContain($other->id);
});
