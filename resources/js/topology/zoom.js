const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));

export class TreeZoom {
    constructor({ viewport, canvas, valueElement, outButton, inButton, resetButton, fitButton, announcer }) {
        this.viewport = viewport;
        this.canvas = canvas;
        this.valueElement = valueElement;
        this.outButton = outButton;
        this.inButton = inButton;
        this.resetButton = resetButton;
        this.fitButton = fitButton;
        this.announcer = announcer;
        this.minimum = 0.2;
        this.maximum = 1.6;
        this.step = 0.1;
        this.value = 1;
        this.mode = 'manual';
        this.resizeFrame = 0;
        this.pan = null;
        this.bindEvents();
        this.apply({ center: false });
    }

    bindEvents() {
        this.outButton?.addEventListener('click', () => this.set(this.value - this.step));
        this.inButton?.addEventListener('click', () => this.set(this.value + this.step));
        this.resetButton?.addEventListener('click', () => this.set(1));
        this.fitButton?.addEventListener('click', () => this.fit());

        this.viewport?.addEventListener('wheel', event => {
            if (!event.ctrlKey && !event.metaKey) return;
            event.preventDefault();
            this.set(this.value + (event.deltaY < 0 ? this.step : -this.step), { center: false });
        }, { passive: false });

        this.viewport?.addEventListener('pointerdown', event => this.startPan(event));
        this.viewport?.addEventListener('pointermove', event => this.movePan(event));
        this.viewport?.addEventListener('pointerup', event => this.stopPan(event));
        this.viewport?.addEventListener('pointercancel', event => this.stopPan(event));

        if ('ResizeObserver' in window && this.viewport) {
            this.resizeObserver = new ResizeObserver(() => {
                cancelAnimationFrame(this.resizeFrame);
                this.resizeFrame = requestAnimationFrame(() => {
                    if (this.mode === 'fit') this.fit({ announce: false, center: false });
                });
            });
            this.resizeObserver.observe(this.viewport);
        }
    }

    set(nextValue, { announce = true, center = true, mode = 'manual' } = {}) {
        this.mode = mode;
        this.value = Math.round(clamp(Number(nextValue) || 1, this.minimum, this.maximum) * 100) / 100;
        this.apply({ announce, center });
    }

    apply({ announce = false, center = true } = {}) {
        if (this.canvas) {
            this.canvas.style.zoom = String(this.value);
            this.canvas.dataset.zoom = String(Math.round(this.value * 100));
        }
        this.updateControls();
        if (center) this.centerSelected();
        if (announce) this.announce(`Zoom tree ${Math.round(this.value * 100)} persen.`);
    }

    fit({ announce = true, center = true } = {}) {
        if (!this.canvas || !this.viewport || this.viewport.clientWidth <= 0 || this.canvas.hidden) return;
        const previousZoom = this.canvas.style.zoom || String(this.value);
        this.canvas.style.zoom = '1';
        const naturalWidth = this.canvas.getBoundingClientRect().width;
        this.canvas.style.zoom = previousZoom;
        const availableWidth = Math.max(1, this.viewport.clientWidth - 24);
        const fittedValue = naturalWidth > 0 ? Math.min(1, availableWidth / naturalWidth) : 1;
        this.set(fittedValue, { announce, center, mode: 'fit' });
    }

    afterTreeRender(selectedId = '') {
        this.selectedId = selectedId;
        if (this.mode === 'fit') {
            requestAnimationFrame(() => this.fit({ announce: false, center: false }));
        } else {
            this.apply({ center: false });
        }
        this.centerSelected();
    }

    centerSelected() {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            if (!this.viewport || !this.canvas) return;
            const cards = [...this.canvas.querySelectorAll('[data-node-card]')];
            const target = cards.find(card => card.dataset.nodeCard === this.selectedId) ?? cards[0];
            if (!target) return;
            const viewportRect = this.viewport.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            const left = this.viewport.scrollLeft + targetRect.left + targetRect.width / 2 - (viewportRect.left + viewportRect.width / 2);
            const top = this.viewport.scrollTop + targetRect.top - viewportRect.top - 28;
            this.viewport.scrollTo({
                left: clamp(left, 0, Math.max(0, this.viewport.scrollWidth - this.viewport.clientWidth)),
                top: clamp(top, 0, Math.max(0, this.viewport.scrollHeight - this.viewport.clientHeight)),
                behavior: 'smooth',
            });
        }));
    }

    updateControls() {
        const percentage = Math.round(this.value * 100);
        if (this.valueElement) this.valueElement.textContent = `${percentage}%`;
        if (this.outButton) this.outButton.disabled = this.value <= this.minimum;
        if (this.inButton) this.inButton.disabled = this.value >= this.maximum;
        if (this.resetButton) {
            this.resetButton.disabled = Math.abs(this.value - 1) < 0.001 && this.mode === 'manual';
            this.resetButton.setAttribute('aria-label', `Zoom tree ${percentage} persen. Klik untuk kembali ke 100 persen.`);
        }
        this.fitButton?.setAttribute('aria-pressed', String(this.mode === 'fit'));
    }

    startPan(event) {
        if (
            !this.viewport ||
            event.button !== 0 ||
            event.pointerType === 'touch' ||
            event.target.closest('button, a, input, select, textarea, label')
        ) return;
        this.pan = {
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            left: this.viewport.scrollLeft,
            top: this.viewport.scrollTop,
        };
        this.viewport.setPointerCapture?.(event.pointerId);
        this.viewport.classList.add('is-panning');
        event.preventDefault();
    }

    movePan(event) {
        if (!this.pan || event.pointerId !== this.pan.pointerId) return;
        this.viewport.scrollLeft = this.pan.left - (event.clientX - this.pan.x);
        this.viewport.scrollTop = this.pan.top - (event.clientY - this.pan.y);
    }

    stopPan(event) {
        if (!this.pan || event.pointerId !== this.pan.pointerId) return;
        this.viewport.releasePointerCapture?.(event.pointerId);
        this.pan = null;
        this.viewport.classList.remove('is-panning');
    }

    announce(message) {
        if (this.announcer) this.announcer.textContent = message;
    }
}
