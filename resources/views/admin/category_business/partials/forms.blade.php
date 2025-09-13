<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="fas fa-tags"></i> Datos de la Categoría
        </h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Nombre <span class="text-danger">*</span></label>
                <input type="text" name="name" 
                       value="{{ old('name', $category->name ?? '') }}" 
                       class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Imagen</label>
                <input type="file" name="image" class="form-control">
                @if(isset($category) && $category->image)
                    <div class="mt-2">
                        <small class="text-muted d-block">Imagen actual:</small>
                        <img src="{{ asset('storage/' . $category->image) }}" 
                             alt="Imagen actual" class="img-thumbnail" style="max-width: 150px;">
                    </div>
                @endif
            </div>
            <div class="col-12">
                <label class="form-label">Descripción</label>
                <textarea name="description" rows="3" class="form-control">{{ old('description', $category->description ?? '') }}</textarea>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end mt-3">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> {{ isset($category) ? 'Actualizar' : 'Guardar' }}
    </button>
</div>
