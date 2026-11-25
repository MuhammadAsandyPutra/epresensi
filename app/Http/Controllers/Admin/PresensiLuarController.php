<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KeputusanRequest;
use App\Models\LogAktivitas;
use App\Models\PresensiTahan;
use App\Services\PresensiLuarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Review presensi luar kantor yang ditahan: Direksi memutuskan, HR/Admin menandai "diketahui". */
class PresensiLuarController extends Controller
{
    public function __construct(private PresensiLuarService $service) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer', 'in:0,1,2'],
            'diketahui' => ['nullable', 'in:0,1'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        $daftar = PresensiTahan::query()->with(['karyawan.departemen', 'pemutus'])
            ->when($f['cari'] ?? null, fn ($q, $c) => $q->whereHas('karyawan', fn ($k) => $k->where('nama_lengkap', 'like', "%{$c}%")->orWhere('nik', 'like', "%{$c}%")))
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(isset($f['diketahui']), fn ($q) => $f['diketahui'] === '1' ? $q->whereNotNull('diketahui_pada') : $q->whereNull('diketahui_pada'))
            ->when($f['dari'] ?? null, fn ($q, $d) => $q->where('tanggal_presensi', '>=', $d))
            ->when($f['sampai'] ?? null, fn ($q, $s) => $q->where('tanggal_presensi', '<=', $s))
            ->orderByRaw('CASE WHEN status = 0 THEN 0 ELSE 1 END')   // yang menunggu tampil paling atas
            ->orderByDesc('tanggal_presensi')->orderByDesc('id')
            ->paginate(15)->withQueryString();

        return view('admin.presensi-luar.index', [
            'daftar' => $daftar,
            'filter' => $f,
            'statusList' => [StatusPengajuan::Menunggu, StatusPengajuan::Disetujui, StatusPengajuan::Ditolak],
        ]);
    }

    public function putuskan(KeputusanRequest $request, PresensiTahan $tahan): RedirectResponse
    {
        $setuju = $request->input('keputusan') === 'setuju';

        try {
            $this->service->putuskan(
                $tahan,
                $setuju ? StatusPengajuan::Disetujui : StatusPengajuan::Ditolak,
                Auth::guard('user')->user(),
                $request->input('catatan'),
            );
        } catch (AturanBisnisException $e) {
            return back()->with('galat', $e->getMessage());
        }

        LogAktivitas::catat($setuju ? 'presensi_luar.setuju' : 'presensi_luar.tolak', "Presensi luar kantor {$tahan->nik} {$tahan->tanggal_presensi->toDateString()} #{$tahan->id}");

        return back()->with('sukses', $setuju ? 'Disetujui. Presensi kini tercatat dengan jam asli karyawan.' : 'Presensi luar kantor ditolak.');
    }

    public function kembalikan(PresensiTahan $tahan): RedirectResponse
    {
        try {
            $this->service->kembalikanKeMenunggu($tahan);
        } catch (AturanBisnisException $e) {
            return back()->with('galat', $e->getMessage());
        }

        LogAktivitas::catat('presensi_luar.kembalikan', "Keputusan presensi luar kantor #{$tahan->id} ({$tahan->nik}) dikembalikan ke Menunggu");

        return back()->with('sukses', 'Keputusan dibatalkan; presensi kembali menunggu.');
    }

    public function ketahui(PresensiTahan $tahan): RedirectResponse
    {
        try {
            $this->service->tandaiDiketahui($tahan, Auth::guard('user')->user());
        } catch (AturanBisnisException $e) {
            return back()->with('galat', $e->getMessage());
        }

        LogAktivitas::catat('presensi_luar.diketahui', "Presensi luar kantor {$tahan->nik} #{$tahan->id} diketahui HR");

        return back()->with('sukses', 'Ditandai sudah diketahui.');
    }
}
