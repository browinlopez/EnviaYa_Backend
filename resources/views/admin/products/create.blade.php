@extends('adminlte::page')

@section('title', 'Crear Producto')

@section('content_header')
    <h1 class="mb-3">Crear Producto</h1>
@stop

@section('content')
<div class="container-fluid">
    <form action="{{ route('admin.products.store') }}" method="POST" id="product-form">
        @csrf

        {{-- Datos generales --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Información General</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nombre</label>
                            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Categoría</label>
                            <select name="category_id" class="form-control" required>
                                <option value="">Seleccione una categoría</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->category_id }}" @selected(old('category_id')==$cat->category_id)>
                                        {{ $cat->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Descripción</label>
                    <textarea name="description" class="form-control">{{ old('description') }}</textarea>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state" class="form-control">
                                <option value="1" selected>Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Negocio</label>
                            <select name="busines_id" class="form-control" id="business-select" required>
                                <option value="">Seleccione un negocio</option>
                                @foreach ($businesses as $b)
                                    <option value="{{ $b->busines_id }}" data-type="{{ $b->type }}">
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
                            <label>Precio</label>
                            <input type="text" name="price" id="price" class="form-control" value="{{ old('price') }}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Cantidad</label>
                            <input type="number" name="amount" class="form-control" value="{{ old('amount') }}" required>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- Datos de Tienda --}}
        <div class="card shadow-sm mb-4" id="grocery-fields" style="display:none;">
            <div class="card-header bg-info text-white">
                <h4 class="mb-0">Datos de Tienda</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Marca</label>
                            <input type="text" name="brand" class="form-control" value="{{ old('brand') }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Tamaño</label>
                            <input type="text" name="size" class="form-control" value="{{ old('size') }}">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Fecha de expiración</label>
                    <input type="date" name="expiration_date" class="form-control" value="{{ old('expiration_date') }}">
                </div>
            </div>
        </div>

        {{-- Datos de Farmacia --}}
        <div class="card shadow-sm mb-4" id="pharmacy-fields" style="display:none;">
            <div class="card-header bg-info text-white">
                <h4 class="mb-0">Datos de Farmacia</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Ingrediente activo</label>
                            <input type="text" name="active_ingredient" class="form-control" value="{{ old('active_ingredient') }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Dosificación</label>
                            <input type="text" name="dosage" class="form-control" value="{{ old('dosage') }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Presentación</label>
                            <input type="text" name="presentation" class="form-control" value="{{ old('presentation') }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Fecha de expiración</label>
                            <input type="date" name="expiration_date" class="form-control" value="{{ old('expiration_date') }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg px-4"><i class="fas fa-save"></i> Crear Producto</button>
    </form>
</div>
@stop

@section('adminlte_js')
@include('admin.products.scripts')
@stop
