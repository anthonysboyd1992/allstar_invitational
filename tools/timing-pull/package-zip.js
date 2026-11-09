const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const exeName = 'Allstar Invitational Race Manager Puller.exe';
const distDir = path.join(__dirname, 'dist');
const dest = path.resolve(__dirname, '../../public/downloads/allstar-invitational-race-manager-puller.zip');

const exe = fs.readdirSync(distDir).find((name) => name.endsWith('.exe') && !name.toLowerCase().includes('setup'));

if (! exe) {
    throw new Error('No Windows .exe found in dist/. Run npm run dist first.');
}

const staging = fs.mkdtempSync(path.join(os.tmpdir(), 'timing-puller-'));
const stagedExe = path.join(staging, exeName);

fs.copyFileSync(path.join(distDir, exe), stagedExe);
fs.mkdirSync(path.dirname(dest), { recursive: true });
fs.rmSync(dest, { force: true });
execFileSync('zip', ['-j', dest, stagedExe], { stdio: 'inherit' });
fs.rmSync(staging, { recursive: true, force: true });

console.log(`Wrote ${dest}`);
