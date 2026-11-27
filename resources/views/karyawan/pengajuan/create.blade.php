@extends('layouts.karyawan')

@section('judul', 'Ajukan Izin / Cuti / Tugas Luar')
@section('header', 'Pengajuan Baru')

@section('content')
<form method="POST" action="{{ route('izin.store') }}" enctype="multipart/form-data" class="kartu form-mobile" style="margin-top:12px">
    @csrf

    <div class="d-flex mb-2" style="gap:8px">
        <div class="flex-fill text-center p-2" style="background:var(--hijau-muda);border-radius:12px">
            <div style="font-size:20px;font-weight:700;color:var(--hijau)">{{ max(0, $rincian['sisa']) }}</div>
            <div style="font-size:11px;color:var(--redam)">Sisa cuti {{ $tahun }} (hari)</div>
        </div>
        <div class="flex-fill text-center p-2" style="background:var(--hijau-muda);border-radius:12px">
            <div style="font-size:20px;font-weight:700;color:var(--hijau)">{{ max(0, $rincianDepan['sisa']) }}</div>
            <div style="font-size:11px;color:var(--redam)">Dapat dipinjam dari {{ $tahun + 1 }}</div>
        </div>
    </div>
    @if ($rincian['luapan'] > 0)
        <div class="mb-2" style="font-size:12px;color:#8a5a00">{{ $rincian['luapan'] }} hari jatah {{ $tahun }} sudah terpakai sebagai pinjaman dari tahun lalu.</div>
    @endif
    <div id="infoPotong" class="mb-3" style="display:none;font-size:13px;border-radius:10px;padding:8px 10px"></div>

    <div class="form-group">
        <label for="jenis">Jenis pengajuan</label>
        <select name="jenis" id="jenis" class="form-control" required>
            @foreach ($jenis as $j)
                <option value="{{ $j->value }}" @selected((string) old('jenis', 1) === (string) $j->value)>{{ $j->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="form-group" id="kolomLokasiTugas" style="display:none">
        <label for="lokasi_tugas">Tujuan / lokasi tugas</label>
        <input type="text" name="lokasi_tugas" id="lokasi_tugas" class="form-control" maxlength="150" value="{{ old('lokasi_tugas') }}" placeholder="Mis. Kantor Cabang Banjarmasin / kunjungan nasabah">
        <div class="text-muted" style="font-size:12px;margin-top:4px">Setelah disetujui Direksi, Anda tetap presensi (selfie + GPS) dari lokasi tugas; batas radius kantor tidak berlaku.</div>
    </div>

    <div class="row">
        <div class="col-6 form-group">
            <label for="tgl_izin">Tanggal mulai</label>
            <input type="date" name="tgl_izin" id="tgl_izin" class="form-control" value="{{ old('tgl_izin', now()->toDateString()) }}" required>
        </div>
        <div class="col-6 form-group">
            <label for="tgl_selesai">Sampai (opsional)</label>
            <input type="date" name="tgl_selesai" id="tgl_selesai" class="form-control" value="{{ old('tgl_selesai') }}">
        </div>
    </div>

    <div class="form-group">
        <label for="keterangan">Keterangan <span id="petunjukKeterangan" class="text-muted" style="font-weight:400">(disarankan)</span></label>
        <textarea name="keterangan" id="keterangan" rows="3" class="form-control" maxlength="500" placeholder="Tulis alasan singkat">{{ old('keterangan') }}</textarea>
    </div>

    <div class="form-group">
        <label for="lampiran">Lampiran <span id="petunjukLampiran" class="text-muted" style="font-weight:400">(opsional, JPG/PNG/PDF)</span></label>
        <input type="file" name="lampiran" id="lampiran" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="padding:6px">
    </div>

    <button type="submit" class="btn btn-primary btn-block btn-lg">Kirim Pengajuan</button>
</form>
@endsection

@push('scripts')
<script>
    const jenis = document.getElementById('jenis'), petunjuk = document.getElementById('petunjukLampiran');
    const kolomLokasi = document.getElementById('kolomLokasiTugas'), inputLokasi = document.getElementById('lokasi_tugas');
    const keterangan = document.getElementById('keterangan'), lampiran = document.getElementById('lampiran');
    const petunjukKet = document.getElementById('petunjukKeterangan'), info = document.getElementById('infoPotong');

    const ubah = () => {
        const j = jenis.value, tugasLuar = j === '4';
        kolomLokasi.style.display = tugasLuar ? '' : 'none';
        inputLokasi.required = tugasLuar;
        keterangan.required = tugasLuar;
        petunjukKet.textContent = tugasLuar ? '(wajib)' : '(disarankan)';
        petunjuk.textContent = (j === '1' || j === '2') ? '(surat/bukti, JPG/PNG/PDF; agar tidak memotong cuti)' : '(opsional, JPG/PNG/PDF)';

        // Izin/sakit: berdokumen (keterangan + surat) tidak memotong cuti; selain itu memotong.
        let pesan = '', latar = '', warna = '';
        if (j === '1' || j === '2') {
            const dokumen = lampiran.files.length > 0 && keterangan.value.trim().length >= 5;
            pesan = dokumen ? 'Berdokumen: pengajuan ini tidak memotong cuti tahunan.'
                            : 'Tanpa keterangan dan surat, hari izin/sakit ini memotong cuti tahunan Anda.';
            latar = dokumen ? '#e7f6ee' : '#fff6e5'; warna = dokumen ? '#1b7a4a' : '#8a5a00';
        } else if (j === '3') {
            pesan = 'Cuti memotong jatah tahunan. Bila sisa tidak cukup, kekurangannya dipotong dari jatah tahun depan.';
            latar = '#eef3fb'; warna = '#2d5a9e';
        }
        info.style.display = pesan ? '' : 'none';
        info.textContent = pesan; info.style.background = latar; info.style.color = warna;
    };
    jenis.addEventListener('change', ubah);
    lampiran.addEventListener('change', ubah);
    keterangan.addEventListener('input', ubah);
    ubah();
</script>
@endpush
