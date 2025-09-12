@extends('adminlte::page')

@section('title', 'Crear Producto')

@section('content_header')
    <h1>Crear Producto</h1>
@stop

@section('content')
    <div class="card shadow-sm">
        <div class="card-body">
            <form action="{{ route('admin.products.store') }}" method="POST" id="product-form">
                @csrf

                {{-- Datos generales del producto --}}
                <h4 class="mb-3">Información General</h4>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Nombre del producto"
                                required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Categoría <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-control" required>
                                <option value="">Seleccione una categoría</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->category_id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Descripción</label>
                    <textarea name="description" class="form-control" placeholder="Descripción del producto"></textarea>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="state" class="form-control">
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Negocio <span class="text-danger">*</span></label>
                            <select name="busines_id" class="form-control" id="business-select" required>
                                <option value="">Seleccione un negocio</option>
                                @foreach ($businesses as $b)
                                    <option value="{{ $b->busines_id }}" data-type="{{ $b->type }}">{{ $b->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Precio y cantidad --}}
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Precio <span class="text-danger">*</span></label>
                            <input type="text" name="price" id="price" class="form-control"
                                placeholder="Ej: 1.000,00" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Cantidad <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" placeholder="Cantidad disponible"
                                required>
                        </div>
                    </div>
                </div>

                {{-- Campos dinámicos según tipo de negocio --}}
                <div id="grocery-fields" style="display:none;">
                    <h5 class="mt-4">Datos de Tienda</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Marca</label>
                                <input type="text" name="brand" class="form-control grocery-field"
                                    placeholder="Marca del producto">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tamaño</label>
                                <input type="text" name="size" class="form-control grocery-field"
                                    placeholder="Tamaño o presentación">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Fecha de expiración</label>
                        <input type="date" name="expiration_date" class="form-control grocery-field">
                    </div>
                </div>


                <div id="pharmacy-fields" style="display:none;">
                    <h5 class="mt-4">Datos de Farmacia</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Ingrediente activo</label>
                                <input type="text" name="active_ingredient" class="form-control"
                                    placeholder="Ingrediente activo">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dosificación</label>
                                <input type="text" name="dosage" class="form-control" placeholder="Dosificación">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Presentación</label>
                                <input type="text" name="presentation" class="form-control" placeholder="Presentación">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Fecha de expiración</label>
                                <input type="date" name="expiration_date" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success mt-3">
                    <i class="fas fa-save"></i> Crear Producto
                </button>
            </form>
        </div>
    </div>
@stop

@section('adminlte_js')
    <script>
        // Mostrar campos según tipo de negocio
        document.getElementById('business-select').addEventListener('change', function() {
            let type = this.selectedOptions[0]?.dataset.type;
            document.getElementById('grocery-fields').style.display = type == 1 ? 'block' : 'none';
            document.getElementById('pharmacy-fields').style.display = type == 2 ? 'block' : 'none';
        });

        // Formatear precio en tiempo real (visual) y limpiar antes de enviar
        const priceInput = document.getElementById('price');
        const form = document.getElementById('product-form');

        priceInput.addEventListener('input', function(e) {
            let value = this.value.replace(/\D/g, ''); // solo números
            if (value) {
                value = Number(value).toLocaleString('es-CO'); // formato colombiano
            }
            this.value = value;
        });

        form.addEventListener('submit', function() {
            priceInput.value = priceInput.value.replace(/\./g, '').replace(/,/g, '.'); // enviar limpio
        });

        // Mostrar campos según tipo de negocio
        const businessSelect = document.getElementById('business-select');
        const groceryFields = document.querySelectorAll('.grocery-field');
        const pharmacyFields = document.querySelectorAll('.pharmacy-field');

        function toggleFields() {
            const type = businessSelect.selectedOptions[0]?.dataset.type;

            // Grocery
            if (type == 1) {
                document.getElementById('grocery-fields').style.display = 'block';
                groceryFields.forEach(f => f.disabled = false);
                document.getElementById('pharmacy-fields').style.display = 'none';
                pharmacyFields.forEach(f => f.disabled = true);
            }
            // Pharmacy
            else if (type == 2) {
                document.getElementById('pharmacy-fields').style.display = 'block';
                pharmacyFields.forEach(f => f.disabled = false);
                document.getElementById('grocery-fields').style.display = 'none';
                groceryFields.forEach(f => f.disabled = true);
            } else {
                // Ninguno
                document.getElementById('grocery-fields').style.display = 'none';
                groceryFields.forEach(f => f.disabled = true);
                document.getElementById('pharmacy-fields').style.display = 'none';
                pharmacyFields.forEach(f => f.disabled = true);
            }
        }

        businessSelect.addEventListener('change', toggleFields);
        toggleFields();
    </script>
@stop
