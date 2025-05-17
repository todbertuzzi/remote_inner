/**
 * Configurazione Pusher per la Scrivania Collaborativa
 */
(function() {
    // Verifica che Pusher sia disponibile
    if (typeof Pusher === 'undefined' || typeof scrivaniaPusherConfig === 'undefined') {
        console.error('Pusher o le configurazioni non sono disponibili');
        return;
    }

    // Inizializza Pusher con le configurazioni fornite da WordPress
    const pusher = new Pusher(scrivaniaPusherConfig.app_key, {
        cluster: scrivaniaPusherConfig.cluster,
        authEndpoint: scrivaniaPusherConfig.auth_endpoint,
        auth: {
            headers: {
                'X-WP-Nonce': scrivaniaPusherConfig.nonce
            }
        }
    });

    // Esponi globalmente l'istanza e le configurazioni
    window.scrivaniaPusher = pusher;
    window.scrivaniaPusherConfig = scrivaniaPusherConfig;

    // Aggiungi un evento quando il DOM è pronto
    document.addEventListener('DOMContentLoaded', function() {
        // Cerca l'elemento root di React
        const reactRoot = document.getElementById('react-tool-root');
        
        if (reactRoot) {
            // Dispara un evento custom per notificare l'app React che Pusher è pronto
            const event = new CustomEvent('pusherReady', { 
                detail: { 
                    pusher: pusher,
                    config: scrivaniaPusherConfig
                } 
            });
            
            reactRoot.dispatchEvent(event);
        }
    });
})();