<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Customer Line Items') }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 2rem; color: #1f2937; }
        h1 { font-size: 1.25rem; margin-bottom: 1rem; }
        h2 { font-size: 1rem; color: #374151; margin: 1.5rem 0 0.75rem; }
        select { padding: 0.4rem 0.6rem; font-size: 1rem; min-width: 16rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 0.75rem; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 0.5rem 0.75rem; text-align: left; font-size: 0.875rem; }
        th { background: #f9fafb; font-weight: 600; }
        td.numeric, th.numeric { text-align: right; }
        .empty { color: #6b7280; margin-top: 1rem; }
    </style>
</head>
<body>
    <h1>{{ __('Customer Line Items') }}</h1>

    <form method="GET">
        <select name="customer_id" onchange="this.form.submit()">
            <option value="">{{ __('Select a customer…') }}</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected($selectedCustomer?->id === $customer->id)>
                    {{ $customer->name }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit">{{ __('Go') }}</button></noscript>
    </form>

    @if ($selectedCustomer)
        <h2>{{ $selectedCustomer->name }}</h2>

        @if ($items->isEmpty())
            <p class="empty">{{ __('No invoice line items yet.') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Invoice #') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Item') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th class="numeric">{{ __('Qty') }}</th>
                        <th class="numeric">{{ __('Price') }}</th>
                        <th class="numeric">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->invoice->invoice_number }}</td>
                            <td>{{ optional($item->invoice->invoice_date)->format('Y-m-d') }}</td>
                            <td>{{ $item->name }}</td>
                            <td>{{ $item->description }}</td>
                            <td class="numeric">{{ $item->quantity }}</td>
                            <td class="numeric">{{ number_format($item->price / 100, 2) }}</td>
                            <td class="numeric">{{ number_format($item->total / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</body>
</html>
