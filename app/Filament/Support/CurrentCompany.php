<?php

namespace App\Filament\Support;

use App\Domains\Accounts\Http\Middleware\CompanyMiddleware;
use Illuminate\Support\Facades\Auth;

/**
 * The Filament panel authenticates against the `web` guard directly and never
 * passes through {@see CompanyMiddleware},
 * so resources fall back to the same choice that middleware makes for a caller
 * with no explicit company: the first company the signed-in user belongs to.
 */
class CurrentCompany
{
    public static function id(): ?int
    {
        return Auth::user()?->companies()->first()?->id;
    }
}
