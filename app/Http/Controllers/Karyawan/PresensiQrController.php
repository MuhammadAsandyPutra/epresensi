<?php

namespace App\Http\Controllers\Karyawan;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Karyawan\PresensiQrRequest;
use App\Models\Pengaturan;
use App\Services\PresensiService;
use App\Services\QrPresensiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Presensi tanpa foto: pindai QR yang tampil di layar kantor. GPS tetap diambil dan divalidasi di server. */
class PresensiQrController extends Controller
{
    public function create(Request $request, QrPresensiService $qr, PresensiService $presensi): View|RedirectResponse
    {
        if (! $qr->aktif()) {
            return redirect()->route('presensi.create')->with('galat', 'Presensi dengan QR belum diaktifkan. Gunakan presensi dengan foto.');
        }

        $karyawan = Auth::guard('karyawan')->user();

        return view('karyawan.presensi.qr', [
            'sesi' => $presensi->sesiHariIni($karyawan, now()->toDateString()),
            'akurasiMaks' => (int) Pengaturan::ambil('gps.akurasi_maks'),
            'tokenAwal' => (string) $request->query('t', ''), // QR dipindai dengan kamera ponsel => token ada di URL
        ]);
    }

    public function store(PresensiQrRequest $request, QrPresensiService $qr, PresensiService $service): JsonResponse
    {
        $karyawan = Auth::guard('karyawan')->user()->load('cabang');

        try {
            if (! $qr->aktif()) {
                throw new AturanBisnisException('Presensi dengan QR belum diaktifkan.', 'qr');
            }
            if (! $karyawan->kode_cabang || ! $qr->sah($request->string('token')->toString(), $karyawan->kode_cabang)) {
                throw new AturanBisnisException('Kode QR tidak berlaku atau sudah kedaluwarsa. Pindai ulang QR yang sedang tampil di layar kantor.', 'qr');
            }

            $hasil = $service->catat(
                $karyawan,
                $request->string('lokasi')->toString(),
                $request->filled('akurasi') ? (float) $request->input('akurasi') : null,
                null, // tanpa foto
                $request->ip(),
                $request->filled('keterangan_lembur') ? $request->string('keterangan_lembur')->toString() : null,
                $request->filled('alasan_pulang_cepat') ? $request->string('alasan_pulang_cepat')->toString() : null,
                null,
                'qr',
            );
        } catch (AturanBisnisException $e) {
            return response()->json(['ok' => false, 'pesan' => $e->getMessage(), 'kode' => $e->kode], 422);
        }

        return response()->json(['ok' => true, ...$hasil]);
    }
}
