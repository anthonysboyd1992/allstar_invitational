const { app, BrowserWindow, dialog, ipcMain, net } = require('electron');
const fs = require('node:fs/promises');
const path = require('node:path');
const { RMonitorClient, resultsUrl, disconnectUrl } = require('./rmonitor');

const defaultConfig = {
    feedUrl: '',
    assignFolder: '',
    rmonitorHost: '127.0.0.1',
    rmonitorPort: '50000',
};

function normalizeConfig(config) {
    const feedTarget = config.feedTarget === 'live' ? 'live' : 'local';
    const localFeedUrl = String(config.localFeedUrl ?? '').trim();
    const liveFeedUrl = String(config.liveFeedUrl ?? '').trim();
    const fromTarget = feedTarget === 'live' ? liveFeedUrl : localFeedUrl;
    const feedUrl = String(config.feedUrl ?? '').trim() || fromTarget || localFeedUrl || liveFeedUrl;

    return {
        feedUrl,
        assignFolder: String(config.assignFolder ?? '').trim(),
        rmonitorHost: String(config.rmonitorHost ?? '127.0.0.1').trim() || '127.0.0.1',
        rmonitorPort: String(config.rmonitorPort ?? '50000').trim() || '50000',
    };
}

function configPath() {
    return path.join(app.getPath('userData'), 'config.json');
}

async function readConfig() {
    try {
        return normalizeConfig({ ...defaultConfig, ...JSON.parse(await fs.readFile(configPath(), 'utf8')) });
    } catch {
        return normalizeConfig({ ...defaultConfig });
    }
}

async function writeConfig(config) {
    const next = normalizeConfig(config);

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

function broadcastRmonitor(snapshot) {
    for (const window of BrowserWindow.getAllWindows()) {
        window.webContents.send('rmonitor:update', snapshot);
    }
}

let feedWrites = Promise.resolve();

function queueFeedWrite(work) {
    feedWrites = feedWrites.then(work, work);

    return feedWrites;
}

const rmonitor = new RMonitorClient(
    (snapshot) => broadcastRmonitor(snapshot),
    (session) => {
        queueFeedWrite(() => pushSession(session).then((sent) => {
            const cars = session.standings?.length ?? 0;
            rmonitor.recordOutput(`POST ${sent.destination} → ${session.name} (${cars} car${cars === 1 ? '' : 's'})`);
            rmonitor.setLastPush(`Sent ${session.name} to ${sent.host} (${cars} car${cars === 1 ? '' : 's'}).`, 'ok');
        }).catch((error) => {
            const message = error?.message ?? String(error);
            rmonitor.recordOutput(`POST failed: ${message}`);
            rmonitor.setLastPush(message, 'error');
        }));
    },
);

async function postJson(url, body) {
    const response = await net.fetch(url.toString(), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(30000),
    });

    if (response.status === 403) {
        throw new Error('The token was rejected. Copy a new URL from Settings.');
    }

    if (response.status === 422) {
        let detail = '';

        try {
            const body = await response.json();
            detail = typeof body.message === 'string' && body.message !== ''
                ? body.message
                : JSON.stringify(body.errors ?? body);
        } catch {
            detail = '';
        }

        throw new Error(detail !== '' ? `Standings rejected: ${detail}` : 'The standings were rejected.');
    }

    if (! response.ok) {
        throw new Error(`Race Manager returned HTTP ${response.status}.`);
    }

    return response.json();
}

async function pushSession(session) {
    const config = await readConfig();
    const destination = resultsUrl(config.feedUrl);

    await postJson(destination, {
        source_key: String(session.id ?? '').slice(0, 64),
        name: String(session.name ?? 'Session').slice(0, 255),
        flag: session.flag ? String(session.flag).slice(0, 50) : null,
        laps_to_go: session.laps_to_go == null || session.laps_to_go === '' ? null : Number.parseInt(session.laps_to_go, 10) || 0,
        race_laps: session.race_laps ? Number.parseInt(session.race_laps, 10) || null : null,
        live: Boolean(session.live),
        standings: (Array.isArray(session.standings) ? session.standings : []).map((row) => ({
            position: Number.parseInt(row.position, 10) || 0,
            number: String(row.number ?? ''),
            transponder: String(row.transponder ?? ''),
            registration: String(row.registration ?? ''),
            laps: row.laps === null || row.laps === undefined || row.laps === '' ? null : Number.parseInt(row.laps, 10) || 0,
            total_time: String(row.total_time ?? ''),
            last_lap: String(row.last_lap ?? ''),
            best_lap: String(row.best_lap ?? ''),
            start_position: Number.parseInt(row.start_position, 10) || 0,
            passes: Number.parseInt(row.passes, 10) || 0,
            first_name: String(row.first_name ?? '').slice(0, 100),
            last_name: String(row.last_name ?? '').slice(0, 100),
        })),
    });

    return {
        destination: destination.toString(),
        host: destination.host,
    };
}

async function notifyDisconnected() {
    try {
        const config = await readConfig();

        if (! config.feedUrl) {
            return;
        }

        await postJson(disconnectUrl(config.feedUrl), {});
    } catch {
        // Local disconnect still stands if Race Manager is unreachable.
    }
}

function createWindow() {
    const window = new BrowserWindow({
        width: 580,
        height: 1080,
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
    ipcMain.handle('rmonitor:status', () => rmonitor.snapshot());
    ipcMain.handle('rmonitor:clear-output', () => {
        rmonitor.clearOutput();

        return rmonitor.snapshot();
    });
    ipcMain.handle('rmonitor:connect', async () => {
        const config = await readConfig();
        rmonitor.connect(config.rmonitorHost, config.rmonitorPort);

        return rmonitor.snapshot();
    });
    ipcMain.handle('rmonitor:disconnect', async () => {
        rmonitor.disconnect();
        await queueFeedWrite(() => notifyDisconnected());

        return rmonitor.snapshot();
    });

    createWindow();
});

app.on('window-all-closed', () => {
    rmonitor.disconnect();
    queueFeedWrite(() => notifyDisconnected()).finally(() => app.quit());
});
