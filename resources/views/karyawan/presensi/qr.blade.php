@extends('layouts.karyawan')

@section('judul', 'Presensi QR')
@section('header', $sesi['sudahMasuk'] ? 'Presensi Pulang (QR)' : 'Presensi Masuk (QR)')

@section('content')
@php $selesai = $sesi['selesai']; @endphp
<div class="px-3 pt-2">
    @if ($selesai)
        <div class="alert alert-success">Presensi hari ini sudah lengkap: masuk {{ \App\Support\Waktu::hm($sesi['jamIn']) }}, pulang {{ \App\Support\Waktu::hm($sesi['jamOut']) }}.</div>
    @endif

    <div class="kartu text-center" style="margin-top:12px">
        <div style="font-size:14px;color:var(--redam)">Pindai QR yang tampil di layar kantor. Tidak perlu foto.</div>

        <div id="statusLokasi" style="margin-top:12px;font-weight:600">Memeriksa lokasi…</div>
        <div id="info" style="font-size:13px;color:var(--redam);margin-top:4px"></div>

        <div id="reader" style="width:100%;max-width:340px;margin:14px auto 0"></div>

        @unless ($selesai)
            <button type="button" id="tombolScan" class="btn btn-primary btn-block btn-lg" style="margin-top:14px">
                <ion-icon name="qr-code-outline"></ion-icon> Mulai Pindai QR
            </button>
        @endunless
    </div>

    <div class="text-center" style="margin-top:14px;font-size:13px">
        <a href="{{ route('presensi.create') }}">Gunakan presensi dengan foto</a>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(() => {
    const SELESAI = @json($selesai);
    const AKURASI_MAKS = {{ (int) $akurasiMaks }};
    const URL_SIMPAN = @json(route('presensi.qr.store'));
    const URL_BERANDA = @json(route('dashboard'));
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    // Pertanyaan yang dapat diajukan server sebelum presensi diterima (kode galat -> isian).
    const MINTA = {
        lembur: { kolom: 'keterangan_lembur', judul: 'Keterangan lembur', isi: 'Tulis pekerjaan yang dilakukan selama lembur' },
        pulang_cepat: { kolom: 'alasan_pulang_cepat', judul: 'Alasan pulang lebih awal', isi: 'Mis. anak sakit, keperluan keluarga mendesak' },
    };

    const elStatus = document.getElementById('statusLokasi'), elInfo = document.getElementById('info');
    const tombol = document.getElementById('tombolScan'), reader = document.getElementById('reader');
    let posisi = null, token = @json($tokenAwal) || null, mengirim = false, pemindai = null, jawaban = {};

    // GPS tetap berjalan sebagai bukti kehadiran, tetapi koordinat dan jarak sengaja tidak ditampilkan.
    function pantauGps() {
        if (!navigator.geolocation) { elStatus.textContent = 'Perangkat ini tidak mendukung GPS.'; return; }
        const idPantau = navigator.geolocation.watchPosition((p) => {
            posisi = { lat: p.coords.latitude, lng: p.coords.longitude, akurasi: p.coords.accuracy };
            elStatus.textContent = posisi.akurasi <= AKURASI_MAKS ? 'Lokasi siap ✓' : 'Mencari sinyal GPS yang lebih baik…';
            coba();
        }, () => {
            elStatus.textContent = 'Izinkan akses lokasi di browser untuk melanjutkan.';
        }, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
        window.addEventListener('pagehide', () => navigator.geolocation.clearWatch(idPantau));
    }

    // QR berisi URL "…/presensi/qr?t=TOKEN"; pemindai kamera ponsel membuka URL itu, pemindai di sini mengambil parameter t.
    function ambilToken(teks) {
        try { const t = new URL(teks).searchParams.get('t'); if (t) return t; } catch (e) { /* bukan URL */ }
        return /^\d+\.[A-Za-z0-9_-]+$/.test(teks) ? teks : null;
    }

    async function hentikanScan() {
        if (!pemindai) return;
        try { await pemindai.stop(); pemindai.clear(); } catch (e) { /* sudah berhenti */ }
        pemindai = null;
    }

    async function mulaiScan() {
        if (SELESAI || mengirim) return;
        tombol.style.display = 'none';
        elInfo.textContent = 'Arahkan kamera ke QR di layar kantor.';
        pemindai = new Html5Qrcode('reader');
        try {
            await pemindai.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, async (teks) => {
                const t = ambilToken(teks);
                if (!t) return;
                token = t;
                await hentikanScan();
                elInfo.textContent = 'QR terbaca.';
                coba();
            }, () => { /* bingkai tanpa QR: abaikan */ });
        } catch (e) {
            tombol.style.display = '';
            elInfo.textContent = 'Kamera tidak dapat dibuka. Izinkan akses kamera, atau pindai QR dengan kamera ponsel.';
        }
    }

    // Kirim otomatis begitu QR terbaca dan GPS cukup akurat.
    function coba() {
        if (SELESAI || mengirim || !token || !posisi || posisi.akurasi > AKURASI_MAKS) return;
        kirim();
    }

    function ulangi() {
        mengirim = false; token = null; jawaban = {};
        if (tombol) tombol.style.display = '';
        elInfo.textContent = '';
    }

    async function kirim() {
        mengirim = true;
        elInfo.textContent = 'Mengirim presensi…';
        try {
            const res = await fetch(URL_SIMPAN, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ token, lokasi: posisi.lat + ',' + posisi.lng, akurasi: posisi.akurasi, ...jawaban }),
            });
            const json = await res.json().catch(() => ({}));

            if (res.ok && json.ok) {
                await Swal.fire({ icon: 'success', title: json.jenis === 'masuk' ? 'Presensi Masuk Berhasil' : 'Presensi Pulang Berhasil', text: json.pesan, timer: 2800, showConfirmButton: false });
                location.href = URL_BERANDA;
                return;
            }

            if (MINTA[json.kode]) {
                const m = MINTA[json.kode];
                mengirim = false;
                const hasil = await Swal.fire({
                    icon: 'question', title: m.judul, text: json.pesan,
                    input: 'textarea', inputPlaceholder: m.isi, inputAttributes: { maxlength: 500 }, inputValue: jawaban[m.kolom] || '',
                    showCancelButton: true, confirmButtonText: 'Lanjutkan', cancelButtonText: 'Batal',
                    inputValidator: (v) => (!v || v.trim().length < 5) ? 'Minimal 5 karakter.' : null,
                });
                if (hasil.isConfirmed) { jawaban[m.kolom] = hasil.value.trim(); return kirim(); }
                return ulangi();
            }

            await Swal.fire({ icon: 'error', title: 'Presensi gagal', text: json.pesan || json.message || 'Terjadi kesalahan. Coba lagi.' });
        } catch (e) {
            await Swal.fire({ icon: 'error', title: 'Tidak ada koneksi', text: 'Periksa jaringan Anda lalu coba lagi.' });
        }
        ulangi();
    }

    pantauGps();
    if (tombol) tombol.addEventListener('click', mulaiScan);
    // Dibuka dari pemindai kamera ponsel (token ada di URL): tidak perlu membuka kamera lagi.
    if (token && !SELESAI) { elInfo.textContent = 'QR terbaca. Menunggu lokasi…'; if (tombol) tombol.style.display = 'none'; }
})();
</script>
@endpush
