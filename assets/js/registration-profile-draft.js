(() => {
    'use strict';
    const inputs = ['profile_customer', 'profile_store'].map(id => document.getElementById(id)).filter(Boolean);
    if (!inputs.length || !window.indexedDB) return;
    let tabKey;
    try {
        tabKey = sessionStorage.getItem('catering.registration.files');
        if (!tabKey) {
            tabKey = crypto.randomUUID();
            sessionStorage.setItem('catering.registration.files', tabKey);
        }
    } catch (error) { return; }

    const database = new Promise((resolve, reject) => {
        const request = indexedDB.open('catering-registration-drafts', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('profiles');
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
    async function access(input, mode, action) {
        const db = await database;
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('profiles', mode);
            const request = action(transaction.objectStore('profiles'), tabKey + ':' + input.id);
            transaction.oncomplete = () => resolve(request.result);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    }
    inputs.forEach(input => {
        let changed = false;
        input.addEventListener('change', () => {
            changed = true;
            // Existing image validation runs before this listener.
            const file = input.files[0];
            access(input, 'readwrite', (store, key) => file ? store.put(file, key) : store.delete(key))
                .catch(() => {
                    const preview = document.getElementById(input.id + '_preview');
                    if (preview) {
                        const message = document.createElement('small');
                        message.textContent = 'Could not save this picture for refresh. Please select it again after refreshing.';
                        preview.appendChild(message);
                        preview.style.display = 'block';
                    }
                });
        });
        if (window.registrationDraftState?.completed) {
            access(input, 'readwrite', (store, key) => store.delete(key)).catch(() => {});
            return;
        }
        access(input, 'readonly', (store, key) => store.get(key)).then(file => {
            if (!file || changed || input.files.length) return;
            const transfer = new DataTransfer();
            transfer.items.add(file instanceof File ? file : new File([file], 'profile-picture', {type: file.type}));
            input.files = transfer.files;
            if (typeof previewFile === 'function') previewFile(input.id);
        }).catch(() => {});
    });
})();
