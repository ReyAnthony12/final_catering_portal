<script>
    function openBookThisServiceModal(productId, storeId, setName) {
        const params = new URLSearchParams({
            product_id: productId,
            store_id: storeId
        });
        if (setName) {
            params.set('set', setName);
        }

        if (location.pathname.endsWith('/browse-caterer.php')) {
            const selectorFor = element => {
                if (element.id) return '#' + CSS.escape(element.id);
                const parts = [];
                while (element && element !== document.body) {
                    const parent = element.parentElement;
                    if (!parent) break;
                    parts.unshift(element.tagName.toLowerCase() + ':nth-child(' + ([...parent.children].indexOf(element) + 1) + ')');
                    element = parent;
                }
                return 'body > ' + parts.join(' > ');
            };
            const areas = [...document.querySelectorAll('body *')]
                .filter(el => el.clientHeight > 0 && el.scrollHeight > el.clientHeight && /auto|scroll/.test(getComputedStyle(el).overflowY))
                .map(el => ({ selector: selectorFor(el), top: el.scrollTop, left: el.scrollLeft }));
            sessionStorage.setItem('catererBookReturn', JSON.stringify({
                url: location.href, top: window.scrollY, left: window.scrollX,
                expanded: [...document.querySelectorAll('details[open]')].map(selectorFor), areas
            }));
            params.set('return_url', location.pathname + location.search + location.hash);
        }
        const target = 'book-service.php?' + params.toString();
        const isLoggedIn = <?php echo (isLoggedIn() && getUserRole() === 'customer') ? 'true' : 'false'; ?>;
        if (!isLoggedIn) {
            window.location.href = '../login.php?redirect=' + encodeURIComponent('customer/' + target);
            return;
        }

        window.location.href = target;
    }
</script>

<script>
(() => {
    if (!location.pathname.endsWith('/browse-caterer.php')) return;
    function restoreBookOrigin() {
        let saved;
        try { saved = JSON.parse(sessionStorage.getItem('catererBookReturn') || 'null'); } catch (_) { return; }
        if (!saved || saved.url !== location.href) return;
        saved.expanded.forEach(selector => {
            const section = document.querySelector(selector);
            if (section) section.open = true;
        });
        requestAnimationFrame(() => requestAnimationFrame(() => {
            saved.areas.forEach(area => {
                const element = document.querySelector(area.selector);
                if (element) { element.scrollTop = area.top; element.scrollLeft = area.left; }
            });
            window.scrollTo(saved.left || 0, saved.top || 0);
            sessionStorage.removeItem('catererBookReturn');
        }));
    }
    window.addEventListener('pageshow', restoreBookOrigin);
})();
</script>
