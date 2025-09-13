@extends('adminlte::page')

@section('title', 'Crear Comprador')

@section('content_header')
    <h1 class="mb-3">Crear Comprador</h1>
@stop

@section('content')
<div class="container-fluid">
    <form action="{{ route('admin.compradores.store') }}" method="POST">
        @csrf

        {{-- Datos del Usuario --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Datos del Usuario</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Seleccione Usuario</label>
                            <select name="user_id" class="form-control" required>
                                <option value="">-- Seleccione --</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->user_id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    {{-- si quieres crear el usuario desde aquí agrega inputs de name/email/phone/address --}}
                </div>
            </div>
        </div>

        {{-- Datos del Comprador --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white">
                <h4 class="mb-0">Datos del Comprador</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Calificación</label>
                            <input type="number" name="qualification" class="form-control" value="{{ old('qualification') }}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Estado</label>
                            <input type="text" name="state" class="form-control" value="{{ old('state') }}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Pertenece a complejo</label>
                            <select name="belongs_to_complex" class="form-control" id="belongs_to_complex">
                                <option value="1">Sí</option>
                                <option value="0" selected>No</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group" id="complex-container" style="display:none">
                    <label>Seleccione conjunto</label>
                    <select name="complex_id" class="form-control">
                        <option value="">-- Seleccione --</option>
                        @foreach($complexes as $complex)
                            <option value="{{ $complex->id }}">{{ $complex->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success btn-lg px-4">Guardar</button>
    </form>
</div>

<script>
    document.getElementById('belongs_to_complex').addEventListener('change', function(){
        document.getElementById('complex-container').style.display = this.value == '1' ? 'block' : 'none';
    });
</script>
@stop
