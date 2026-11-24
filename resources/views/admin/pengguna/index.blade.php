@extends('layouts.admin')

@section('judul', 'Akun Panel')
@section('aksi')<a href="{{ route('panel.pengguna.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Tambah Akun</a>@endsection

@section('content')
<div class="alert alert-info">
    <strong>Admin</strong> mengelola sistem · <strong>HR</strong> mengelola data karyawan dan menerima pemberitahuan (diketahui) ·
    <strong>Direksi</strong> menyetujui izin, sakit, cuti, tugas luar, dan lembur.
</div>
<div class="card"><div class="table-responsive">
    <table class="table card-table table-vcenter">
        <thead><tr><th>Nama</th><th>Email</th><th>Role</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        @forelse ($daftar as $u)
            <tr>
                <td>{{ $u->name }}@if ($u->is(auth('user')->user())) <span class="badge bg-blue-lt">Anda</span>@endif</td>
                <td>{{ $u->email }}</td>
                <td><span class="badge bg-{{ $u->isDireksi() ? 'purple' : ($u->isAdmin() ? 'red' : 'green') }}-lt text-uppercase">{{ \App\Models\User::ROLE_LABEL[$u->role] ?? $u->role }}</span></td>
                <td class="text-end text-nowrap">
                    <a href="{{ route('panel.pengguna.edit', $u) }}" class="btn btn-sm btn-outline-primary"><i class="ti ti-edit"></i></a>
                    <form method="POST" action="{{ route('panel.pengguna.destroy', $u) }}" class="d-inline" onsubmit="return confirm('Hapus akun {{ $u->email }}?')">
                        @csrf @method('DELETE') <button class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-secondary py-4">Belum ada akun.</td></tr>
        @endforelse
        </tbody>
    </table>
</div></div>
@endsection
