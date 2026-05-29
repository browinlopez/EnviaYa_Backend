@extends('adminlte::page')

@section('title', 'Crear Producto')

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
                <h1>Nuevo Producto</h1>
                <p>Registrar un nuevo producto en el sistema</p>
            </div>
        </div>

        <a href="{{ route('admin.products.index') }}" class="btn-back">
            <i class="fas fa-arrow-left"></i>
            Volver
        </a>
    </div>

    {{-- FORM --}}
    <form action="{{ route('admin.products.store') }}" method="POST" id="product-form">
        @csrf

        <div class="owner-grid">

            {{-- CARD IZQUIERDA --}}
            <div class="owner-card">
                <h3 class="card-title">Información general</h3>

                <div class="card-content">

                    <div class="row-2">
                        <div class="form-group">
                            <label>Nombre *</label>
                            <input name="name" required value="{{ old('name') }}">
                            <small class="error-msg"></small>
                        </div>

                        <div class="form-group">
                            <label>Categoría *</label>
                            <select name="category_id" required>
                                <option value="">Seleccione</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->category_id }}"
                                        @selected(old('category_id') == $cat->category_id)>
                                        {{ $cat->name }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="error-msg"></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea name="description" rows="3">{{ old('description') }}</textarea>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state">
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Negocio *</label>
                            <select name="business_id" id="business-select" required>
                                <option value="">Seleccione</option>
                                @foreach ($businesses as $b)
                                    <option value="{{ $b->business_id }}" data-type="{{ $b->type }}">
                                        {{ $b->name }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="error-msg"></small>
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Precio *</label>
                            <input name="price" required value="{{ old('price') }}">
                            <small class="error-msg"></small>
                        </div>

                        <div class="form-group">
                            <label>Cantidad *</label>
                            <input type="number" name="amount" required value="{{ old('amount') }}">
                            <small class="error-msg"></small>
                        </div>
                    </div>

                </div>
            </div>

            {{-- CARD DERECHA --}}
            <div class="owner-card">
                <h3 class="card-title">Datos específicos</h3>

                <div class="photo-box">
                    <img id="photoPreview" src="https://ui-avatars.com/api/?name=Producto&background=1B1464&color=fff"
                        alt="Producto">

                    <input type="file" name="product_image" id="productImage" accept="image/*">
                </div>

                {{-- TIENDA --}}
                <div id="grocery-fields" style="display:none">
                    <div class="row-2">
                        <div class="form-group">
                            <label>Marca</label>
                            <input name="brand">
                        </div>

                        <div class="form-group">
                            <label>Tamaño</label>
                            <input name="size">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Fecha de expiración</label>
                        <input type="date" name="expiration_date">
                    </div>
                </div>

                {{-- FARMACIA --}}
                <div id="pharmacy-fields" style="display:none">
                    <div class="row-3">
                        <div class="form-group">
                            <label>Ingrediente activo</label>
                            <input name="active_ingredient">
                        </div>
                        <div class="form-group">
                            <label>Dosificación</label>
                            <input name="dosage">
                        </div>
                        <div class="form-group">
                            <label>Presentación</label>
                            <input name="presentation">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Fecha de expiración</label>
                        <input type="date" name="expiration_date">
                    </div>
                </div>

                {{-- RESTAURANTE --}}
                <div id="restaurant-fields" style="display:none">
                    <div class="row-2">
                        <div class="form-group">
                            <label>Tipo de comida</label>
                            <input name="food_type">
                        </div>
                        <div class="form-group">
                            <label>Tamaño porción</label>
                            <input name="portion_size">
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Vegano</label>
                            <select name="is_vegan">
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Sin gluten</label>
                            <select name="is_gluten_free">
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Alérgenos</label>
                        <input name="allergens">
                    </div>
                </div>

                {{-- AUTOPARTES --}}
                <div id="carparts-fields" style="display:none">
                    <div class="row-3">
                        <div class="form-group">
                            <label>Marca</label>
                            <input name="car_brand">
                        </div>
                        <div class="form-group">
                            <label>Modelo</label>
                            <input name="car_model">
                        </div>
                        <div class="form-group">
                            <label>Año</label>
                            <input type="number" name="car_year">
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>OEM Code</label>
                            <input name="oem_code">
                        </div>
                        <div class="form-group">
                            <label>Compatibilidad</label>
                            <input name="compatibility">
                        </div>
                    </div>
                </div>

            </div>

        </div>

        {{-- FOOTER --}}
        <div class="form-footer">
            <button class="btn-save" type="submit">
                Guardar Producto
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