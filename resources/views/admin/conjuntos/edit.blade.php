@extends('adminlte::page')

@section('title', 'Editar Conjunto Residencial')

@section('content_header')
<h1>Editar Conjunto Residencial</h1>
@stop

@section('content')
<form action="{{ route('admin.conjuntos.update', $complex->complex_id) }}" method="POST">
    @csrf
    @method('PUT')
    <div class="form-group">
        <label>Nombre</label>
        <input type="text" name="name" class="form-control" value="{{ $complex->name }}" required>
    </div>
    <div class="form-group">
        <label>Dirección</label>
        <input type="text" name="address" class="form-control" value="{{ $complex->address }}">
    </div>
    <div class="form-group">
        <label>Estado</label>
        <select name="state" class="form-control">
            <option value="1" @if($complex->state) selected @endif>Activo</option>
            <option value="0" @if(!$complex->state) selected @endif>Inactivo</option>
        </select>
    </div>
    <div class="form-group">
        <label>Personas</label>
        <input type="number" name="people_count" class="form-control" value="{{ $complex->people_count }}">
    </div>
    <button type="submit" class="btn btn-success mt-3">Actualizar</button>
</form>
@stop
