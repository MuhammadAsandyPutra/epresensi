<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi dari luar kantor (mis. menagih angsuran ke pasar/toko nasabah): hanya untuk karyawan yang
 * ditandai HR. Presensinya DITAHAN di tabel `presensi_tahan` dan baru masuk ke `presensi` (dengan jam
 * asli) setelah Direksi menyetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawan', function (Blueprint $t) {
            if (! Schema::hasColumn('karyawan', 'boleh_presensi_luar')) {
                $t->boolean('boleh_presensi_luar')->default(false);
            }
        });

        Schema::table('presensi', function (Blueprint $t) {
            if (! Schema::hasColumn('presensi', 'luar_kantor')) {
                $t->boolean('luar_kantor')->default(false);   // berasal dari presensi luar kantor yang disetujui
            }
        });

        if (! Schema::hasTable('presensi_tahan')) {
            Schema::create('presensi_tahan', function (Blueprint $t) {
                $t->id();
                $t->string('nik', 20);
                $t->date('tanggal_presensi');
                $t->char('kode_jam_kerja', 4)->nullable();

                $t->time('jam_in')->nullable();
                $t->string('foto_in')->nullable();
                $t->string('lokasi_in', 60)->nullable();
                $t->unsignedInteger('jarak_in')->nullable();
                $t->unsignedSmallInteger('akurasi_in')->nullable();

                $t->time('jam_out')->nullable();
                $t->string('foto_out')->nullable();
                $t->string('lokasi_out', 60)->nullable();
                $t->unsignedInteger('jarak_out')->nullable();

                $t->string('alasan', 500);                          // tujuan di luar kantor
                $t->unsignedSmallInteger('lembur_menit')->nullable();
                $t->string('keterangan_lembur', 500)->nullable();
                $t->unsignedSmallInteger('pulang_cepat_menit')->nullable();
                $t->string('alasan_pulang_cepat', 500)->nullable();
                $t->string('ip_address', 45)->nullable();

                $t->unsignedTinyInteger('status')->default(0);      // 0 menunggu, 1 disetujui, 2 ditolak
                $t->string('catatan', 255)->nullable();
                $t->unsignedBigInteger('diputuskan_oleh')->nullable();
                $t->timestamp('diputuskan_pada')->nullable();
                $t->unsignedBigInteger('diketahui_oleh')->nullable();
                $t->timestamp('diketahui_pada')->nullable();
                $t->timestamps();

                $t->index(['nik', 'tanggal_presensi']);
                $t->index('status');
            });
        }
    }

    public function down(): void
    {
        // Sengaja kosong (lihat catatan pada migrasi tabel master).
    }
};
