@extends('layouts.admin')

@section('judul', 'Izin, Sakit, Cuti & Tugas Luar')

@section('content')
@php $user = auth('user')->user(); @endphp
<form method="GET" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Cari nama / NIK</label><input type="text" name="cari" class="form-control" value="{{ $filter['cari'] ?? '' }}"></div>
        <div class="col-md-2"><label class="form-label">Jenis</label>
            <select name="jenis" class="form-select"><option value="">Semua</option>
                @foreach ($jenisList as $j)<option value="{{ $j->value }}" @selected(isset($filter['jenis']) && (int) $filter['jenis'] === $j->value)>{{ $j->label() }}</option>@endforeach
            </select></div>
        <div class="col-md-2"><label class="form-label">Status</label>
            <select name="status" class="form-select"><option value="">Semua</option>
                @foreach ($statusList as $s)<option value="{{ $s->value }}" @selected(isset($filter['status']) && (int) $filter['status'] === $s->value)>{{ $s->label() }}</option>@endforeach
            </select></div>
        <div class="col-md-2"><label class="form-label">Diketahui HR</label>
            <select name="diketahui" class="form-select"><option value="">Semua</option>
                <option value="0" @selected(($filter['diketahui'] ?? null) === '0')>Belum</option>
                <option value="1" @selected(($filter['diketahui'] ?? null) === '1')>Sudah</option>
            </select></div>
        <div class="col-md-2"><label class="form-label">Dari</label><input type="date" name="dari" class="form-control" value="{{ $filter['dari'] ?? '' }}"></div>
        <div class="col-md-1"><label class="form-label">Sampai</label><input type="date" name="sampai" class="form-control" value="{{ $filter['sampai'] ?? '' }}"></div>
        <div class="col-md-1"><button class="btn btn-primary w-100"><i class="ti ti-search"></i></button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead><tr><th>Karyawan</th><th>Jenis</th><th>Tanggal</th><th>Keterangan</th><th>Keputusan Direksi</th><th>Diketahui HR</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
            @forelse ($daftar as $p)
                <tr>
                    <td><div class="fw-bold">{{ $p->karyawan?->nama_lengkap ?? $p->nik }}</div><div class="text-secondary small">{{ $p->nik }} · {{ $p->karyawan?->departemen?->nama_dept }}</div></td>
                    <td><span class="badge bg-{{ $p->jenis()->warna() }}-lt">{{ $p->jenis()->label() }}</span>
                        @if ($p->potong_cuti)<div class="mt-1"><span class="badge bg-orange-lt" title="Memotong jatah cuti tahunan">Memotong cuti</span></div>
                        @elseif ($p->jenis()->bisaBerdokumen() && $p->lampiran)<div class="mt-1"><span class="badge bg-green-lt" title="Ada surat/lampiran; periksa kesahihannya">Berdokumen</span></div>@endif
                        @if ($p->hari_pinjam > 0)<div class="text-secondary small">Pinjam {{ $p->hari_pinjam }} hr dari jatah {{ \Illuminate\Support\Carbon::parse($p->tgl_izin)->year + 1 }}</div>@endif</td>
                    <td>{{ $p->tgl_izin->translatedFormat('d M Y') }}@if ($p->tgl_selesai && ! $p->tgl_selesai->isSameDay($p->tgl_izin)) – {{ $p->tgl_selesai->translatedFormat('d M Y') }}@endif
                        <div class="text-secondary small">{{ $p->jumlah_hari }} hari kerja</div></td>
                    <td style="max-width:280px;white-space:normal">@if ($p->lokasi_tugas)<div class="fw-bold"><i class="ti ti-map-pin"></i> {{ $p->lokasi_tugas }}</div>@endif{{ $p->keterangan }}
                        @if ($p->lampiran)<div><a href="{{ route('berkas.lampiran', $p) }}" target="_blank" rel="noopener"><i class="ti ti-paperclip"></i> Lampiran</a></div>@endif
                        @if ($p->catatan_hr)<div class="text-secondary small">Catatan Direksi: {{ $p->catatan_hr }}</div>@endif</td>
                    <td><span class="badge bg-{{ $p->status_approved->warna() }}-lt">{{ $p->status_approved->label() }}</span>
                        @if ($p->pemutus && in_array($p->status_approved, [\App\Enums\StatusPengajuan::Disetujui, \App\Enums\StatusPengajuan::Ditolak], true))<div class="text-secondary small">{{ $p->pemutus->name }}@if ($p->diputuskan_pada) · {{ $p->diputuskan_pada->translatedFormat('d M H:i') }}@endif</div>@endif</td>
                    <td>@if ($p->sudahDiketahuiHr())<span class="badge bg-green-lt"><i class="ti ti-check"></i> Diketahui</span>@elseif ($p->status_approved === \App\Enums\StatusPengajuan::Dibatalkan)<span class="text-secondary">—</span>@else<span class="badge bg-yellow-lt">Belum</span>@endif</td>
                    <td class="text-end text-nowrap">
                        @if ($user->isDireksi())
                            @if ($p->status_approved === \App\Enums\StatusPengajuan::Menunggu)
                                <button type="button" class="btn btn-sm btn-success btn-putus" data-bs-toggle="modal" data-bs-target="#modalPutus" data-url="{{ route('panel.pengajuan.putuskan', $p) }}" data-keputusan="setuju" data-nama="{{ $p->karyawan?->nama_lengkap }}">Setujui</button>
                                <button type="button" class="btn btn-sm btn-outline-danger btn-putus" data-bs-toggle="modal" data-bs-target="#modalPutus" data-url="{{ route('panel.pengajuan.putuskan', $p) }}" data-keputusan="tolak" data-nama="{{ $p->karyawan?->nama_lengkap }}">Tolak</button>
                            @elseif (in_array($p->status_approved, [\App\Enums\StatusPengajuan::Disetujui, \App\Enums\StatusPengajuan::Ditolak], true))
                                <form method="POST" action="{{ route('panel.pengajuan.kembalikan', $p) }}" class="d-inline" onsubmit="return confirm('Kembalikan pengajuan ini ke status Menunggu?')">
                                    @csrf <button class="btn btn-sm btn-outline-secondary">Batalkan keputusan</button>
                                </form>
                            @endif
                        @elseif ($user->penerimaInfo() && ! $p->sudahDiketahuiHr() && $p->status_approved !== \App\Enums\StatusPengajuan::Dibatalkan)
                            <form method="POST" action="{{ route('panel.pengajuan.ketahui', $p) }}" class="d-inline">
                                @csrf <button class="btn btn-sm btn-outline-primary"><i class="ti ti-eye-check me-1"></i>Tandai diketahui</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-secondary py-4">Tidak ada pengajuan.</td></tr>
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
        document.getElementById('judulPutus').textContent = (setuju ? 'Setujui' : 'Tolak') + ' pengajuan ' + d.nama;
        document.getElementById('labelCatatan').textContent = setuju ? 'Catatan (opsional)' : 'Alasan penolakan (wajib)';
        document.getElementById('inputCatatan').required = !setuju;
        document.getElementById('inputCatatan').value = '';
        const t = document.getElementById('tombolPutus'); t.textContent = setuju ? 'Setujui' : 'Tolak'; t.className = 'btn ' + (setuju ? 'btn-success' : 'btn-danger');
    });
</script>
@endif
@endpush
