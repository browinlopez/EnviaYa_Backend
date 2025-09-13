<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-user"></i> Información del Usuario
        </h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Nombre</label>
                <input type="text" name="name" 
                       value="{{ old('name', $domiciliary->user->name ?? '') }}"
                       class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" 
                       value="{{ old('email', $domiciliary->user->email ?? '') }}"
                       class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">
                    Contraseña 
                    @if(!isset($domiciliary)) <span class="text-danger">*</span> @endif
                </label>
                <input type="password" name="password" class="form-control"
                       @if(!isset($domiciliary)) required @endif>
                <small class="text-muted">Déjelo vacío si no desea cambiarla</small>
            </div>
            <div class="col-md-6">
                <label class="form-label">Teléfono</label>
                <input type="text" name="phone" 
                       value="{{ old('phone', $domiciliary->user->phone ?? '') }}"
                       class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Dirección</label>
                <input type="text" name="address" 
                       value="{{ old('address', $domiciliary->user->address ?? '') }}"
                       class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Documento</label>
                <input type="text" name="document" 
                       value="{{ old('document', $domiciliary->document ?? '') }}"
                       class="form-control">
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0">
            <i class="fas fa-motorcycle"></i> Información del Domiciliario
        </h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Disponible</label>
                <select name="available" class="form-control">
                    <option value="1" @selected(old('available', $domiciliary->available ?? '') == 1)>Disponible</option>
                    <option value="0" @selected(old('available', $domiciliary->available ?? '') == 0)>No disponible</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Estado</label>
                <select name="state" class="form-control">
                    <option value="1" @selected(old('state', $domiciliary->state ?? '') == 1)>Activo</option>
                    <option value="0" @selected(old('state', $domiciliary->state ?? '') == 0)>Inactivo</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Calificación</label>
                <input type="number" step="0.1" min="0" max="5" name="qualification"
                       value="{{ old('qualification', $domiciliary->qualification ?? '') }}"
                       class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label">Negocio</label>
                <select name="business_id" class="form-control">
                    <option value="">-- Seleccione un negocio --</option>
                    @foreach($businesses as $business)
                        <option value="{{ $business->busines_id }}"
                            @if(isset($domiciliary) && $domiciliary->businesses->first()?->busines_id == $business->busines_id) selected @endif>
                            {{ $business->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end mt-3">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> {{ isset($domiciliary) ? 'Actualizar' : 'Guardar' }}
    </button>
</div>
