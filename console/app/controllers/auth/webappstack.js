import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class AuthWebappstackController extends Controller {
    @service webappstack;
    @service session;
    @tracked error = null;
    @tracked state = 'STARTING';
    @tracked code = '';
    clientToken = null;

    async start(payload) {
        this.error = null;
        this.state = 'STARTING';
        try {
            if (!payload) {
                return await this.webappstack.begin();
            }
            if (!payload.code || !payload.state || !payload.transaction) {
                throw new Error('This delivery login did not start in this browser. Start again from App Manager.');
            }
            const result = await this.webappstack.request('complete', payload);
            if (result.state === 'MFA_REQUIRED') {
                const challenge = await this.webappstack.request('mfa/challenge');
                this.clientToken = challenge.clientToken;
                this.state = 'MFA_REQUIRED';
                return;
            }
            if (result.state !== 'AUTHENTICATED') {
                throw new Error('Delivery setup is incomplete.');
            }
            return await this.session.authenticate('authenticator:webappstack');
        } catch (error) {
            this.error = error.message;
            this.state = 'FAILED';
        }
    }

    @action async verify(event) {
        event.preventDefault();
        this.error = null;
        try {
            await this.webappstack.request('mfa/verify', { code: this.code, clientToken: this.clientToken });
            this.code = '';
            this.clientToken = null;
            await this.session.authenticate('authenticator:webappstack');
        } catch (error) {
            this.error = 'The verification code was not accepted. Check it and try again, or start a new login.';
        }
    }

    @action retry() {
        this.code = '';
        this.clientToken = null;
        return this.start();
    }
}
