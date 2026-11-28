@extends('layouts.admin')

@section('judul', 'QR Kantor')

@push('head')
<style>
    @media print {
        body * { visibility: hidden !important; }
        #areaCetak, #areaCetak * { visibility: visible !important; }
        #areaCetak { position: absolute; left: 0; top: 0; width: 100%; border: 0 !important; box-shadow: none !important; }
        .d-print-none { display: none !important; }
    }
</style>
@endpush

@section('content')
@if (! $aktif)
    <div class="alert alert-warning d-print-none">Presensi QR <strong>belum diaktifkan</strong>. Nyalakan di menu <a href="{{ route('panel.pengaturan') }}">Pengaturan</a> agar karyawan dapat memakainya.</div>
@endif

@if ($mode === 'tetap')
    <div class="alert alert-info d-print-none">
        <strong>Mode QR tetap.</strong> Cetak QR di bawah dan tempel di <strong>dalam kantor</strong> (jangan di tempat yang terlihat dari luar). QR ini <strong>tidak berubah</strong>
        sampai Anda menekan <em>Ganti QR</em>. Karena bisa difoto, GPS tetap memeriksa bahwa karyawan benar-benar di kantor. Ganti QR bila fotonya tersebar atau berkala (mis. tiap bulan).
    </div>
@else
    <div class="alert alert-info d-print-none">
        <strong>Mode QR berganti otomatis.</strong> Buka halaman ini di tablet/monitor kantor dan biarkan terbuka. QR berganti tiap {{ $ttl }} detik.
        Jam dan hitung mundur di bawah QR menandakan layar ini sedang hidup.
    </div>
@endif

<div class="card" id="areaCetak">
    <div class="card-body text-center py-4">
        <div class="row justify-content-center mb-3 d-print-none">
            <div class="col-md-4">
                <select id="pilihCabang" class="form-select">
                    @foreach ($cabangList as $c)<option value="{{ $c->kode_cabang }}" @selected($terpilih === $c->kode_cabang)>{{ $c->nama_cabang }}</option>@endforeach
                </select>
            </div>
        </div>

        <h2 class="mb-1" id="namaCabang"></h2>
        <div class="text-secondary mb-3">Presensi dengan QR (tanpa foto)</div>

        <div id="kotakQr" class="d-inline-block p-3 bg-white rounded" style="min-width:344px;min-height:344px"></div>

        <div class="mt-3 text-secondary" id="petunjuk">Buka aplikasi E-Presensi → <strong>Presensi dengan QR</strong> → pindai kode ini.</div>

        @if ($mode === 'berganti')
            <div class="mt-3" style="font-size:40px;font-weight:700;font-variant-numeric:tabular-nums" id="jam">--:--:--</div>
            <div class="text-secondary" id="hitungMundur">Memuat QR…</div>
        @else
            <div class="mt-3 text-secondary small" id="infoVersi">Memuat QR…</div>
        @endif

        <div class="mt-3 d-print-none">
            @if ($mode === 'tetap')
                <button type="button" class="btn btn-primary" onclick="window.print()"><i class="ti ti-printer me-1"></i>Cetak</button>
                <form method="POST" action="{{ route('panel.qr-kantor.ganti') }}" class="d-inline"
                      onsubmit="return confirm('Ganti QR? QR lama yang sudah dicetak langsung tidak berlaku dan karyawan tidak bisa presensi QR sampai QR baru dipasang.')">
                    @csrf
                    <input type="hidden" name="cabang" id="cabangGanti">
                    <button class="btn btn-outline-danger"><i class="ti ti-refresh me-1"></i>Ganti QR</button>
                </form>
            @else
                <button type="button" class="btn btn-outline-secondary" id="tombolLayarPenuh"><i class="ti ti-arrows-maximize me-1"></i>Layar penuh</button>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(() => {
    const URL_TOKEN = @json(route('panel.qr-kantor.token'));
    const pilih = document.getElementById('pilihCabang'), kotak = document.getElementById('kotakQr');
    const nama = document.getElementById('namaCabang'), cabangGanti = document.getElementById('cabangGanti');
    const jam = document.getElementById('jam'), hitung = document.getElementById('hitungMundur'), infoVersi = document.getElementById('infoVersi');
    let qr = null, sisa = 0, jadwal = null;

    if (jam) {
        setInterval(() => {
            jam.textContent = new Date().toLocaleTimeString('id-ID', { hour12: false });
            if (sisa > 0) { sisa--; hitung.textContent = 'QR berganti dalam ' + sisa + ' detik'; }
        }, 1000);
    }

    async function segarkan() {
        clearTimeout(jadwal);
        nama.textContent = pilih.options[pilih.selectedIndex]?.text ?? '';
        if (cabangGanti) cabangGanti.value = pilih.value;
        try {
            const res = await fetch(URL_TOKEN + '?cabang=' + encodeURIComponent(pilih.value), { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (!res.ok) { throw new Error('http ' + res.status); }
            const j = await res.json();

            if (!qr) { qr = new QRCode(kotak, { text: j.url, width: 320, height: 320, correctLevel: QRCode.CorrectLevel.M }); }
            else { qr.makeCode(j.url); }

            if (j.mode === 'berganti') {
                sisa = j.sisa;
                hitung.textContent = 'QR berganti dalam ' + sisa + ' detik';
                jadwal = setTimeout(segarkan, (j.sisa + 1) * 1000); // segarkan tepat setelah slot berganti
            } else if (infoVersi) {
                infoVersi.textContent = 'Versi QR ' + j.versi + (j.diganti ? ' · terakhir diganti ' + j.diganti : ' · belum pernah diganti');
            }
        } catch (e) {
            const pesan = 'Tidak dapat memuat QR. Sesi mungkin berakhir: muat ulang halaman dan login.';
            if (hitung) { hitung.textContent = pesan + ' Mencoba lagi…'; jadwal = setTimeout(segarkan, 10000); }
            else if (infoVersi) { infoVersi.textContent = pesan; }
        }
    }

    pilih.addEventListener('change', () => { history.replaceState(null, '', '?cabang=' + encodeURIComponent(pilih.value)); segarkan(); });
    document.getElementById('tombolLayarPenuh')?.addEventListener('click', () => document.getElementById('areaCetak').requestFullscreen?.());
    segarkan();
})();
</script>
@endpush
