@extends('adminlte::page')

@section('title', 'Editar Conjunto Residencial')

@section('content_header')
<h1>Editar Conjunto Residencial</h1>
@stop

@section('content')
<form action="{{ route('admin.conjuntos.update', $complex->complex_id) }}" method="POST">
    @csrf
    @method('PUT')

    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-edit"></i> Editar Datos del Conjunto
            </h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="{{ $complex->name }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Dirección</label>
                    <input type="text" name="address" class="form-control" value="{{ $complex->address }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Estado</label>
                    <select name="state" class="form-control">
                        <option value="1" @if($complex->state) selected @endif>Activo</option>
                        <option value="0" @if(!$complex->state) selected @endif>Inactivo</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Personas</label>
                    <input type="number" name="people_count" class="form-control" value="{{ $complex->people_count }}" min="0">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mt-3">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Actualizar
        </button>
    </div>
</form>
@stop
