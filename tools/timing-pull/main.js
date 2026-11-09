const { app, BrowserWindow, dialog, ipcMain, net } = require('electron');
const fs = require('node:fs/promises');
const path = require('node:path');

const defaultConfig = {
    feedUrl: '',
    assignFolder: '',
};

function configPath() {
    return path.join(app.getPath('userData'), 'config.json');
}

async function readConfig() {
    try {
        return { ...defaultConfig, ...JSON.parse(await fs.readFile(configPath(), 'utf8')) };
    } catch {
        return { ...defaultConfig };
    }
}

async function writeConfig(config) {
    const next = {
        feedUrl: String(config.feedUrl ?? '').trim(),
        assignFolder: String(config.assignFolder ?? '').trim(),
    };

    await fs.mkdir(app.getPath('userData'), { recursive: true });
    await fs.writeFile(configPath(), JSON.stringify(next, null, 2));

    return next;
}

function httpUrl(raw) {
    let url;

    try {
        url = new URL(String(raw ?? '').trim());
    } catch {
        throw new Error('Paste the Timing File Feed URL from Settings.');
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        throw new Error('The Timing File Feed URL must start with http or https.');
    }

    return url;
}

function safeFileName(name) {
    const base = path.basename(String(name).replaceAll('\\', '/'));

    if (!base || base === '.' || base === '..') {
        throw new Error(`Skipped an invalid file name: ${name}`);
    }

    return base;
}

function normalizeSessions(payload) {
    if (Array.isArray(payload?.races)) {
        return payload.races.map((session) => ({
            key: String(session.key ?? ''),
            name: String(session.name ?? 'Race'),
            type: String(session.type ?? 'Races'),
            laps: Number.parseInt(session.laps, 10) || 0,
            files: (Array.isArray(session.files) ? session.files : []).map((file) => ({
                name: safeFileName(file?.name),
                contents: String(file?.contents ?? ''),
            })),
        })).filter((session) => session.key && session.files.length);
    }

    return [];
}

async function fetchJson(url) {
    const response = await net.fetch(url.toString(), { signal: AbortSignal.timeout(30000) });

    if (response.status === 403) {
        throw new Error('The token was rejected. Copy a new URL from Settings.');
    }

    if (!response.ok) {
        throw new Error(`The feed returned HTTP ${response.status}.`);
    }

    return response.json();
}

async function fetchLineupSessions() {
    const config = await readConfig();

    return normalizeSessions(await fetchJson(httpUrl(config.feedUrl)));
}

async function listLineups() {
    return { races: await fetchLineupSessions() };
}

async function pullLineups(_event, selectedKeys = []) {
    const config = await readConfig();
    const folder = String(config.assignFolder ?? '').trim();
    const wanted = new Set((Array.isArray(selectedKeys) ? selectedKeys : []).map(String));

    if (!folder) {
        throw new Error('Choose an Assign Folder path first.');
    }

    try {
        await fs.access(folder);
    } catch {
        throw new Error(`Assign Folder does not exist: ${folder}`);
    }

    if (wanted.size === 0) {
        throw new Error('Select at least one race to pull.');
    }

    const sessions = await fetchLineupSessions();
    const written = [];

    for (const session of sessions) {
        if (!wanted.has(session.key)) {
            continue;
        }

        for (const file of session.files) {
            await fs.writeFile(path.join(folder, file.name), file.contents, 'utf8');
            written.push(file.name);
        }
    }

    return {
        folder,
        files: written,
        races: sessions,
    };
}

function createWindow() {
    const window = new BrowserWindow({
        width: 580,
        height: 800,
        minWidth: 460,
        minHeight: 560,
        title: 'Allstar Invitational Race Manager Puller',
        icon: path.join(__dirname, 'renderer', 'logo.png'),
        backgroundColor: '#0f172a',
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
        },
    });

    window.removeMenu();
    window.loadFile(path.join(__dirname, 'renderer', 'index.html'));
}

app.whenReady().then(() => {
    ipcMain.handle('config:get', () => readConfig());
    ipcMain.handle('config:save', (_event, config) => writeConfig(config));
    ipcMain.handle('assignfolder:choose', async () => {
        const result = await dialog.showOpenDialog({
            title: 'Choose Assign Folder',
            properties: ['openDirectory', 'createDirectory'],
        });

        if (result.canceled || !result.filePaths[0]) {
            return null;
        }

        const config = await readConfig();

        return writeConfig({ ...config, assignFolder: result.filePaths[0] });
    });
    ipcMain.handle('lineups:list', () => listLineups());
    ipcMain.handle('lineups:pull', (event, selectedKeys) => pullLineups(event, selectedKeys));

    createWindow();
});

app.on('window-all-closed', () => {
    app.quit();
});
