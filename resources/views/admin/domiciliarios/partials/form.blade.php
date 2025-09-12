<div class="card shadow-sm mb-4">
    <div class="card-body">
        <!-- Datos del usuario -->
        <h5>Información del Usuario</h5>
        <div class="row">
            <div class="col-md-6">
                <label>Nombre</label>
                <input type="text" name="name" value="{{ old('name', $domiciliary->user->name ?? '') }}"
                    class="form-control" required>
            </div>
            <div class="col-md-6">
                <label>Email</label>
                <input type="email" name="email" value="{{ old('email', $domiciliary->user->email ?? '') }}"
                    class="form-control" required>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <label>Contraseña @if(!isset($domiciliary)) * @endif</label>
                <input type="password" name="password" class="form-control"
                    @if(!isset($domiciliary)) required @endif>
            </div>
            <div class="col-md-6">
                <label>Teléfono</label>
                <input type="text" name="phone" value="{{ old('phone', $domiciliary->user->phone ?? '') }}"
                    class="form-control">
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <label>Dirección</label>
                <input type="text" name="address" value="{{ old('address', $domiciliary->user->address ?? '') }}"
                    class="form-control">
            </div>

            <div class="col-md-6">
                <label>Documento</label>
                <input type="text" name="document" value="{{ old('document', $domiciliary->document ?? '') }}"
                    class="form-control">
            </div>
        </div>

        <!-- Datos del domiciliario -->
        <h5 class="mt-4">Información del Domiciliario</h5>
        <div class="row mt-3">
            <div class="col-md-4">
                <label>Disponible</label>
                <select name="available" class="form-control">
                    <option value="1" @selected(old('available', $domiciliary->available ?? '') == 1)>Disponible</option>
                    <option value="0" @selected(old('available', $domiciliary->available ?? '') == 0)>No disponible</option>
                </select>
            </div>
            <div class="col-md-4">
                <label>Estado</label>
                <select name="state" class="form-control">
                    <option value="1" @selected(old('state', $domiciliary->state ?? '') == 1)>Activo</option>
                    <option value="0" @selected(old('state', $domiciliary->state ?? '') == 0)>Inactivo</option>
                </select>
            </div>
            <div class="col-md-4">
                <label>Calificación</label>
                <input type="number" step="0.1" min="0" max="5" name="qualification"
                    value="{{ old('qualification', $domiciliary->qualification ?? '') }}" class="form-control">
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <label>Negocio</label>
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
