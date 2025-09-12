@extends('adminlte::page')

@section('title', 'Productos')

@section('content_header')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="m-0">Productos</h1>
    <a href="{{ route('admin.products.create') }}" 
       class="btn btn-lg btn-primary shadow-sm"
       style="transition: transform 0.2s, background-color 0.2s;"
       onmouseover="this.style.backgroundColor='#0062cc'; this.style.transform='scale(1.05)';"
       onmouseout="this.style.backgroundColor='#0d6efd'; this.style.transform='scale(1)';">
        <i class="fas fa-plus me-1"></i> Nuevo Producto
    </a>
</div>
@stop

@section('content')
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Lista de Productos</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="products-table" class="table table-hover table-striped table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Categoría</th>
                        <th>Negocio</th>
                        <th>Precio</th>
                        <th>Cantidad</th>
                        <th>Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $p)
                        <tr>
                            <td>{{ $p->products_id }}</td>
                            <td>{{ $p->name }}</td>
                            <td>{{ $p->category_id }}</td>
                            <td>{{ $p->businesses->first()?->name }}</td>
                            <td>{{ $p->productBusinesses->first()?->price }}</td>
                            <td>{{ $p->productBusinesses->first()?->amount }}</td>
                            <td>
                                <span class="badge {{ $p->state ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $p->state ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="{{ route('admin.products.edit', $p->products_id) }}" class="btn btn-sm btn-warning me-1 mb-1">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <form action="{{ route('admin.products.destroy', $p->products_id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger mb-1" onclick="return confirm('¿Eliminar producto?')">
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
    $('#products-table').DataTable({
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
