const tcp = require('node:net');
const { randomUUID } = require('node:crypto');

const MAX_CAPTURED = 20;
const MAX_OUTPUT = 250;
const LIVE_PUSH_MS = 200;
const MIN_LAP_MS = 1000;
const MAX_LAP_MS = 180000;

function splitFields(line) {
    const fields = [];
    let current = '';
    let quoted = false;

    for (const character of line) {
        if (character === '"') {
            quoted = ! quoted;
            continue;
        }

        if (character === ',' && ! quoted) {
            fields.push(current.trim());
            current = '';
            continue;
        }

        current += character;
    }

    fields.push(current.trim());

    return fields;
}

function clockMs(time) {
    const raw = String(time ?? '').trim();

    if (raw === '' || raw === '--') {
        return null;
    }

    const match = raw.match(/^(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d+)?)$/);

    if (! match) {
        return null;
    }

    const seconds = Number(match[3]);
    let minutes = 0;
    let hours = 0;

    if (match[1] && match[2]) {
        hours = Number.parseInt(match[1], 10);
        minutes = Number.parseInt(match[2], 10);
    } else if (match[1]) {
        minutes = Number.parseInt(match[1], 10);
    }

    return Math.round(((hours * 3600) + (minutes * 60) + seconds) * 1000);
}

function lapMs(time) {
    const ms = clockMs(time);

    if (ms === null || ms < MIN_LAP_MS || ms > MAX_LAP_MS) {
        return null;
    }

    return ms;
}

function usableLapTime(time) {
    return lapMs(time) === null ? '' : String(time).trim();
}

function fasterLap(left, right) {
    const leftMs = lapMs(left);
    const rightMs = lapMs(right);

    if (leftMs === null) {
        return usableLapTime(right);
    }

    if (rightMs === null || leftMs <= rightMs) {
        return usableLapTime(left);
    }

    return usableLapTime(right);
}

function emptySession() {
    return {
        id: randomUUID(),
        name: 'Session',
        flag: '',
        laps_to_go: null,
        race_laps: 0,
        competitors: new Map(),
        race: new Map(),
        qual: new Map(),
        passings: new Map(),
        stats: new Map(),
    };
}

function standingFrom(session, registration, position, extra = {}) {
    const competitor = session.competitors.get(registration) ?? {};
    const passing = session.passings?.get(registration) ?? {};
    const stats = session.stats?.get(registration) ?? {};

    return {
        position,
        registration,
        number: String(competitor.number ?? ''),
        transponder: String(competitor.transponder || registration || ''),
        first_name: String(competitor.first_name ?? ''),
        last_name: String(competitor.last_name ?? ''),
        laps: extra.laps ?? passing.laps ?? null,
        total_time: String(extra.total_time ?? extra.best_time ?? passing.total_time ?? ''),
        last_lap: usableLapTime(extra.last_lap) || usableLapTime(passing.last_lap),
        best_lap: fasterLap(extra.best_time, fasterLap(passing.best_time, extra.last_lap)),
        start_position: stats.start_position || 0,
        passes: stats.passes || 0,
    };
}

function rankedPassings(session) {
    return [...(session.passings ?? new Map()).entries()]
        .sort((left, right) => {
            if (right[1].laps !== left[1].laps) {
                return right[1].laps - left[1].laps;
            }

            return String(left[1].total_time).localeCompare(String(right[1].total_time));
        })
        .map(([registration, row], index) => standingFrom(session, registration, index + 1, row));
}

function sessionStandings(session) {
    const source = session.race.size ? session.race : session.qual;

    if (source.size) {
        const standings = [];

        for (const [registration, row] of source) {
            standings.push(standingFrom(session, registration, row.position || 0, row));
        }

        return standings.sort((left, right) => (left.position || 9999) - (right.position || 9999));
    }

    const passings = rankedPassings(session);

    if (passings.length) {
        return passings;
    }

    return [...session.competitors.keys()].map((registration, index) => (
        standingFrom(session, registration, index + 1)
    ));
}

function flagFromHeartbeat(fields) {
    const fromProtocol = String(fields[5] ?? '').trim();

    if (fromProtocol !== '') {
        return fromProtocol;
    }

    const match = [...fields].reverse().find((field) => (
        /^(green|yellow|red|white|black|finish|checkered|none|p-start|p-stop|stop|start)$/i.test(String(field).trim())
    ));

    return match ? String(match).trim() : '';
}

function parsePositionalStanding(fields) {
    const first = String(fields[1] ?? '');
    const second = String(fields[2] ?? '');
    const firstNumber = Number.parseInt(first, 10);

    if (Number.isInteger(firstNumber) && firstNumber >= 0 && firstNumber <= 200 && second !== '') {
        return {
            position: firstNumber,
            registration: second,
            third: fields[3] ?? '',
            fourth: fields[4] ?? '',
        };
    }

    return {
        position: 0,
        registration: first,
        third: second,
        fourth: fields[3] ?? '',
    };
}

function applyStandingStats(session, standings) {
    if (! session.stats) {
        session.stats = new Map();
    }

    for (const row of standings) {
        const key = row.registration || row.transponder || row.number;
        const stats = session.stats.get(key) ?? { start_position: 0, last_position: 0, passes: 0 };

        if (row.position > 0) {
            if (! stats.start_position) {
                stats.start_position = row.position;
            }

            if (stats.last_position > 0 && row.position < stats.last_position) {
                stats.passes += stats.last_position - row.position;
            }

            stats.last_position = row.position;
        }

        session.stats.set(key, stats);
        row.start_position = stats.start_position;
        row.passes = stats.passes;
    }
}

function sessionView(session, live) {
    const standings = sessionStandings(session);
    applyStandingStats(session, standings);

    const leaderLaps = standings.reduce((max, row) => Math.max(max, Number(row.laps) || 0), 0);

    if (session.laps_to_go != null && session.laps_to_go >= 0 && leaderLaps > 0) {
        session.race_laps = leaderLaps + session.laps_to_go;
    }

    return {
        id: session.id,
        name: session.name,
        flag: session.flag,
        live,
        standings,
        laps_to_go: session.laps_to_go,
        race_laps: session.race_laps || null,
    };
}

class RMonitorClient {
    constructor(onChange, onPush) {
        this.onChange = onChange;
        this.onPush = onPush;
        this.socket = null;
        this.buffer = '';
        this.live = emptySession();
        this.captured = [];
        this.host = '127.0.0.1';
        this.port = 50000;
        this.status = 'Disconnected';
        this.reconnectTimer = null;
        this.pushTimer = null;
        this.shouldRun = false;
        this.liveDirty = false;
        this.output = [];
        this.lastPush = { message: '', kind: '' };
    }

    snapshot() {
        const sessions = this.captured.map((session) => sessionView(session, false));

        if (sessionStandings(this.live).length || this.live.name !== 'Session') {
            sessions.unshift(sessionView(this.live, true));
        }

        return {
            status: this.status,
            connected: Boolean(this.socket),
            host: this.host,
            port: this.port,
            sessions,
            output: [...this.output],
            lastPush: this.lastPush,
        };
    }

    setLastPush(message, kind = '') {
        this.lastPush = { message: String(message ?? ''), kind: String(kind ?? '') };
        this.emit();
    }

    recordOutput(line) {
        this.output.push(line);

        if (this.output.length > MAX_OUTPUT) {
            this.output = this.output.slice(-MAX_OUTPUT);
        }
    }

    clearOutput() {
        this.output = [];
        this.emit();
    }

    connect(host, port) {
        this.host = String(host ?? '127.0.0.1').trim() || '127.0.0.1';
        this.port = Number.parseInt(port, 10) || 50000;
        this.shouldRun = true;
        this.openSocket();
    }

    disconnect() {
        this.shouldRun = false;
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = null;
        clearTimeout(this.pushTimer);
        this.pushTimer = null;

        if (this.liveDirty || sessionStandings(this.live).length) {
            this.liveDirty = false;
            this.pushSession(this.live, false);
        }

        this.closeSocket();
        this.status = 'Disconnected';
        this.emit();
    }

    openSocket() {
        this.closeSocket();
        this.status = `Connecting to ${this.host}:${this.port}…`;
        this.emit();

        const socket = tcp.connect({ host: this.host, port: this.port });
        this.socket = socket;

        socket.setEncoding('utf8');
        socket.on('connect', () => {
            if (this.socket !== socket) {
                return;
            }

            this.status = `Listening on ${this.host}:${this.port}`;
            this.emit();
        });
        socket.on('data', (chunk) => this.ingest(chunk));
        socket.on('error', (error) => {
            this.status = error.message || 'RMonitor connection failed.';
            this.emit();
        });
        socket.on('close', () => {
            if (this.socket === socket) {
                this.socket = null;
            }

            if (this.shouldRun) {
                this.status = `Reconnecting to ${this.host}:${this.port}…`;
                this.emit();
                this.reconnectTimer = setTimeout(() => {
                    if (this.shouldRun) {
                        this.openSocket();
                    }
                }, 2000);
            }
        });
    }

    closeSocket() {
        if (! this.socket) {
            return;
        }

        const socket = this.socket;
        this.socket = null;
        socket.removeAllListeners();
        socket.destroy();
    }

    ingest(chunk) {
        this.buffer += String(chunk ?? '');
        const lines = this.buffer.split(/\r\n|\n|\r/);
        this.buffer = lines.pop() ?? '';

        for (const line of lines) {
            const trimmed = line.trim();

            if (! trimmed) {
                continue;
            }

            this.recordOutput(trimmed);
            this.handleLine(trimmed);
        }

        if (this.liveDirty) {
            this.scheduleLivePush();
        }

        this.emit();
    }

    handleLine(line) {
        const fields = splitFields(line);
        const type = fields[0];

        if (type === '$I') {
            this.archiveLive();
            this.live = emptySession();
            return;
        }

        if (type === '$B') {
            const name = fields[2] || fields[1] || 'Session';

            if (name !== this.live.name && sessionStandings(this.live).length) {
                this.archiveLive();
                const competitors = this.live.competitors;
                this.live = emptySession();
                this.live.competitors = competitors;
            }

            this.live.name = name;
            this.liveDirty = true;
            return;
        }

        if (type === '$F') {
            const flag = flagFromHeartbeat(fields);
            const lapsToGoRaw = String(fields[1] ?? '').trim();
            let dirty = false;

            if (flag !== this.live.flag) {
                this.live.flag = flag;
                dirty = true;
            }

            if (/^\d+$/.test(lapsToGoRaw)) {
                const lapsToGo = Number.parseInt(lapsToGoRaw, 10);

                if (lapsToGo !== this.live.laps_to_go) {
                    this.live.laps_to_go = lapsToGo;
                    dirty = true;
                }
            }

            if (dirty) {
                this.liveDirty = true;
            }

            return;
        }

        if (type === '$A') {
            const registration = fields[1] ?? '';

            if (! registration) {
                return;
            }

            this.live.competitors.set(registration, {
                number: fields[2] ?? '',
                transponder: fields[3] ?? '',
                first_name: fields[4] ?? '',
                last_name: fields[5] ?? '',
            });
            this.liveDirty = true;
            return;
        }

        if (type === '$COMP') {
            const registration = fields[1] ?? '';

            if (! registration) {
                return;
            }

            this.live.competitors.set(registration, {
                number: fields[2] ?? '',
                transponder: '',
                first_name: fields[4] ?? '',
                last_name: fields[5] ?? '',
            });
            this.liveDirty = true;
            return;
        }

        if (type === '$G') {
            const parsed = parsePositionalStanding(fields);

            if (! parsed.registration) {
                return;
            }

            this.live.race.set(parsed.registration, {
                position: parsed.position,
                laps: parsed.third === '' ? null : Number.parseInt(parsed.third, 10) || 0,
                total_time: parsed.fourth ?? '',
            });
            this.liveDirty = true;
            return;
        }

        if (type === '$H') {
            const parsed = parsePositionalStanding(fields);

            if (! parsed.registration) {
                return;
            }

            const existing = this.live.qual.get(parsed.registration) ?? {};

            this.live.qual.set(parsed.registration, {
                position: parsed.position,
                best_lap: Number.parseInt(parsed.third, 10) || existing.best_lap || 0,
                best_time: fasterLap(parsed.fourth, existing.best_time),
            });
            this.liveDirty = true;
            return;
        }

        if (type === '$J') {
            const registration = fields[1] ?? '';

            if (! registration) {
                return;
            }

            const lastLap = usableLapTime(fields[2]);
            const totalTime = fields[3] ?? '';
            const passing = this.live.passings.get(registration) ?? {
                laps: 0,
                last_lap: '',
                total_time: '',
                best_time: '',
            };

            passing.total_time = totalTime || passing.total_time;

            if (lastLap) {
                passing.laps += 1;
                passing.last_lap = lastLap;
                passing.best_time = fasterLap(lastLap, passing.best_time);
            }

            this.live.passings.set(registration, passing);

            const competitor = [...this.live.competitors.values()].find((row) => (
                String(row.transponder) === registration || String(row.number) === registration
            ));

            if (competitor && competitor.transponder && ! this.live.competitors.has(registration)) {
                this.live.competitors.set(registration, competitor);
            }

            const race = this.live.race.get(registration);

            if (race) {
                if (totalTime !== '') {
                    race.total_time = totalTime;
                }

                if (race.laps == null || passing.laps > race.laps) {
                    race.laps = passing.laps;
                }
            }

            this.liveDirty = true;
            return;
        }
    }

    archiveLive() {
        this.flushLive();

        if (! sessionStandings(this.live).length) {
            return;
        }

        const archived = {
            id: this.live.id,
            name: this.live.name,
            flag: this.live.flag,
            laps_to_go: this.live.laps_to_go,
            race_laps: this.live.race_laps,
            competitors: new Map(this.live.competitors),
            race: new Map(this.live.race),
            qual: new Map(this.live.qual),
            passings: new Map(this.live.passings),
            stats: new Map(this.live.stats),
        };
        this.captured.unshift(archived);
        this.captured = this.captured.slice(0, MAX_CAPTURED);
        this.pushSession(archived, false);
    }

    scheduleLivePush() {
        if (this.pushTimer) {
            return;
        }

        this.pushTimer = setTimeout(() => this.flushLive(), LIVE_PUSH_MS);
    }

    flushLive() {
        clearTimeout(this.pushTimer);
        this.pushTimer = null;

        if (! this.liveDirty) {
            return;
        }

        this.liveDirty = false;
        this.pushSession(this.live, true);
    }

    pushSession(session, live) {
        const view = sessionView(session, live);

        if (! view.standings.length && view.name === 'Session' && ! view.flag) {
            return;
        }

        this.onPush?.(view);
    }

    emit() {
        this.onChange?.(this.snapshot());
    }
}

function resultsUrl(feedUrl) {
    let url;

    try {
        url = new URL(String(feedUrl ?? '').trim());
    } catch {
        throw new Error('Paste the Timing File Feed URL from Settings first.');
    }

    if (url.pathname.endsWith('/timing/lineups.json') || url.pathname.endsWith('lineups.json')) {
        url.pathname = url.pathname.replace(/lineups\.json$/, 'results.json');
    } else {
        url.pathname = '/timing/results.json';
    }

    return url;
}

function disconnectUrl(feedUrl) {
    const url = resultsUrl(feedUrl);
    url.pathname = url.pathname.replace(/results\.json$/, 'results/disconnect.json');

    return url;
}

module.exports = {
    RMonitorClient,
    resultsUrl,
    disconnectUrl,
    sessionStandings,
    splitFields,
};
