@extends('adminlte::page')

@section('title', 'Editar Comprador')

@section('content_header')
    <h1 class="mb-3">Editar Comprador</h1>
@stop

@section('content')
    <div class="container-fluid">
        <form action="{{ route('admin.compradores.update', $compradore->buyer_id) }}" method="POST">
            @csrf
            @method('PUT')

            {{-- Datos del Usuario --}}
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Datos del Usuario</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nombre</label>
                                <input type="text" name="user_name" value="{{ $compradore->user->name }}"
                                    class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="user_email" value="{{ $compradore->user->email }}"
                                    class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Teléfono</label>
                                <input type="text" name="user_phone" value="{{ $compradore->user->phone }}"
                                    class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dirección</label>
                                <input type="text" name="user_address" value="{{ $compradore->user->address }}"
                                    class="form-control">
                            </div>
                        </div>
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
                                <input type="number" name="qualification" value="{{ $compradore->qualification }}"
                                    class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Estado</label>
                                <input type="text" name="state" value="{{ $compradore->state }}" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Pertenece a complejo</label>
                                <select name="belongs_to_complex" class="form-control" id="belongs_to_complex">
                                    <option value="1" @if ($compradore->belongs_to_complex) selected @endif>Sí</option>
                                    <option value="0" @if (!$compradore->belongs_to_complex) selected @endif>No</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- si quieres que aparezca el select de conjuntos cuando seleccionan "Sí" --}}
                    <div class="form-group" id="complex-container"
                        style="display: {{ $compradore->belongs_to_complex ? 'block' : 'none' }}">
                        <label>Seleccione conjunto</label>
                        <select name="complex_id" class="form-control">
                            <option value="">-- Seleccione --</option>
                            @foreach ($complexes as $complex)
                                <option value="{{ $complex->complex_id }}" {{-- si quieres marcarlo seleccionado --}}
                                    @if ($compradore->residentialComplexes->contains($complex->complex_id)) selected @endif>
                                    {{ $complex->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                </div>
            </div>

            <button type="submit" class="btn btn-success btn-lg px-4">Actualizar</button>
        </form>
    </div>

    <script>
        document.getElementById('belongs_to_complex').addEventListener('change', function() {
            document.getElementById('complex-container').style.display = this.value == '1' ? 'block' : 'none';
        });
    </script>
@stop
