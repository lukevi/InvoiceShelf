<?php

namespace App\Filament\Concerns;

use App\Filament\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps a resource's list/relation queries inside the signed-in user's own
 * company, since the Filament panel has no `company` header to scope by. A
 * super admin is left unscoped, matching how the API treats them.
 */
trait ScopedToCurrentCompany
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (Auth::user()?->isSuperAdmin()) {
            return $query;
        }

        return $query->where($query->getModel()->qualifyColumn('company_id'), CurrentCompany::id());
    }
}
