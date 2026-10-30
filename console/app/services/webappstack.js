import Service from '@ember/service';
import config from '@fleetbase/console/config/environment';
import window from 'ember-window-mock';

export default class WebappstackService extends Service {
    async request(operation, body = {}) {
        const url = new URL(`/integrations/webappstack/${operation}`, config.API.host || window.location.origin);
        const response = await window.fetch(url, {
            method: 'POST', credentials: 'include', redirect: 'error',
            headers: { 'Content-Type': 'application/json', 'X-WebAppStack-Auth': '1' },
            body: JSON.stringify(body),
        });
        if (!response.ok) {
            throw new Error(response.status === 403 || response.status === 401
                ? 'Delivery access is unavailable or revoked. Start again from your App Manager.'
                : 'Delivery login could not complete. Required setup or verification may still be pending.');
        }
        return response.json();
    }

    async begin() {
        const { redirectUri } = await this.request('start');
        const destination = new URL(redirectUri);
        const local = ['localhost', '127.0.0.1', '[::1]'].includes(destination.hostname);
        if (destination.username || destination.password || destination.hash
            || destination.pathname !== '/integrations/fleetbase/authorize'
            || (destination.protocol !== 'https:' && !(destination.protocol === 'http:' && local))) {
            throw new Error('The App Manager login destination is invalid.');
        }
        window.location.assign(destination.href);
    }
}
