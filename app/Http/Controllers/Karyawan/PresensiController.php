<?php

namespace App\Http\Controllers\Karyawan;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Karyawan\PresensiRequest;
use App\Enums\JenisPengajuan;
use App\Enums\StatusPengajuan;
use App\Models\Pengaturan;
use App\Models\PengajuanIzin;
use App\Models\Presensi;
use App\Services\JadwalService;
use App\Services\PresensiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PresensiController extends Controller
{
    /** Halaman kamera + peta. Semua keputusan tetap divalidasi ulang di server saat store(). */
    public function create(JadwalService $jadwal, PresensiService $service): View
    {
        $karyawan = Auth::guard('karyawan')->user()->load('cabang');
        $sesi = $service->sesiHariIni($karyawan, now()->toDateString());

        return view('karyawan.presensi.create', [
            'karyawan' => $karyawan,
            'cabang' => $karyawan->cabang,
            'jamKerja' => $jadwal->untukTanggal($karyawan, now()),
            'presensi' => $sesi['presensi'],
            'sesi' => $sesi, // termasuk presensi luar kantor yang sedang ditahan
            'akurasiMaks' => (int) Pengaturan::ambil('gps.akurasi_maks'),
            // Tugas luar yang sudah disetujui hari ini: radius kantor tidak dipakai.
            'tugasLuar' => PengajuanIzin::query()->where('nik', $karyawan->nik)
                ->where('status_approved', StatusPengajuan::Disetujui->value)
                ->where('status', JenisPengajuan::TugasLuar->value)
                ->berimpitDengan(now()->toDateString(), now()->toDateString())->first(),
        ]);
    }

    public function store(PresensiRequest $request, PresensiService $service): JsonResponse
    {
        try {
            $hasil = $service->catat(
                Auth::guard('karyawan')->user(),
                $request->string('lokasi')->toString(),
                $request->filled('akurasi') ? (float) $request->input('akurasi') : null,
                $request->string('foto')->toString(),
                $request->ip(),
                $request->filled('keterangan_lembur') ? $request->string('keterangan_lembur')->toString() : null,
                $request->filled('alasan_pulang_cepat') ? $request->string('alasan_pulang_cepat')->toString() : null,
                $request->filled('alasan_luar_kantor') ? $request->string('alasan_luar_kantor')->toString() : null,
            );
        } catch (AturanBisnisException $e) {
            return response()->json(['ok' => false, 'pesan' => $e->getMessage(), 'kode' => $e->kode], 422);
        }

        return response()->json(['ok' => true, ...$hasil]);
    }
}
