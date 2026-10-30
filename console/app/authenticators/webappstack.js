import Base from 'ember-simple-auth/authenticators/base';
import { inject as service } from '@ember/service';

export default class WebappstackAuthenticator extends Base {
    @service webappstack;

    restore(data) {
        if (data.token !== 'webappstack-cookie') {
            return Promise.reject(new Error('Invalid App-linked session.'));
        }
        return this.webappstack.request('session');
    }

    authenticate() {
        return this.webappstack.request('session');
    }

    invalidate() {
        return this.webappstack.request('logout');
    }
}
