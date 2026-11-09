/**
 * Expand a live timing board over the app. Native fullscreen targets the document,
 * not the Livewire board, so polling cannot replace the fullscreen element.
 */
window.timingFullscreen = () => ({
    full: false,
    init() {
        this.full = document.documentElement.classList.contains('timing-fullscreen');
    },
    toggle() {
        if (this.full) {
            this.exit();

            return;
        }

        this.enter();
    },
    enter() {
        this.full = true;
        document.documentElement.classList.add('timing-fullscreen');

        const root = document.documentElement;
        const request = root.requestFullscreen ?? root.webkitRequestFullscreen;

        if (typeof request === 'function') {
            Promise.resolve(request.call(root)).catch(() => {});
        }
    },
    exit() {
        this.full = false;
        document.documentElement.classList.remove('timing-fullscreen');

        const current = document.fullscreenElement ?? document.webkitFullscreenElement;

        if (current) {
            (document.exitFullscreen ?? document.webkitExitFullscreen).call(document);
        }
    },
    sync() {
        const current = document.fullscreenElement ?? document.webkitFullscreenElement;

        if (! current && this.full) {
            this.full = false;
            document.documentElement.classList.remove('timing-fullscreen');
        }
    },
});

document.addEventListener('livewire:navigated', () => {
    document.documentElement.classList.remove('timing-fullscreen');
});

/**
 * Copy text and confirm with a toast. navigator.clipboard only exists on HTTPS or localhost,
 * so plain-HTTP servers fall back to a hidden textarea and execCommand.
 */
window.copyText = async (text, label = 'URL') => {
    let copied = false;

    if (window.isSecureContext && navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(text);
            copied = true;
        } catch {
            copied = false;
        }
    }

    if (!copied) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            copied = document.execCommand('copy');
        } catch {
            copied = false;
        }

        textarea.remove();
    }

    window.dispatchEvent(new CustomEvent('notify', {
        detail: copied
            ? { message: `Copied ${label}: ${text}` }
            : { message: `Couldn't copy automatically. Select it and press Ctrl/Cmd+C: ${text}`, type: 'error' },
    }));
};

/**
 * Download several files one after another. Browsers drop clicks that fire too close together.
 */
window.downloadFiles = async (urls) => {
    for (const url of urls) {
        const link = document.createElement('a');
        link.href = url;
        link.download = '';
        document.body.appendChild(link);
        link.click();
        link.remove();
        await new Promise((resolve) => setTimeout(resolve, 400));
    }
};
