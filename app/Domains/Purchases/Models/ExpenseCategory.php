<?php

namespace App\Domains\Purchases\Models;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\WorkLog\Models\WorkLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A heading company spend is filed under.
 *
 * Little more than a name and a note: nothing stops two headings in the same
 * company from sharing a name, and the running total the listing shows is
 * summed on demand rather than kept on the row. A heading is only removable
 * while no expense points at it, a rule the controller enforces.
 */
class ExpenseCategory extends Model
{
    protected $table = 'expense_categories';

    use HasFactory;

    /**
     * Columns a caller may set.
     *
     * @var array
     */
    protected $fillable = ['name', 'company_id', 'description'];

    /**
     * Computed attributes, listed in the order they are serialized.
     *
     * @var array
     */
    protected $appends = ['amount', 'formattedCreatedAt'];

    /**
     * Everything filed under this heading.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_category_id');
    }

    /**
     * Work logs charged under this heading.
     */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class, 'charge_category_id');
    }

    /**
     * Company the heading belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Creation date in the company's configured date format.
     *
     * Unlike the expense's own dates this one is not translated -- month and
     * day names come out in English whatever language the application is
     * running in.
     */
    public function getFormattedCreatedAtAttribute(mixed $value): string
    {
        $format = CompanySetting::getSetting('carbon_date_format', $this->company_id);

        return Carbon::parse($this->created_at)->format($format);
    }

    /**
     * Everything spent under this heading, summed on the spot.
     *
     * The figure is the raw `amount` column, so an expense entered in a
     * foreign currency contributes its face value rather than its worth in the
     * company's books, and headings mixing currencies add up to a number that
     * is in no currency at all.
     */
    public function getAmountAttribute(): float
    {
        return $this->expenses()->sum('amount');
    }

    /**
     * Narrow to the company the current request is acting on.
     *
     * The company is read from the request header and never from an argument,
     * so a caller passing one is quietly ignored.
     */
    public function scopeWhereCompany(Builder $query): void
    {
        $company = request()->header('company');

        $query->where('company_id', $company);
    }

    /**
     * Widen a listing to also take in one specific heading.
     */
    public function scopeWhereCategory(Builder $query, int $category_id): void
    {
        $query->orWhere('id', $category_id);
    }

    /**
     * Partial match on the heading name.
     */
    public function scopeWhereSearch(Builder $query, string $search): void
    {
        $needle = '%'.$search.'%';

        $query->where('name', 'LIKE', $needle);
    }

    /**
     * Run every listed filter that carries a value.
     *
     * Order is load-bearing: the heading filter contributes an OR, which makes
     * whatever follows it part of that alternative. A filter sent as an empty
     * string, a zero or a null counts as not sent at all. Note that the
     * company filter only decides *whether* to scope by company -- which
     * company is taken from the request header, not from the value sent.
     */
    public function scopeApplyFilters(Builder $query, array $filters): void
    {
        $clauses = [
            'category_id' => fn ($wanted) => $query->whereCategory($wanted),
            'company_id' => fn ($wanted) => $query->whereCompany($wanted),
            'search' => fn ($wanted) => $query->whereSearch($wanted),
        ];

        foreach ($clauses as $filter => $clause) {
            $wanted = $filters[$filter] ?? null;

            if ($wanted) {
                $clause($wanted);
            }
        }
    }

    /**
     * Return the whole result set for the sentinel limit "all", otherwise a
     * page of the requested size.
     *
     * @return Collection|LengthAwarePaginator
     */
    public function scopePaginateData(Builder $query, string $limit)
    {
        return $limit == 'all' ? $query->get() : $query->paginate($limit);
    }
}
