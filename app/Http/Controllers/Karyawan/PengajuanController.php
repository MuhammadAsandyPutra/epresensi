<?php

namespace App\Http\Controllers\Karyawan;

use App\Enums\JenisPengajuan;
use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Karyawan\PengajuanRequest;
use App\Models\PengajuanIzin;
use App\Services\PengajuanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PengajuanController extends Controller
{
    public function __construct(private PengajuanService $service) {}

    public function index(): View
    {
        $karyawan = Auth::guard('karyawan')->user();

        return view('karyawan.pengajuan.index', [
            'daftar' => PengajuanIzin::query()->where('nik', $karyawan->nik)->latest('tgl_izin')->latest('id')->paginate(10),
        ]);
    }

    public function create(): View
    {
        $karyawan = Auth::guard('karyawan')->user();

        $tahun = now()->year;

        return view('karyawan.pengajuan.create', [
            'jenis' => JenisPengajuan::cases(),
            'tahun' => $tahun,
            'rincian' => $this->service->rincianCuti($karyawan, $tahun),
            'rincianDepan' => $this->service->rincianCuti($karyawan, $tahun + 1),
        ]);
    }

    public function store(PengajuanRequest $request): RedirectResponse
    {
        try {
            $p = $this->service->ajukan(Auth::guard('karyawan')->user(), $request->validated(), $request->file('lampiran'));
        } catch (AturanBisnisException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        $pesan = 'Pengajuan terkirim ke Direksi dan menunggu persetujuan.';
        if ($p->potong_cuti && $p->jenis() !== JenisPengajuan::Cuti) {
            $pesan .= " Karena tanpa surat/keterangan, {$p->jumlah_hari} hari ini memotong cuti tahunan Anda.";
        }
        if ($p->hari_pinjam > 0) {
            $pesan .= " {$p->hari_pinjam} hari dipotong dari jatah cuti ".(Carbon::parse($p->tgl_izin)->year + 1).'.';
        }

        return redirect()->route('izin.index')->with('sukses', $pesan);
    }

    public function batal(PengajuanIzin $pengajuan): RedirectResponse
    {
        try {
            $this->service->batal(Auth::guard('karyawan')->user(), $pengajuan);
        } catch (AturanBisnisException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengajuan dibatalkan.');
    }
}
