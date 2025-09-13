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
        if (value) value = Number(value).toLocaleString('es-CO');
        this.value = value;
    });

    form.addEventListener('submit', function() {
        priceInput.value = priceInput.value.replace(/\./g, '').replace(/,/g, '.');
    });
});
</script>
