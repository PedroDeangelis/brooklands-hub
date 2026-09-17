<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Sign in &middot; {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>

<body class="min-h-screen bg-sky-50 font-[Instrument_Sans,system-ui,sans-serif] text-slate-900 antialiased">
    <div class="relative min-h-screen flex flex-col items-center justify-center px-4 py-12 overflow-hidden">

        <div class="pointer-events-none absolute -top-40 -right-40 h-112 w-md rounded-full bg-blue-200/40 blur-3xl">
        </div>
        <div class="pointer-events-none absolute -bottom-40 -left-40 h-112 w-md rounded-full bg-blue-200/40 blur-3xl">
        </div>

        <div class="relative w-full max-w-xl">
            <div
                class="bg-white rounded-3xl shadow-xl shadow-slate-900/5 ring-1 ring-slate-900/5 px-10 py-14 sm:px-14 sm:py-16">

                <div class="flex flex-col items-center text-center">
                    <img src="{{ Vite::asset('resources/images/logo-600.png') }}" alt="{{ config('app.name') }} logo"
                        class="h-14 w-auto">
                </div>

                @if ($errors->any())
                    <div class="mt-12 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if (session('status'))
                    <div
                        class="mt-8 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                <a href="/login/google"
                    class="mt-6 flex w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-5 py-4 text-base font-medium text-slate-800 transition hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
                    <img src="{{ Vite::asset('resources/images/google-logo.png') }}" alt="Google" class="h-6 w-6">
                    <span>Continue with Google</span>
                </a>

                <div class="mt-8 flex items-center justify-center gap-2 text-sm text-slate-400">
                    <svg class="h-4 w-4 text-sky-400" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span>Secure, simple and trusted by developers</span>
                </div>
            </div>

            <p class="mt-8 text-center text-sm text-slate-500">
                By continuing, you agree to access {{ config('app.name') }} with your authorised Google account.
            </p>
            <p class="mt-2 text-center text-sm text-slate-400">
                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
            </p>
        </div>
    </div>
</body>

</html>
