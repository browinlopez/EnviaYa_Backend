@extends('adminlte::page')

@section('title', 'Owners')

@section('content_header')
    <div class="owners-header">
        <div class="owners-title">
            <span class="owners-badge">
                <i class="fas fa-users"></i>
            </span>
            <div>
                <h1>Owners</h1>
                <p>Gestión de propietarios y sus negocios</p>
            </div>
        </div>

        <div class="owners-actions">
            <a href="{{ route('admin.owners.create') }}" class="btn-create-owner">
                <i class="fas fa-plus"></i>
                <span>Nuevo Owner</span>
            </a>
        </div>
    </div>
@stop

@section('content')

    {{-- SKELETON --}}
    <div id="skeleton">
        @for ($i = 0; $i < 6; $i++)
            <div class="skeleton-row"></div>
        @endfor
    </div>

    {{-- CARD --}}
    <div id="ownersApp" class="modern-card d-none">

        {{-- SEARCH + EXPORT --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Buscar owner..." onkeyup="filterOwners(this.value)">
            </div>

            <button class="btn-export" onclick="exportExcel()">
                <i class="fas fa-file-excel"></i> Exportar
            </button>
        </div>

        {{-- TABLE --}}
        <table class="modern-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Avatar</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Documento</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>

            <tbody id="ownersTable">
                @foreach ($owners as $o)
                    <tr class="owner-row">
                        <td>{{ $o->owner_id }}</td>

                        <td>
                            <img src="{{ $o->profile_photo_url }}" class="avatar">
                        </td>

                        <td>{{ $o->user->name }}</td>
                        <td>{{ $o->user->email }}</td>

                        <td>{{ $o->document_type }} - {{ $o->document_number }}</td>

                        <td>
                            <span class="{{ $o->state ? 'badge-active' : 'badge-inactive' }}">
                                {{ $o->state ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>

                        <td class="text-center">
                            <button class="action-btn" data-bs-toggle="modal"
                                data-bs-target="#businessModal{{ $o->owner_id }}">
                                <i class="fas fa-store"></i>
                            </button>

                            <a href="{{ route('admin.owners.edit', $o->owner_id) }}" class="action-btn">
                                <i class="fas fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- PAGINATION --}}
        <div class="d-flex justify-content-center mt-4 gap-2" id="pagination"></div>
    </div>

    {{-- MODALS --}}
    @foreach ($owners as $o)
        @include('admin.owners.partials.business-modal', [
            'owner' => $o,
            'businesses' => $businesses,
        ])
    @endforeach

@stop

@section('adminlte_js')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <script>
        /* INIT */
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                document.getElementById('skeleton').remove();
                document.getElementById('ownersApp').classList.remove('d-none');
                paginate();
            }, 600);
        });

        /* SEARCH */
        function filterOwners(value) {
            value = value.toLowerCase();
            document.querySelectorAll('.owner-row').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none';
            });
        }

        /* PAGINATION */
        const rowsPerPage = 6;
        let currentPage = 1;

        function paginate() {
            const rows = Array.from(document.querySelectorAll('.owner-row'));
            const pages = Math.ceil(rows.length / rowsPerPage);

            rows.forEach((row, i) => {
                row.style.display =
                    i >= (currentPage - 1) * rowsPerPage &&
                    i < currentPage * rowsPerPage ? '' : 'none';
            });

            const pagination = document.getElementById('pagination');
            pagination.innerHTML = '';

            for (let i = 1; i <= pages; i++) {
                const btn = document.createElement('button');
                btn.className = 'pagination-btn ' + (i === currentPage ? 'active' : '');
                btn.innerText = i;
                btn.onclick = () => {
                    currentPage = i;
                    paginate();
                };
                pagination.appendChild(btn);
            }
        }

        /* EXPORT EXCEL */
        function exportExcel() {
            const rows = document.querySelectorAll('.owner-row');
            const data = [];

            data.push(['ID', 'Usuario', 'Email', 'Documento', 'Estado']);

            rows.forEach(row => {
                if (row.style.display === 'none') return;

                const cols = row.querySelectorAll('td');

                data.push([
                    cols[0].innerText.trim(),
                    cols[2].innerText.trim(),
                    cols[3].innerText.trim(),
                    cols[4].innerText.trim(),
                    cols[5].innerText.trim()
                ]);
            });

            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();

            XLSX.utils.book_append_sheet(wb, ws, 'Owners');
            XLSX.writeFile(wb, 'owners.xlsx');
        }

        /* SAVE BUSINESSES */
        function saveBusinesses(ownerId) {
            const form = document.getElementById(`business-form-${ownerId}`);
            const data = new FormData(form);

            fetch(`/admin/owners/${ownerId}/businesses`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: data
            }).then(() => {
                bootstrap.Modal.getInstance(
                    document.getElementById(`businessModal${ownerId}`)
                ).hide();
            });
        }
    </script>
@stop

@section('css')
<link rel="stylesheet" href="{{ asset('css/dashboardIndex.css') }}">
@stop
