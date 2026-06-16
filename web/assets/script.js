// Modern UI Interactions

function runWhenIdle(fn, timeout = 250) {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(fn, { timeout });
        return;
    }
    window.setTimeout(fn, 16);
}

function applyFadeInInChunks(elements) {
    let index = 0;
    const total = elements.length;

    function processChunk(deadline) {
        let processed = 0;
        while (index < total && processed < 24 && (!deadline || deadline.timeRemaining() > 4)) {
            const el = elements[index];
            el.classList.add('animate-fade-in');
            el.style.animationDelay = `${index * 0.05}s`;
            index += 1;
            processed += 1;
        }

        if (index < total) {
            runWhenIdle(processChunk, 150);
        }
    }

    runWhenIdle(processChunk, 150);
}

document.addEventListener('DOMContentLoaded', () => {
    // Sidebar active state
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-item a');
    navLinks.forEach(link => {
        if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href'))) {
            link.classList.add('active');
        }
    });

    // Add fade-in classes lazily so first interactions stay responsive.
    const fadeElements = document.querySelectorAll('.stat-card, .glass-panel, table tr');
    applyFadeInInChunks(fadeElements);
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
/* ============================================================================
   MOBILE-SPECIFIC ENHANCEMENTS
   ============================================================================ */

/**
 * Detect if device is mobile
 */
function isMobileDevice() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
           window.innerWidth <= 768;
}

/**
 * Detect if device is touchscreen
 */
function isTouchDevice() {
    return (('ontouchstart' in window) ||
            (navigator.maxTouchPoints > 0) ||
            (navigator.msMaxTouchPoints > 0));
}

/**
 * Mobile menu utility
 */
const MobileMenu = {
    init: function() {
        const toggle = document.getElementById('mobileMenuToggle');
        const close = document.getElementById('mobileMenuClose');
        const nav = document.getElementById('navigation');
        const navLinks = document.getElementById('navLinks');
        
        if (!toggle || !nav) return;
        
        // Use delegated touch feedback to avoid attaching listeners on every button/link.
        if (isTouchDevice()) {
            document.addEventListener('touchstart', function(e) {
                const target = e.target.closest('button, a');
                if (target) {
                    target.style.backgroundColor = 'rgba(255,255,255,0.1)';
                }
            }, { passive: true });

            document.addEventListener('touchend', function(e) {
                const target = e.target.closest('button, a');
                if (target) {
                    target.style.backgroundColor = '';
                }
            });
        }
    }
};

/**
 * Responsive table enhancements
 */
const ResponsiveTable = {
    convertToCards: function(table) {
        if (window.innerWidth > 768) return;
        
        // Add data-label attributes if they don't exist
        const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
        
        table.querySelectorAll('tbody tr').forEach(row => {
            row.querySelectorAll('td').forEach((cell, index) => {
                if (!cell.dataset.label && headers[index]) {
                    cell.dataset.label = headers[index];
                }
            });
        });
    },
    
    init: function() {
        document.querySelectorAll('table').forEach(table => {
            this.convertToCards(table);
        });
    }
};

/**
 * Optimized inputs for mobile
 */
const MobileInputs = {
    init: function() {
        if (!isMobileDevice()) return;
        
        // Add proper input types for mobile keyboards
        document.querySelectorAll('input[type="text"]').forEach(input => {
            const name = input.name || input.placeholder || '';
            if (name.toLowerCase().includes('email')) input.type = 'email';
            if (name.toLowerCase().includes('phone')) input.type = 'tel';
            if (name.toLowerCase().includes('number')) input.type = 'number';
            if (name.toLowerCase().includes('date')) input.type = 'date';
            if (name.toLowerCase().includes('time')) input.type = 'time';
        });
        
        // Increase input height for easier tapping
        document.querySelectorAll('.glass-input, input[type="text"], input[type="email"], input[type="tel"], select, textarea').forEach(el => {
            const minHeight = el.tagName === 'TEXTAREA' ? '80px' : '44px';
            if (!el.style.minHeight) {
                el.style.minHeight = minHeight;
            }
        });
    }
};

/**
 * Viewport height fix for mobile browsers with address bars
 */
const ViewportFix = {
    init: function() {
        if (!isMobileDevice()) return;
        
        const vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', `${vh}px`);
        
        window.addEventListener('resize', () => {
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        });
    }
};

/**
 * Initialize all mobile enhancements on page load
 */
document.addEventListener('DOMContentLoaded', function() {
    if (isMobileDevice() || isTouchDevice()) {
        ViewportFix.init();
        MobileMenu.init();
        
        // Add mobile class to body
        document.body.classList.add('is-mobile');

        // Defer non-critical DOM mutation work.
        runWhenIdle(() => {
            ResponsiveTable.init();
            MobileInputs.init();
        }, 250);
    }
});

/**
 * Handle orientation changes
 */
window.addEventListener('orientationchange', function() {
    // Reflow responsive elements
    ResponsiveTable.init();
    
    // Update viewport height on orientation change
    if (isMobileDevice()) {
        const vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', `${vh}px`);
    }
});

/**
 * Prevent zoom on double-tap (for better mobile UX)
 */
let lastTouchEnd = 0;
document.addEventListener('touchend', function(event) {
    const now = Date.now();
    if (now - lastTouchEnd <= 300) {
        event.preventDefault();
    }
    lastTouchEnd = now;
}, false);