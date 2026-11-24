@extends('layouts.admin')

@section('judul', 'Lembur')

@section('content')
@php $user = auth('user')->user(); @endphp
<div class="alert alert-info d-print-none">
    Lembur tercatat otomatis saat karyawan presensi pulang melewati batas lembur dan mengisi keterangannya.
    <strong>Direksi</strong> menyetujui atau menolak; <strong>HR</strong> menandai sudah diketahui.
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
            <thead><tr><th>Karyawan</th><th>Tanggal</th><th>Jam</th><th>Lembur</th><th>Keterangan</th><th>Keputusan Direksi</th><th>Diketahui HR</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
            @forelse ($daftar as $p)
                @php
                    $jam = intdiv((int) $p->lembur_menit, 60);
                    $mnt = (int) $p->lembur_menit % 60;
                @endphp
                <tr>
                    <td><div class="fw-bold">{{ $p->karyawan?->nama_lengkap ?? $p->nik }}</div><div class="text-secondary small">{{ $p->nik }} · {{ $p->karyawan?->departemen?->nama_dept }}</div></td>
                    <td>{{ $p->tanggal_presensi->translatedFormat('d M Y') }}</td>
                    <td>{{ \App\Support\Waktu::hm($p->jam_in) }} – {{ \App\Support\Waktu::hm($p->jam_out) }}</td>
                    <td class="text-nowrap"><span class="badge bg-purple-lt">{{ $jam > 0 ? $jam.' j ' : '' }}{{ $mnt }} mnt</span></td>
                    <td style="max-width:300px;white-space:normal">{{ $p->keterangan_lembur }}
                        @if ($p->lembur_catatan)<div class="text-secondary small">Catatan Direksi: {{ $p->lembur_catatan }}</div>@endif</td>
                    <td><span class="badge bg-{{ $p->lembur_status->warna() }}-lt">{{ $p->lembur_status->label() }}</span>
                        @if ($p->pemutusLembur && $p->lembur_status !== \App\Enums\StatusPengajuan::Menunggu)<div class="text-secondary small">{{ $p->pemutusLembur->name }}@if ($p->lembur_diputuskan_pada) · {{ $p->lembur_diputuskan_pada->translatedFormat('d M H:i') }}@endif</div>@endif</td>
                    <td>@if ($p->lembur_diketahui_pada)<span class="badge bg-green-lt"><i class="ti ti-check"></i> Diketahui</span>@else<span class="badge bg-yellow-lt">Belum</span>@endif</td>
                    <td class="text-end text-nowrap">
                        @if ($user->isDireksi())
                            @if ($p->lembur_status === \App\Enums\StatusPengajuan::Menunggu)
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalPutus" data-url="{{ route('panel.lembur.putuskan', $p) }}" data-keputusan="setuju" data-nama="{{ $p->karyawan?->nama_lengkap }}">Setujui</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalPutus" data-url="{{ route('panel.lembur.putuskan', $p) }}" data-keputusan="tolak" data-nama="{{ $p->karyawan?->nama_lengkap }}">Tolak</button>
                            @else
                                <form method="POST" action="{{ route('panel.lembur.kembalikan', $p) }}" class="d-inline" onsubmit="return confirm('Kembalikan lembur ini ke status Menunggu?')">
                                    @csrf <button class="btn btn-sm btn-outline-secondary">Batalkan keputusan</button>
                                </form>
                            @endif
                        @elseif ($user->penerimaInfo() && ! $p->lembur_diketahui_pada)
                            <form method="POST" action="{{ route('panel.lembur.ketahui', $p) }}" class="d-inline">
                                @csrf <button class="btn btn-sm btn-outline-primary"><i class="ti ti-eye-check me-1"></i>Tandai diketahui</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-secondary py-4">Belum ada lembur.</td></tr>
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
        document.getElementById('judulPutus').textContent = (setuju ? 'Setujui' : 'Tolak') + ' lembur ' + d.nama;
        document.getElementById('labelCatatan').textContent = setuju ? 'Catatan (opsional)' : 'Alasan penolakan (wajib)';
        document.getElementById('inputCatatan').required = !setuju;
        document.getElementById('inputCatatan').value = '';
        const t = document.getElementById('tombolPutus'); t.textContent = setuju ? 'Setujui' : 'Tolak'; t.className = 'btn ' + (setuju ? 'btn-success' : 'btn-danger');
    });
</script>
@endif
@endpush
