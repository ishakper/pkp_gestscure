<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') — PKP Secure</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/two-factor.css') }}">
</head>
<body>
<main class="tf-card @yield('card_class')">
    <div class="tf-status"><span>[ PKP_SECURITY // @yield('status_label', 'TWO_FACTOR') ]</span><b>SYS_ONLINE</b></div>
    <div class="tf-brand">
        <img src="{{ asset('images/pkp-logo.png') }}" alt="PKP Secure" onerror="this.src='{{ asset('favicon.svg') }}'">
        <h1 class="tf-title">@yield('heading')</h1>
        @hasSection('subheading')<p class="tf-sub">@yield('subheading')</p>@endif
    </div>

    @if (session('status'))
        <div class="tf-ok" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="tf-error" role="alert">{{ $errors->first() }}</div>
    @endif

    @yield('content')
</main>
@stack('scripts')
</body>
</html>
