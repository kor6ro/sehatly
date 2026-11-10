import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/app/app';
import { initializeTheme } from '@/hooks/use-appearance';
import { detectPortal, PORTAL_NAMA } from '@/lib/portal';
import '@/styles/app.css';

const container = document.getElementById('root');

if (container === null) {
    throw new Error('Sehatly SPA root element #root is missing from index.html');
}

initializeTheme();
document.title = PORTAL_NAMA[detectPortal()];

createRoot(container).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
