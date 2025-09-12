<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <label>Nombre</label>
                <input type="text" name="name" value="{{ old('name', $category->name ?? '') }}" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label>Imagen</label>
                <input type="file" name="image" class="form-control">
                @if(isset($category) && $category->image)
                    <small>Imagen actual: {{ $category->image }}</small><br>
                    <img src="{{ asset('storage/' . $category->image) }}" alt="Imagen" style="max-width: 100px; margin-top: 5px;">
                @endif
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-12">
                <label>Descripción</label>
                <textarea name="description" class="form-control">{{ old('description', $category->description ?? '') }}</textarea>
            </div>
        </div>
    </div>
</div>
