/* motion-helpers.js
   Shared lightweight helpers for reveals, staggers, and reduced-motion gating.
   Vanilla JS, no deps. Uses IntersectionObserver + Web Animations API where
   imperative motion is needed.
*/

(function () {
    'use strict';

    var reducedQ = window.matchMedia('(prefers-reduced-motion: reduce)');
    var reduced = reducedQ.matches;
    reducedQ.addEventListener('change', function (e) { reduced = e.matches; });

    /* -------- Reveal on scroll -------- */

    function initReveal() {
        var nodes = document.querySelectorAll('.reveal, .reveal-stagger');
        if (!nodes.length) return;

        if (reduced || !('IntersectionObserver' in window)) {
            nodes.forEach(function (n) { n.classList.add('is-in'); });
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var el = entry.target;
                    if (el.classList.contains('reveal-stagger')) {
                        Array.prototype.forEach.call(el.children, function (child, i) {
                            child.style.setProperty('--i', i);
                        });
                    }
                    el.classList.add('is-in');
                    io.unobserve(el);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

        nodes.forEach(function (n) { io.observe(n); });
    }

    /* -------- Typewriter: type text into an element at a cadence -------- */

    function typewriter(el, text, opts) {
        opts = opts || {};
        var speed = opts.speed || 28;
        var delay = opts.delay || 0;
        var onDone = opts.onDone;

        if (reduced) {
            el.textContent = text;
            if (onDone) onDone();
            return { cancel: function () {} };
        }

        var cancelled = false;
        var i = 0;
        var timeoutId = null;

        function step() {
            if (cancelled) return;
            if (i <= text.length) {
                el.textContent = text.slice(0, i);
                i++;
                timeoutId = setTimeout(step, speed);
            } else if (onDone) {
                onDone();
            }
        }

        setTimeout(step, delay);

        return {
            cancel: function () {
                cancelled = true;
                if (timeoutId) clearTimeout(timeoutId);
            }
        };
    }

    /* -------- Run a sequence of ticks with cancellation -------- */

    function sequence(steps) {
        var cancelled = false;
        var idx = 0;
        var currentTimeout = null;

        function next() {
            if (cancelled || idx >= steps.length) return;
            var step = steps[idx++];
            var delay = reduced ? 0 : (step.delay || 0);
            currentTimeout = setTimeout(function () {
                if (cancelled) return;
                try { step.run(); } catch (e) { /* swallow */ }
                next();
            }, delay);
        }

        next();

        return {
            cancel: function () {
                cancelled = true;
                if (currentTimeout) clearTimeout(currentTimeout);
            }
        };
    }

    /* -------- Loop an animation when in-view, pause when not -------- */

    function inView(el, cb) {
        if (!('IntersectionObserver' in window)) { cb(true); return function () {}; }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { cb(e.isIntersecting); });
        }, { threshold: 0.3 });
        io.observe(el);
        return function () { io.disconnect(); };
    }

    /* -------- Expose -------- */

    window.ffMotion = {
        reduced: function () { return reduced; },
        initReveal: initReveal,
        typewriter: typewriter,
        sequence: sequence,
        inView: inView
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReveal);
    } else {
        initReveal();
    }
})();
