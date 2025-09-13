@php
    // Si no viene $business desde el controller, lo definimos como null
    $business = $business ?? null;

    // Arrays de IDs seleccionados para checkboxes (si no hay $business → arrays vacíos)
    $productsSelected = old(
        'products',
        $business ? $business->products->pluck('products_id')->map(fn($id)=>(string)$id)->toArray() : []
    );

    $domiciliariesSelected = old(
        'domiciliaries',
        $business ? $business->domiciliaries->pluck('domiciliary_id')->map(fn($id)=>(string)$id)->toArray() : []
    );
@endphp

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white d-flex align-items-center">
        <i class="fas fa-store me-2"></i>
        <h5 class="mb-0 fw-bold">{{ $business ? 'Editar Negocio' : 'Nuevo Negocio' }}</h5>
    </div>
    <div class="card-body">
        {{-- Datos generales --}}
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Nombre</label>
                <input type="text" name="name"
                       value="{{ old('name', $business->name ?? '') }}"
                       class="form-control form-control-sm">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Teléfono</label>
                <input type="text" name="phone"
                       value="{{ old('phone', $business->phone ?? '') }}"
                       class="form-control form-control-sm">
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Dirección</label>
                <input type="text" name="address"
                       value="{{ old('address', $business->address ?? '') }}"
                       class="form-control form-control-sm">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">NIT</label>
                <input type="text" name="NIT"
                       value="{{ old('NIT', $business->NIT ?? '') }}"
                       class="form-control form-control-sm">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Razón Social DCD</label>
                <input type="text" name="razonSocial_DCD"
                       value="{{ old('razonSocial_DCD', $business->razonSocial_DCD ?? '') }}"
                       class="form-control form-control-sm">
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Categoría</label>
                <select name="type" class="form-control form-control-sm">
                    <option value="">-- Seleccione --</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('type', $business->type ?? '') == $cat->id)>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Logo</label>
                <input type="file" name="logo" class="form-control form-control-sm">
                @if ($business && $business->logo)
                    <div class="mt-2" style="width:100px;height:100px;border:1px solid #ddd;
                            display:flex;align-items:center;justify-content:center;
                            overflow:hidden;border-radius:8px;">
                        <img src="{{ asset('storage/' . $business->logo) }}"
                             style="width:100%;height:100%;object-fit:contain;">
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Solo mostrar productos/domiciliarios en edición --}}
@if($business)
<div class="row">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white d-flex align-items-center">
                <i class="fas fa-box me-2"></i>
                <h5 class="mb-0 fw-bold">Productos Afiliados</h5>
            </div>
            <div class="card-body p-0" style="max-height:250px;overflow-y:auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Sel.</th>
                            <th>Producto</th>
                            <th>Precio</th>
                            <th>Cantidad</th>
                            <th>Calificación</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($products as $prod)
                        @php
                            $pivot = $business->products
                                ->firstWhere('products_id',$prod->products_id)?->pivot;
                        @endphp
                        <tr>
                            <td>
                                <input type="checkbox" name="products[]" value="{{ $prod->products_id }}"
                                       @checked(in_array((string)$prod->products_id,$productsSelected))>
                            </td>
                            <td>{{ $prod->name }}</td>
                            <td><input type="number" step="0.01" name="price[{{ $prod->products_id }}]"
                                       value="{{ old('price.' . $prod->products_id, $pivot->price ?? '') }}"
                                       class="form-control form-control-sm"></td>
                            <td><input type="number" name="amount[{{ $prod->products_id }}]"
                                       value="{{ old('amount.' . $prod->products_id, $pivot->amount ?? '') }}"
                                       class="form-control form-control-sm"></td>
                            <td><input type="number" step="0.1" name="qualification[{{ $prod->products_id }}]"
                                       value="{{ old('qualification.' . $prod->products_id, $pivot->qualification ?? '') }}"
                                       class="form-control form-control-sm"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white d-flex align-items-center">
                <i class="fas fa-motorcycle me-2"></i>
                <h5 class="mb-0 fw-bold">Domiciliarios Afiliados</h5>
            </div>
            <div class="card-body p-0" style="max-height:250px;overflow-y:auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Sel.</th>
                            <th>Domiciliario</th>
                            <th>Disponible</th>
                            <th>Calificación</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($domiciliaries as $dom)
                        <tr>
                            <td>
                                <input type="checkbox" name="domiciliaries[]" value="{{ $dom->domiciliary_id }}"
                                       @checked(in_array((string)$dom->domiciliary_id,$domiciliariesSelected))>
                            </td>
                            <td>{{ $dom->user->name ?? '' }}</td>
                            <td>{{ $dom->available ? 'Disponible' : 'No disponible' }}</td>
                            <td>{{ $dom->qualification ?? 'N/A' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
