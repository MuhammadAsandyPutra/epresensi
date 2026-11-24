<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KeputusanRequest;
use App\Models\LogAktivitas;
use App\Models\Presensi;
use App\Services\LemburService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Daftar lembur: Direksi memutuskan, HR/Admin menandai "diketahui". */
class LemburController extends Controller
{
    public function __construct(private LemburService $service) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer', 'in:0,1,2'],
            'diketahui' => ['nullable', 'in:0,1'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        $daftar = Presensi::query()->with(['karyawan.departemen', 'pemutusLembur'])
            ->whereNotNull('lembur_status')
            ->when($f['cari'] ?? null, fn ($q, $c) => $q->whereHas('karyawan', fn ($k) => $k->where('nama_lengkap', 'like', "%{$c}%")->orWhere('nik', 'like', "%{$c}%")))
            ->when(isset($f['status']), fn ($q) => $q->where('lembur_status', $f['status']))
            ->when(isset($f['diketahui']), fn ($q) => $f['diketahui'] === '1'
                ? $q->whereNotNull('lembur_diketahui_pada')
                : $q->whereNull('lembur_diketahui_pada'))
            ->when($f['dari'] ?? null, fn ($q, $d) => $q->where('tanggal_presensi', '>=', $d))
            ->when($f['sampai'] ?? null, fn ($q, $s) => $q->where('tanggal_presensi', '<=', $s))
            // Yang menunggu tampil paling atas.
            ->orderByRaw('CASE WHEN lembur_status = 0 THEN 0 ELSE 1 END')
            ->orderByDesc('tanggal_presensi')->orderByDesc('id')
            ->paginate(15)->withQueryString();

        return view('admin.lembur.index', [
            'daftar' => $daftar,
            'filter' => $f,
            'statusList' => [StatusPengajuan::Menunggu, StatusPengajuan::Disetujui, StatusPengajuan::Ditolak],
        ]);
    }

    public function putuskan(KeputusanRequest $request, Presensi $presensi): RedirectResponse
    {
        $setuju = $request->input('keputusan') === 'setuju';

        try {
            $this->service->putuskan(
                $presensi,
                $setuju ? StatusPengajuan::Disetujui : StatusPengajuan::Ditolak,
                Auth::guard('user')->user(),
                $request->input('catatan'),
            );
        } catch (AturanBisnisException $e) {
            return back()->with('galat', $e->getMessage());
        }

        LogAktivitas::catat($setuju ? 'lembur.setuju' : 'lembur.tolak', "Lembur {$presensi->nik} #{$presensi->id}");

        return back()->with('sukses', 'Lembur '.($setuju ? 'disetujui.' : 'ditolak.'));
    }

    public function kembalikan(Presensi $presensi): RedirectResponse
    {
        try {
            $this->service->kembalikanKeMenunggu($presensi);
        } catch (AturanBisnisException $e) {
            return back()->with('galat', $e->getMessage());
        }

        LogAktivitas::catat('lembur.kembalikan', "Keputusan lembur #{$presensi->id} ({$presensi->nik}) dikembalikan ke Menunggu");

        return back()->with('sukses', 'Keputusan dibatalkan; lembur kembali menunggu.');
    }

    public function ketahui(Presensi $presensi): RedirectResponse
    {
        try {
            $this->service->tandaiDiketahui($presensi, Auth::guard('user')->user());
        } catch (AturanBisnisException $e) {
            return back()->with('galat', $e->getMessage());
        }

        LogAktivitas::catat('lembur.diketahui', "Lembur {$presensi->nik} #{$presensi->id} diketahui HR");

        return back()->with('sukses', 'Ditandai sudah diketahui.');
    }
}
