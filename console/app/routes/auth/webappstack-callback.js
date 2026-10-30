import Route from '@ember/routing/route';
import window from 'ember-window-mock';
import removeBootLoader from '../../utils/remove-boot-loader';

export default class AuthWebappstackCallbackRoute extends Route {
    controllerName = 'auth.webappstack';
    templateName = 'auth.webappstack';

    beforeModel() {
        removeBootLoader();
    }

    model() {
        const fragment = new URLSearchParams(window.location.hash.slice(1));
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
        return { code: fragment.get('code'), state: fragment.get('state'), transaction: fragment.get('transaction') };
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.start(model);
    }
}
