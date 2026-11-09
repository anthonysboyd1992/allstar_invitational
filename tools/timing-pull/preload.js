const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('timingPull', {
    getConfig: () => ipcRenderer.invoke('config:get'),
    saveConfig: (config) => ipcRenderer.invoke('config:save', config),
    chooseAssignFolder: () => ipcRenderer.invoke('assignfolder:choose'),
    listLineups: () => ipcRenderer.invoke('lineups:list'),
    pullLineups: (selectedKeys) => ipcRenderer.invoke('lineups:pull', selectedKeys),
    rmonitorStatus: () => ipcRenderer.invoke('rmonitor:status'),
    rmonitorConnect: () => ipcRenderer.invoke('rmonitor:connect'),
    rmonitorDisconnect: () => ipcRenderer.invoke('rmonitor:disconnect'),
    rmonitorClearOutput: () => ipcRenderer.invoke('rmonitor:clear-output'),
    onRmonitorUpdate: (callback) => {
        const listener = (_event, snapshot) => callback(snapshot);
        ipcRenderer.on('rmonitor:update', listener);

        return () => ipcRenderer.removeListener('rmonitor:update', listener);
    },
});
