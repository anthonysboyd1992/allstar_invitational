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
