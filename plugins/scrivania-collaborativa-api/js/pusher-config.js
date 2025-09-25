/**
 * Improved Pusher configuration for Scrivania Collaborativa
 */
(function() {
    console.log('Pusher config script starting');
    
    // Ensure this script doesn't run multiple times
    if (window.pusherConfigInitialized) {
        console.log('Pusher config already initialized');
        return;
    }
    
    window.pusherConfigInitialized = true;
    
    // Log available objects
    console.log('Checking available objects:');
    console.log('- Pusher available:', typeof Pusher !== 'undefined');
    console.log('- scrivaniaPusherConfig available:', typeof scrivaniaPusherConfig !== 'undefined');
    
    // If Pusher isn't available, wait for it
    if (typeof Pusher === 'undefined') {
        console.warn('Pusher not available yet, waiting...');
        
        let attempts = 0;
        const maxAttempts = 10;
        
        const checkPusher = function() {
            attempts++;
            if (typeof Pusher !== 'undefined') {
                console.log('Pusher loaded after waiting');
                initializePusher();
            } else if (attempts < maxAttempts) {
                console.log(`Waiting for Pusher (attempt ${attempts}/${maxAttempts})...`);
                setTimeout(checkPusher, 500);
            } else {
                console.error('Pusher failed to load after multiple attempts');
            }
        };
        
        setTimeout(checkPusher, 500);
        return;
    }
    
    // If config isn't available, create fallback
    if (typeof scrivaniaPusherConfig === 'undefined') {
        console.warn('scrivaniaPusherConfig not available, creating fallback');
        
        // Try to get nonce from any available source
        let nonce = '';
        if (document.querySelector('input[name="_wpnonce"]')) {
            nonce = document.querySelector('input[name="_wpnonce"]').value;
        } else if (window.wpApiSettings && window.wpApiSettings.nonce) {
            nonce = window.wpApiSettings.nonce;
        }
        
        window.scrivaniaPusherConfig = {
            app_key: '36cf02242d86c80d6e7b', // Replace with your actual key
            cluster: 'eu',
            auth_endpoint: '/wp-json/scrivania/v1/pusher-auth',
            nonce: nonce
        };
    }
    
    initializePusher();
    
    function initializePusher() {
        try {
            // Initialize Pusher with the provided configurations
            const pusher = new Pusher(scrivaniaPusherConfig.app_key, {
                cluster: scrivaniaPusherConfig.cluster,
                authEndpoint: scrivaniaPusherConfig.auth_endpoint,
                auth: {
                    headers: {
                        'X-WP-Nonce': scrivaniaPusherConfig.nonce
                    }
                }
            });
            
            // Expose globally
            window.scrivaniaPusher = pusher;
            
            // Log connection status
            pusher.connection.bind('connected', () => {
                console.log('Pusher connected successfully');
            });
            
            pusher.connection.bind('error', (err) => {
                console.error('Pusher connection error:', err);
            });
            
            // Notify when DOM is ready
            const notifyReactWhenReady = function() {
                const reactRoot = document.getElementById('react-tool-root');
                
                if (reactRoot) {
                    console.log('Notifying React that Pusher is ready');
                    // Create custom event
                    const event = new CustomEvent('pusherReady', { 
                        detail: { 
                            pusher: pusher,
                            config: scrivaniaPusherConfig
                        } 
                    });
                    
                    reactRoot.dispatchEvent(event);
                } else {
                    console.warn('React root element not found, cannot dispatch event');
                }
            };
            
            // If DOM is already loaded, notify immediately
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                notifyReactWhenReady();
            } else {
                // Otherwise wait for DOM to be ready
                document.addEventListener('DOMContentLoaded', notifyReactWhenReady);
            }
            
            console.log("Pusher configured successfully");
        } catch (error) {
            console.error("Error initializing Pusher:", error);
        }
    }
})();