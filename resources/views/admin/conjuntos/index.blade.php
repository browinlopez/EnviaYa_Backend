@extends('adminlte::page')

@section('title', 'Conjuntos Residenciales')

@section('content_header')
<div class="owners-header">
    <div class="owners-title">
        <span class="owners-badge"><i class="fas fa-building"></i></span>
        <div>
            <h1>Conjuntos Residenciales</h1>
            <p>Gestión y control de los conjuntos registrados</p>
        </div>
    </div>

    <a href="{{ route('admin.conjuntos.create') }}" class="btn-create-owner">
        <i class="fas fa-plus"></i> Nuevo Conjunto
    </a>
</div>
@stop

@section('content')
<div class="modern-card">

    <div class="mb-3 d-flex gap-3">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Buscar conjunto..." onkeyup="loadComplexes(1)">
        </div>
        <div class="search-box">
            <i class="fas fa-toggle-on"></i>
            <select id="stateFilter" onchange="loadComplexes(1)">
                <option value="">Todos los estados</option>
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
            </select>
        </div>
    </div>

    <div id="tableLoader" class="text-center my-4 d-none">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Cargando...</span>
        </div>
    </div>

    <table class="modern-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Dirección</th>
                <th>Estado</th>
                <th>Personas</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody id="complexesTable"></tbody>
    </table>

    <div id="pagination" class="d-flex justify-content-center mt-4 gap-2"></div>
</div>
@stop

@section('adminlte_js')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let currentPage = 1;

document.addEventListener('DOMContentLoaded', () => {
    loadComplexes();
    @if(session('success'))
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: @json(session('success')),
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    @endif
});

function showLoader(){ document.getElementById('tableLoader').classList.remove('d-none'); }
function hideLoader(){ document.getElementById('tableLoader').classList.add('d-none'); }

function loadComplexes(page = 1){
    currentPage = page;
    const search = document.getElementById('searchInput').value.trim();
    const state = document.getElementById('stateFilter').value;

    showLoader();

    const url = `{{ route('admin.conjuntos.index') }}?page=${page}&search=${encodeURIComponent(search)}&state=${state}`;

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(res => res.json())
        .then(res => {
            renderComplexes(res.data);
            renderPagination(res.current_page,res.last_page);
        })
        .catch(err => { console.error(err); alert('Error cargando conjuntos'); })
        .finally(()=>hideLoader());
}

function renderComplexes(complexes){
    const tbody = document.getElementById('complexesTable');
    tbody.innerHTML = '';

    if(!complexes.length){
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No se encontraron conjuntos</td></tr>`;
        return;
    }

    complexes.forEach(c=>{
        tbody.innerHTML += `
        <tr>
            <td>${c.complex_id}</td>
            <td><strong>${c.name}</strong></td>
            <td>${c.address ?? '—'}</td>
            <td><span class="${c.state?'badge-active':'badge-inactive'}">${c.state?'Activo':'Inactivo'}</span></td>
            <td>${c.people_count}</td>
            <td class="text-end">
                <a href="/admin/conjuntos/${c.complex_id}/edit" class="action-btn me-1"><i class="fas fa-edit"></i></a>
                <form method="POST" action="/admin/conjuntos/${c.complex_id}" class="d-inline delete-form">
                    @csrf
                    @method('DELETE')
                    <button type="button" class="action-btn text-danger" onclick="confirmDelete(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </td>
        </tr>`;
    });
}

function renderPagination(current,last){
    const container = document.getElementById('pagination');
    container.innerHTML = '';
    for(let i=1;i<=last;i++){
        container.innerHTML += `<button class="pagination-btn ${i===current?'active':''}" onclick="loadComplexes(${i})">${i}</button>`;
    }
}

function confirmDelete(button){
    const form = button.closest('form');
    Swal.fire({
        title:'¿Eliminar conjunto?',
        text:'Esta acción no se puede deshacer',
        icon:'warning',
        showCancelButton:true,
        confirmButtonColor:'#d33',
        cancelButtonColor:'#6c757d',
        confirmButtonText:'Sí, eliminar',
        cancelButtonText:'Cancelar'
    }).then(result=>{ if(result.isConfirmed) form.submit(); });
}
</script>
@stop

@section('css')
<link rel="stylesheet" href="{{ asset('css/dashboardIndex.css') }}">
@stop