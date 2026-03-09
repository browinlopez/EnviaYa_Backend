<div class="modal fade" id="businessModal{{ $owner->owner_id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content">

            <!-- HEADER -->
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-store me-2"></i>
                    Negocios asignados
                </h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <!-- BODY -->
            <div class="modal-body">
                <form id="business-form-{{ $owner->owner_id }}">
                    @csrf

                    @foreach ($businesses as $b)
                        <div class="business-item">
                            <span class="business-name">
                                {{ $b->name }}
                            </span>

                            <div class="form-check form-switch m-0">
                                <input class="form-check-input"
                                       type="checkbox"
                                       name="businesses[]"
                                       value="{{ $b->business_id }}"
                                       {{ $owner->businesses->contains($b->business_id) ? 'checked' : '' }}>
                            </div>
                        </div>
                    @endforeach
                </form>
            </div>

            <!-- FOOTER -->
            <div class="modal-footer">
                <button class="btn btn-modern btn-cancel"
                        data-bs-dismiss="modal">
                    Cancelar
                </button>

                <button class="btn btn-modern btn-save"
                        onclick="saveBusinesses({{ $owner->owner_id }})">
                    Guardar cambios
                </button>
            </div>

        </div>
    </div>
</div>