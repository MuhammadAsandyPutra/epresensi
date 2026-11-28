<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use App\Models\LogAktivitas;
use App\Services\QrPresensiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * QR kantor untuk presensi tanpa foto. Mode `tetap`: halaman ini dipakai untuk MENCETAK QR dan menggantinya bila perlu.
 * Mode `berganti`: dibuka di tablet/monitor kantor, QR berganti otomatis. Siapa pun yang melihat QR langsungnya dapat
 * memakainya (itulah sebabnya GPS tetap divalidasi), jadi jangan menampilkannya di luar kantor.
 */
class QrKantorController extends Controller
{
    public function index(Request $request, QrPresensiService $qr): View
    {
        return view('admin.qr-kantor.index', [
            'cabangList' => Cabang::query()->orderBy('nama_cabang')->get(['kode_cabang', 'nama_cabang', 'qr_versi', 'qr_diganti_pada']),
            'terpilih' => (string) $request->query('cabang', ''),
            'aktif' => $qr->aktif(),
            'mode' => $qr->mode(),
            'ttl' => $qr->ttl(),
        ]);
    }

    public function token(Request $request, QrPresensiService $qr): JsonResponse
    {
        $d = $request->validate(['cabang' => ['required', 'string', 'max:10', 'exists:cabang,kode_cabang']]);
        $t = $qr->buat($d['cabang']);
        $cabang = Cabang::query()->find($d['cabang']);

        return response()
            ->json([
                'url' => route('presensi.qr', ['t' => $t['token']]),
                'mode' => $t['mode'],
                'sisa' => $t['sisa_detik'],
                'ttl' => $t['ttl'],
                'versi' => $t['versi'],
                'diganti' => $cabang?->qr_diganti_pada?->translatedFormat('d M Y H:i'),
                'aktif' => $qr->aktif(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /** Ganti QR cabang (mode tetap): QR yang sudah dicetak langsung tidak berlaku, jadi harus dicetak dan dipasang ulang. */
    public function ganti(Request $request, QrPresensiService $qr): RedirectResponse
    {
        $d = $request->validate(['cabang' => ['required', 'string', 'max:10', 'exists:cabang,kode_cabang']]);
        $cabang = Cabang::query()->findOrFail($d['cabang']);

        $versi = $qr->ganti($cabang);
        LogAktivitas::catat('qr.ganti', "QR presensi cabang {$cabang->kode_cabang} diganti (versi {$versi})");

        return redirect()->route('panel.qr-kantor', ['cabang' => $cabang->kode_cabang])
            ->with('sukses', "QR diganti (versi {$versi}). QR lama yang sudah dicetak tidak berlaku lagi: cetak dan pasang yang baru.");
    }
}
