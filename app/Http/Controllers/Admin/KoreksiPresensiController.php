<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KoreksiPresensiRequest;
use App\Models\Karyawan;
use App\Models\LogAktivitas;
use App\Models\Pengaturan;
use App\Models\Presensi;
use App\Services\KoreksiPresensiService;
use App\Support\Waktu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Koreksi presensi oleh HR/Admin (karyawan tidak bisa presensi sama sekali, atau lupa presensi pulang). */
class KoreksiPresensiController extends Controller
{
    public function __construct(private KoreksiPresensiService $service) {}

    public function index(Request $request): View
    {
        $nik = $request->query('nik');
        $tanggal = (string) $request->query('tanggal', now()->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            $tanggal = now()->toDateString();
        }

        $karyawan = $nik ? Karyawan::query()->with('departemen')->find($nik) : null;
        $presensi = $karyawan ? Presensi::query()->where('nik', $karyawan->nik)->where('tanggal_presensi', $tanggal)->first() : null;

        return view('admin.koreksi.index', [
            'karyawanList' => Karyawan::query()->aktif()->orderBy('nama_lengkap')->get(['nik', 'nama_lengkap']),
            'karyawan' => $karyawan,
            'tanggal' => $tanggal,
            'presensi' => $presensi,
            'maksMundur' => (int) Pengaturan::ambil('koreksi.maks_hari_mundur'),
            'riwayat' => Presensi::query()->with(['karyawan', 'pengoreksi'])->whereNotNull('koreksi_pada')
                ->orderByDesc('koreksi_pada')->limit(30)->get(),
        ]);
    }

    public function store(KoreksiPresensiRequest $request): RedirectResponse
    {
        $d = $request->validated();
        $karyawan = Karyawan::query()->findOrFail($d['nik']);

        try {
            $presensi = $this->service->koreksi(
                $karyawan, $d['tanggal'], $d['jam_masuk'] ?? null, $d['jam_pulang'] ?? null, $d['alasan'], Auth::guard('user')->user(),
            );
        } catch (AturanBisnisException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        LogAktivitas::catat('presensi.koreksi', "{$karyawan->nik} {$d['tanggal']}: masuk ".Waktu::hm($presensi->jam_in).', pulang '.Waktu::hm($presensi->jam_out).". Alasan: {$d['alasan']}");

        return redirect()->route('panel.koreksi.index')->with('sukses', 'Presensi dikoreksi. Perubahan tercatat di Log Aktivitas dan ditandai "Dikoreksi HR".');
    }
}
