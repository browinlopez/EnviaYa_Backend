@extends('adminlte::page')

@section('title', 'Editar Owner')

@section('content')

    <br>
    {{-- HEADER --}}
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon">
                <i class="fas fa-user-edit"></i>
            </div>
            <div>
                <h1>Editar Owner</h1>
                <p>Actualizar información del propietario</p>
            </div>
        </div>

        <a href="{{ route('admin.owners.index') }}" class="btn-back">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>

    <form id="ownerEditForm" method="POST" action="{{ route('admin.owners.update', $owner->owner_id) }}"
        enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="owner-grid">

            {{-- CARD IZQUIERDA --}}
            <div class="owner-card">
                <h3 class="card-title">Datos básicos</h3>

                <div class="card-content">

                    <div class="form-group">
                        <label>Usuario</label>
                        <input class="form-control" value="{{ $owner->user->name }} ({{ $owner->user->email }})" disabled>
                    </div>

                    <div class="form-group">
                        <label>Estado</label>
                        <select name="state" class="form-control" required>
                            <option value="">Seleccione</option>
                            <option value="1" {{ $owner->state ? 'selected' : '' }}>Activo</option>
                            <option value="0" {{ !$owner->state ? 'selected' : '' }}>Inactivo</option>
                        </select>
                        <small class="error-text"></small>
                    </div>

                    <div class="form-group">
                        <div class="form-group">
                            <label>Tipo de documento</label>
                            <select name="document_type_id" required>
                                @foreach ($documentTypes as $type)
                                    <option value="{{ $type->id }}"
                                        {{ $owner->document_type_id == $type->id ? 'selected' : '' }}>
                                        {{ $type->name_es }} ({{ $type->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Número de documento</label>
                        <input name="document_number" class="form-control" value="{{ $owner->document_number }}" required>
                        <small class="error-text"></small>
                    </div>

                    <div class="form-group">
                        <label>Fecha de nacimiento</label>
                        <input type="date" name="birthdate" class="form-control" value="{{ $owner->birthdate }}">
                    </div>

                    <div class="form-group">
                        <label>Contacto secundario</label>
                        <input name="contact_secondary" class="form-control" value="{{ $owner->contact_secondary }}">
                    </div>

                </div>
            </div>

            {{-- CARD DERECHA --}}
            <div class="owner-card">
                <h3 class="card-title">Datos extras</h3>

                <div class="card-content">

                    <div class="photo-box">
                        <label>Foto actual</label>
                        <img
                            src="{{ $owner->profile_photo ? asset('owner/' . $owner->profile_photo) : asset('img/default-user.png') }}">
                    </div>

                    <div class="form-group">
                        <label>Nueva foto</label>
                        <input type="file" name="profile_photo" class="form-control" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label>Notas</label>
                        <textarea name="notes" class="form-control" rows="4">{{ $owner->notes }}</textarea>
                    </div>

                </div>
            </div>

        </div>

        {{-- FOOTER --}}
        <div class="form-footer">
            <button type="submit" class="btn-save">
                <i class="fas fa-save"></i> Actualizar
            </button>
        </div>
    </form>

    {{-- TOAST --}}
    @if (session('success'))
        <div id="toastSuccess" class="toast show">
            <i class="fas fa-check-circle me-2"></i>
            {{ session('success') }}
        </div>
    @endif

    <div class="vp-modal-backdrop" id="confirmModal">
        <div class="vp-modal">
            <div class="vp-modal-icon">
                <i class="fas fa-question-circle"></i>
            </div>

            <h3>¿Confirmar cambios?</h3>
            <p>Estás a punto de actualizar la información del owner.</p>

            <div class="vp-modal-actions">
                <button type="button" class="btn-cancel" id="cancelSubmit">
                    Cancelar
                </button>
                <button type="button" class="btn-confirm" id="confirmSubmit">
                    Sí, actualizar
                </button>
            </div>
        </div>
    </div>

@stop

{{-- ================= ESTILOS ================= --}}
@section('css')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
@stop

{{-- ================= JS ================= --}}
@section('js')
    <script>
        const form = document.getElementById('ownerEditForm');
        const requiredFields = form.querySelectorAll('[required]');
        const modal = document.getElementById('confirmModal');
        const confirmBtn = document.getElementById('confirmSubmit');
        const cancelBtn = document.getElementById('cancelSubmit');

        let allowSubmit = false;

        requiredFields.forEach(field => {
            field.addEventListener('input', () => validateField(field));
        });

        function validateField(field) {
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                field.classList.remove('is-valid');
                return false;
            } else {
                field.classList.remove('is-invalid');
                field.classList.add('is-valid');
                return true;
            }
        }

        form.addEventListener('submit', e => {
            if (!allowSubmit) {
                e.preventDefault();

                let valid = true;
                requiredFields.forEach(f => {
                    if (!validateField(f)) valid = false;
                });

                if (!valid) return;

                modal.classList.add('show');
            }
        });

        confirmBtn.addEventListener('click', () => {
            allowSubmit = true;
            modal.classList.remove('show');
            form.submit();
        });

        cancelBtn.addEventListener('click', () => {
            modal.classList.remove('show');
        });
    </script>
@stop
