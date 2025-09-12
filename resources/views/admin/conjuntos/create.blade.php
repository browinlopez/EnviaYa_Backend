@extends('adminlte::page')

@section('title', 'Crear Conjunto Residencial')

@section('content_header')
<h1>Crear Conjunto Residencial</h1>
@stop

@section('content')
<form action="{{ route('admin.conjuntos.store') }}" method="POST">
    @csrf
    <div class="form-group">
        <label>Nombre</label>
        <input type="text" name="name" class="form-control" required>
    </div>
    <div class="form-group">
        <label>Dirección</label>
        <input type="text" name="address" class="form-control">
    </div>
    <div class="form-group">
        <label>Estado</label>
        <select name="state" class="form-control">
            <option value="1">Activo</option>
            <option value="0">Inactivo</option>
        </select>
    </div>
    <div class="form-group">
        <label>Personas</label>
        <input type="number" name="people_count" class="form-control">
    </div>
    <button type="submit" class="btn btn-success mt-3">Crear</button>
</form>
@stop
