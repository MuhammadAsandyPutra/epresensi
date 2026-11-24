@extends('layouts.admin')

@php $edit = $pengguna->exists; @endphp
@section('judul', $edit ? 'Ubah Akun Panel' : 'Tambah Akun Panel')

@section('content')
<form method="POST" class="card" style="max-width:560px" action="{{ $edit ? route('panel.pengguna.update', $pengguna) : route('panel.pengguna.store') }}" autocomplete="off">
    @csrf @if ($edit) @method('PUT') @endif
    <div class="card-body">
        <div class="mb-3"><label class="form-label required">Nama</label><input type="text" name="name" class="form-control" value="{{ old('name', $pengguna->name) }}" maxlength="100" required></div>
        <div class="mb-3"><label class="form-label required">Email (untuk login)</label><input type="email" name="email" class="form-control" value="{{ old('email', $pengguna->email) }}" maxlength="150" required></div>
        <div class="mb-3"><label class="form-label required">Role</label>
            <select name="role" class="form-select" required>
                @foreach (\App\Models\User::ROLE_LABEL as $nilai => $label)
                    <option value="{{ $nilai }}" @selected(old('role', $pengguna->role) === $nilai)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="form-hint">Direksi memutuskan pengajuan &amp; lembur. HR menerima pemberitahuan dan mengelola data karyawan. Admin mengelola sistem.</div>
        </div>
        <div class="mb-3"><label class="form-label {{ $edit ? '' : 'required' }}">Kata sandi{{ $edit ? ' baru' : '' }}</label>
            <input type="password" name="password" class="form-control" minlength="8" maxlength="100" autocomplete="new-password" {{ $edit ? '' : 'required' }}>
            <div class="form-hint">{{ $edit ? 'Kosongkan bila tidak ingin mengganti.' : 'Minimal 8 karakter.' }}</div>
        </div>
    </div>
    <div class="card-footer text-end"><a href="{{ route('panel.pengguna.index') }}" class="btn btn-link">Batal</a><button class="btn btn-primary">Simpan</button></div>
</form>
@endsection
