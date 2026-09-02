<?php

namespace App\Domains\Reporting\Http\Controllers;

use App\Domains\Contacts\Models\Customer;
use App\Domains\Sales\Models\InvoiceItem;
use App\Platform\Http\Controller;
use Illuminate\Http\Request;
use Silber\Bouncer\BouncerFacade;

/**
 * Every invoice line item ever billed to one customer, picked from a plain
 * dropdown, so a description already used doesn't need to be re-typed or
 * re-billed.
 */
class CustomerLineItemsReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        $customers = Customer::query()
            ->whereIn('company_id', $user->companies->pluck('id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedCustomer = null;
        $items = collect();

        if ($request->filled('customer_id')) {
            $selectedCustomer = Customer::findOrFail($request->integer('customer_id'));

            BouncerFacade::scope()->to($selectedCustomer->company_id);

            $this->authorize('view', $selectedCustomer);

            $items = InvoiceItem::query()
                ->select('invoice_items.*')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.customer_id', $selectedCustomer->id)
                ->orderByDesc('invoices.invoice_date')
                ->orderByDesc('invoice_items.id')
                ->with('invoice:id,invoice_number,invoice_date')
                ->limit(200)
                ->get();
        }

        return view('reports.customer-line-items', [
            'customers' => $customers,
            'selectedCustomer' => $selectedCustomer,
            'items' => $items,
        ]);
    }
}
