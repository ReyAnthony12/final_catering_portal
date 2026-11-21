<style>
    #catererClosedNotice {
        box-sizing: border-box;
        width: min(420px, calc(100% - 32px));
        max-height: calc(100dvh - 32px);
        margin: auto;
        padding: 28px;
        overflow-y: auto;
        border: 1px solid var(--border-color, #96a38e);
        border-radius: 18px;
        background: var(--card-bg, #d4dbcd);
        color: var(--text-main, #20372b);
        box-shadow: 0 18px 60px rgb(23 61 50 / 25%);
        text-align: center;
    }
    #catererClosedNotice::backdrop { background: rgb(16 37 29 / 55%); }
    #catererClosedNotice .closed-notice-icon {
        display: grid; place-items: center;
        width: 52px; height: 52px; margin: 0 auto 16px;
        border-radius: 50%; background: #c5d0be; color: #173d32;
        font-size: 24px;
    }
    #catererClosedNotice h2 { margin: 0 0 10px; color: inherit; font-size: 22px; line-height: 1.3; }
    #catererClosedNotice p { margin: 0 0 22px; color: inherit; font-size: 15px; line-height: 1.6; overflow-wrap: anywhere; }
    #catererClosedNotice button {
        display: block; width: 100%; min-height: 44px; padding: 12px 16px;
        border: 1px solid #173d32; border-radius: 10px;
        background: #173d32; color: #f0f1e6;
        font: inherit; font-weight: 600; cursor: pointer;
    }
    #catererClosedNotice button:focus-visible { outline: 3px solid #708b6b; outline-offset: 3px; }
    @media (max-width: 480px) {
        #catererClosedNotice { padding: 24px 20px; border-radius: 14px; }
        #catererClosedNotice h2 { font-size: 20px; }
    }
</style>
<dialog id="catererClosedNotice" aria-labelledby="catererClosedTitle" aria-describedby="catererClosedDescription">
    <div class="closed-notice-icon" aria-hidden="true"><i class="fas fa-store-slash"></i></div>
    <h2 id="catererClosedTitle">Caterer currently closed</h2>
    <p id="catererClosedDescription">This caterer is currently closed and is not accepting bookings right now.</p>
    <form method="dialog"><button type="submit" autofocus>Got it</button></form>
</dialog>
<script>
(() => {
    const notice = document.getElementById('catererClosedNotice');
    const openNotice = () => {
        const previousFocus = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        notice.addEventListener('close', () => {
            document.body.style.overflow = previousOverflow;
            if (previousFocus instanceof HTMLElement) previousFocus.focus();
        }, { once: true });
        notice.showModal();
        document.body.style.overflow = 'hidden';
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openNotice, { once: true });
    } else {
        openNotice();
    }
})();
</script>
