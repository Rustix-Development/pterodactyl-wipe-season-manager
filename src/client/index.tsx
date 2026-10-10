import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import { setConfig } from './config';

export default definePterodactylExtension({
    setup({ screens, config }) {
        setConfig(config);

        screens.register('wipes', () => import('./screens/WipesScreen'));
        screens.register('overview', () => import('./screens/AdminOverview'));
    },
});
