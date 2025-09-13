{{-- Campos comunes para create y edit --}}
<h4 class="mb-3">Información General</h4>
<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Nombre <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control"
                   value="{{ old('name', $product->name ?? '') }}" required>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>Categoría <span class="text-danger">*</span></label>
            <select name="category_id" class="form-control" required>
                <option value="">Seleccione una categoría</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->category_id }}"
                        @if(old('category_id', $product->category_id ?? '') == $cat->category_id) selected @endif>
                        {{ $cat->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
</div>

<div class="form-group">
    <label>Descripción</label>
    <textarea name="description" class="form-control">{{ old('description', $product->description ?? '') }}</textarea>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Estado</label>
            <select name="state" class="form-control">
                <option value="1" @if(old('state', $product->state ?? 1)) selected @endif>Activo</option>
                <option value="0" @if(!old('state', $product->state ?? 1)) selected @endif>Inactivo</option>
            </select>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>Negocio <span class="text-danger">*</span></label>
            <select name="busines_id" class="form-control" id="business-select" required>
                <option value="">Seleccione un negocio</option>
                @foreach ($businesses as $b)
                    <option value="{{ $b->busines_id }}" data-type="{{ $b->type }}"
                        @if(old('busines_id', $product->businesses->first()->busines_id ?? '') == $b->busines_id) selected @endif>
                        {{ $b->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Precio <span class="text-danger">*</span></label>
            <input type="text" name="price" id="price" class="form-control"
                   value="{{ old('price', isset($product) ? number_format($product->productBusinesses->first()?->price, 0, ',', '.') : '') }}" required>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>Cantidad <span class="text-danger">*</span></label>
            <input type="number" name="amount" class="form-control"
                   value="{{ old('amount', $product->productBusinesses->first()?->amount ?? '') }}" required>
        </div>
    </div>
</div>

{{-- Grocery --}}
<div id="grocery-fields" style="display:none;">
    <h5 class="mt-4">Datos de Tienda</h5>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label>Marca</label>
                <input type="text" name="brand" class="form-control"
                       value="{{ old('brand', $product->grocery?->brand ?? '') }}">
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label>Tamaño</label>
                <input type="text" name="size" class="form-control"
                       value="{{ old('size', $product->grocery?->size ?? '') }}">
            </div>
        </div>
    </div>
    <div class="form-group">
        <label>Fecha de expiración</label>
        <input type="date" name="expiration_date" class="form-control"
               value="{{ old('expiration_date', $product->grocery?->expiration_date ?? '') }}">
    </div>
</div>

{{-- Pharmacy --}}
<div id="pharmacy-fields" style="display:none;">
    <h5 class="mt-4">Datos de Farmacia</h5>
    <div class="row">
        <div class="col-md-3">
            <div class="form-group">
                <label>Ingrediente activo</label>
                <input type="text" name="active_ingredient" class="form-control"
                       value="{{ old('active_ingredient', $product->pharmacy?->active_ingredient ?? '') }}">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label>Dosificación</label>
                <input type="text" name="dosage" class="form-control"
                       value="{{ old('dosage', $product->pharmacy?->dosage ?? '') }}">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label>Presentación</label>
                <input type="text" name="presentation" class="form-control"
                       value="{{ old('presentation', $product->pharmacy?->presentation ?? '') }}">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label>Fecha de expiración</label>
                <input type="date" name="expiration_date" class="form-control"
                       value="{{ old('expiration_date', $product->pharmacy?->expiration_date ?? '') }}">
            </div>
        </div>
    </div>
</div>
