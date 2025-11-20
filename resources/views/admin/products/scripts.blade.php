<script>
document.addEventListener('DOMContentLoaded', function() {
    const businessSelect = document.getElementById('business-select');

    const groceryFields = document.getElementById('grocery-fields');      // type 1
    const restaurantFields = document.getElementById('restaurant-fields'); // type 2
    const pharmacyFields = document.getElementById('pharmacy-fields');    // type 3
    const carpartsFields = document.getElementById('carparts-fields');    // type 4

    const priceInput = document.getElementById('price');
    const form = document.getElementById('product-form');

    function toggleFields() {
        const type = Number(businessSelect.selectedOptions[0]?.dataset.type);

        // Ocultar todas las secciones
        groceryFields.style.display = 'none';
        restaurantFields.style.display = 'none';
        pharmacyFields.style.display = 'none';
        carpartsFields.style.display = 'none';

        // Activar por tipo
        if (type === 1) groceryFields.style.display = 'block';
        if (type === 3) restaurantFields.style.display = 'block';
        if (type === 2) pharmacyFields.style.display = 'block';
        if (type === 4) carpartsFields.style.display = 'block';
    }

    businessSelect.addEventListener('change', toggleFields);
    toggleFields();

    // ➤ Formateo de precio
    priceInput.addEventListener('input', function() {
        let value = this.value.replace(/\D/g, '');
        if (value) value = Number(value).toLocaleString('es-CO');
        this.value = value;
    });

    // ➤ Limpiar formato al enviar
    form.addEventListener('submit', function() {
        priceInput.value = priceInput.value.replace(/\./g, '').replace(/,/g, '.');
    });
});
</script>
