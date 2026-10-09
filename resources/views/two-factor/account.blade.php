@extends('two-factor.layout')

@section('title', 'Keamanan Akun')
@section('status_label', 'ACCOUNT_SECURITY')
@section('card_class', 'wide')
@section('heading', 'Keamanan Akun')
@section('subheading')
    {{ $admin->name }} · <b>{{ $admin->email }}</b>
@endsection

@section('content')
<dl class="tf-kv">
    <dt>Verifikasi 2 langkah</dt>
    <dd>
        @if ($enrolled)
            <span class="tf-badge on">AKTIF</span>
        @else
            <span class="tf-badge off">BELUM AKTIF</span>
        @endif
        @if ($required)
            <span class="tf-badge off">WAJIB UNTUK PERAN INI</span>
        @endif
    </dd>
    @if ($enrolled)
        <dt>Diaktifkan</dt>
        <dd>{{ $confirmedAt?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</dd>
        <dt>Sisa recovery code</dt>
        <dd>{{ $remaining }}</dd>
    @endif
    @unless ($enabled)
        <dt>Status sistem</dt>
        <dd>2FA belum diwajibkan di server (TWO_FACTOR_ENABLED=false).</dd>
    @endunless
</dl>

@if (! $enrolled)
    <a href="/two-factor/setup" class="tf-btn" style="display:block;text-align:center;text-decoration:none">Aktifkan 2FA</a>
@else
    <div class="tf-section">
        <h2>Buat ulang recovery code</h2>
        <p class="tf-sub" style="margin-bottom:.9rem">Kode lama langsung tidak berlaku.</p>
        <form method="POST" action="/account/security/recovery-codes" autocomplete="off">
            @csrf
            <div class="tf-field">
                <label class="tf-label" for="regen_code">// KODE_AUTHENTICATOR</label>
                <input class="tf-input code" id="regen_code" name="code" inputmode="numeric" maxlength="7" placeholder="000000" required>
            </div>
            <button type="submit" class="tf-btn">Buat Ulang</button>
        </form>
    </div>

    @unless ($required && $enabled)
        <div class="tf-section">
            <h2>Nonaktifkan 2FA</h2>
            <form method="POST" action="/account/security/disable" autocomplete="off">
                @csrf
                <div class="tf-field">
                    <label class="tf-label" for="password">// PASSWORD</label>
                    <input class="tf-input" id="password" type="password" name="password" required autocomplete="current-password">
                </div>
                <div class="tf-field">
                    <label class="tf-label" for="disable_code">// KODE_AUTHENTICATOR</label>
                    <input class="tf-input code" id="disable_code" name="code" inputmode="numeric" maxlength="7" placeholder="000000" required>
                </div>
                <button type="submit" class="tf-btn danger">Nonaktifkan 2FA</button>
            </form>
        </div>
    @endunless
@endif

<div class="tf-row">
    <a class="tf-link" href="/">← Kembali ke Dashboard</a>
</div>
@endsection
