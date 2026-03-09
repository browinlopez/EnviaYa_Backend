@extends('adminlte::page')

@section('title', 'Productos')

@section('content_header')
    <div class="owners-header">
        <div class="owners-title">
            <span class="owners-badge"><i class="fas fa-box"></i></span>
            <div>
                <h1>Productos</h1>
                <p>Gestión y control de productos registrados</p>
            </div>
        </div>

        <a href="{{ route('admin.products.create') }}" class="btn-create-owner">
            <i class="fas fa-plus"></i> Nuevo Producto
        </a>
    </div>
@stop

@section('content')

    <div class="modern-card">

        <div class="mb-3 d-flex gap-3">

            {{-- SEARCH --}}
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Buscar producto..." onkeyup="loadProducts(1)">
            </div>

            {{-- FILTER BUSINESS --}}
            <div class="search-box">
                <i class="fas fa-store"></i>
                <select id="businessFilter" onchange="loadProducts(1)">
                    <option value="">Todos los negocios</option>
                    @foreach (\App\Models\Business::select('busines_id', 'name')->get() as $b)
                        <option value="{{ $b->busines_id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

        </div>

        {{-- LOADER --}}
        <div id="tableLoader" class="text-center my-4 d-none">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
        </div>

        {{-- TABLA --}}
        <table class="modern-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th>Negocio</th>
                    <th>Precio</th>
                    <th>Cantidad</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody id="productsTable"></tbody>
        </table>

        {{-- PAGINACIÓN --}}
        <div id="pagination" class="d-flex justify-content-center mt-4 gap-2"></div>
    </div>
@stop

{{-- ================= JS ================= --}}
@section('adminlte_js')
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let currentPage = 1;

        document.addEventListener('DOMContentLoaded', () => {
            loadProducts();
        });

        document.addEventListener('DOMContentLoaded', () => {
            @if (session('success'))
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: @json(session('success')),
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
            @endif
        });

        function showLoader() {
            document.getElementById('tableLoader').classList.remove('d-none');
        }

        function hideLoader() {
            document.getElementById('tableLoader').classList.add('d-none');
        }

        function loadProducts(page = 1) {
            currentPage = page;

            const business = document.getElementById('businessFilter').value;
            const search = document.getElementById('searchInput').value.trim();

            showLoader();

            const url = `{{ route('admin.product.ajax') }}` +
                `?page=${page}` +
                `&busines_id=${business}` +
                `&search=${encodeURIComponent(search)}`;

            fetch(url, {
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(res => {
                    if (!res.ok) throw new Error('Error HTTP');
                    return res.json();
                })
                .then(res => {
                    renderProducts(res.data);
                    renderPagination(res.current_page, res.last_page);
                })
                .catch(err => {
                    console.error(err);
                    alert('Error cargando productos');
                })
                .finally(() => {
                    hideLoader();
                });
        }

        function renderProducts(products) {
            const tbody = document.getElementById('productsTable');
            tbody.innerHTML = '';

            if (!products.length) {
                tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    No se encontraron productos
                </td>
            </tr>
        `;
                return;
            }

            products.forEach(p => {
                const pb = p.product_businesses?.[0] ?? {};
                const business = p.businesses?.[0]?.name ?? '—';

                tbody.innerHTML += `
        <tr>
            <td>${p.products_id}</td>
            <td><strong>${p.name}</strong></td>
            <td>${p.category?.name ?? 'Sin categoría'}</td>
            <td>${business}</td>
            <td>$${Number(pb.price ?? 0).toLocaleString()}</td>
            <td>${pb.amount ?? 0}</td>
            <td>
                <span class="${p.state ? 'badge-active' : 'badge-inactive'}">
                    ${p.state ? 'Activo' : 'Inactivo'}
                </span>
            </td>
            <td class="text-end">
                <a href="/admin/productos/edit/${p.products_id}"
                   class="action-btn me-1"
                   title="Editar">
                    <i class="fas fa-edit"></i>
                </a>

                <form method="POST"
                    action="/admin/productos/destroy/${p.products_id}"
                    class="d-inline delete-form">
                    @csrf
                    @method('DELETE')
                    <button type="button"
                            class="action-btn text-danger"
                            onclick="confirmDelete(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </td>
        </tr>`;
            });
        }

        function renderPagination(current, last) {
            const container = document.getElementById('pagination');
            container.innerHTML = '';

            for (let i = 1; i <= last; i++) {
                container.innerHTML += `
        <button
            class="pagination-btn ${i === current ? 'active' : ''}"
            onclick="loadProducts(${i})">
            ${i}
        </button>`;
            }
        }

        function confirmDelete(button) {
            const form = button.closest('form');

            Swal.fire({
                title: '¿Eliminar producto?',
                text: 'Esta acción no se puede deshacer',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
    </script>
@stop

{{-- ================= CSS ================= --}}
@section('css')
    <link rel="stylesheet" href="{{ asset('css/dashboardIndex.css') }}">
@stop
