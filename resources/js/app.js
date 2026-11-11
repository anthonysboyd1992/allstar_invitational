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
 * Pill Draw TV board: fullscreen controls plus flying cards from "still to draw"
 * (or a previous heat slot) into the projected heat column.
 */
window.pillDrawBoard = () => ({
    ...window.timingFullscreen(),
    origins: {},
    snapshotTimer: null,
    flying: false,
    init() {
        window.timingFullscreen().init.call(this);
        this.captureOrigins();
        this.snapshotTimer = window.setInterval(() => {
            if (! this.flying) {
                this.captureOrigins();
            }
        }, 280);
    },
    destroy() {
        if (this.snapshotTimer) {
            window.clearInterval(this.snapshotTimer);
        }
    },
    captureOrigins() {
        const map = {};

        this.$root.querySelectorAll('[data-undrawn-id]').forEach((el) => {
            map[`u:${el.dataset.undrawnId}`] = el.getBoundingClientRect();
        });

        this.$root.querySelectorAll('[data-heat-driver-id]').forEach((el) => {
            map[`h:${el.dataset.heatDriverId}`] = el.getBoundingClientRect();
        });

        const waiting = this.$root.querySelector('[data-undrawn-column]');

        if (waiting) {
            map.column = waiting.getBoundingClientRect();
        }

        this.origins = map;
    },
    fallbackOrigin() {
        const column = this.origins.column;

        if (column) {
            return {
                left: column.left + column.width * 0.15,
                top: column.top + Math.min(120, column.height * 0.2),
                width: Math.min(220, column.width * 0.9),
                height: 56,
            };
        }

        const board = this.$root.getBoundingClientRect();

        return {
            left: board.right - 240,
            top: board.top + board.height * 0.35,
            width: 200,
            height: 56,
        };
    },
    async onFly(event) {
        const arrivals = event.detail?.arrivals ?? event.detail?.[0]?.arrivals ?? [];

        if (! Array.isArray(arrivals) || arrivals.length === 0) {
            return;
        }

        this.flying = true;

        try {
            await Promise.all(arrivals.map((arrival, index) => this.flyArrival(arrival, index)));
        } finally {
            this.flying = false;
            this.captureOrigins();
        }
    },
    flyArrival(arrival, index) {
        return new Promise((resolve) => {
            window.requestAnimationFrame(() => {
                const target = this.$root.querySelector(`[data-heat-driver-id="${arrival.id}"]`);
                const heat = this.$root.querySelector(`[data-heat-index="${arrival.heat}"]`);
                const originKey = arrival.from === 'heat' ? `h:${arrival.id}` : `u:${arrival.id}`;
                const fromRect = this.origins[originKey] ?? this.fallbackOrigin();
                const toRect = target?.getBoundingClientRect();

                if (! target || ! toRect || toRect.width === 0) {
                    target?.classList.remove('pill-draw-row--awaiting');
                    resolve();

                    return;
                }

                target.classList.add('pill-draw-row--awaiting');
                heat?.classList.add('pill-draw-heat--catching');

                const flyer = document.createElement('div');
                const safeName = String(arrival.name ?? '').replace(/[<>&]/g, (char) => (
                    { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[char]
                ));
                flyer.className = 'pill-draw-flyer';
                flyer.innerHTML = `
                    <span class="pill-draw-flyer-pill">${arrival.car || '—'}</span>
                    <span class="pill-draw-flyer-copy">
                        <span class="pill-draw-flyer-car">Pill ${arrival.pill}</span>
                        <span class="pill-draw-flyer-name">${safeName}</span>
                    </span>
                `;

                const startWidth = Math.max(fromRect.width || 180, 160);
                const startHeight = Math.max(fromRect.height || 52, 48);

                Object.assign(flyer.style, {
                    left: `${fromRect.left}px`,
                    top: `${fromRect.top}px`,
                    width: `${startWidth}px`,
                    height: `${startHeight}px`,
                });

                document.body.appendChild(flyer);

                const dx = toRect.left - fromRect.left;
                const dy = toRect.top - fromRect.top;
                const arc = Math.min(140, Math.max(48, Math.abs(dx) * 0.18));
                const delay = index * 110;

                const animation = flyer.animate(
                    [
                        {
                            transform: 'translate(0px, 0px) scale(1) rotate(0deg)',
                            offset: 0,
                            boxShadow: '0 10px 30px rgba(251, 191, 36, 0.15)',
                        },
                        {
                            transform: `translate(${dx * 0.45}px, ${dy * 0.35 - arc}px) scale(1.18) rotate(${dx < 0 ? -7 : 7}deg)`,
                            offset: 0.55,
                            boxShadow: '0 24px 60px rgba(251, 191, 36, 0.45)',
                        },
                        {
                            transform: `translate(${dx}px, ${dy}px) scale(${Math.max(0.92, toRect.width / startWidth)}) rotate(0deg)`,
                            offset: 1,
                            boxShadow: '0 8px 20px rgba(251, 191, 36, 0.2)',
                        },
                    ],
                    {
                        duration: 920,
                        delay,
                        easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                        fill: 'forwards',
                    },
                );

                animation.onfinish = () => {
                    flyer.remove();
                    target.classList.remove('pill-draw-row--awaiting');
                    target.classList.add('pill-draw-row--landed');
                    heat?.classList.add('pill-draw-heat--landed');

                    window.setTimeout(() => {
                        target.classList.remove('pill-draw-row--landed');
                        heat?.classList.remove('pill-draw-heat--catching');
                        heat?.classList.remove('pill-draw-heat--landed');
                        resolve();
                    }, 700);
                };
            });
        });
    },
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
