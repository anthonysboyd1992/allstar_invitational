const feedUrl = document.getElementById('feedUrl');
const assignFolder = document.getElementById('assignFolder');
const chooseFolder = document.getElementById('chooseFolder');
const refresh = document.getElementById('refresh');
const selectAll = document.getElementById('selectAll');
const selectNone = document.getElementById('selectNone');
const pull = document.getElementById('pull');
const races = document.getElementById('races');
const status = document.getElementById('status');

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
    });
}

function applyConfig(config) {
    feedUrl.value = config.feedUrl ?? '';
    assignFolder.value = config.assignFolder ?? '';
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

    if (config.feedUrl) {
        try {
            await loadRaces();
        } catch (error) {
            setStatus(error?.message ?? String(error), 'error');
        }
    }
});
