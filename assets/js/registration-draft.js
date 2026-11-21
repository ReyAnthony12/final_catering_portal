(() => {
    'use strict';
    const form = document.getElementById('registerForm');
    if (!form) return;
    const key = 'catering.registration.draft';
    const state = window.registrationDraftState || {};
    const picker = document.getElementById('ph-address-picker');
    const addressFields = {
        address_island: 'island', address_region: 'region', address_province: 'province',
        address_city: 'city', address_barangay: 'barangay'
    };
    // Profile pictures are persisted separately in IndexedDB.
    const fields = Array.from(form.elements).filter(field =>
        field.name && !['file', 'submit', 'button', 'hidden'].includes(field.type)
    );
    let draft = {};
    try {
        if (state.completed) sessionStorage.removeItem(key);
        else draft = JSON.parse(sessionStorage.getItem(key) || '{}') || {};
    } catch (error) { /* Storage may be unavailable in restricted browser modes. */ }

    fields.forEach(field => {
        if (!Object.prototype.hasOwnProperty.call(draft, field.name)) return;
        if (state.submitted && field.type !== 'password') return;
        if (field.type === 'checkbox' || field.type === 'radio') {
            field.checked = draft[field.name] === true;
        } else if (typeof draft[field.name] === 'string') {
            if (addressFields[field.name] && picker) {
                picker.dataset[addressFields[field.name]] = draft[field.name];
            } else {
                field.value = draft[field.name];
            }
        }
    });

    function saveDraft() {
        fields.forEach(field => {
            // Preserve selections while asynchronous address choices load.
            if (addressFields[field.name] && picker && picker.dataset.ready !== 'true') return;
            draft[field.name] = ['checkbox', 'radio'].includes(field.type) ? field.checked : field.value;
            if (addressFields[field.name] && picker) {
                picker.dataset[addressFields[field.name]] = field.value;
            }
        });
        try { sessionStorage.setItem(key, JSON.stringify(draft)); } catch (error) {}
    }
    form.addEventListener('input', saveDraft);
    form.addEventListener('change', saveDraft);
    form.addEventListener('submit', saveDraft);
    form.addEventListener('address-ready', saveDraft);
    if (typeof updateSubmitState === 'function') updateSubmitState();
})();
