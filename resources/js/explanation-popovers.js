const clampPanelToViewport = (panel) => {
    if (!panel.matches(':popover-open') || panel.dataset.dragged !== 'true') {
        return;
    }

    const margin = 8;
    const rect = panel.getBoundingClientRect();
    const left = Math.min(
        Math.max(margin, rect.left),
        Math.max(margin, window.innerWidth - rect.width - margin),
    );
    const top = Math.min(
        Math.max(margin, rect.top),
        Math.max(margin, window.innerHeight - rect.height - margin),
    );

    panel.style.left = `${left}px`;
    panel.style.top = `${top}px`;
    panel.style.right = 'auto';
    panel.style.bottom = 'auto';
};

document.addEventListener('pointerdown', (event) => {
    const heading = event.target.closest('.result-explanation-heading');
    if (!heading || event.target.closest('button')) {
        return;
    }

    const panel = heading.closest('.result-explanation-panel');
    if (!panel?.matches(':popover-open')) {
        return;
    }

    event.preventDefault();

    const panelRect = panel.getBoundingClientRect();
    const offsetX = event.clientX - panelRect.left;
    const offsetY = event.clientY - panelRect.top;
    const pointerId = event.pointerId;

    panel.dataset.dragged = 'true';
    heading.classList.add('is-dragging');
    heading.setPointerCapture(pointerId);

    const movePanel = (moveEvent) => {
        if (moveEvent.pointerId !== pointerId) {
            return;
        }

        const margin = 8;
        const maxLeft = Math.max(margin, window.innerWidth - panel.offsetWidth - margin);
        const maxTop = Math.max(margin, window.innerHeight - panel.offsetHeight - margin);
        const nextLeft = Math.min(Math.max(margin, moveEvent.clientX - offsetX), maxLeft);
        const nextTop = Math.min(Math.max(margin, moveEvent.clientY - offsetY), maxTop);

        panel.style.left = `${nextLeft}px`;
        panel.style.top = `${nextTop}px`;
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
    };

    const stopDragging = (endEvent) => {
        if (endEvent.pointerId !== pointerId) {
            return;
        }

        heading.classList.remove('is-dragging');
        heading.removeEventListener('pointermove', movePanel);
        heading.removeEventListener('pointerup', stopDragging);
        heading.removeEventListener('pointercancel', stopDragging);

        if (heading.hasPointerCapture(pointerId)) {
            heading.releasePointerCapture(pointerId);
        }
    };

    heading.addEventListener('pointermove', movePanel);
    heading.addEventListener('pointerup', stopDragging);
    heading.addEventListener('pointercancel', stopDragging);
});

window.addEventListener('resize', () => {
    document.querySelectorAll('.result-explanation-panel:popover-open').forEach(clampPanelToViewport);
});
