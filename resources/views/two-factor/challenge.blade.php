@extends('two-factor.layout')

@section('title', 'Verifikasi 2FA')
@section('status_label', 'TWO_FACTOR_CHALLENGE')
@section('heading', 'Verifikasi 2 Langkah')
@section('subheading')
    Masukkan kode 6 digit dari aplikasi authenticator untuk <b>{{ $admin->email }}</b>.
@endsection

@section('content')
<form method="POST" action="/two-factor/challenge" autocomplete="off">
    @csrf
    <div class="tf-field" id="codeField">
        <label class="tf-label" for="code">// KODE_AUTHENTICATOR</label>
        <input class="tf-input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
               autocomplete="one-time-code" placeholder="000000" autofocus>
    </div>
    <div class="tf-field" id="recoveryField" hidden>
        <label class="tf-label" for="recovery_code">// RECOVERY_CODE</label>
        <input class="tf-input mono" id="recovery_code" name="recovery_code" maxlength="16" placeholder="XXXXX-XXXXX" disabled>
    </div>

    @if ($rememberDays > 0)
        <label class="tf-check">
            <input type="checkbox" name="remember_device" value="1">
            <span>Ingat perangkat ini selama {{ $rememberDays }} hari</span>
        </label>
    @endif

    <button type="submit" class="tf-btn">Verifikasi &amp; Masuk</button>
</form>

<div class="tf-row">
    <button type="button" class="tf-toggle" id="toggleRecovery">Pakai recovery code</button>
    <form method="POST" action="/two-factor/cancel">
        @csrf
        <button type="submit" class="tf-link">Batal</button>
    </form>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var toggle = document.getElementById('toggleRecovery');
        var codeField = document.getElementById('codeField');
        var recoveryField = document.getElementById('recoveryField');
        var code = document.getElementById('code');
        var recovery = document.getElementById('recovery_code');
        toggle.addEventListener('click', function () {
            var useRecovery = recoveryField.hidden;
            recoveryField.hidden = !useRecovery;
            codeField.hidden = useRecovery;
            recovery.disabled = !useRecovery;
            code.disabled = useRecovery;
            toggle.textContent = useRecovery ? 'Pakai kode authenticator' : 'Pakai recovery code';
            (useRecovery ? recovery : code).focus();
        });
    })();
</script>
@endpush
