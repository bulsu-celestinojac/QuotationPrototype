<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate and retrieve CSRF Token
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF Token
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Set a session-based flash message for redirects
function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

// Display and clear the flash message via SweetAlert2 Animation
function display_flash_message() {
    if (isset($_SESSION['flash'])) {
        $type = $_SESSION['flash']['type'] === 'error' ? 'error' : 'success';
        $title = $type === 'error' ? 'Error!' : 'Success!';
        $msg = htmlspecialchars($_SESSION['flash']['message'], ENT_QUOTES, 'UTF-8');
        
        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: '{$title}',
                    text: '{$msg}',
                    icon: '{$type}',
                    confirmButtonColor: '#8B1538',
                    timer: 3500, // Auto-close after 3.5 seconds
                    timerProgressBar: true,
                    showConfirmButton: true,
                    confirmButtonText: 'Got it',
                    customClass: {
                        popup: 'swal-popup-custom',
                        title: 'swal-title-custom'
                    }
                });
            });
        </script>
        <style>
            .swal-title-custom { font-family: 'Outfit', sans-serif !important; font-weight: 900 !important; color: #8B1538 !important; letter-spacing: -0.02em; }
            .swal-popup-custom { font-family: 'DM Sans', sans-serif !important; border-radius: 24px !important; box-shadow: 0 24px 60px rgba(0,0,0,0.1) !important;}
        </style>
        ";
        unset($_SESSION['flash']);
    }
}
?>