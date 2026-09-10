<?php

namespace App\Domains\WorkLog\Models;

use App\Domains\Accounts\Models\Company;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Purchases\Models\ExpenseCategory;
use App\Domains\Sales\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single entry of billable time: a charge category, a description, and how
 * long it took, logged against a customer ahead of any invoice.
 */
class WorkLog extends Model
{
    use HasFactory;

    protected $table = 'work_logs';

    protected $fillable = [
        'customer_id',
        'charge_category_id',
        'description',
        'duration_hours',
        'invoice_item_id',
        'company_id',
    ];

    protected function casts(): array
    {
        return [
            'duration_hours' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * The heading this time is filed under, reusing the app's existing
     * expense categories rather than a separate lookup list.
     */
    public function chargeCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'charge_category_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * The invoice line this entry was billed onto, once it has been.
     */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    /**
     * Whether this time has been carried onto an invoice yet.
     */
    public function isBilled(): bool
    {
        return $this->invoice_item_id !== null;
    }

    /**
     * Restrict to the company the request is acting in; callers get no say in
     * which company that is.
     */
    public function scopeWhereCompany(Builder $query): void
    {
        $query->where('company_id', request()->header('company'));
    }
}
