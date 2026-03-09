@extends('adminlte::page')

@section('title', 'Editar Conjunto Residencial')

@section('content')

<br>

<div class="owner-create-card">

    {{-- HEADER --}}
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon">
                <i class="fas fa-building"></i>
            </div>
            <div>
                <h1>Editar Conjunto Residencial</h1>
                <p>Actualizar información del conjunto</p>
            </div>
        </div>

        <a href="{{ route('admin.conjuntos.index') }}" class="btn-back">
            <i class="fas fa-arrow-left"></i>
            Volver
        </a>
    </div>

    {{-- FORM --}}
    <form action="{{ route('admin.conjuntos.update', $complex->complex_id) }}"
          method="POST"
          id="complex-form">
        @csrf
        @method('PUT')

        <div class="owner-grid">

            {{-- CARD IZQUIERDA --}}
            <div class="owner-card">
                <h3 class="card-title">Información general</h3>

                <div class="card-content">

                    <div class="row-2">
                        <div class="form-group">
                            <label>Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="name" required value="{{ $complex->name }}">
                        </div>

                        <div class="form-group">
                            <label>Dirección</label>
                            <input type="text" name="address" value="{{ $complex->address }}">
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state">
                                <option value="1" @selected($complex->state)>Activo</option>
                                <option value="0" @selected(!$complex->state)>Inactivo</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Personas</label>
                            <input type="number" name="people_count" min="0" value="{{ $complex->people_count }}">
                        </div>
                    </div>

                </div>
            </div>

            {{-- CARD DERECHA --}}
            <div class="owner-card">
                <h3 class="card-title">Opciones adicionales</h3>
                <div class="card-content">
                    {{-- Aquí puedes agregar campos extra si necesitas, por ejemplo notas, códigos o imágenes --}}
                    <p class="text-muted">No hay datos adicionales por editar.</p>
                </div>
            </div>

        </div>

        {{-- FOOTER --}}
        <div class="form-footer">
            <button class="btn-save" type="submit">
                Actualizar Conjunto
            </button>
        </div>

    </form>
</div>

@stop

@section('css')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
@stop