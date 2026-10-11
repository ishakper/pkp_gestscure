@extends('two-factor.layout')

@section('title', 'Recovery Code')
@section('status_label', 'RECOVERY_CODES')
@section('card_class', 'wide')
@section('heading', 'Simpan Recovery Code')
@section('subheading')
    Pakai salah satu kode ini bila HP hilang. <b>Setiap kode hanya berlaku sekali</b> dan
    tidak akan ditampilkan lagi.
@endsection

@section('content')
<ul class="tf-codes" id="codes">
    @foreach ($codes as $code)
        <li>{{ $code }}</li>
    @endforeach
</ul>

<div class="tf-row tf-noprint" style="margin-top:0;margin-bottom:1.2rem">
    <button type="button" class="tf-link" id="copyCodes">Salin</button>
    <button type="button" class="tf-link" onclick="window.print()">Cetak</button>
</div>

<label class="tf-check tf-noprint">
    <input type="checkbox" id="savedConfirm">
    <span>Saya sudah menyimpan recovery code di tempat yang aman.</span>
</label>
<a href="/" class="tf-btn tf-noprint" id="continueBtn" style="display:block;text-align:center;text-decoration:none;opacity:.45;pointer-events:none" aria-disabled="true">Lanjut ke Dashboard</a>
@endsection

@push('scripts')
<script>
    (function () {
        var confirmBox = document.getElementById('savedConfirm');
        var btn = document.getElementById('continueBtn');
        confirmBox.addEventListener('change', function () {
            btn.style.opacity = confirmBox.checked ? '1' : '.45';
            btn.style.pointerEvents = confirmBox.checked ? 'auto' : 'none';
            btn.setAttribute('aria-disabled', confirmBox.checked ? 'false' : 'true');
        });
        document.getElementById('copyCodes').addEventListener('click', function (e) {
            var text = Array.prototype.map.call(document.querySelectorAll('#codes li'), function (li) { return li.textContent.trim(); }).join('\n');
            if (navigator.clipboard) { navigator.clipboard.writeText(text).then(function () { e.target.textContent = 'Tersalin'; }); }
        });
    })();
</script>
@endpush
