@extends('adminlte::page')

@section('title', 'Crear Conjunto Residencial')

@section('content')
<br>

<div class="owner-create-card">

    {{-- HEADER --}}
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon">
                <i class="fas fa-city"></i>
            </div>
            <div>
                <h1>Nuevo Conjunto Residencial</h1>
                <p>Registrar un nuevo conjunto en el sistema</p>
            </div>
        </div>

        <a href="{{ route('admin.conjuntos.index') }}" class="btn-back">
            <i class="fas fa-arrow-left"></i>
            Volver
        </a>
    </div>

    {{-- FORM --}}
    <form action="{{ route('admin.conjuntos.store') }}" method="POST" id="complex-form">
        @csrf

        <div class="owner-grid">

            {{-- CARD IZQUIERDA --}}
            <div class="owner-card">
                <h3 class="card-title">Información general</h3>
                <div class="card-content">

                    <div class="row-2">
                        <div class="form-group">
                            <label>Nombre *</label>
                            <input type="text" name="name" required value="{{ old('name') }}">
                            <small class="error-msg"></small>
                        </div>

                        <div class="form-group">
                            <label>Dirección</label>
                            <input type="text" name="address" value="{{ old('address') }}">
                            <small class="error-msg"></small>
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state">
                                <option value="1" @selected(old('state')=='1')>Activo</option>
                                <option value="0" @selected(old('state')=='0')>Inactivo</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Personas</label>
                            <input type="number" name="people_count" min="0" value="{{ old('people_count', 0) }}">
                        </div>
                    </div>

                </div>
            </div>

            {{-- CARD DERECHA --}}
            <div class="owner-card">
                <h3 class="card-title">Datos adicionales</h3>
                <div class="card-content">
                    <p class="text-muted">Aquí puedes agregar más campos relacionados al conjunto si los necesitas en el futuro.</p>
                </div>
            </div>

        </div>

        {{-- FOOTER --}}
        <div class="form-footer">
            <button class="btn-save" type="submit">
                <i class="fas fa-save"></i> Crear Conjunto
            </button>
        </div>

    </form>
</div>
@stop

@section('css')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
@stop

@section('js')
<script>
    // Aquí puedes agregar validaciones JS personalizadas si lo necesitas
</script>
@stop