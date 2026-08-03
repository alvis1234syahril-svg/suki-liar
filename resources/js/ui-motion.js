const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

document.documentElement.classList.add('motion-ready');

document.body.classList.remove('sidebar-collapsed');
localStorage.removeItem('log-traffic-sidebar');

const revealTargets = document.querySelectorAll(
    'main section, main .metric-card, main [data-traffic-chart]'
);

revealTargets.forEach((element, index) => {
    element.classList.add('ui-reveal');
    element.classList.add('is-visible');
    element.style.setProperty('--reveal-delay', `${Math.min(index % 6, 5) * 55}ms`);
});

if (!reduceMotion && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });

    revealTargets.forEach(element => observer.observe(element));
}

document.querySelectorAll('button, a').forEach(element => {
    element.addEventListener('pointerdown', () => element.classList.add('is-pressing'));
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(eventName => {
        element.addEventListener(eventName, () => element.classList.remove('is-pressing'));
    });
});
