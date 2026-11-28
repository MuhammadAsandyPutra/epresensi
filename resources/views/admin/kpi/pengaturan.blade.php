@extends('layouts.admin')

@section('judul', 'Pengaturan KPI & Kebijakan')

@section('content')
@php
    $f = fn (string $nama, string $label, string $hint = '', string $step = '1') =>
        '<div class="col-md-4"><label class="form-label required">'.e($label).'</label>'
        .'<input type="number" step="'.$step.'" name="'.$nama.'" class="form-control" value="'.e(old($nama, $nilai[$nama])).'" required>'
        .($hint ? '<div class="form-hint">'.e($hint).'</div>' : '').'</div>';
@endphp
<form method="POST" action="{{ route('panel.pengaturan.update') }}">
    @csrf @method('PUT')

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Bobot skor KPI</h3></div>
        <div class="card-body"><div class="row g-3">
            {!! $f('kpi_bobot_kehadiran', 'Bobot kehadiran', 'Hadir ÷ hari efektif') !!}
            {!! $f('kpi_bobot_ketepatan', 'Bobot ketepatan waktu', 'Hari tepat waktu ÷ hari hadir') !!}
            {!! $f('kpi_bobot_disiplin', 'Bobot disiplin', 'Dikurangi penalti di bawah') !!}
        </div><div class="form-hint mt-2">Bobot tidak harus berjumlah 100; sistem menormalkannya otomatis.</div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Penalti disiplin (dari 100 poin)</h3></div>
        <div class="card-body"><div class="row g-3">
            {!! $f('kpi_penalti_per_menit', 'Per menit terlambat', 'mis. 0,2 → 50 menit = −10 poin', '0.01') !!}
            {!! $f('kpi_penalti_alpa', 'Per hari tanpa keterangan', '', '0.5') !!}
            {!! $f('kpi_penalti_pulang_cepat', 'Per kejadian pulang cepat', '', '0.5') !!}
        </div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Batas kategori skor</h3></div>
        <div class="card-body"><div class="row g-3">
            {!! $f('kpi_batas_sangat_baik', 'Sangat Baik ≥', '', '0.5') !!}
            {!! $f('kpi_batas_baik', 'Baik ≥', '', '0.5') !!}
            {!! $f('kpi_batas_cukup', 'Cukup ≥', 'Di bawahnya: Perlu Pembinaan', '0.5') !!}
        </div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Kebijakan izin, cuti, dan GPS</h3></div>
        <div class="card-body"><div class="row g-3">
            {!! $f('izin_sakit_mundur_hari', 'Sakit boleh diajukan mundur (hari)') !!}
            {!! $f('cuti_jatah_tahunan', 'Jatah cuti default (hari/tahun)', 'Dipakai bila jatah karyawan kosong') !!}
            {!! $f('gps_akurasi_maks', 'Akurasi GPS maksimal (meter)', 'Lebih buruk dari ini → presensi ditolak') !!}
            {!! $f('gps_review_akurasi', 'Tandai "perlu review" bila akurasi > (meter)') !!}
            {!! $f('lembur_minimal_menit', 'Lembur dihitung setelah jam pulang + (menit)', 'Pulang melewati batas ini wajib mengisi keterangan lembur') !!}
        </div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Presensi dengan QR (tanpa foto)</h3></div>
        <div class="card-body"><div class="row g-3">
            <div class="col-md-4"><label class="form-label required">Presensi QR</label>
                <select name="qr_aktif" class="form-select" required>
                    <option value="0" @selected((string) (int) old('qr_aktif', $nilai['qr_aktif']) === '0')>Nonaktif</option>
                    <option value="1" @selected((string) (int) old('qr_aktif', $nilai['qr_aktif']) === '1')>Aktif</option>
                </select>
                <div class="form-hint">Karyawan memindai QR di layar kantor, tanpa selfie. GPS tetap divalidasi. Nyalakan setelah QR kantor dicetak (menu QR Kantor).</div></div>
            <div class="col-md-4"><label class="form-label required">Jenis QR</label>
                <select name="qr_mode" class="form-select" required>
                    <option value="tetap" @selected(old('qr_mode', $nilai['qr_mode']) !== 'berganti')>Tetap (dicetak, diganti manual)</option>
                    <option value="berganti" @selected(old('qr_mode', $nilai['qr_mode']) === 'berganti')>Berganti otomatis (butuh layar kantor)</option>
                </select>
                <div class="form-hint"><strong>Tetap:</strong> cetak dan tempel di dalam kantor; QR hanya berubah saat Admin menekan Ganti QR. <strong>Berganti:</strong> lebih aman, tetapi butuh tablet/monitor yang selalu menyala.</div></div>
            {!! $f('qr_berlaku_detik', 'QR berganti tiap (detik)', 'Hanya untuk jenis Berganti. QR berlaku 1x–2x nilai ini; 60 detik cukup untuk memindai dan menunggu GPS') !!}
        </div></div>
        <div class="card-footer text-end"><button class="btn btn-primary">Simpan Pengaturan</button></div>
    </div>
</form>
@endsection
