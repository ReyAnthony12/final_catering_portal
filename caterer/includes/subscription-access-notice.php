<dialog id="subscriptionAccessNotice" aria-labelledby="subscriptionAccessTitle" aria-describedby="subscriptionAccessDescription">
    <div class="access-notice-heading">
        <span class="access-notice-icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
        <h2 id="subscriptionAccessTitle">Subscription required</h2>
        <button type="button" class="access-notice-close" aria-label="Close subscription notice">&times;</button>
    </div>
    <div class="access-notice-body">
        <p id="subscriptionAccessDescription">Your caterer access is limited until your subscription payment is approved.</p>
        <div class="access-notice-available"><strong>Available for now</strong><span><i class="fas fa-envelope" aria-hidden="true"></i> Messages</span><span><i class="fas fa-credit-card" aria-hidden="true"></i> Subscription</span></div>
        <p class="access-notice-hint">View your subscription below to check your status or submit payment proof.</p>
    </div>
    <div class="access-notice-actions"><a href="messages.php" class="access-notice-messages">Open Messages</a><button type="button" class="access-notice-dismiss">Got it</button></div>
</dialog>
<style>
#subscriptionAccessNotice {position:fixed;inset:0;margin:auto;width:min(460px,calc(100% - 28px));max-height:calc(100vh - 28px);max-height:calc(100dvh - 28px);box-sizing:border-box;overflow:auto;padding:0;border:1px solid #96a38e;border-radius:18px;background:#d4dbcd;color:#20372b;box-shadow:0 24px 70px #0c201950;}
#subscriptionAccessNotice::backdrop {background:rgb(12 32 25 / 65%);backdrop-filter:blur(4px);}
#subscriptionAccessNotice .access-notice-heading {display:flex;align-items:center;gap:12px;padding:18px;border-bottom:1px solid #a8b69d;background:#c9d2c1;}
#subscriptionAccessNotice .access-notice-icon {display:grid;place-items:center;width:40px;height:40px;flex-shrink:0;border-radius:12px;background:#173d32;color:#edf1e7;}
#subscriptionAccessNotice h2 {flex:1;min-width:0;margin:0;font-size:18px;line-height:1.4;color:#173d32;}
#subscriptionAccessNotice button,#subscriptionAccessNotice a {font:inherit;cursor:pointer;box-sizing:border-box;}
#subscriptionAccessNotice .access-notice-close {border:0;border-radius:9px;background:transparent;color:#173d32;min-width:44px;min-height:44px;font-size:26px;}
#subscriptionAccessNotice .access-notice-body {padding:20px;}
#subscriptionAccessNotice p {margin:0 0 16px;font-size:14px;line-height:1.7;color:#354e3c;}
#subscriptionAccessNotice .access-notice-available {display:flex;flex-wrap:wrap;gap:10px;padding:14px;border:1px solid #a8b69d;border-radius:12px;background:#c9d2c1;}
#subscriptionAccessNotice .access-notice-available strong {width:100%;font-size:12px;color:#405747;}
#subscriptionAccessNotice .access-notice-available span {display:inline-flex;align-items:center;gap:8px;font-size:13px;color:#173d32;}
#subscriptionAccessNotice p.access-notice-hint {margin:16px 0 0;font-size:13px;}
#subscriptionAccessNotice .access-notice-actions {display:flex;gap:10px;padding:16px 20px;border-top:1px solid #a8b69d;}
#subscriptionAccessNotice .access-notice-actions > * {display:flex;align-items:center;justify-content:center;flex:1;min-height:46px;border:1px solid #96a38e;border-radius:10px;padding:10px 12px;font-size:13px;font-weight:600;text-decoration:none;color:#173d32;background:#c9d2c1;}
#subscriptionAccessNotice .access-notice-actions .access-notice-dismiss {background:#173d32;color:#edf1e7;border-color:#173d32;}
@media(max-width:420px){#subscriptionAccessNotice .access-notice-heading {padding:14px;gap:9px;}#subscriptionAccessNotice h2 {font-size:16px;}#subscriptionAccessNotice .access-notice-body {padding:16px;}#subscriptionAccessNotice .access-notice-actions {padding:14px 16px;flex-direction:column-reverse;}}
</style>
<script>
(() => {
    const notice = document.getElementById('subscriptionAccessNotice');
    notice.querySelectorAll('.access-notice-close, .access-notice-dismiss').forEach(button => button.addEventListener('click', () => notice.close()));
    notice.showModal();
})();
</script>
