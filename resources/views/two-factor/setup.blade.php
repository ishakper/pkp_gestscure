@extends('two-factor.layout')

@section('title', 'Aktifkan 2FA')
@section('status_label', 'TWO_FACTOR_SETUP')
@section('heading', 'Aktifkan Verifikasi 2 Langkah')
@section('subheading')
    @if ($required)
        Akun <b>{{ $admin->email }}</b> wajib memakai 2FA. Selesaikan langkah ini untuk masuk.
    @else
        Lindungi akun <b>{{ $admin->email }}</b> dengan kode dari HP.
    @endif
@endsection

@section('content')
<ol class="tf-steps">
    <li>Buka Google Authenticator, Microsoft Authenticator, atau aplikasi sejenis.</li>
    <li>Pindai QR di bawah, atau ketik kunci manual.</li>
    <li>Masukkan kode 6 digit yang muncul.</li>
</ol>

<div class="tf-qr" aria-label="QR code untuk aplikasi authenticator">{!! $qrSvg !!}</div>
<div class="tf-secret" title="Kunci manual">{{ $secret }}</div>

<form method="POST" action="/two-factor/setup" autocomplete="off">
    @csrf
    <div class="tf-field">
        <label class="tf-label" for="code">// KODE_KONFIRMASI</label>
        <input class="tf-input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
               autocomplete="one-time-code" placeholder="000000" required autofocus>
    </div>
    <button type="submit" class="tf-btn">Aktifkan 2FA</button>
</form>

<div class="tf-row">
    @if ($duringLogin)
        <form method="POST" action="/two-factor/cancel">
            @csrf
            <button type="submit" class="tf-link">Batal &amp; kembali ke login</button>
        </form>
    @else
        <a class="tf-link" href="/account/security">Kembali</a>
    @endif
</div>
@endsection
