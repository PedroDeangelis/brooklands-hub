<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Brooklands Hub' }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-canvas font-sans text-ink antialiased">
    <div class="flex min-h-full flex-col">
        <header class="flex h-19 shrink-0 items-center gap-10 border-b border-line bg-surface px-6">
            <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-3 text-ink">
                <span class="flex size-10.5 items-center justify-center rounded-[11px] bg-brand">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12a9 9 0 0 1-15.5 6.2"></path>
                        <path d="M3 12A9 9 0 0 1 18.5 5.8"></path>
                        <path d="M18 2v4h-4"></path>
                        <path d="M6 22v-4h4"></path>
                    </svg>
                </span>
                <span class="flex flex-col">
                    <span class="text-[17px] font-bold tracking-tight">Product Sync</span>
                    <span class="text-[13px] text-ink-muted">Business Central &rarr; Laravel</span>
                </span>
            </a>
        </header>

        <div class="flex min-h-0 grow">
            <nav aria-label="Primary"
                 class="flex w-61.5 shrink-0 flex-col gap-9 border-r border-line bg-surface-muted px-4 py-6 max-lg:hidden">
                <div class="flex flex-col gap-1.5">
                    <x-nav-link :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                        <x-slot:icon>
                            <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                            <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                            <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                            <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                        </x-slot:icon>
                        Dashboard
                    </x-nav-link>

                    <x-nav-link :href="route('products.index')" :current="request()->routeIs('products.*')">
                        <x-slot:icon>
                            <path d="M8 6h13"></path><path d="M8 12h13"></path><path d="M8 18h13"></path>
                            <path d="M3 6h.01"></path><path d="M3 12h.01"></path><path d="M3 18h.01"></path>
                        </x-slot:icon>
                        Products
                    </x-nav-link>

                    <x-nav-link :href="route('promotions.index')" :current="request()->routeIs('promotions.*')">
                        <x-slot:icon>
                            <path d="M3 11.5 12.5 3a1.5 1.5 0 0 1 1.1-.4l5.3.3a1.5 1.5 0 0 1 1.4 1.4l.3 5.3a1.5 1.5 0 0 1-.4 1.1L11.5 20a1.5 1.5 0 0 1-2.1 0l-6.4-6.4a1.5 1.5 0 0 1 0-2.1Z"></path>
                            <path d="M16.5 7.5h.01"></path>
                        </x-slot:icon>
                        Promotions
                    </x-nav-link>

                    <x-nav-link :href="route('customers.index')" :current="request()->routeIs('customers.*')">
                        <x-slot:icon>
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle>
                            <path d="M22 21v-2a4 4 0 0 0-3-3.9"></path><path d="M16 3.1a4 4 0 0 1 0 7.8"></path>
                        </x-slot:icon>
                        Customers
                    </x-nav-link>

                    <x-nav-link :href="route('contacts.index')" :current="request()->routeIs('contacts.*')">
                        <x-slot:icon>
                            <circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>
                            <path d="M16 3.5a4 4 0 0 1 0 9"></path>
                        </x-slot:icon>
                        Contacts
                    </x-nav-link>

                    <x-nav-link :href="route('sales-orders.index')" :current="request()->routeIs('sales-orders.*')">
                        <x-slot:icon>
                            <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
                            <path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path>
                        </x-slot:icon>
                        Sales orders
                    </x-nav-link>

                    @php
                        // Invoices and credit memos share one route and one list; the
                        // kind filter (or, on a detail page, the document itself) says
                        // which of the two sidebar entries is the current one.
                        $creditMemosActive = (request()->routeIs('sales-invoices.index')
                                && request()->query(\App\SalesInvoices\SalesInvoiceFilter::PARAM_KIND) === \App\SalesInvoices\SalesInvoiceKind::CreditMemo->value)
                            || (request()->routeIs('sales-invoices.show')
                                && (bool) request()->route('salesInvoice')?->isCreditMemo());
                    @endphp

                    <x-nav-link :href="route('sales-invoices.index')" :current="request()->routeIs('sales-invoices.*') && ! $creditMemosActive">
                        <x-slot:icon>
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path>
                            <path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h8"></path>
                        </x-slot:icon>
                        Sales invoices
                    </x-nav-link>

                    <x-nav-link :href="route('sales-invoices.index', [\App\SalesInvoices\SalesInvoiceFilter::PARAM_KIND => \App\SalesInvoices\SalesInvoiceKind::CreditMemo->value])"
                                :current="$creditMemosActive">
                        <x-slot:icon>
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path>
                            <path d="M14 2v6h6"></path><path d="M8 15h8"></path>
                        </x-slot:icon>
                        Sales credit memos
                    </x-nav-link>
                    <x-nav-link :href="route('document-attachments.index')" :current="request()->routeIs('document-attachments.*')">
                        <x-slot:icon>
                            <path d="M21.4 11.1 12.3 20a5.5 5.5 0 0 1-7.8-7.8l9.1-9.1a3.7 3.7 0 0 1 5.2 5.2l-9.1 9.1a1.8 1.8 0 0 1-2.6-2.6l8.4-8.4"></path>
                        </x-slot:icon>
                        Attachments
                    </x-nav-link>
                </div>

                <div class="flex flex-col gap-3.5 px-4">
                    <span class="text-xs font-semibold tracking-[0.08em] text-ink-muted uppercase">Pipeline</span>

                    @foreach ([
                        ['Business Central', 'Source · connected', true],
                        ['Laravel', 'Normalised store', true],
                        ['WordPress', 'Planned', false],
                    ] as [$name, $detail, $live])
                        <div class="flex items-start gap-3.5">
                            <span @class([
                                'mt-1.5 size-2.25 shrink-0 rounded-full',
                                'bg-synced' => $live,
                                'bg-[#b9c0bc]' => ! $live,
                            ])></span>
                            <span class="flex flex-col">
                                <span class="text-sm font-medium">{{ $name }}</span>
                                <span class="text-[13px] text-ink-muted">{{ $detail }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-auto flex items-center gap-3.5 rounded-xl border border-line bg-surface px-4 py-3.5">
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                        <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
                    </svg>
                    <span class="flex flex-col">
                        <span class="text-sm font-medium">Read-only view</span>
                        <span class="text-xs text-ink-muted">Visibility only, no actions</span>
                    </span>
                </div>
            </nav>

            <main class="flex min-w-0 grow flex-col gap-7 px-8 pt-7 pb-10">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
