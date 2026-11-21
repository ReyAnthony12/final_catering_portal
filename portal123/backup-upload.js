(() => {
    const form = document.getElementById('backupImportForm');
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const file = form.elements.backup.files[0];
        if (!file) return;
        const button = form.querySelector('button');
        const status = document.getElementById('backupUploadStatus');
        status.hidden = false;
        button.disabled = true;
        const send = async (action, values = {}) => {
            const body = new FormData();
            body.set('csrf_token', form.elements.csrf_token.value);
            body.set('action', action);
            Object.entries(values).forEach(([key, value]) => body.set(key, value));
            const response = await fetch('backup.php', {method: 'POST', body, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Upload failed. Please sign in again if your session expired.');
        };
        try {
            status.textContent = 'Preparing upload…';
            await send('upload_start', {size: String(file.size)});
            const chunkSize = 4 * 1024 * 1024;
            for (let offset = 0; offset < file.size; offset += chunkSize) {
                await send('upload_chunk', {offset: String(offset), chunk: file.slice(offset, offset + chunkSize)});
                status.textContent = `Uploading backup: ${Math.round(Math.min(offset + chunkSize, file.size) / file.size * 100)}%`;
            }
            status.textContent = 'Verifying backup files…';
            await send('upload_finish');
            location.href = 'backup.php?review=1';
        } catch (error) {
            status.textContent = error.message;
            button.disabled = false;
        }
    });
})();
