// Modern UI Interactions

document.addEventListener('DOMContentLoaded', () => {
    // Add fade-in classes to main elements automatically
    const fadeElements = document.querySelectorAll('.stat-card, .glass-panel, table tr');
    fadeElements.forEach((el, index) => {
        el.classList.add('animate-fade-in');
        el.style.animationDelay = `${index * 0.05}s`;
    });

    // Sidebar active state
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-item a');
    navLinks.forEach(link => {
        if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href'))) {
            link.classList.add('active');
        }
    });
});


/**
 * Helper to fetch data from PHP/Flask APIs
 */
async function fetchData(url, options = {}) {
    try {
        const response = await fetch(url, options);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return await response.json();
    } catch (error) {
        console.error("Fetch Error:", error);
        showToast(error.message, 'error');
        return null;
    }
}

/**
 * Simple Toast Notification
 */
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `glass-panel alert alert-${type}`;
    toast.style.position = 'fixed';
    toast.style.bottom = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '1000';
    toast.style.minWidth = '250px';
    toast.textContent = message;

    document.body.appendChild(toast);

    // Animate in
    toast.animate([
        { transform: 'translateY(100px)', opacity: 0 },
        { transform: 'translateY(0)', opacity: 1 }
    ], { duration: 300, easing: 'ease-out' });

    // Remove after 3s
    setTimeout(() => {
        toast.animate([
            { transform: 'translateY(0)', opacity: 1 },
            { transform: 'translateY(100px)', opacity: 0 }
        ], { duration: 300, easing: 'ease-in' }).onfinish = () => toast.remove();
    }, 3000);
}

/**
 * Global confirm helper for forms
 */
async function confirmAction(event, title, message) {
    if (event.target.dataset.confirmed) return true;

    event.preventDefault();
    const confirmed = await window.customConfirm(title, message);

    if (confirmed) {
        event.target.dataset.confirmed = "true";
        event.target.submit();
    }
    return false;
}
