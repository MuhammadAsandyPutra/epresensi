<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2: alur persetujuan Direksi, "diketahui HR", tugas luar, dan lembur.
 * Aman dijalankan ulang (setiap kolom dicek dulu) dan tidak menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_izin', function (Blueprint $t) {
            if (! Schema::hasColumn('pengajuan_izin', 'lokasi_tugas')) {
                $t->string('lokasi_tugas', 150)->nullable();           // tujuan/lokasi tugas luar
            }
            if (! Schema::hasColumn('pengajuan_izin', 'diketahui_hr_oleh')) {
                $t->unsignedBigInteger('diketahui_hr_oleh')->nullable();
            }
            if (! Schema::hasColumn('pengajuan_izin', 'diketahui_hr_pada')) {
                $t->timestamp('diketahui_hr_pada')->nullable();
            }
        });

        Schema::table('presensi', function (Blueprint $t) {
            if (! Schema::hasColumn('presensi', 'tugas_luar')) {
                $t->boolean('tugas_luar')->default(false);              // presensi dibuat saat tugas luar disetujui
            }
            if (! Schema::hasColumn('presensi', 'lembur_menit')) {
                $t->unsignedSmallInteger('lembur_menit')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'keterangan_lembur')) {
                $t->string('keterangan_lembur', 500)->nullable();
            }
            if (! Schema::hasColumn('presensi', 'lembur_status')) {
                $t->unsignedTinyInteger('lembur_status')->nullable();   // null = tidak lembur; 0/1/2 = StatusPengajuan
            }
            if (! Schema::hasColumn('presensi', 'lembur_catatan')) {
                $t->string('lembur_catatan', 255)->nullable();
            }
            if (! Schema::hasColumn('presensi', 'lembur_diputuskan_oleh')) {
                $t->unsignedBigInteger('lembur_diputuskan_oleh')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'lembur_diputuskan_pada')) {
                $t->timestamp('lembur_diputuskan_pada')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'lembur_diketahui_oleh')) {
                $t->unsignedBigInteger('lembur_diketahui_oleh')->nullable();
            }
            if (! Schema::hasColumn('presensi', 'lembur_diketahui_pada')) {
                $t->timestamp('lembur_diketahui_pada')->nullable();
            }
        });

        // Pengajuan lama dianggap sudah diketahui HR (dulu HR sendiri yang memutuskan),
        // supaya lencana "belum diketahui" tidak banjir saat pertama kali dipasang.
        DB::table('pengajuan_izin')->whereNull('diketahui_hr_pada')->update(['diketahui_hr_pada' => now()]);
    }

    public function down(): void
    {
        // Sengaja kosong (lihat catatan pada migrasi tabel master).
    }
};
