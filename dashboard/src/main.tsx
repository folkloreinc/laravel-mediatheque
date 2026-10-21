import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { App } from './App';
import { createApi } from './api';
import './page.css';
import type { DashboardConfig } from './types';

const element = document.getElementById('mediatheque-dashboard');

if (element !== null) {
    const config = JSON.parse(element.dataset.config ?? '{}') as DashboardConfig;

    createRoot(element).render(
        <StrictMode>
            <App api={createApi(config)} canOperate={config.canOperate} />
        </StrictMode>,
    );
}
