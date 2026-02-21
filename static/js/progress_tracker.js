/**
 * Progress Tracker JavaScript Client
 * 
 * Usage:
 * const tracker = new ProgressTracker('my-session-id');
 * tracker.start();
 * tracker.onUpdate((progress) => {
 *   console.log('Progress:', progress.percent_complete + '%');
 * });
 */

class ProgressTracker {
    constructor(sessionId, options = {}) {
        this.sessionId = sessionId;
        this.apiUrl = options.apiUrl || '/api/websocket_progress.php';
        this.sseUrl = options.sseUrl || '/api/websocket_progress.php?sse=1';
        this.updateInterval = options.updateInterval || 1000; // ms
        this.onUpdateCallback = null;
        this.onErrorCallback = null;
        this.onCompleteCallback = null;
        this.eventSource = null;
        this.pollingInterval = null;
    }

    /**
     * Initialize progress session
     */
    async init() {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'init',
                    session_id: this.sessionId
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Failed to init progress:', error);
            return false;
        }
    }

    /**
     * Update progress (call from backend)
     */
    async update(phase, percent, message = '') {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'update',
                    session_id: this.sessionId,
                    phase: phase,
                    percent: percent,
                    message: message
                })
            });

            return (await response.json()).success;
        } catch (error) {
            console.error('Failed to update progress:', error);
            return false;
        }
    }

    /**
     * Report error
     */
    async error(errorMessage) {
        try {
            await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'error',
                    session_id: this.sessionId,
                    error: errorMessage
                })
            });
        } catch (error) {
            console.error('Failed to report error:', error);
        }
    }

    /**
     * Get current progress
     */
    async get() {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'get',
                    session_id: this.sessionId
                })
            });

            const data = await response.json();
            return data.success ? data.progress : null;
        } catch (error) {
            console.error('Failed to get progress:', error);
            return null;
        }
    }

    /**
     * Start tracking via Server-Sent Events
     */
    startSSE() {
        if (this.eventSource) {
            this.eventSource.close();
        }

        const url = this.sseUrl + '&session_id=' + encodeURIComponent(this.sessionId);
        this.eventSource = new EventSource(url);

        this.eventSource.addEventListener('progress', (event) => {
            const progress = JSON.parse(event.data);
            if (this.onUpdateCallback) {
                this.onUpdateCallback(progress);
            }
        });

        this.eventSource.addEventListener('error', (event) => {
            const error = JSON.parse(event.data);
            if (this.onErrorCallback) {
                this.onErrorCallback(error);
            }
            this.stop();
        });

        this.eventSource.addEventListener('completed', (event) => {
            if (this.onCompleteCallback) {
                this.onCompleteCallback();
            }
            this.stop();
        });

        this.eventSource.addEventListener('error', () => {
            this.stop();
        });
    }

    /**
     * Start tracking via polling
     */
    startPolling() {
        this.pollingInterval = setInterval(async () => {
            const progress = await this.get();
            
            if (progress) {
                if (this.onUpdateCallback) {
                    this.onUpdateCallback(progress);
                }

                if (progress.percent_complete >= 100) {
                    if (this.onCompleteCallback) {
                        this.onCompleteCallback();
                    }
                    this.stop();
                }

                if (progress.error_message) {
                    if (this.onErrorCallback) {
                        this.onErrorCallback({ error: progress.error_message });
                    }
                    this.stop();
                }
            }
        }, this.updateInterval);
    }

    /**
     * Start tracking
     */
    async start(useSSE = true) {
        // Initialize first
        const initialized = await this.init();
        if (!initialized) {
            console.error('Failed to initialize progress tracking');
            return;
        }

        // Try SSE if EventSource is supported, fall back to polling
        if (useSSE && typeof EventSource !== 'undefined') {
            this.startSSE();
        } else {
            this.startPolling();
        }
    }

    /**
     * Stop tracking
     */
    stop() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }

        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    /**
     * Register update callback
     */
    onUpdate(callback) {
        this.onUpdateCallback = callback;
    }

    /**
     * Register error callback
     */
    onError(callback) {
        this.onErrorCallback = callback;
    }

    /**
     * Register completion callback
     */
    onComplete(callback) {
        this.onCompleteCallback = callback;
    }
}

// Export for use in HTML
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ProgressTracker;
}
