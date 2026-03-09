@extends('adminlte::page')

@section('title', 'Editar Producto')

@section('content')

<br>

<div class="owner-create-card">

    {{-- HEADER --}}
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon">
                <i class="fas fa-box"></i>
            </div>
            <div>
                <h1>Editar Producto</h1>
                <p>Actualizar información del producto</p>
            </div>
        </div>

        <a href="{{ route('admin.products.index') }}" class="btn-back">
            <i class="fas fa-arrow-left"></i>
            Volver
        </a>
    </div>

    {{-- FORM --}}
    <form action="{{ route('admin.products.update', $product->products_id) }}"
          method="POST"
          enctype="multipart/form-data"
          id="product-form">
        @csrf
        @method('PUT')

        <div class="owner-grid">

            {{-- CARD IZQUIERDA --}}
            <div class="owner-card">
                <h3 class="card-title">Información general</h3>

                <div class="card-content">

                    <div class="row-2">
                        <div class="form-group">
                            <label>Nombre *</label>
                            <input name="name" required value="{{ $product->name }}">
                        </div>

                        <div class="form-group">
                            <label>Categoría *</label>
                            <select name="category_id" required>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->category_id }}"
                                        @selected($product->category_id == $cat->category_id)>
                                        {{ $cat->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea name="description" rows="3">{{ $product->description }}</textarea>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state">
                                <option value="1" @selected($product->state)>Activo</option>
                                <option value="0" @selected(!$product->state)>Inactivo</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Negocio *</label>
                            <select name="busines_id" id="business-select" required>
                                @foreach ($businesses as $b)
                                    <option value="{{ $b->busines_id }}"
                                            data-type="{{ $b->type }}"
                                            @selected($product->businesses->first()?->busines_id == $b->busines_id)>
                                        {{ $b->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Precio *</label>
                            <input name="price" id="price" required
                                   value="{{ number_format($product->productBusinesses->first()?->price, 0, ',', '.') }}">
                        </div>

                        <div class="form-group">
                            <label>Cantidad *</label>
                            <input type="number" name="amount" required
                                   value="{{ $product->productBusinesses->first()?->amount }}">
                        </div>
                    </div>

                </div>
            </div>

            {{-- CARD DERECHA --}}
            <div class="owner-card">
                <h3 class="card-title">Datos específicos</h3>

                <div class="photo-box">
                    <img id="photoPreview"
                         src="{{ $product->image
                            ? asset('storage/'.$product->image)
                            : 'https://ui-avatars.com/api/?name=Producto&background=1B1464&color=fff' }}"
                         alt="Producto">

                    <input type="file"
                           name="product_image"
                           id="productImage"
                           accept="image/*">
                </div>

                {{-- GROCERY --}}
                <div id="grocery-fields" style="display:none">
                    <div class="row-2">
                        <div class="form-group">
                            <label>Marca</label>
                            <input name="brand" value="{{ $product->grocery?->brand }}">
                        </div>
                        <div class="form-group">
                            <label>Tamaño</label>
                            <input name="size" value="{{ $product->grocery?->size }}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Fecha de expiración</label>
                        <input type="date" name="expiration_date"
                               value="{{ $product->grocery?->expiration_date }}">
                    </div>
                </div>

                {{-- PHARMACY --}}
                <div id="pharmacy-fields" style="display:none">
                    <div class="row-3">
                        <div class="form-group">
                            <label>Ingrediente activo</label>
                            <input name="active_ingredient"
                                   value="{{ $product->pharmacy?->active_ingredient }}">
                        </div>
                        <div class="form-group">
                            <label>Dosificación</label>
                            <input name="dosage"
                                   value="{{ $product->pharmacy?->dosage }}">
                        </div>
                        <div class="form-group">
                            <label>Presentación</label>
                            <input name="presentation"
                                   value="{{ $product->pharmacy?->presentation }}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Fecha de expiración</label>
                        <input type="date" name="expiration_date"
                               value="{{ $product->pharmacy?->expiration_date }}">
                    </div>
                </div>

            </div>
        </div>

        {{-- FOOTER --}}
        <div class="form-footer">
            <button class="btn-save" type="submit">
                Actualizar Producto
            </button>
        </div>

    </form>
</div>
@stop

@section('css')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
@stop

@section('js')
    @include('admin.products.scripts')
@stop
