<div class="card shadow-sm mb-4">
    <div class="card-body">
        <!-- Datos generales del negocio -->
        <div class="row">
            <div class="col-md-6">
                <label>Nombre</label>
                <input type="text" name="name" value="{{ old('name', $business->name ?? '') }}" class="form-control">
            </div>
            <div class="col-md-6">
                <label>Teléfono</label>
                <input type="text" name="phone" value="{{ old('phone', $business->phone ?? '') }}"
                    class="form-control">
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-4">
                <label>Dirección</label>
                <input type="text" name="address" value="{{ old('address', $business->address ?? '') }}"
                    class="form-control">
            </div>
            <div class="col-md-4">
                <label>NIT</label>
                <input type="text" name="NIT" value="{{ old('NIT', $business->NIT ?? '') }}"
                    class="form-control">
            </div>

            <div class="col-md-4">
                <label>Razón Social DCD</label>
                <input type="text" name="razonSocial_DCD"
                    value="{{ old('razonSocial_DCD', $business->razonSocial_DCD ?? '') }}" class="form-control">
            </div>

        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <label>Categoría</label>
                <select name="type" class="form-control">
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('type', $business->type ?? '') == $cat->id)>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label>Logo</label>
                <input type="file" name="logo" class="form-control mb-2">

                @if (isset($business) && $business->logo)
                    <div
                        style="width: 100px; height: 100px; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 8px;">
                        <img src="{{ asset('storage/' . $business->logo) }}"
                            style="width: 100%; height: 100%; object-fit: contain;">
                    </div>
                @endif
            </div>
        </div>

        <!-- Tablas lado a lado: Solo si $business existe -->
        @isset($business)
            <div class="row mt-4">
                <!-- Productos -->
                <div class="col-md-6">
                    <h5>Productos Afiliados</h5>
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Seleccionar</th>
                                <th>Producto</th>
                                <th>Precio</th>
                                <th>Cantidad</th>
                                <th>Calificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $prod)
                                @php
                                    $pivot = $business->products->firstWhere('products_id', $prod->products_id)?->pivot;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="products[]" value="{{ $prod->products_id }}"
                                            @if ($pivot) checked @endif>
                                    </td>
                                    <td>{{ $prod->name }}</td>
                                    <td>
                                        <input type="number" step="0.01" name="price[{{ $prod->products_id }}]"
                                            value="{{ old('price.' . $prod->products_id, $pivot->price ?? '') }}"
                                            class="form-control form-control-sm">
                                    </td>
                                    <td>
                                        <input type="number" name="amount[{{ $prod->products_id }}]"
                                            value="{{ old('amount.' . $prod->products_id, $pivot->amount ?? '') }}"
                                            class="form-control form-control-sm">
                                    </td>
                                    <td>
                                        <input type="number" step="0.1" name="qualification[{{ $prod->products_id }}]"
                                            value="{{ old('qualification.' . $prod->products_id, $pivot->qualification ?? '') }}"
                                            class="form-control form-control-sm">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Domiciliarios -->
                <div class="col-md-6">
                    <h5>Domiciliarios Afiliados</h5>
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Seleccionar</th>
                                <th>Domiciliario</th>
                                <th>Disponible</th>
                                <th>Calificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($domiciliaries as $dom)
                                @php
                                    $pivot = $business->domiciliaries->firstWhere(
                                        'domiciliary_id',
                                        $dom->domiciliary_id,
                                    )?->pivot;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="domiciliaries[]" value="{{ $dom->domiciliary_id }}"
                                            @if ($pivot) checked @endif>
                                    </td>
                                    <td>{{ $dom->user->name }}</td>
                                    <td>{{ $dom->available ? 'Disponible' : 'No disponible' }}</td>
                                    <td>{{ $dom->qualification ?? 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endisset
    </div>
</div>
