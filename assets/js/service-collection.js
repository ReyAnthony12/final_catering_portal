(() => {
    let busy = false;
    const collection = () => document.getElementById('serviceCollection');
    const currentView = () => collection().querySelector('.services-tabs a.selected').getAttribute('href');
    const announce = message => { collection().querySelector('.collection-status').textContent = message; };
    async function refreshCollection(url, message = '') {
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) throw new Error('Unable to load services. Please try again.');
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = doc.getElementById('serviceCollection');
        if (!fresh) throw new Error('Your session may have expired. Refresh the page to sign in.');
        const old = collection();
        const scrollY = window.scrollY;
        const listScroll = old.querySelector('.services-list')?.scrollTop || 0;
        old.replaceWith(fresh);
        const list = fresh.querySelector('.services-list');
        if (list) list.scrollTop = listScroll;
        const locationUrl = new URL(window.location.href);
        const viewUrl = new URL(url, window.location.href);
        if (viewUrl.searchParams.get('view') === 'trash') locationUrl.searchParams.set('view', 'trash');
        else locationUrl.searchParams.delete('view');
        history.replaceState(history.state, '', locationUrl);
        announce(message);
        window.scrollTo({ top: scrollY, behavior: 'instant' });
    }
    // Keep an open library current without interrupting actions or keyboard focus.
    async function syncCollection() {
        const modal = document.getElementById('servicesModal');
        if (busy || !modal?.open || document.hidden || collection().contains(document.activeElement)) return;
        busy = true;
        try { await refreshCollection(currentView(), collection().querySelector('.collection-status').textContent); }
        catch (_) { /* Keep the current list available during a temporary connection failure. */ }
        finally { busy = false; }
    }
    window.setInterval(syncCollection, 15000);
    window.addEventListener('focus', syncCollection);
    document.addEventListener('visibilitychange', syncCollection);
    document.addEventListener('click', async event => {
        const tab = event.target.closest('#serviceCollection .services-tabs a');
        if (!tab) return;
        event.preventDefault();
        if (busy || tab.classList.contains('selected')) return;
        busy = true;
        collection().setAttribute('aria-busy', 'true');
        try { await refreshCollection(tab.href); }
        catch (error) { announce(error.message); }
        finally { busy = false; collection().removeAttribute('aria-busy'); }
    });
    document.addEventListener('submit', async event => {
        const form = event.target.closest('#serviceCollection .service-action-form');
        if (!form) return;
        event.preventDefault();
        if (busy) return;
        busy = true;
        const button = form.querySelector('button');
        const data = new FormData(form);
        const view = currentView();
        button.disabled = true;
        collection().setAttribute('aria-busy', 'true');
        announce('Saving…');
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'X-Service-Collection': '1' }
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to save. Please try again.');
            try { await refreshCollection(view, result.message); }
            catch (error) { throw new Error(`${result.message} The list could not refresh. Select the other tab to reload it.`); }
        } catch (error) { announce(error.message || 'Unable to save. Please try again.'); }
        finally {
            busy = false;
            button.disabled = false;
            collection().removeAttribute('aria-busy');
        }
    });
})();

(() => {
    const modal = document.getElementById('servicesModal');
    const opener = document.getElementById('viewServicesButton');
    const closer = document.getElementById('closeServicesButton');
    if (!modal || !opener || !closer) return;
    const setOpenInUrl = open => {
        const url = new URL(window.location.href);
        if (open) url.searchParams.set('library', 'open');
        else url.searchParams.delete('library');
        history.replaceState(history.state, '', url);
    };
    const openLibrary = () => {
        if (modal.open) return;
        modal.showModal();
        document.body.classList.add('services-modal-open');
        setOpenInUrl(true);
    };
    opener.addEventListener('click', openLibrary);
    closer.addEventListener('click', () => modal.close());
    modal.addEventListener('cancel', event => event.preventDefault());
    modal.addEventListener('close', () => {
        document.body.classList.remove('services-modal-open');
        setOpenInUrl(false);
        opener.focus();
    });
    if (new URL(window.location.href).searchParams.get('library') === 'open') openLibrary();
})();