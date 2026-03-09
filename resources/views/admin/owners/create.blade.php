@extends('adminlte::page')

@section('title', 'Crear Owner')

@section('content')

    <br>

    <div class="owner-create-card">

        {{-- HEADER --}}
        <div class="page-header">
            <div class="page-header-left">
                <div class="page-icon">
                    <i class="fas fa-user-plus"></i>
                </div>

                <div>
                    <h1>Nuevo Owner</h1>
                    <p>Registrar un nuevo propietario en el sistema</p>
                </div>
            </div>

            <a href="{{ route('admin.owners.index') }}" class="btn-back">
                <i class="fas fa-arrow-left"></i>
                Volver
            </a>
        </div>

        {{-- FORM --}}
        <form id="ownerForm" method="POST" action="{{ route('admin.owners.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="owner-grid">

                {{-- CARD IZQUIERDA --}}
                <div class="owner-card">
                    <h3 class="card-title">Datos básicos</h3>

                    <div class="card-content">
                        <div class="row-1">
                            <div class="form-group">
                                <label>Usuario *</label>
                                <select name="user_id" required>
                                    <option value="">Seleccione</option>
                                    @foreach ($users as $u)
                                        <option value="{{ $u->user_id }}">{{ $u->name }} — {{ $u->email }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="error-msg"></small>
                            </div>
                        </div>

                        <div class="row-2">
                            <div class="form-group">
                                <label>Tipo de documento</label>
                                <select name="document_type_id" required>
                                    <option value="">Selecciona el tipo de documento</option>
                                    @foreach ($documentTypes as $type)
                                        <option value="{{ $type->id }}">
                                            {{ $type->name_es }} ({{ $type->code }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Número documento *</label>
                                <input name="document_number" required>
                                <small class="error-msg"></small>
                            </div>
                        </div>

                        <div class="row-2">
                            <div class="form-group">
                                <label>Fecha nacimiento</label>
                                <input type="date" name="birthdate">
                            </div>

                            <div class="form-group">
                                <label>Contacto secundario</label>
                                <input name="contact_secondary">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state">
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- CARD DERECHA --}}
                <div class="owner-card">
                    <h3 class="card-title">Datos extras</h3>

                    <div class="photo-box">
                        <img id="photoPreview" src="https://ui-avatars.com/api/?name=Owner&background=1B1464&color=fff">
                        <input type="file" name="profile_photo" accept="image/*" onchange="previewPhoto(event)">
                    </div>

                    <div class="form-group">
                        <label>Notas</label>
                        <textarea name="notes" rows="5"></textarea>
                    </div>
                </div>

            </div>

            <div class="form-footer">
                <button class="btn-save" type="submit">
                    Guardar Owner
                </button>
            </div>

        </form>
    </div>

@stop

{{-- ================= ESTILOS ================= --}}
@section('css')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
@stop

{{-- ================= JS ================= --}}
@section('js')
    <script>
        /* PREVIEW FOTO */
        function previewPhoto(e) {
            document.getElementById('photoPreview').src =
                URL.createObjectURL(e.target.files[0]);
        }

        /* VALIDACION */
        document.querySelectorAll('#ownerForm [required]').forEach(input => {
            input.addEventListener('input', () => {
                if (input.value.trim()) {
                    input.classList.remove('is-invalid');
                    input.classList.add('is-valid');
                    input.nextElementSibling.innerText = '';
                }
            });
        });

        /* SUBMIT */
        document.getElementById('ownerForm').addEventListener('submit', e => {
            let valid = true;

            document.querySelectorAll('#ownerForm [required]').forEach(input => {
                if (!input.value.trim()) {
                    valid = false;
                    input.classList.add('is-invalid');
                    input.nextElementSibling.innerText = 'Campo obligatorio';
                }
            });

            if (!valid) {
                e.preventDefault();
                return;
            }

            showToast('Owner creado correctamente');
        });

        /* TOAST */
        function showToast(msg) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerText = msg;
            document.body.appendChild(toast);

            setTimeout(() => toast.classList.add('show'), 50);
            setTimeout(() => toast.remove(), 3000);
        }
    </script>
@stop
