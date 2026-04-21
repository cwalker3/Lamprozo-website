/* home.js
   Firefly landing page — animates:
   1) Hero triple-panel (typewriter CLI + live-state chips + deploy ping)
   2) CLI demo section (scroll-in loop of `firefly views create pricing`)
   Depends on motion-helpers.js (window.ffMotion).
*/

(function () {
    'use strict';

    if (!window.ffMotion) return;
    var M = window.ffMotion;

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /* =========================================================
       1) Hero triple-panel — looping narrative
       ========================================================= */

    var tripleEl = $('.triple');
    if (tripleEl) runTripleLoop(tripleEl);

    function runTripleLoop(container) {
        var cmdEl   = $('[data-cli-cmd]', container);
        var cursor  = $('[data-cli-cursor]', container);
        var steps   = $$('[data-cli-step]', container);
        var deploy  = $('[data-deploy-status]', container);
        var guLive  = $('[data-gu-live]', container);
        var guSave  = $('[data-gu-save]', container);
        var cliLive = $('[data-cli-live]', container);
        var phpLive = $('[data-php-live]', container);

        if (!cmdEl || !steps.length) return;

        var COMMANDS = [
            {
                cmd: 'firefly views create pricing',
                steps: [
                    '✓ views/pricing.php',
                    '✓ assets/css/pricing.css',
                    '✓ assets/js/pricing.js',
                    '✓ $valid_views updated',
                    '✓ schema + WP page'
                ],
                deploy: '/pricing → deployed',
                phpStatus: 'writing'
            },
            {
                cmd: 'firefly plugin-views create stats',
                steps: [
                    '✓ plugins/.../views/stats.php',
                    '✓ plugins/.../models/stats.php',
                    '✓ add_menu_page() wired',
                    '✓ templates.json updated',
                    '✓ admin page live'
                ],
                deploy: '/wp-admin?page=stats → deployed',
                phpStatus: 'wiring rest'
            },
            {
                cmd: 'firefly import default',
                steps: [
                    '✓ schema/default.json read',
                    '✓ 3 pages reconciled',
                    '✓ 2 snippets updated',
                    '✓ menu rebuilt',
                    '✓ cache cleared'
                ],
                deploy: '/ → synced',
                phpStatus: 'idle'
            }
        ];

        var index = 0;
        var running = false;
        var stopped = false;

        function resetOutputs() {
            steps.forEach(function (s) { s.textContent = ''; s.className = ''; });
            if (deploy) deploy.textContent = '⏳ waiting for agent…';
            if (guSave) guSave.textContent = '// draft · unsaved';
            if (guLive) guLive.textContent = 'editing';
            if (cliLive) cliLive.textContent = 'idle';
            if (phpLive) phpLive.textContent = 'idle';
        }

        function runCycle() {
            if (stopped) return;
            running = true;
            var c = COMMANDS[index % COMMANDS.length];
            resetOutputs();

            if (cliLive) cliLive.textContent = 'typing';
            if (phpLive) phpLive.textContent = c.phpStatus || 'writing';
            if (guLive) guLive.textContent = 'editing';

            // Type the command
            M.typewriter(cmdEl, c.cmd, {
                speed: 34,
                delay: 200,
                onDone: function () {
                    if (stopped) return;
                    if (cursor) cursor.style.visibility = 'hidden';
                    if (cliLive) cliLive.textContent = 'scaffolding';
                    var stepDelay = 260;

                    c.steps.forEach(function (txt, i) {
                        setTimeout(function () {
                            if (stopped) return;
                            var el = steps[i];
                            if (el) {
                                el.textContent = txt;
                                el.className = 'tag-a';
                            }
                            if (i === c.steps.length - 1) {
                                // Final deploy notice
                                setTimeout(function () {
                                    if (stopped) return;
                                    if (cliLive) cliLive.textContent = 'complete';
                                    if (guSave) guSave.textContent = '// saved · synced to snippet';
                                    if (phpLive) phpLive.textContent = 'saved';
                                    if (deploy) deploy.textContent = '✓ ' + c.deploy;
                                    // Hold and loop
                                    setTimeout(function () {
                                        if (cursor) cursor.style.visibility = '';
                                        index += 1;
                                        runCycle();
                                    }, 2600);
                                }, 460);
                            }
                        }, i * stepDelay);
                    });
                }
            });
        }

        // Start / stop based on visibility
        var stop = M.inView(container, function (visible) {
            if (visible && !running) {
                stopped = false;
                runCycle();
            } else if (!visible) {
                stopped = true;
                running = false;
            }
        });
    }

    /* =========================================================
       2) CLI demo section — looping command
       ========================================================= */

    var cliEl = $('[data-cli-demo]');
    if (cliEl) runCliDemo(cliEl);

    function runCliDemo(container) {
        var cmdEl = $('[data-cli-type]', container);
        var outs  = $$('[data-cli-out]', container);
        if (!cmdEl) return;

        var SCRIPT = {
            cmd: 'firefly views create pricing',
            out: [
                '',
                '  ✓ Created views/pricing.php',
                '  ✓ Created assets/css/pricing.css',
                '  ✓ Created assets/js/pricing.js',
                '  ✓ Registered in $valid_views',
                '  ✓ Added to get_view_assets()',
                '  ✓ Added to schema',
                '  ✓ Created WordPress page',
                '  Done in 0.8s.'
            ]
        };

        var stopped = false;
        var running = false;

        function reset() {
            cmdEl.textContent = '';
            outs.forEach(function (el) { el.textContent = ''; el.className = 'dim'; });
        }

        function runCycle() {
            if (stopped) return;
            running = true;
            reset();
            M.typewriter(cmdEl, SCRIPT.cmd, {
                speed: 32,
                delay: 200,
                onDone: function () {
                    if (stopped) return;
                    SCRIPT.out.forEach(function (line, i) {
                        setTimeout(function () {
                            if (stopped) return;
                            var el = outs[i];
                            if (!el) return;
                            el.textContent = line;
                            if (line.indexOf('✓') !== -1) el.className = 'ok';
                            else el.className = 'dim';
                            if (i === SCRIPT.out.length - 1) {
                                setTimeout(function () { runCycle(); }, 3000);
                            }
                        }, i * 180);
                    });
                }
            });
        }

        var stop = M.inView(container, function (visible) {
            if (visible && !running) {
                stopped = false;
                runCycle();
            } else if (!visible) {
                stopped = true;
                running = false;
            }
        });
    }

})();
