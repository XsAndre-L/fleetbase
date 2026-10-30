import Service from '@ember/service';
import CoreSocket from '@fleetbase/ember-core/services/socket';
import config from '@fleetbase/console/config/environment';

class UnavailableSocketService extends Service {
    channels = [];
    available = false;

    instance() {
        return { state: 'closed', disconnect() {}, subscribe() {
            return { close() {}, unsubscribe() {}, listener() { return { once: async () => null }; }, async *[Symbol.asyncIterator]() {} };
        } };
    }

    async listen() {
        return null;
    }

    closeChannels() {}
}

export default config.APP.webappstack ? UnavailableSocketService : CoreSocket;
