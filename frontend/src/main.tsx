import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/app/app';
import { initializeTheme } from '@/hooks/use-appearance';
import '@/styles/app.css';

const container = document.getElementById('root');

if (container === null) {
    throw new Error('Sehatly SPA root element #root is missing from index.html');
}

initializeTheme();

createRoot(container).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
