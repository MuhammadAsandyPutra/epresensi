@extends('layouts.admin')

@section('judul', 'Koreksi Presensi')

@section('content')
<div class="alert alert-info">
    Gunakan hanya bila karyawan <strong>sama sekali tidak bisa presensi</strong> (HP rusak, aplikasi error, dll.) atau lupa presensi pulang.
    Alasan wajib diisi. Jam asli, nama Anda, dan waktu koreksi tersimpan permanen dan ditandai <em>Dikoreksi HR</em> di Monitoring dan Laporan.
    Koreksi hanya untuk {{ $maksMundur }} hari terakhir.
</div>

<form method="GET" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-5"><label class="form-label">Karyawan</label>
            <select name="nik" class="form-select" required>
                <option value="">Pilih karyawan…</option>
                @foreach ($karyawanList as $k)<option value="{{ $k->nik }}" @selected(($karyawan?->nik ?? old('nik')) === $k->nik)>{{ $k->nama_lengkap }} ({{ $k->nik }})</option>@endforeach
            </select></div>
        <div class="col-md-3"><label class="form-label">Tanggal</label><input type="date" name="tanggal" class="form-control" value="{{ $tanggal }}" max="{{ now()->toDateString() }}" required></div>
        <div class="col-md-2"><button class="btn btn-primary w-100"><i class="ti ti-search me-1"></i>Tampilkan</button></div>
    </div>
</form>

@if ($karyawan)
<form method="POST" action="{{ route('panel.koreksi.store') }}" class="card mb-3" onsubmit="return confirm('Simpan koreksi presensi {{ $karyawan->nama_lengkap }} tanggal {{ $tanggal }}?')">
    @csrf
    <input type="hidden" name="nik" value="{{ $karyawan->nik }}">
    <input type="hidden" name="tanggal" value="{{ $tanggal }}">
    <div class="card-header"><h3 class="card-title">{{ $karyawan->nama_lengkap }} · {{ \Illuminate\Support\Carbon::parse($tanggal)->translatedFormat('l, d F Y') }}</h3></div>
    <div class="card-body">
        @if ($presensi)
            <div class="mb-3 text-secondary">
                Presensi saat ini: masuk <strong>{{ \App\Support\Waktu::hm($presensi->jam_in) }}</strong>, pulang <strong>{{ \App\Support\Waktu::hm($presensi->jam_out) }}</strong>
                @if ($presensi->dikoreksi())<span class="badge bg-blue-lt ms-1">Sudah pernah dikoreksi</span>@endif
            </div>
        @else
            <div class="mb-3 text-secondary">Belum ada presensi pada tanggal ini. Isi <strong>jam masuk</strong> (dan jam pulang bila sudah pulang) untuk membuat catatannya.</div>
        @endif
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Jam masuk</label><input type="time" name="jam_masuk" class="form-control" value="{{ old('jam_masuk', $presensi?->jam_in ? substr($presensi->jam_in, 0, 5) : '') }}"></div>
            <div class="col-md-3"><label class="form-label">Jam pulang</label><input type="time" name="jam_pulang" class="form-control" value="{{ old('jam_pulang', $presensi?->jam_out ? substr($presensi->jam_out, 0, 5) : '') }}"></div>
            <div class="col-md-12"><label class="form-label required">Alasan koreksi</label>
                <textarea name="alasan" class="form-control" rows="3" minlength="10" maxlength="500" required placeholder="Mis. HP karyawan rusak seharian; hadir sesuai konfirmasi atasan langsung">{{ old('alasan') }}</textarea>
                <div class="form-hint">Jam masuk memengaruhi hitungan terlambat. Jam pulang sebelum jam pulang jadwal tetap dihitung pulang cepat di KPI.</div></div>
        </div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Simpan koreksi</button></div>
</form>
@endif

<div class="card">
    <div class="card-header"><h3 class="card-title">30 koreksi terakhir</h3></div>
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead><tr><th>Dikoreksi</th><th>Karyawan</th><th>Tanggal</th><th>Jam asli</th><th>Jam sekarang</th><th>Alasan</th><th>Oleh</th></tr></thead>
            <tbody>
            @forelse ($riwayat as $r)
                <tr>
                    <td class="text-nowrap">{{ $r->koreksi_pada->translatedFormat('d M Y H:i') }}</td>
                    <td><div class="fw-bold">{{ $r->karyawan?->nama_lengkap ?? $r->nik }}</div><div class="text-secondary small">{{ $r->nik }}</div></td>
                    <td class="text-nowrap">{{ $r->tanggal_presensi->translatedFormat('d M Y') }}</td>
                    <td class="text-nowrap">{{ $r->koreksi_jam_in_asli || $r->koreksi_jam_out_asli ? \App\Support\Waktu::hm($r->koreksi_jam_in_asli).' – '.\App\Support\Waktu::hm($r->koreksi_jam_out_asli) : 'belum ada presensi' }}</td>
                    <td class="text-nowrap">{{ \App\Support\Waktu::hm($r->jam_in) }} – {{ \App\Support\Waktu::hm($r->jam_out) }}</td>
                    <td style="max-width:300px;white-space:normal">{{ $r->koreksi_alasan }}</td>
                    <td>{{ $r->pengoreksi?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">Belum ada koreksi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
