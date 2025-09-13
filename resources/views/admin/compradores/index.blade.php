@extends('adminlte::page')

@section('title', 'Compradores')

@section('content_header')
    <h1 class="mb-3">Compradores</h1>
@stop

@section('content')
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Lista de Compradores</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered" id="buyers-table">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Correo</th>
                            <th>Usuario</th>
                            <th>Teléfono</th>
                            <th>Dirección</th>
                            <th>Calificación</th>
                            <th>Estado</th>
                            <th>Pertenece a complejo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buyers as $buyer)
                            <tr>
                                <td>{{ $buyer->buyer_id }}</td>
                                <td>{{ $buyer->user->email ?? 'Sin correo' }}</td>
                                <td>{{ $buyer->user->name ?? 'Sin usuario' }}</td>
                                <td>{{ $buyer->user->phone ?? 'Sin teléfono' }}</td>
                                <td>{{ $buyer->user->address ?? 'Sin dirección' }}</td>
                                <td>{{ $buyer->qualification }}</td>
                                <td>
                                    <span class="badge {{ $buyer->state ? 'bg-success' : 'bg-secondary' }}">
                                        {{ $buyer->state ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($buyer->belongs_to_complex && $buyer->residentialComplexes->isNotEmpty())
                                        @php
                                            $names = $buyer->residentialComplexes->pluck('name')->join(', ');
                                        @endphp
                                        <span class="badge bg-info">
                                            Sí, pertenece al {{ $names }}
                                        </span>
                                    @else
                                        <span class="badge bg-light text-dark">No</span>
                                    @endif
                                </td>

                                <td>
                                    <a href="{{ route('admin.compradores.edit', $buyer->buyer_id) }}"
                                        class="btn btn-sm btn-warning me-1">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    <form action="{{ route('admin.compradores.destroy', $buyer->buyer_id) }}"
                                        method="POST" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('¿Eliminar?')">
                                            <i class="fas fa-trash"></i> Eliminar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@stop

@section('adminlte_js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#buyers-table').DataTable({
                dom: 'Bfrtip',
                buttons: [{
                        extend: 'excelHtml5',
                        className: 'btn btn-success btn-sm me-1',
                        text: '<i class="fas fa-file-excel"></i> Excel'
                    },
                    {
                        extend: 'csvHtml5',
                        className: 'btn btn-info btn-sm',
                        text: '<i class="fas fa-file-csv"></i> CSV'
                    }
                ],
                order: [
                    [0, 'asc']
                ],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Buscar...",
                    lengthMenu: "Mostrar _MENU_ registros",
                    info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
                    infoEmpty: "No hay registros",
                    zeroRecords: "No se encontraron coincidencias",
                    paginate: {
                        first: "Primero",
                        last: "Último",
                        next: "Siguiente",
                        previous: "Anterior"
                    }
                }
            });
        });
    </script>
@stop
