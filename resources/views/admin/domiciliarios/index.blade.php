@extends('adminlte::page')

@section('title', 'Domiciliarios')

@section('content_header')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="m-0">Domiciliarios</h1>
    <a href="{{ route('admin.domiciliarios.create') }}" 
       class="btn btn-lg btn-primary shadow-sm"
       style="transition: transform 0.2s, background-color 0.2s;"
       onmouseover="this.style.backgroundColor='#0062cc'; this.style.transform='scale(1.05)';"
       onmouseout="this.style.backgroundColor='#0d6efd'; this.style.transform='scale(1)';">
        <i class="fas fa-plus me-1"></i> Nuevo Domiciliario
    </a>
</div>
@stop

@section('content')
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Lista de Domiciliarios</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered align-middle" id="domiciliaries-table">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Documento</th>
                        <th>Disponible</th>
                        <th>Calificación</th>
                        <th>Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($domiciliaries as $dom)
                        <tr>
                            <td>{{ $dom->domiciliary_id }}</td>
                            <td>{{ $dom->user->name ?? 'Sin usuario' }}</td>
                            <td>{{ $dom->document ?? 'Sin documento registrado' }}</td>
                            <td>
                                <span class="badge {{ $dom->available ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $dom->available ? 'Disponible' : 'No disponible' }}
                                </span>
                            </td>
                            <td>{{ $dom->qualification ?? 'N/A' }}</td>
                            <td>
                                <span class="badge {{ $dom->state ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $dom->state ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="{{ route('admin.domiciliarios.edit', $dom->domiciliary_id) }}" class="btn btn-sm btn-warning me-1 mb-1">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <form action="{{ route('admin.domiciliarios.destroy', $dom->domiciliary_id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger mb-1" onclick="return confirm('¿Eliminar?')">
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
    $('#domiciliaries-table').DataTable({
        dom: 'Bfrtip',
        buttons: [
            {
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
        order: [[0, 'asc']],
        responsive: true,
        lengthMenu: [5, 10, 25, 50],
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
