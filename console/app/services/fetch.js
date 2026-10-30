import CoreFetch from '@fleetbase/ember-core/services/fetch';

export default class FetchService extends CoreFetch {
    getHeaders() {
        const headers = super.getHeaders();
        if (this.session?.data?.authenticated?.token === 'webappstack-cookie') {
            delete headers.Authorization;
            headers['X-WebAppStack-Auth'] = '1';
            delete headers['Access-Console-Sandbox'];
            delete headers['Access-Console-Sandbox-Key'];
        }
        return headers;
    }
}
