<form action="{{ $action }}"
      method="POST"
      enctype="multipart/form-data"
      id="product-form">

@csrf
@if($isEdit)
    @method('PUT')
@endif

{{-- ================= INFORMACIÓN GENERAL ================= --}}
<div class="card card-primary card-outline mb-4">
    <div class="card-header">
        <h3 class="card-title">Información General</h3>
    </div>

    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <label>Nombre *</label>
                <input type="text" name="name" class="form-control"
                       value="{{ old('name', $product->name ?? '') }}" required>
            </div>

            <div class="col-md-6">
                <label>Categoría *</label>
                <select name="category_id" class="form-control" required>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->category_id }}"
                            @selected(old('category_id', $product->category_id ?? '') == $cat->category_id)>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-3">
            <label>Descripción</label>
            <textarea name="description" class="form-control">{{ old('description', $product->description ?? '') }}</textarea>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <label>Estado</label>
                <select name="state" class="form-control">
                    <option value="1" @selected(old('state', $product->state ?? 1) == 1)>Activo</option>
                    <option value="0" @selected(old('state', $product->state ?? 1) == 0)>Inactivo</option>
                </select>
            </div>

            <div class="col-md-6">
                <label>Negocio *</label>
                <select name="busines_id" class="form-control" id="business-select" required>
                    @foreach($businesses as $b)
                        <option value="{{ $b->busines_id }}"
                                data-type="{{ $b->type }}"
                                @selected(old('busines_id', $product->businesses->first()?->busines_id ?? '') == $b->busines_id)>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <label>Precio *</label>
                <input type="text" id="price" name="price" class="form-control"
                       value="{{ old('price',
                        isset($product)
                        ? number_format($product->productBusinesses->first()?->price, 0, ',', '.')
                        : '') }}" required>
            </div>

            <div class="col-md-6">
                <label>Cantidad *</label>
                <input type="number" name="amount" class="form-control"
                       value="{{ old('amount', $product->productBusinesses->first()?->amount ?? '') }}" required>
            </div>
        </div>
    </div>
</div>

{{-- ================= IMAGEN ================= --}}
<div class="card card-info card-outline mb-4">
    <div class="card-header">
        <h3 class="card-title">Imagen del Producto</h3>
    </div>

    <div class="card-body text-center">
        <img id="photoPreview"
             src="{{ isset($product) && $product->image
                    ? asset('storage/'.$product->image)
                    : asset('img/no-image.png') }}"
             class="img-thumbnail mb-3"
             style="max-height:200px">

        <input type="file"
               name="product_image"
               id="productImage"
               class="form-control"
               accept="image/*"
               {{ $isEdit ? '' : 'required' }}>

        @if($isEdit)
            <small class="text-muted d-block mt-2">
                Si no seleccionas una imagen, se conservará la actual
            </small>
        @endif
    </div>
</div>

{{-- ================= GROCERY ================= --}}
<div id="grocery-fields" class="card card-success card-outline mb-4">
    <div class="card-header"><h3 class="card-title">Datos Tienda</h3></div>
    <div class="card-body row">
        <div class="col-md-4">
            <label>Marca</label>
            <input type="text" name="brand" class="form-control"
                   value="{{ old('brand', $product->grocery?->brand ?? '') }}">
        </div>
        <div class="col-md-4">
            <label>Tamaño</label>
            <input type="text" name="size" class="form-control"
                   value="{{ old('size', $product->grocery?->size ?? '') }}">
        </div>
        <div class="col-md-4">
            <label>Fecha expiración</label>
            <input type="date" name="expiration_date" class="form-control"
                   value="{{ old('expiration_date', $product->grocery?->expiration_date ?? '') }}">
        </div>
    </div>
</div>

{{-- ================= BOTÓN ================= --}}
<div class="text-right">
    <button class="btn btn-primary btn-lg">
        <i class="fas fa-save"></i>
        {{ $isEdit ? 'Actualizar Producto' : 'Crear Producto' }}
    </button>
</div>

</form>