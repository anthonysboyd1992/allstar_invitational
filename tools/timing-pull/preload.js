const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('timingPull', {
    getConfig: () => ipcRenderer.invoke('config:get'),
    saveConfig: (config) => ipcRenderer.invoke('config:save', config),
    chooseAssignFolder: () => ipcRenderer.invoke('assignfolder:choose'),
    listLineups: () => ipcRenderer.invoke('lineups:list'),
    pullLineups: (selectedKeys) => ipcRenderer.invoke('lineups:pull', selectedKeys),
});
