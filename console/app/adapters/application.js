import CoreAdapter from '@fleetbase/ember-core/adapters/application';

export default class ApplicationAdapter extends CoreAdapter {
    setupHeaders() {
        const headers = super.setupHeaders();
        if (this.session?.data?.authenticated?.token === 'webappstack-cookie') {
            delete headers.Authorization;
            headers['X-WebAppStack-Auth'] = '1';
            delete headers['Access-Console-Sandbox'];
            delete headers['Access-Console-Sandbox-Key'];
        }
        return headers;
    }
}
