<?php

namespace App\Providers;

use App\Models\PengajuanIzin;
use App\Models\Presensi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Di produksi semua URL (route, asset, foto) dibuat https agar tidak diblokir sebagai mixed content.
        if (config('app.env') === 'production' || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Batasi percobaan login: 5x per menit per (IP + NIK/email).
        RateLimiter::for('login', fn(Request $request) => Limit::perMinute(5)
            ->by($request->ip() . '|' . strtolower((string) ($request->input('nik') ?? $request->input('email')))));

        // Lencana pada menu sidebar panel. Direksi: yang menunggu keputusannya.
        // HR/Admin: yang belum ditandai "diketahui". (Kolom baru dicek dulu agar halaman tidak error
        // pada saat deploy sebelum `php artisan migrate` dijalankan.)
        View::composer('layouts.admin', function ($view) {
            $user = Auth::guard('user')->user();
            $pengajuan = 0;
            $lembur = 0;
            $pulangCepat = 0;

            if ($user && Schema::hasTable('pengajuan_izin')) {
                $baru = Schema::hasColumn('presensi', 'lembur_status');
                $barePc = Schema::hasColumn('presensi', 'pulang_cepat_status');

                if ($user->isDireksi()) {
                    $pengajuan = PengajuanIzin::query()->where('status_approved', 0)->count();
                    $lembur = $baru ? Presensi::query()->where('lembur_status', 0)->count() : 0;
                    $pulangCepat = $barePc ? Presensi::query()->where('pulang_cepat_status', 0)->count() : 0;
                } elseif ($baru) {
                    $pengajuan = PengajuanIzin::query()->whereNull('diketahui_hr_pada')->where('status_approved', '!=', 3)->count();
                    $lembur = Presensi::query()->whereNotNull('lembur_status')->whereNull('lembur_diketahui_pada')->count();
                    $pulangCepat = $barePc ? Presensi::query()->whereNotNull('pulang_cepat_status')->whereNull('pulang_cepat_diketahui_pada')->count() : 0;
                } else {
                    $pengajuan = PengajuanIzin::query()->where('status_approved', 0)->count();
                }
            }

            $view->with(['badgePengajuan' => $pengajuan, 'badgeLembur' => $lembur, 'badgePulangCepat' => $pulangCepat, 'jumlahMenunggu' => $pengajuan]);
        });
    }
}
