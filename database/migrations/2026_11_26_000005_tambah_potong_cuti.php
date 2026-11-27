<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan cuti baru: izin/sakit tanpa kuota bulanan. Izin/sakit berdokumen (keterangan + surat) tidak
 * memotong cuti; tanpa dokumen memotong jatah cuti tahunan. Kekurangan saldo dipinjam dari tahun berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_izin', function (Blueprint $t) {
            if (! Schema::hasColumn('pengajuan_izin', 'potong_cuti')) {
                $t->boolean('potong_cuti')->default(false);                 // pengajuan ini memotong jatah cuti tahunan
            }
            if (! Schema::hasColumn('pengajuan_izin', 'hari_pinjam')) {
                $t->unsignedSmallInteger('hari_pinjam')->default(0);        // hari yang dipinjam dari jatah tahun berikutnya (saat diajukan)
            }
        });

        // Cuti lama memang memotong jatah. Izin/sakit lama TIDAK dipotong surut: dulu diatur kuota bulanan.
        DB::table('pengajuan_izin')->where('status', 3)->update(['potong_cuti' => true]);
    }

    public function down(): void
    {
        // Sengaja kosong (lihat catatan pada migrasi tabel master).
    }
};
