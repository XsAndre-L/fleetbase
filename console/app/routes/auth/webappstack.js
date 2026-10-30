import Route from '@ember/routing/route';
import removeBootLoader from '../../utils/remove-boot-loader';

export default class AuthWebappstackRoute extends Route {
    beforeModel() {
        removeBootLoader();
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.start();
    }
}
