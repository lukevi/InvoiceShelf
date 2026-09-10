<?php

use App\Domains\Accounts\Models\User;
use App\Domains\Receivables\Models\PaymentAllocation;
use App\Domains\Sales\Application\RecurringInvoiceService;
use App\Domains\Sales\Http\Controllers\Company\RecurringInvoiceController;
use App\Domains\Sales\Http\Requests\RecurringInvoiceRequest;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceItem;
use App\Domains\Sales\Models\RecurringInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

test('get recurring invoices', function () {
    RecurringInvoice::factory()->create();

    getJson('api/v1/recurring-invoices?page=1')
        ->assertOk();
});

test('store user using a form request', function () {
    $this->assertActionUsesFormRequest(
        RecurringInvoiceController::class,
        'store',
        RecurringInvoiceRequest::class
    );
});

test('store recurring invoice', function () {
    $recurringInvoice = RecurringInvoice::factory()->raw();
    $recurringInvoice['items'] = [
        InvoiceItem::factory()->raw(),
    ];

    postJson('api/v1/recurring-invoices', $recurringInvoice)
        ->assertStatus(201);

    $recurringInvoice = collect($recurringInvoice)
        ->only([
            'frequency',
        ])
        ->toArray();

    $this->assertDatabaseHas('recurring_invoices', $recurringInvoice);
});

test('rejects a nonzero per-item placeholder tax row', function () {
    $recurringInvoice = RecurringInvoice::factory()->raw([
        'items' => [
            InvoiceItem::factory()->raw([
                'taxes' => [[
                    'tax_type_id' => 0,
                    'amount' => 1,
                ]],
            ]),
        ],
    ]);

    postJson('api/v1/recurring-invoices', $recurringInvoice)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.taxes.0.amount');
});

test('allows a zero-valued per-item placeholder tax row', function () {
    $recurringInvoice = RecurringInvoice::factory()->raw([
        'items' => [
            InvoiceItem::factory()->raw([
                'taxes' => [[
                    'tax_type_id' => 0,
                    'amount' => 0,
                ]],
            ]),
        ],
    ]);

    postJson('api/v1/recurring-invoices', $recurringInvoice)
        ->assertCreated();
});

test('generated invoices retain the recurring template tax-included semantics', function () {
    $recurringInvoice = RecurringInvoice::factory()->create([
        'starts_at' => Carbon::yesterday(),
        'next_invoice_at' => Carbon::yesterday(),
        'status' => RecurringInvoice::ACTIVE,
        'limit_by' => RecurringInvoice::NONE,
        'tax_included' => true,
        'sub_total' => 10000,
        'tax' => 2019,
        'total' => 10119,
        'due_amount' => 10119,
    ]);

    app(RecurringInvoiceService::class)->generateInvoice($recurringInvoice);

    $this->assertDatabaseHas('invoices', [
        'recurring_invoice_id' => $recurringInvoice->id,
        'tax_included' => 1,
        'tax' => 2019,
        'total' => 10119,
    ]);
});

test('get recurring invoice', function () {
    $recurringInvoice = RecurringInvoice::factory()->create();

    getJson("api/v1/recurring-invoices/{$recurringInvoice->id}")
        ->assertOk();
});

test('update user using a form request', function () {
    $this->assertActionUsesFormRequest(
        RecurringInvoiceController::class,
        'update',
        RecurringInvoiceRequest::class
    );
});

test('update recurring invoice', function () {
    $recurringInvoice = RecurringInvoice::factory()->create();
    $recurringInvoice['items'] = [
        InvoiceItem::factory()->raw(),
    ];

    $new_recurringInvoice = RecurringInvoice::factory()->raw();
    $new_recurringInvoice['items'] = [
        InvoiceItem::factory()->raw(),
    ];

    putJson("api/v1/recurring-invoices/{$recurringInvoice->id}", $new_recurringInvoice)
        ->assertOk();

    $new_recurringInvoice = collect($new_recurringInvoice)
        ->only([
            'frequency',
        ])
        ->toArray();

    $this->assertDatabaseHas('recurring_invoices', $new_recurringInvoice);
});

test('delete multiple recurring invoice', function () {
    $recurringInvoices = RecurringInvoice::factory()->count(3)->create();

    $data = [
        'ids' => $recurringInvoices->pluck('id'),
    ];

    postJson('api/v1/recurring-invoices/delete', $data)
        ->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    foreach ($recurringInvoices as $recurringInvoice) {
        $this->assertModelMissing($recurringInvoice);
    }
});

test('calculate frequency for recurring invoice', function () {
    $data = [
        'frequency' => '* * 2 * *',
        'starts_at' => Carbon::now()->format('Y-m-d'),
    ];

    $queryString = http_build_query($data, '', '&');

    getJson('api/v1/recurring-invoice-frequency?'.$queryString)
        ->assertOk();
});

test('generateInvoice ignores a schedule whose next invoice date has not arrived yet', function () {
    $recurringInvoice = RecurringInvoice::factory()->create([
        'starts_at' => Carbon::yesterday(),
        'next_invoice_at' => Carbon::tomorrow(),
        'status' => RecurringInvoice::ACTIVE,
        'limit_by' => RecurringInvoice::NONE,
    ]);

    app(RecurringInvoiceService::class)->generateInvoice($recurringInvoice);

    expect(Invoice::where('recurring_invoice_id', $recurringInvoice->id)->exists())->toBeFalse();
});

test('generateInvoice ignores a schedule with no next invoice date recorded yet', function () {
    $recurringInvoice = RecurringInvoice::factory()->create([
        'starts_at' => Carbon::yesterday(),
        'next_invoice_at' => null,
        'status' => RecurringInvoice::ACTIVE,
        'limit_by' => RecurringInvoice::NONE,
    ]);

    app(RecurringInvoiceService::class)->generateInvoice($recurringInvoice);

    expect(Invoice::where('recurring_invoice_id', $recurringInvoice->id)->exists())->toBeFalse();
});

test('a due schedule advances its next invoice date instead of staying fixed', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    try {
        $recurringInvoice = RecurringInvoice::factory()->create([
            'starts_at' => '2026-06-01 00:00:00',
            'next_invoice_at' => '2026-06-15 00:00:00',
            'status' => RecurringInvoice::ACTIVE,
            'frequency' => '0 0 * * *',
            'limit_by' => RecurringInvoice::NONE,
        ]);

        app(RecurringInvoiceService::class)->generateInvoice($recurringInvoice);

        expect(Invoice::where('recurring_invoice_id', $recurringInvoice->id)->count())->toBe(1)
            ->and($recurringInvoice->fresh()->next_invoice_at)->toBe('2026-06-16 00:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('the recurring invoices scheduler command does not duplicate an occurrence across repeated runs', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    try {
        $recurringInvoice = RecurringInvoice::factory()->create([
            'starts_at' => '2026-06-01 00:00:00',
            'next_invoice_at' => '2026-06-15 00:00:00',
            'status' => RecurringInvoice::ACTIVE,
            'frequency' => '0 0 * * *',
            'limit_by' => RecurringInvoice::NONE,
        ]);

        Artisan::call('recurring-invoices:generate');
        Artisan::call('recurring-invoices:generate');
        Artisan::call('recurring-invoices:generate');

        expect(Invoice::where('recurring_invoice_id', $recurringInvoice->id)->count())->toBe(1)
            ->and($recurringInvoice->fresh()->next_invoice_at)->toBe('2026-06-16 00:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('the recurring invoices scheduler command skips schedules that are not active', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    try {
        $onHold = RecurringInvoice::factory()->create([
            'starts_at' => '2026-06-01 00:00:00',
            'next_invoice_at' => '2026-06-15 00:00:00',
            'status' => RecurringInvoice::ON_HOLD,
            'frequency' => '0 0 * * *',
            'limit_by' => RecurringInvoice::NONE,
        ]);

        Artisan::call('recurring-invoices:generate');

        expect(Invoice::where('recurring_invoice_id', $onHold->id)->exists())->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('a schedule left overdue for days catches up but is capped instead of generating without bound', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    try {
        // Frozen 30 days behind a daily schedule: without a per-schedule cap,
        // a single run would mint all 30 missed invoices at once.
        $recurringInvoice = RecurringInvoice::factory()->create([
            'starts_at' => '2026-05-01 00:00:00',
            'next_invoice_at' => '2026-05-16 00:00:00',
            'status' => RecurringInvoice::ACTIVE,
            'frequency' => '0 0 * * *',
            'limit_by' => RecurringInvoice::NONE,
        ]);

        Artisan::call('recurring-invoices:generate');

        expect(Invoice::where('recurring_invoice_id', $recurringInvoice->id)->count())->toBe(10)
            ->and($recurringInvoice->fresh()->next_invoice_at)->toBe('2026-05-26 00:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('the runaway cleanup command reports what it would delete without --force', function () {
    $recurringInvoice = RecurringInvoice::factory()->create();
    Invoice::factory()->count(3)->create(['recurring_invoice_id' => $recurringInvoice->id]);

    Artisan::call('recurring-invoices:cleanup-runaway', ['recurring_invoice_id' => $recurringInvoice->id]);

    expect(Artisan::output())->toContain('3 draft, unpaid, unallocated invoice(s)')
        ->toContain('Dry run only');
    expect(Invoice::where('recurring_invoice_id', $recurringInvoice->id)->count())->toBe(3);
});

test('the runaway cleanup command deletes only untouched draft duplicates when forced', function () {
    $recurringInvoice = RecurringInvoice::factory()->create();

    $duplicates = Invoice::factory()->count(3)->create(['recurring_invoice_id' => $recurringInvoice->id]);
    $sent = Invoice::factory()->sent()->create(['recurring_invoice_id' => $recurringInvoice->id]);
    $allocated = Invoice::factory()->create(['recurring_invoice_id' => $recurringInvoice->id]);
    PaymentAllocation::factory()->create(['invoice_id' => $allocated->id]);

    Artisan::call('recurring-invoices:cleanup-runaway', [
        'recurring_invoice_id' => $recurringInvoice->id,
        '--force' => true,
    ]);

    foreach ($duplicates as $duplicate) {
        $this->assertModelMissing($duplicate);
    }
    $this->assertModelExists($sent);
    $this->assertModelExists($allocated);
});

test('the runaway cleanup command reports nothing to do for an unaffected schedule', function () {
    $recurringInvoice = RecurringInvoice::factory()->create();

    Artisan::call('recurring-invoices:cleanup-runaway', ['recurring_invoice_id' => $recurringInvoice->id]);

    expect(Artisan::output())->toContain('No removable duplicate invoices found');
});
