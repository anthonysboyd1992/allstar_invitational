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
