@extends('adminlte::page')

@section('title', 'Crear Comprador')

@section('content_header')
    <h1>Crear Comprador</h1>
@stop

@section('content')
    <form action="{{ route('compradores.store') }}" method="POST">
        @csrf

        <div class="form-group">
            <label>Usuario</label>
            <select name="user_id" class="form-control">
                @foreach($users as $user)
                    <option value="{{ $user->user_id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- <div class="form-group">
            <label>Calificación</label>
            <input type="number" name="qualification" class="form-control">
        </div> --}}

        {{-- <div class="form-group">
            <label>Estado</label>
            <input type="text" name="state" class="form-control">
        </div> --}}

        <div class="form-group">
            <label>Pertenece a complejo</label>
            <select name="belongs_to_complex" class="form-control">
                <option value="1">Sí</option>
                <option value="0">No</option>
            </select>
        </div>

        <button type="submit" class="btn btn-success">Guardar</button>
    </form>
@stop
