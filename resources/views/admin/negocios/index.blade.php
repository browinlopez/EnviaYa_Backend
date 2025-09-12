@extends('adminlte::page')

@section('title','Negocios')

@section('content_header')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="m-0">Negocios</h1>
    <a href="{{ route('admin.negocios.create') }}" 
       class="btn btn-lg btn-primary shadow-sm"
       style="transition: transform 0.2s, background-color 0.2s;"
       onmouseover="this.style.backgroundColor='#0062cc'; this.style.transform='scale(1.05)';"
       onmouseout="this.style.backgroundColor='#0d6efd'; this.style.transform='scale(1)';">
        <i class="fas fa-plus me-1"></i> Nuevo Negocio
    </a>
</div>
@stop


@section('content')
<div class="card shadow-sm">
     <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Lista de Negocios</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered align-middle" id="business-table">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>NIT</th>
                        <th>Teléfono</th>
                        <th>Categoría</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($businesses as $business)
                    <tr>
                        <td>{{ $business->busines_id }}</td>
                        <td>{{ $business->name }}</td>
                        <td>{{ $business->NIT }}</td>
                        <td>{{ $business->phone }}</td>
                        <td>{{ $business->category->name ?? 'Sin categoría' }}</td>
                        <td>
                            <span class="badge {{ $business->state ? 'bg-success' : 'bg-secondary' }}">
                                {{ $business->state ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('admin.negocios.edit',$business->busines_id) }}" class="btn btn-sm btn-warning me-1">
                                <i class="fas fa-edit"></i> Editar
                            </a>
                            <form action="{{ route('admin.negocios.destroy',$business->busines_id) }}" method="POST" style="display:inline-block">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este negocio?')">
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
    $('#business-table').DataTable({
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
