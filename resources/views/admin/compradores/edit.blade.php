@extends('adminlte::page')

@section('title', 'Editar Comprador')

@section('content_header')
    <h1>Editar Comprador</h1>
@stop

@section('content')
    <form action="{{ route('admin.compradores.update', $compradore->buyer_id) }}" method="POST">
        @csrf
        @method('PUT')

        <h4 class="mt-3">Datos del Usuario</h4>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Nombre</label>
                    <input type="text" name="user_name" value="{{ $compradore->user->name }}" class="form-control">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="user_email" value="{{ $compradore->user->email }}" class="form-control">
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="text" name="user_phone" value="{{ $compradore->user->phone }}" class="form-control">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label>Dirección</label>
                    <input type="text" name="user_address" value="{{ $compradore->user->address }}" class="form-control">
                </div>
            </div>
        </div>

        <h4 class="mt-4">Datos del Comprador</h4>
        <div class="form-group">
            <label>Calificación</label>
            <input type="number" name="qualification" value="{{ $compradore->qualification }}" class="form-control">
        </div>

        <div class="form-group">
            <label>Estado</label>
            <input type="text" name="state" value="{{ $compradore->state }}" class="form-control">
        </div>

        <div class="form-group">
            <label>Pertenece a complejo</label>
            <select name="belongs_to_complex" class="form-control">
                <option value="1" @if($compradore->belongs_to_complex) selected @endif>Sí</option>
                <option value="0" @if(!$compradore->belongs_to_complex) selected @endif>No</option>
            </select>
        </div>

        <button type="submit" class="btn btn-success mt-3">Actualizar</button>
    </form>
@stop
