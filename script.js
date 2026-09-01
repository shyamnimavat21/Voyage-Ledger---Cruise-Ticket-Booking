// Example frontend handler for the login form submission
async function handleLoginSubmit(event) {
    event.preventDefault();

    const emailInput = document.querySelector('#username').value.trim();
    const passwordInput = document.querySelector('#password').value;
    const isAdminMode = document.querySelector('.admin-btn')?.classList.contains('active') ? 'admin' : 'client';
    const errorDisplay = document.querySelector('#login-error-msg');

    if (errorDisplay) {
        errorDisplay.textContent = '';
    }

    try {
        // Use relative URL to avoid CORS and Mixed Content issues
        const response = await fetch('api.php?action=login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                email: emailInput,
                password: passwordInput,
                role: isAdminMode
            })
        });

        const contentType = response.headers.get("content-type");
        let data = {};

        if (contentType && contentType.includes("application/json")) {
            data = await response.json();
        } else {
            throw new Error("Server returned non-JSON output. Check PHP execution or database connection.");
        }

        if (!response.ok) {
            throw new Error(data.error || 'Authentication failed.');
        }

        // Store user session upon success
        localStorage.setItem('user', JSON.stringify(data.user));
        
        // Redirect or refresh UI
        window.location.reload();

    } catch (error) {
        console.error('Login error:', error);
        if (errorDisplay) {
            errorDisplay.textContent = error.message;
        }
    }
}