<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth_popup = $_SESSION['auth_popup'] ?? null;
if (isset($_SESSION['auth_popup'])) {
    unset($_SESSION['auth_popup']);
}

$auth_popup_json = $auth_popup ? json_encode($auth_popup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.swal2-popup.auth-popup-card.auth-welcome-popup {
    width: min(380px, calc(100vw - 32px));
    padding: 0 0 22px;
    background: #d4dbcd !important;
    color: #20372b !important;
    border: 1px solid #96a38e !important;
    outline: none !important;
    border-radius: 16px;
    box-shadow: 0 12px 32px rgb(12 32 25 / 18%);
}
.auth-welcome-popup .swal2-icon { margin: 24px auto 8px; font-size: 10px; }
.auth-welcome-popup .swal2-success { border-color: #708b6b !important; color: #245539 !important; }
.auth-welcome-popup .swal2-success-ring { border-color: #708b6b !important; }
.auth-welcome-popup .swal2-success [class^="swal2-success-line"] { background: #245539 !important; }
.auth-welcome-popup .swal2-title { padding: 10px 24px 0; color: #20372b !important; font-size: 22px; line-height: 1.3; }
.auth-welcome-popup .swal2-html-container { margin: 10px 24px 0; color: #425642 !important; font-size: 14px; line-height: 1.5; }
.auth-welcome-popup .swal2-timer-progress-bar { background: #708b6b !important; height: 3px; }
@media (max-width: 600px) {
    .swal2-popup.auth-popup-card {
        width: min(300px, calc(100vw - 48px));
        padding: 0 0 18px;
        border-radius: 14px;
    }

    .auth-popup-card .swal2-icon {
        margin: 22px auto 10px;
        font-size: 11px;
    }

    .auth-popup-card .swal2-title {
        padding: 8px 18px 0;
        font-size: 20px;
        line-height: 1.3;
    }

    .auth-popup-card .swal2-html-container {
        margin: 12px 18px 0;
        padding: 0;
        font-size: 14px;
        line-height: 1.45;
    }

    .auth-popup-card .swal2-actions {
        gap: 8px;
        margin: 18px 12px 0;
    }

    .auth-popup-card .swal2-actions .swal2-styled {
        margin: 0;
        min-height: 44px;
        padding: 10px 14px;
        font-size: 13px;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const authPopup = <?php echo $auth_popup_json; ?>;

    if (authPopup && (authPopup.type === 'welcome_back' || authPopup.type === 'welcome_new')) {
        const welcomeName = authPopup.name || 'there';
        const welcomeRole = authPopup.role || 'user';
        const isNew = authPopup.type === 'welcome_new';

        let welcomeTitle = '';
        let welcomeText = '';

        if (isNew) {
            welcomeTitle = welcomeRole === 'caterer'
                ? 'Welcome, Caterer Owner!'
                : (welcomeRole === 'customer' ? 'Welcome to Catering Portal!' : 'Welcome, ' + welcomeName + '!');
            welcomeText = welcomeRole === 'caterer'
                ? 'Your caterer account has been created successfully. Welcome, ' + welcomeName + '!'
                : (welcomeRole === 'customer'
                    ? 'Your customer account has been created and you are now logged in. Welcome, ' + welcomeName + '!'
                    : 'Your account has been created successfully.');
        } else {
            welcomeTitle = welcomeRole === 'caterer'
                ? 'Welcome back, Caterer Owner!'
                : (welcomeRole === 'customer' ? 'Welcome back, Customer!' : 'Welcome back, ' + welcomeName + '!');
            welcomeText = welcomeRole === 'caterer'
                ? 'Good to see you again, ' + welcomeName + '. Your caterer account is ready.'
                : (welcomeRole === 'customer'
                    ? 'Good to see you again, ' + welcomeName + '. Your customer account is ready.'
                    : 'Good to see you again, ' + welcomeName + '.');
        }

        Swal.fire({
            icon: 'success',
            iconColor: '#245539',
            title: welcomeTitle,
            text: welcomeText,
            timer: 4000,
            timerProgressBar: true,
            showConfirmButton: false,
            background: '#d4dbcd',
            color: '#20372b',
            scrollbarPadding: false,
            heightAuto: false,
            customClass: {
                popup: 'auth-popup-card auth-welcome-popup'
            }
        });
    }

    document.querySelectorAll('a[href$="logout.php"]').forEach(function (logoutLink) {
        logoutLink.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();

            const targetHref = logoutLink.getAttribute('href');

            Swal.fire({
                icon: 'question',
                title: 'Logout now?',
                heightAuto: false,
                scrollbarPadding: false,
                text: 'Are you sure you want to sign out from this account?',
                showCancelButton: true,
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Stay here',
                reverseButtons: true,
                background: '#1e1e1e',
                color: '#ffffff',
                confirmButtonColor: '#d4af37',
                cancelButtonColor: '#2a2a2a',
                customClass: {
                    popup: 'auth-popup-card'
                }
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Thank you!',
                    heightAuto: false,
                    scrollbarPadding: false,
                    text: 'Thank you for using Catering Portal.',
                    timer: 1200,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    background: '#1e1e1e',
                    color: '#ffffff',
                    customClass: {
                        popup: 'auth-popup-card'
                    },
                    willClose: function () {
                        window.location.href = targetHref;
                    }
                });
            });
        });
    });
});
</script>
