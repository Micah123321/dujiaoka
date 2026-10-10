import { createWorkbenchBridge } from './mount';
import './shell.css';
import './workbench.css';

if (window.__dujiaokaAdminBridge) window.__dujiaokaAdminBridge.refresh();
else window.__dujiaokaAdminBridge = createWorkbenchBridge();
