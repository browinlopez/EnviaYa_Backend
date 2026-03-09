@section('adminlte_js')
<script>
document.addEventListener('DOMContentLoaded', () => {

    const business = document.getElementById('business-select');
    const grocery = document.getElementById('grocery-fields');
    const pharmacy = document.getElementById('pharmacy-fields');
    const price = document.getElementById('price');
    const imgInput = document.getElementById('productImage');
    const preview = document.getElementById('photoPreview');

    function toggleFields() {
        const type = business?.selectedOptions[0]?.dataset.type;
        grocery.style.display = type == 1 ? 'block' : 'none';
        pharmacy && (pharmacy.style.display = type == 2 ? 'block' : 'none');
    }

    business?.addEventListener('change', toggleFields);
    toggleFields();

    imgInput?.addEventListener('change', e => {
        if (e.target.files[0]) {
            preview.src = URL.createObjectURL(e.target.files[0]);
        }
    });

    price?.addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '');
        this.value = v ? Number(v).toLocaleString('es-CO') : '';
    });

    document.getElementById('product-form')?.addEventListener('submit', () => {
        price.value = price.value.replace(/\./g, '');
    });
});
</script>
@stop