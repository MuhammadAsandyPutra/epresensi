@extends('layouts.admin')

@section('judul', 'Presensi Luar Kantor')

@section('content')
@php $user = auth('user')->user(); @endphp
<div class="alert alert-info d-print-none">
    Presensi karyawan yang ditandai <strong>boleh presensi dari luar kantor</strong> (mis. menagih angsuran) ditahan di sini dan <strong>belum dihitung</strong>.
    <strong>Direksi</strong> menyetujui atau menolak; bila disetujui, presensi tercatat dengan <strong>jam asli</strong> saat karyawan presensi.
    <strong>HR</strong> menandai sudah diketahui.
</div>

<form method="GET" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Cari nama / NIK</label><input type="text" name="cari" class="form-control" value="{{ $filter['cari'] ?? '' }}"></div>
        <div class="col-md-2"><label class="form-label">Keputusan</label>
            <select name="status" class="form-select"><option value="">Semua</option>
                @foreach ($statusList as $s)<option value="{{ $s->value }}" @selected(isset($filter['status']) && (int) $filter['status'] === $s->value)>{{ $s->label() }}</option>@endforeach
            </select></div>
        <div class="col-md-2"><label class="form-label">Diketahui HR</label>
            <select name="diketahui" class="form-select"><option value="">Semua</option>
                <option value="0" @selected(($filter['diketahui'] ?? null) === '0')>Belum</option>
                <option value="1" @selected(($filter['diketahui'] ?? null) === '1')>Sudah</option>
            </select></div>
        <div class="col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $filter['dari'] ?? '' }}"></div>
        <div class="col-md-2"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $filter['sampai'] ?? '' }}"></div>
        <div class="col-md-1"><button class="btn btn-primary w-100"><i class="ti ti-search"></i></button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead><tr><th>Karyawan</th><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Tujuan</th><th>Keputusan Direksi</th><th>Diketahui HR</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
            @forelse ($daftar as $p)
                @php
                    $lokIn = \App\Support\Geo::parse($p->lokasi_in);
                    $lokOut = \App\Support\Geo::parse($p->lokasi_out);
                @endphp
                <tr>
                    <td><div class="fw-bold">{{ $p->karyawan?->nama_lengkap ?? $p->nik }}</div><div class="text-secondary small">{{ $p->nik }} · {{ $p->karyawan?->departemen?->nama_dept }}</div></td>
                    <td class="text-nowrap">{{ $p->tanggal_presensi->translatedFormat('d M Y') }}</td>
                    <td class="text-nowrap">
                        @if ($p->jam_in)
                            <div class="fw-bold">{{ \App\Support\Waktu::hm($p->jam_in) }}</div>
                            <div class="text-secondary small">{{ $p->jarak_in !== null ? $p->jarak_in.' m dari kantor' : 'jarak tidak diketahui' }}</div>
                            @if ($p->foto_in)<a href="{{ route('berkas.tahan', [$p, 'in']) }}" target="_blank"><img src="{{ route('berkas.tahan', [$p, 'in']) }}" class="rounded" width="40" height="40" style="object-fit:cover" loading="lazy" alt="Foto masuk"></a>@endif
                            @if ($lokIn)<a href="https://www.openstreetmap.org/?mlat={{ $lokIn[0] }}&mlon={{ $lokIn[1] }}#map=17/{{ $lokIn[0] }}/{{ $lokIn[1] }}" target="_blank" rel="noopener" class="ms-1"><i class="ti ti-map-pin"></i></a>@endif
                        @else <span class="text-secondary">—</span> @endif
                    </td>
                    <td class="text-nowrap">
                        @if ($p->jam_out)
                            <div class="fw-bold">{{ \App\Support\Waktu::hm($p->jam_out) }}</div>
                            <div class="text-secondary small">{{ $p->jarak_out !== null ? $p->jarak_out.' m dari kantor' : 'jarak tidak diketahui' }}</div>
                            @if ($p->foto_out)<a href="{{ route('berkas.tahan', [$p, 'out']) }}" target="_blank"><img src="{{ route('berkas.tahan', [$p, 'out']) }}" class="rounded" width="40" height="40" style="object-fit:cover" loading="lazy" alt="Foto pulang"></a>@endif
                            @if ($lokOut)<a href="https://www.openstreetmap.org/?mlat={{ $lokOut[0] }}&mlon={{ $lokOut[1] }}#map=17/{{ $lokOut[0] }}/{{ $lokOut[1] }}" target="_blank" rel="noopener" class="ms-1"><i class="ti ti-map-pin"></i></a>@endif
                        @else <span class="text-secondary">belum</span> @endif
                    </td>
                    <td style="max-width:300px;white-space:normal">{{ $p->alasan }}
                        @if ($p->lembur_menit)<div class="small"><span class="badge bg-purple-lt">Lembur {{ $p->lembur_menit }} mnt</span> {{ $p->keterangan_lembur }}</div>@endif
                        @if ($p->pulang_cepat_menit)<div class="small"><span class="badge bg-orange-lt">Pulang awal {{ $p->pulang_cepat_menit }} mnt</span> {{ $p->alasan_pulang_cepat }}</div>@endif
                        @if ($p->catatan)<div class="text-secondary small">Catatan Direksi: {{ $p->catatan }}</div>@endif</td>
                    <td><span class="badge bg-{{ $p->status->warna() }}-lt">{{ $p->status === \App\Enums\StatusPengajuan::Menunggu ? 'Ditahan' : $p->status->label() }}</span>
                        @if ($p->pemutus && $p->status !== \App\Enums\StatusPengajuan::Menunggu)<div class="text-secondary small">{{ $p->pemutus->name }}@if ($p->diputuskan_pada) · {{ $p->diputuskan_pada->translatedFormat('d M H:i') }}@endif</div>@endif</td>
                    <td>@if ($p->sudahDiketahui())<span class="badge bg-green-lt"><i class="ti ti-check"></i> Diketahui</span>@else<span class="badge bg-yellow-lt">Belum</span>@endif</td>
                    <td class="text-end text-nowrap">
                        @if ($user->isDireksi())
                            @if ($p->status === \App\Enums\StatusPengajuan::Menunggu)
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalPutus" data-url="{{ route('panel.presensi-luar.putuskan', $p) }}" data-keputusan="setuju" data-nama="{{ $p->karyawan?->nama_lengkap }}">Setujui</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalPutus" data-url="{{ route('panel.presensi-luar.putuskan', $p) }}" data-keputusan="tolak" data-nama="{{ $p->karyawan?->nama_lengkap }}">Tolak</button>
                            @elseif ($p->status === \App\Enums\StatusPengajuan::Ditolak)
                                <form method="POST" action="{{ route('panel.presensi-luar.kembalikan', $p) }}" class="d-inline" onsubmit="return confirm('Kembalikan presensi ini ke status Ditahan?')">
                                    @csrf <button class="btn btn-sm btn-outline-secondary">Batalkan keputusan</button>
                                </form>
                            @endif
                        @elseif ($user->penerimaInfo() && ! $p->sudahDiketahui())
                            <form method="POST" action="{{ route('panel.presensi-luar.ketahui', $p) }}" class="d-inline">
                                @csrf <button class="btn btn-sm btn-outline-primary"><i class="ti ti-eye-check me-1"></i>Tandai diketahui</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-secondary py-4">Belum ada presensi luar kantor.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $daftar->links() }}</div>
</div>

@if ($user->isDireksi())
<div class="modal modal-blur fade" id="modalPutus" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form method="POST" id="formPutus" class="modal-content">
        @csrf
        <input type="hidden" name="keputusan" id="inputKeputusan">
        <div class="modal-header"><h5 class="modal-title" id="judulPutus"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
        <div class="modal-body">
            <p class="text-secondary small mb-2" id="infoSetuju">Bila disetujui, presensi tercatat dengan jam asli saat karyawan presensi. Lembur atau pulang awal di dalamnya ikut disetujui.</p>
            <label class="form-label" id="labelCatatan">Catatan (opsional)</label>
            <textarea name="catatan" id="inputCatatan" class="form-control" rows="3" maxlength="255"></textarea>
        </div>
        <div class="modal-footer"><button type="button" class="btn" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary" id="tombolPutus">Simpan</button></div>
    </form></div>
</div>
@endif
@endsection

@push('scripts')
@if (auth('user')->user()->isDireksi())
<script>
    document.getElementById('modalPutus').addEventListener('show.bs.modal', (e) => {
        const d = e.relatedTarget.dataset, setuju = d.keputusan === 'setuju';
        document.getElementById('formPutus').action = d.url;
        document.getElementById('inputKeputusan').value = d.keputusan;
        document.getElementById('judulPutus').textContent = (setuju ? 'Setujui' : 'Tolak') + ' presensi luar kantor ' + d.nama;
        document.getElementById('infoSetuju').style.display = setuju ? '' : 'none';
        document.getElementById('labelCatatan').textContent = setuju ? 'Catatan (opsional)' : 'Alasan penolakan (wajib)';
        document.getElementById('inputCatatan').required = !setuju;
        document.getElementById('inputCatatan').value = '';
        const t = document.getElementById('tombolPutus'); t.textContent = setuju ? 'Setujui' : 'Tolak'; t.className = 'btn ' + (setuju ? 'btn-success' : 'btn-danger');
    });
</script>
@endif
@endpush
