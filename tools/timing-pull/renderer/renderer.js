const feedUrl = document.getElementById('feedUrl');
const assignFolder = document.getElementById('assignFolder');
const chooseFolder = document.getElementById('chooseFolder');
const refresh = document.getElementById('refresh');
const selectAll = document.getElementById('selectAll');
const selectNone = document.getElementById('selectNone');
const pull = document.getElementById('pull');
const races = document.getElementById('races');
const status = document.getElementById('status');
const rmonitorHost = document.getElementById('rmonitorHost');
const rmonitorPort = document.getElementById('rmonitorPort');
const listen = document.getElementById('listen');
const disconnect = document.getElementById('disconnect');
const showOutput = document.getElementById('showOutput');
const rmonitorOutputWrap = document.getElementById('rmonitorOutputWrap');
const rmonitorOutput = document.getElementById('rmonitorOutput');
const clearOutput = document.getElementById('clearOutput');
const sessions = document.getElementById('sessions');
const rmonitorStatus = document.getElementById('rmonitorStatus');
const pushStatus = document.getElementById('pushStatus');

let raceList = [];

function setStatus(message, kind = '') {
    status.textContent = message;
    status.className = kind;
}

function selectedKeys() {
    return [...races.querySelectorAll('input.race-box:checked')].map((input) => input.value);
}

function groupedTypes() {
    const types = [];
    const index = new Map();

    for (const race of raceList) {
        if (!index.has(race.type)) {
            const group = { name: race.type, races: [] };
            index.set(race.type, group);
            types.push(group);
        }

        index.get(race.type).races.push(race);
    }

    return types;
}

function renderRaces(nextRaces, selected = null) {
    raceList = nextRaces;
    races.replaceChildren();

    if (!nextRaces.length) {
        const empty = document.createElement('li');
        empty.className = 'empty';
        empty.textContent = 'No lineup files to pull. Set races to Lineups on Results or Lineups to send them here.';
        races.append(empty);
        return;
    }

    const keep = selected === null ? new Set(nextRaces.map((race) => race.key)) : new Set(selected);

    for (const type of groupedTypes()) {
        const typeItem = document.createElement('li');
        const typeLabel = document.createElement('label');
        typeLabel.className = 'type-heading';

        const typeBox = document.createElement('input');
        typeBox.type = 'checkbox';
        typeBox.checked = type.races.every((race) => keep.has(race.key));
        typeBox.addEventListener('change', () => {
            const keys = new Set(selectedKeys());

            for (const race of type.races) {
                if (typeBox.checked) {
                    keys.add(race.key);
                } else {
                    keys.delete(race.key);
                }
            }

            renderRaces(raceList, [...keys]);
        });

        const typeName = document.createElement('span');
        typeName.textContent = type.name;

        typeLabel.append(typeBox, typeName);
        typeItem.append(typeLabel);
        races.append(typeItem);

        for (const race of type.races) {
            const item = document.createElement('li');
            const label = document.createElement('label');
            label.className = 'race';

            const box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'race-box';
            box.value = race.key;
            box.checked = keep.has(race.key);

            const name = document.createElement('span');
            name.textContent = race.name;

            const laps = document.createElement('span');
            laps.className = 'laps';
            laps.textContent = `${race.laps} lap${race.laps === 1 ? '' : 's'}`;

            const files = document.createElement('span');
            files.className = 'files';
            files.textContent = race.files.map((file) => file.name).join(', ');

            label.append(box, name, laps, files);
            item.append(label);
            races.append(item);
        }
    }
}

async function persist() {
    await window.timingPull.saveConfig({
        feedUrl: feedUrl.value.trim(),
        assignFolder: assignFolder.value.trim(),
        rmonitorHost: rmonitorHost.value.trim() || '127.0.0.1',
        rmonitorPort: rmonitorPort.value.trim() || '50000',
    });
}

function applyConfig(config) {
    feedUrl.value = config.feedUrl ?? '';
    assignFolder.value = config.assignFolder ?? '';
    rmonitorHost.value = config.rmonitorHost ?? '127.0.0.1';
    rmonitorPort.value = config.rmonitorPort ?? '50000';
}

function setRmonitorStatus(message, kind = '') {
    rmonitorStatus.textContent = message;
    rmonitorStatus.className = kind;
}

function renderSessions(list) {
    sessions.replaceChildren();

    if (! list.length) {
        const empty = document.createElement('li');
        empty.className = 'empty';
        empty.textContent = 'No sessions yet. After Race Manager starts a run, it is sent to Live Timing.';
        sessions.append(empty);
        return;
    }

    for (const session of list) {
        const item = document.createElement('li');
        item.className = 'race';
        item.style.paddingLeft = '8px';
        item.style.cursor = 'default';

        const name = document.createElement('span');
        name.textContent = session.live ? `${session.name} (live)` : session.name;

        const meta = document.createElement('span');
        meta.className = 'laps';
        const flag = session.flag ? ` · ${session.flag}` : '';
        meta.textContent = `${session.standings.length} car${session.standings.length === 1 ? '' : 's'}${flag}`;

        item.append(name, meta);
        sessions.append(item);
    }
}

function renderOutput(lines) {
    rmonitorOutputWrap.hidden = ! showOutput.checked;

    if (! showOutput.checked) {
        return;
    }

    const atBottom = rmonitorOutput.scrollHeight - rmonitorOutput.scrollTop - rmonitorOutput.clientHeight < 24;
    rmonitorOutput.textContent = lines.length ? lines.join('\n') : 'Waiting for Race Manager…';

    if (atBottom) {
        rmonitorOutput.scrollTop = rmonitorOutput.scrollHeight;
    }
}

function applyRmonitorSnapshot(snapshot) {
    listen.disabled = snapshot.connected;
    disconnect.disabled = ! snapshot.connected;
    setRmonitorStatus(snapshot.status, snapshot.connected ? 'ok' : '');
    pushStatus.textContent = snapshot.lastPush?.message ?? '';
    pushStatus.className = snapshot.lastPush?.kind ?? '';
    renderSessions(snapshot.sessions ?? []);
    renderOutput(snapshot.output ?? []);
}

async function loadRaces() {
    await persist();
    const result = await window.timingPull.listLineups();
    const previously = raceList.length ? selectedKeys() : null;
    renderRaces(result.races, previously);

    if (result.races.length === 0) {
        setStatus('No races available to pull.', 'ok');
    } else {
        setStatus(`${result.races.length} races available.`, 'ok');
    }
}

feedUrl.addEventListener('change', persist);
assignFolder.addEventListener('change', persist);
rmonitorHost.addEventListener('change', persist);
rmonitorPort.addEventListener('change', persist);

listen.addEventListener('click', async () => {
    await persist();
    setRmonitorStatus('Working…');
    listen.disabled = true;

    try {
        applyRmonitorSnapshot(await window.timingPull.rmonitorConnect());
    } catch (error) {
        listen.disabled = false;
        setRmonitorStatus(error?.message ?? String(error), 'error');
    }
});

disconnect.addEventListener('click', async () => {
    setRmonitorStatus('Working…');
    disconnect.disabled = true;

    try {
        applyRmonitorSnapshot(await window.timingPull.rmonitorDisconnect());
    } catch (error) {
        disconnect.disabled = false;
        setRmonitorStatus(error?.message ?? String(error), 'error');
    }
});

showOutput.addEventListener('change', async () => {
    renderOutput((await window.timingPull.rmonitorStatus()).output ?? []);
});

clearOutput.addEventListener('click', async () => {
    applyRmonitorSnapshot(await window.timingPull.rmonitorClearOutput());
});

window.timingPull.onRmonitorUpdate(applyRmonitorSnapshot);

chooseFolder.addEventListener('click', async () => {
    const config = await window.timingPull.chooseAssignFolder();

    if (config) {
        applyConfig(config);
    }
});

refresh.addEventListener('click', async () => {
    refresh.disabled = true;
    setStatus('Loading races…');

    try {
        await loadRaces();
    } catch (error) {
        setStatus(error?.message ?? String(error), 'error');
    } finally {
        refresh.disabled = false;
    }
});

selectAll.addEventListener('click', () => {
    renderRaces(raceList, raceList.map((race) => race.key));
});

selectNone.addEventListener('click', () => {
    renderRaces(raceList, []);
});

pull.addEventListener('click', async () => {
    pull.disabled = true;
    setStatus('Pulling lineup files…');

    try {
        await persist();

        if (!raceList.length) {
            await loadRaces();
        }

        const selected = selectedKeys();
        const result = await window.timingPull.pullLineups(selected);
        renderRaces(result.races, selected);

        if (result.files.length === 0) {
            setStatus(`No files written to ${result.folder}.`, 'ok');
        } else {
            setStatus(`Overwrote ${result.files.length} files in ${result.folder}.`, 'ok');
        }
    } catch (error) {
        setStatus(error?.message ?? String(error), 'error');
    } finally {
        pull.disabled = false;
    }
});

window.timingPull.getConfig().then(async (config) => {
    applyConfig(config);
    applyRmonitorSnapshot(await window.timingPull.rmonitorStatus());

    if (config.feedUrl) {
        try {
            await loadRaces();
        } catch (error) {
            setStatus(error?.message ?? String(error), 'error');
        }
    }
});
