(() => {
    'use strict';
    document.querySelectorAll('[name="first_name"], [name="last_name"], [name="middle_name"]').forEach(input => {
        input.maxLength = 250;
        input.addEventListener('input', () => {
            input.value = input.value.replace(/[^\p{L}\p{M} .'’-]/gu, '');
        });
    });
    document.querySelectorAll('[name="phone_number"]').forEach(input => {
        input.inputMode = 'numeric'; input.maxLength = 11; input.minLength = 11;
        input.pattern = '[0-9]{11}'; input.title = 'Enter exactly 11 digits, with no letters or symbols.';
        input.addEventListener('input', () => {
            input.value = input.value.replace(/[^0-9]/g, '').slice(0, 11);
            input.setCustomValidity(/^[0-9]{11}$/.test(input.value) ? '' : input.title);
        });
    });
    const picker = document.getElementById('ph-address-picker');
    if (!picker) return;
    const region = document.getElementById('address-region');
    function updateRequirements() {
        const changing = !!region.value || !picker.dataset.profileAddress;
        picker.querySelectorAll('select:not(#address-island)').forEach(select => { select.required = changing; });
        if (!changing) {
            document.getElementById('address').value = picker.dataset.profileAddress;
            document.getElementById('address-status').textContent = 'Your current address will be kept. Select a region to change it.';
        }
    }
    picker.addEventListener('address-ready', updateRequirements);
    picker.addEventListener('change', updateRequirements);
})();
