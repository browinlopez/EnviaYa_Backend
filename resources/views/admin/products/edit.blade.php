@extends('adminlte::page')

@section('title', 'Editar Producto')

@section('content_header')
    <h1 class="mb-3">Editar Producto</h1>
@stop

@section('content')
<div class="container-fluid">
    <form action="{{ route('admin.products.update', $product->products_id) }}" method="POST" id="product-form">
        @csrf
        @method('PUT')

        {{-- Información General --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Información General</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="name" value="{{ $product->name }}" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Categoría <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-control" required>
                                <option value="">Seleccione una categoría</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->category_id }}" @selected($product->category_id == $cat->category_id)>
                                        {{ $cat->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Descripción</label>
                            <textarea name="description" class="form-control">{{ $product->description }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state" class="form-control">
                                <option value="1" @selected($product->state)>Activo</option>
                                <option value="0" @selected(!$product->state)>Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Negocio <span class="text-danger">*</span></label>
                            <select name="busines_id" class="form-control" id="business-select" required>
                                <option value="">Seleccione un negocio</option>
                                @foreach($businesses as $b)
                                    <option value="{{ $b->busines_id }}" data-type="{{ $b->type }}"
                                        @if($product->businesses->first()?->busines_id == $b->busines_id) selected @endif>
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
                                value="{{ number_format($product->productBusinesses->first()?->price, 0, ',', '.') }}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Cantidad <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control"
                                value="{{ $product->productBusinesses->first()?->amount }}" required>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- Grocery Fields --}}
        <div id="grocery-fields" class="card shadow-sm mb-4" style="display:none;">
            <div class="card-header bg-success text-white">Datos Tienda</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Marca</label>
                            <input type="text" name="brand" class="form-control" value="{{ $product->grocery?->brand }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Tamaño</label>
                            <input type="text" name="size" class="form-control" value="{{ $product->grocery?->size }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Fecha de expiración</label>
                            <input type="date" name="expiration_date" class="form-control" value="{{ $product->grocery?->expiration_date }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pharmacy Fields --}}
        <div id="pharmacy-fields" class="card shadow-sm mb-4" style="display:none;">
            <div class="card-header bg-success text-white">Datos Farmacia</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Ingrediente activo</label>
                            <input type="text" name="active_ingredient" class="form-control" value="{{ $product->pharmacy?->active_ingredient }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Dosificación</label>
                            <input type="text" name="dosage" class="form-control" value="{{ $product->pharmacy?->dosage }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Presentación</label>
                            <input type="text" name="presentation" class="form-control" value="{{ $product->pharmacy?->presentation }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Fecha de expiración</label>
                            <input type="date" name="expiration_date" class="form-control" value="{{ $product->pharmacy?->expiration_date }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Botón --}}
        <div class="text-end mb-4">
            <button type="submit" class="btn btn-primary btn-lg px-4">
                <i class="fas fa-save"></i> Actualizar Producto
            </button>
        </div>
    </form>
</div>
@stop

@section('adminlte_js')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const businessSelect = document.getElementById('business-select');
    const groceryFields = document.getElementById('grocery-fields');
    const pharmacyFields = document.getElementById('pharmacy-fields');
    const priceInput = document.getElementById('price');
    const form = document.getElementById('product-form');

    function toggleFields() {
        const type = businessSelect.selectedOptions[0]?.dataset.type;
        groceryFields.style.display = type == 1 ? 'block' : 'none';
        pharmacyFields.style.display = type == 2 ? 'block' : 'none';
    }
    businessSelect.addEventListener('change', toggleFields);
    toggleFields();

    priceInput.addEventListener('input', function() {
        let value = this.value.replace(/\D/g, '');
        if (value) {
            value = Number(value).toLocaleString('es-CO');
        }
        this.value = value;
    });

    form.addEventListener('submit', function() {
        priceInput.value = priceInput.value.replace(/\./g, '').replace(/,/g, '.');
    });
});
</script>
@stop
