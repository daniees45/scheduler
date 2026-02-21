/**
 * Unified API Configuration for Flask Backend
 * 
 * Automatically detects environment (local vs production) and 
 * provides consistent API call interface with error handling.
 * 
 * Usage:
 *   import this file in HTML: <script src="config.js"></script>
 *   Call endpoints: apiCall('/generate', { method: 'POST', ... })
 */

const API_CONFIG = {
    // Auto-detect environment based on hostname
    baseURL: window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1'
        ? 'http://localhost:5000'
        : 'https://my-ai-service-yj44.onrender.com',
    
    // Default timeout for all requests (30 seconds)
    timeout: 30000,
    // Long-running timeout (10 minutes) for generation endpoints
    longTimeout: 600000,
    
    // Retry configuration
    maxRetries: 3,
    retryDelay: 1000, // milliseconds, will use exponential backoff

    // Same-origin PHP proxy fallback (helps Safari/CORS/mixed-content constraints)
    proxyURL: 'api/ai_proxy.php',
};

function createTimeoutController(timeoutMs) {
    const controller = new AbortController();
    const timerId = setTimeout(() => controller.abort(), timeoutMs);
    return {
        signal: controller.signal,
        clear: () => clearTimeout(timerId)
    };
}

async function apiCallViaProxy(endpoint, options = {}) {
    const proxyUrl = `${API_CONFIG.proxyURL}?endpoint=${encodeURIComponent(endpoint)}`;
    const isLongRun = endpoint === '/generate' || endpoint === '/api/generate' || endpoint === '/generate/exam';
    const timeoutMs = isLongRun ? API_CONFIG.longTimeout : API_CONFIG.timeout;
    const timeoutCtrl = createTimeoutController(timeoutMs);

    try {
        console.log(`[API Proxy] ${options.method || 'GET'} ${proxyUrl}`);
        const response = await fetch(proxyUrl, {
            ...options,
            signal: timeoutCtrl.signal
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return response;
    } finally {
        timeoutCtrl.clear();
    }
}

/**
 * Make an API call to Flask backend with automatic retry logic
 * 
 * @param {string} endpoint - API endpoint path (e.g., '/generate', '/health')
 * @param {object} options - Fetch API options (method, body, headers, etc.)
 * @param {number} retryCount - Current retry attempt (internal use)
 * @returns {Promise<Response>} - Fetch response object
 */
async function apiCall(endpoint, options = {}, retryCount = 0) {
    const url = API_CONFIG.baseURL + endpoint;
    const isLongRun = endpoint === '/generate' || endpoint === '/api/generate' || endpoint === '/generate/exam';
    const timeoutMs = isLongRun ? API_CONFIG.longTimeout : API_CONFIG.timeout;
    const maxRetries = isLongRun ? 0 : API_CONFIG.maxRetries;
    
    // Set default headers
    const headers = {
        'Content-Type': 'application/json',
        ...options.headers
    };
    
    const config = {
        ...options,
        headers
    };
    const timeoutCtrl = createTimeoutController(timeoutMs);
    config.signal = timeoutCtrl.signal;
    
    try {
        console.log(`[API] ${options.method || 'GET'} ${url}`);
        const response = await fetch(url, config);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return response;
        
    } catch (error) {
        console.error(`[API Error] Attempt ${retryCount + 1}/${maxRetries + 1}:`, error.message);
        
        // Retry logic with exponential backoff
        if (retryCount < maxRetries) {
            const delay = API_CONFIG.retryDelay * Math.pow(2, retryCount);
            console.log(`[API] Retrying in ${delay}ms...`);
            await new Promise(resolve => setTimeout(resolve, delay));
            return apiCall(endpoint, options, retryCount + 1);
        }

        // Safari/CORS/mixed-content fallback: route via same-origin PHP proxy
        if (!options.__proxyTried) {
            try {
                const proxyOptions = { ...options, __proxyTried: true };
                delete proxyOptions.__proxyTried;
                return await apiCallViaProxy(endpoint, proxyOptions);
            } catch (proxyError) {
                throw new Error(`Direct + proxy failed: ${proxyError.message}`);
            }
        }
        
        // All retries exhausted
        throw new Error(`API call failed after ${maxRetries + 1} attempts: ${error.message}`);
    } finally {
        timeoutCtrl.clear();
    }
}

/**
 * Check if Flask backend is reachable
 * 
 * @returns {Promise<boolean>} - true if backend responds, false otherwise
 */
async function checkAPIStatus() {
    try {
        const response = await apiCall('/health', { 
            method: 'GET' 
        });
        const data = await response.json();
        return data.status === 'healthy';
    } catch (error) {
        console.error('[API] Health check failed:', error.message);
        return false;
    }
}

/**
 * Simplified POST request helper
 * 
 * @param {string} endpoint - API endpoint path
 * @param {object} body - Request body (will be JSON stringified)
 * @returns {Promise<object>} - Parsed JSON response
 */
async function apiPost(endpoint, body) {
    const response = await apiCall(endpoint, {
        method: 'POST',
        body: JSON.stringify(body)
    });
    return response.json();
}

/**
 * Simplified GET request helper
 * 
 * @param {string} endpoint - API endpoint path
 * @returns {Promise<object>} - Parsed JSON response
 */
async function apiGet(endpoint) {
    const response = await apiCall(endpoint, {
        method: 'GET'
    });
    return response.json();
}

// Display current configuration on load
console.log('[API Config] Initialized with:', {
    baseURL: API_CONFIG.baseURL,
    environment: window.location.hostname === 'localhost' ? 'local' : 'production',
    timeout: `${API_CONFIG.timeout}ms`,
    maxRetries: API_CONFIG.maxRetries
});
