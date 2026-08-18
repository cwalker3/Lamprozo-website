/**
 * Template Sync — client-orchestrated whole-template push/pull pipeline.
 *
 * Each step is its own REST call so no single PHP request outlives the
 * proxy window and the operator gets live progress. Step order (both
 * directions): files → pages → posts → menu → media → settings →
 * [mirror] → [activate]. Files-first installs the template on the
 * receiver, so the per-page/menu template guards pass for every later step.
 */
(function ($) {
    'use strict';

    var config = window.fireflyTemplateSync || {};

    // Pull is the default: the most common reason to open this page is
    // bringing a template DOWN (fresh machine, refresh from dev/prod);
    // pushing is the deliberate, less frequent act.
    var state = {
        direction: 'pull',
        env: null,
        template: null,
        localManifest: null,
        remoteManifest: null,
        mediaDiff: null,
        running: false
    };

    // ------------------------------------------------------------------
    // REST helper
    // ------------------------------------------------------------------

    function api(path, data, method) {
        return $.ajax({
            url: config.restUrl + path,
            method: method || 'POST',
            contentType: 'application/json',
            data: method === 'GET' ? undefined : JSON.stringify(data || {}),
            dataType: 'json',
            headers: { 'X-WP-Nonce': config.nonce },
            timeout: 0
        });
    }

    function apiGet(path, params) {
        // Params go through $.ajax's `data` so jQuery picks the right
        // separator — with Plain permalinks restUrl is already the
        // `?rest_route=/...` form, and a hand-concatenated second `?` would
        // corrupt the route (the fresh-machine "No route was found" bug).
        return $.ajax({
            url: config.restUrl + path,
            method: 'GET',
            data: params || {},
            dataType: 'json',
            headers: { 'X-WP-Nonce': config.nonce },
            timeout: 0
        });
    }

    function errMessage(xhr, fallback) {
        try {
            var data = xhr.responseJSON || JSON.parse(xhr.responseText);
            if (data && data.message) return data.message;
        } catch (e) { /* noop */ }
        return fallback || 'Request failed.';
    }

    // ------------------------------------------------------------------
    // Setup UI
    // ------------------------------------------------------------------

    function availableEnvs() {
        if (config.environment === 'live_dev') {
            return config.hasProdEndpoint ? ['prod'] : [];
        }
        var envs = [];
        if (config.hasDevEndpoint) envs.push('dev');
        if (config.hasProdEndpoint) envs.push('prod');
        return envs;
    }

    function envLabel(env) {
        if (env === 'prod') return 'Production' + (config.prodSite ? ' (' + config.prodSite + ')' : '');
        return 'Live Dev' + (config.devSite ? ' (' + config.devSite + ')' : '');
    }

    function populateEnvs() {
        var $env = $('#fts-env').empty();
        var envs = availableEnvs();
        if (!envs.length) {
            $env.append($('<option>').val('').text('No sync endpoints configured in wp-config.php'));
            $('#fts-run').prop('disabled', true).text('Not configured');
            return false;
        }
        envs.forEach(function (env) {
            $env.append($('<option>').val(env).text(envLabel(env)));
        });
        state.env = envs[0];
        return true;
    }

    function populateTemplates() {
        var $sel = $('#fts-template').empty();
        state.template = null;
        renderSummary(null);

        if (state.direction === 'push') {
            var templates = config.localTemplates || [];
            if (!templates.length) {
                $sel.append($('<option>').val('').text('No local templates found'));
                return;
            }
            templates.forEach(function (t) {
                var label = t.name + (t.name === config.activeTemplate ? ' (active)' : '');
                $sel.append($('<option>').val(t.name).text(label));
            });
            var preferred = templates.some(function (t) { return t.name === config.activeTemplate; })
                ? config.activeTemplate : templates[0].name;
            $sel.val(preferred);
            state.template = preferred;
            loadManifests();
        } else {
            $sel.append($('<option>').val('').text('Loading templates from ' + envLabel(state.env) + '…'));
            $('#fts-run').prop('disabled', true).text('Loading…');
            apiGet('template-sync/remote-templates', { source_env: state.env })
                .done(function (res) {
                    $sel.empty();
                    var templates = (res && res.templates) || [];
                    if (!templates.length) {
                        $sel.append($('<option>').val('').text('No templates on remote'));
                        return;
                    }
                    templates.forEach(function (t) {
                        var label = t.name + (t.name === res.active_template ? ' (active there)' : '');
                        $sel.append($('<option>').val(t.name).text(label));
                    });
                    var preferred = templates.some(function (t) { return t.name === res.active_template; })
                        ? res.active_template : templates[0].name;
                    $sel.val(preferred);
                    state.template = preferred;
                    loadManifests();
                })
                .fail(function (xhr) {
                    $sel.empty().append($('<option>').val('').text('Failed to reach remote'));
                    $('#fts-summary').html('<div class="fts-error">' + errMessage(xhr, 'Could not list remote templates.') + '</div>');
                    $('#fts-run').prop('disabled', true).text('Unavailable');
                });
        }
    }

    function loadManifests() {
        if (!state.template) return;
        $('#fts-run').prop('disabled', true).text('Loading…');
        state.localManifest = null;
        state.remoteManifest = null;

        if (state.direction === 'push') {
            apiGet('template-sync/manifest', { template: state.template })
                .done(function (res) {
                    state.localManifest = res.manifest;
                    renderSummary(res.manifest, 'local');
                    readyRun();
                })
                .fail(function (xhr) {
                    $('#fts-summary').html('<div class="fts-error">' + errMessage(xhr) + '</div>');
                });
        } else {
            apiGet('template-sync/remote-manifest', { source_env: state.env, template: state.template })
                .done(function (res) {
                    state.remoteManifest = res.manifest;
                    renderSummary(res.manifest, 'remote');
                    readyRun();
                })
                .fail(function (xhr) {
                    $('#fts-summary').html('<div class="fts-error">' + errMessage(xhr) + '</div>');
                    $('#fts-run').prop('disabled', true).text('Unavailable');
                });
        }
    }

    function renderSummary(manifest, side) {
        var $s = $('#fts-summary');
        if (!manifest) { $s.empty(); return; }
        var mb = manifest.files_bytes ? (manifest.files_bytes / (1024 * 1024)).toFixed(1) + ' MB' : '0 MB';
        var rows = [
            ['Files', manifest.files_count + ' (' + mb + ')'],
            ['Pages', manifest.pages.length],
            ['Posts', manifest.posts.length],
            ['Menu', manifest.menu_id ? manifest.menu_name : '—'],
            ['Media', manifest.media_count + (manifest.shared_media_count ? ' + ' + manifest.shared_media_count + ' shared' : '')],
            ['Categories', manifest.categories_count],
            ['Options', manifest.options_count]
        ];
        var html = '<div class="fts-summary-title">' +
            (side === 'remote' ? 'On ' + envLabel(state.env) : 'On this site') +
            ' — <code>' + state.template + '</code></div><ul class="fts-summary-list">';
        rows.forEach(function (r) {
            html += '<li><span>' + r[0] + '</span><strong>' + r[1] + '</strong></li>';
        });
        html += '</ul>';
        $s.html(html);
    }

    function readyRun() {
        var verb = state.direction === 'push' ? 'Push' : 'Pull';
        var prep = state.direction === 'push' ? 'to' : 'from';
        $('#fts-run').prop('disabled', false).text(verb + ' "' + state.template + '" ' + prep + ' ' + envLabel(state.env));
        var warnings = [];
        if (state.env === 'prod') warnings.push('Target is PRODUCTION.');
        if ($('#fts-activate').is(':checked')) warnings.push('Will switch the target’s live template.');
        $('#fts-run-warning').text(warnings.join(' '));
    }

    // ------------------------------------------------------------------
    // Pipeline
    // ------------------------------------------------------------------

    function stepEl(step) {
        return $('#fts-step-' + step.id);
    }

    function setStepStatus(step, status, note) {
        var icons = { pending: '○', running: '◐', done: '●', warn: '▲', fail: '✕', skipped: '–' };
        var $el = stepEl(step);
        $el.attr('data-status', status);
        $el.find('.fts-step-icon').text(icons[status] || '○');
        if (note !== undefined) $el.find('.fts-step-note').text(note);
    }

    function log(msg, cls) {
        var $log = $('#fts-log');
        $log.append($('<div>').addClass(cls || '').text(msg));
        $log.scrollTop($log[0].scrollHeight);
    }

    /**
     * Run an array of items sequentially through fn(item) → promise.
     * Item failures are warnings (collected), not fatal.
     */
    function runLoop(step, items, label, fn) {
        var d = $.Deferred();
        var idx = 0;
        var failures = [];

        function next() {
            if (idx >= items.length) {
                d.resolve({ total: items.length, failures: failures });
                return;
            }
            var item = items[idx];
            setStepStatus(step, 'running', (idx + 1) + '/' + items.length + ' — ' + label(item));
            fn(item)
                .done(function (res) {
                    if (res && res.success === false) {
                        failures.push({ item: item, message: res.message || 'failed' });
                        log('  ⚠ ' + label(item) + ': ' + (res.message || 'failed'), 'fts-log-warn');
                    }
                })
                .fail(function (xhr) {
                    failures.push({ item: item, message: errMessage(xhr) });
                    log('  ⚠ ' + label(item) + ': ' + errMessage(xhr), 'fts-log-warn');
                })
                .always(function () {
                    idx++;
                    next();
                });
        }
        next();
        return d.promise();
    }

    function buildSteps() {
        var direction = state.direction;
        var env = state.env;
        var template = state.template;
        var mode = $('#fts-mirror').is(':checked') ? 'mirror' : 'safe';
        var includeMedia = $('#fts-include-media').is(':checked');
        var includeShared = $('#fts-include-shared').is(':checked');
        var activate = $('#fts-activate').is(':checked');
        var manifest = direction === 'push' ? state.localManifest : state.remoteManifest;

        var steps = [];

        steps.push({
            id: 'files',
            label: 'Template files (theme + plugin + schema)',
            run: function () {
                var path = direction === 'push' ? 'template-sync/push-files' : 'template-sync/pull-files';
                var body = { template: template, mode: mode };
                body[direction === 'push' ? 'target_env' : 'source_env'] = env;
                return api(path, body).then(function (res) {
                    log('Files: ' + (res.message || 'done'));
                    return { note: (res.files_written || 0) + ' written' + (res.files_deleted ? ', ' + res.files_deleted + ' removed' : '') };
                });
            }
        });

        ['pages', 'posts'].forEach(function (kind) {
            steps.push({
                id: kind,
                label: kind.charAt(0).toUpperCase() + kind.slice(1),
                run: function (step) {
                    var items = manifest[kind] || [];
                    if (!items.length) return $.Deferred().resolve({ note: 'none' }).promise();
                    var fn;
                    if (direction === 'push') {
                        fn = function (item) {
                            return api('sync-page', { post_id: item.id, target_env: env, sync_template_files: false });
                        };
                    } else {
                        fn = function (item) {
                            return api('pull-page', {
                                firefly_page_id: item.firefly_page_id,
                                post_slug: item.slug,
                                template: template,
                                source_env: env
                            });
                        };
                    }
                    return runLoop(step, items, function (i) { return i.slug; }, fn).then(function (r) {
                        return { note: (r.total - r.failures.length) + '/' + r.total + ' synced', failures: r.failures };
                    });
                }
            });
        });

        steps.push({
            id: 'menu',
            label: 'Menu',
            run: function () {
                if (!manifest.menu_id) return $.Deferred().resolve({ note: 'no menu' }).promise();
                var req = direction === 'push'
                    ? api('sync-menu', { menu_id: manifest.menu_id, target_env: env })
                    : api('pull-menu', { remote_menu_id: manifest.menu_id, local_menu_id: 0, source_env: env });
                return req.then(function (res) {
                    log('Menu: ' + (res.message || 'done'));
                    return { note: manifest.menu_name };
                });
            }
        });

        if (includeMedia) {
            steps.push({
                id: 'media',
                label: 'Media' + (includeShared ? ' (incl. shared)' : ''),
                run: function (step) {
                    return api('template-sync/media-diff', {
                        template: template, env: env, direction: direction, include_shared: includeShared
                    }).then(function (diff) {
                        state.mediaDiff = diff;
                        var items = diff.to_transfer || [];
                        log('Media: ' + items.length + ' file(s) to transfer, ' + (diff.orphans || []).length + ' orphan(s) on destination.');
                        if (!items.length) return { note: 'up to date' };
                        var fn = direction === 'push'
                            ? function (item) { return api('template-sync/push-media-item', { attachment_id: item.id, target_env: env }); }
                            : function (item) { return api('template-sync/pull-media-item', { rel_path: item.rel_path, source_env: env }); };
                        return runLoop(step, items, function (i) { return i.rel_path; }, fn).then(function (r) {
                            return { note: (r.total - r.failures.length) + '/' + r.total + ' transferred', failures: r.failures };
                        });
                    });
                }
            });
        }

        steps.push({
            id: 'settings',
            label: 'Settings (options, categories, page roles, pricing)',
            run: function () {
                var path = direction === 'push' ? 'template-sync/push-settings' : 'template-sync/pull-settings';
                var body = { template: template };
                body[direction === 'push' ? 'target_env' : 'source_env'] = env;
                return api(path, body).then(function (res) {
                    var report = res.report || {};
                    (report.warnings || []).forEach(function (w) { log('  ⚠ ' + w, 'fts-log-warn'); });
                    var bits = [];
                    if (report.options_set) bits.push(report.options_set + ' options');
                    if (report.categories_created) bits.push(report.categories_created + ' categories created');
                    if (report.assignments_applied) bits.push(report.assignments_applied + ' assignments');
                    if (report.pricing === 'applied') bits.push('pricing applied');
                    return { note: bits.join(', ') || 'done' };
                });
            }
        });

        if (mode === 'mirror') {
            steps.push({
                id: 'mirror',
                label: 'Mirror cleanup (remove destination extras)',
                run: function () {
                    var d = $.Deferred();
                    var body = { template: template, direction: direction, target_env: env };
                    var chain;
                    if (direction === 'pull') {
                        var keepPages = (manifest.pages || []).map(function (i) { return i.firefly_page_id; });
                        var keepPosts = (manifest.posts || []).map(function (i) { return i.firefly_page_id; });
                        chain = api('template-sync/mirror-content', $.extend({}, body, { post_type: 'page', keep_fpids: keepPages }))
                            .then(function (r1) {
                                log('Mirror pages: ' + r1.deleted + ' trashed locally.');
                                return api('template-sync/mirror-content', $.extend({}, body, { post_type: 'post', keep_fpids: keepPosts }));
                            });
                    } else {
                        chain = api('template-sync/mirror-content', $.extend({}, body, { post_type: 'page' }))
                            .then(function (r1) {
                                log('Mirror pages: ' + r1.deleted + ' deleted on remote.');
                                return api('template-sync/mirror-content', $.extend({}, body, { post_type: 'post' }));
                            });
                    }
                    chain.then(function (r2) {
                        log('Mirror posts: ' + r2.deleted + ' removed.');
                        var orphans = (state.mediaDiff && state.mediaDiff.orphans) || [];
                        if (!orphans.length) return $.Deferred().resolve({ deleted: [] }).promise();
                        return api('template-sync/mirror-media', $.extend({}, body, { rel_paths: orphans }));
                    }).then(function (rm) {
                        if (rm && rm.deleted && rm.deleted.length) log('Mirror media: ' + rm.deleted.length + ' removed.');
                        d.resolve({ note: 'done' });
                    }, function (xhr) {
                        d.reject(xhr);
                    });
                    return d.promise();
                }
            });
        }

        if (activate) {
            steps.push({
                id: 'activate',
                label: 'Activate template on ' + (direction === 'push' ? envLabel(env) : 'this site'),
                run: function () {
                    var req = direction === 'push'
                        ? api('template-sync/remote-activate', { template: template, target_env: env })
                        : api('template-sync/activate-local', { template: template });
                    return req.then(function () { return { note: 'activated' }; });
                }
            });
        }

        // Final step: clear THIS site's page cache after a pull so freshly-synced
        // content (the front page especially) renders immediately instead of
        // being served stale from the static cache. Runs last, after content +
        // activation. Never fails the pull — a cache miss is cosmetic.
        if (direction === 'pull') {
            steps.push({
                id: 'clearcache',
                label: 'Clear cache (this site)',
                run: function () {
                    return api('template-sync/clear-cache', { template: template })
                        .then(function (res) {
                            return { note: (res && res.cleared || []).join(', ') || 'cleared' };
                        }, function () {
                            // Non-fatal: report but don't reject the pipeline.
                            return { note: 'skipped (clear cache manually if the front page looks stale)' };
                        });
                }
            });
        }

        return steps;
    }

    function confirmMirror() {
        // Deletion preview before anything runs: content orphans from the two
        // manifests, media orphans from a diff.
        var d = $.Deferred();
        var direction = state.direction;
        var includeShared = $('#fts-include-shared').is(':checked');

        var needRemote = direction === 'push'
            ? apiGet('template-sync/remote-manifest', { source_env: state.env, template: state.template })
            : $.Deferred().resolve({ manifest: state.remoteManifest }).promise();
        var needLocal = direction === 'push'
            ? $.Deferred().resolve({ manifest: state.localManifest }).promise()
            : apiGet('template-sync/manifest', { template: state.template });

        $.when(needRemote, needLocal, api('template-sync/media-diff', {
            template: state.template, env: state.env, direction: direction,
            include_shared: includeShared
        })).done(function (remoteRes, localRes, diffRes) {
            var remote = (remoteRes[0] || remoteRes).manifest;
            var local = (localRes[0] || localRes).manifest;
            var diff = diffRes[0] || diffRes;
            var source = direction === 'push' ? local : remote;
            var dest = direction === 'push' ? remote : local;

            function orphanCount(kind) {
                var src = {};
                (source[kind] || []).forEach(function (i) { src[i.firefly_page_id] = 1; });
                return (dest[kind] || []).filter(function (i) { return !src[i.firefly_page_id]; }).length;
            }
            var pages = orphanCount('pages');
            var posts = orphanCount('posts');
            var media = (diff.orphans || []).length;

            if (!pages && !posts && !media) {
                log('Mirror preview: no destination extras to remove.');
                d.resolve();
                return;
            }
            var where = direction === 'push' ? envLabel(state.env) : 'THIS site';
            var msg = 'Mirror will remove from ' + where + ':\n\n' +
                '  ' + pages + ' page(s)\n  ' + posts + ' post(s)\n  ' + media + ' media file(s)\n\n' +
                'These exist on the destination but not on the source. Continue?';
            if (window.confirm(msg)) {
                d.resolve();
            } else {
                d.reject('cancelled');
            }
        }).fail(function (xhr) {
            alert('Could not build the mirror deletion preview: ' + errMessage(xhr) + '\n\nMirror cancelled — run again without mirror, or fix the error.');
            d.reject('preview-failed');
        });

        return d.promise();
    }

    function runPipeline() {
        if (state.running) return;
        var manifest = state.direction === 'push' ? state.localManifest : state.remoteManifest;
        if (!manifest) return;

        if ($('#fts-activate').is(':checked')) {
            var target = state.direction === 'push' ? envLabel(state.env) : 'this site';
            if (!window.confirm('This will make "' + state.template + '" the LIVE template on ' + target + '. Continue?')) {
                return;
            }
        }

        var start = $('#fts-mirror').is(':checked') ? confirmMirror() : $.Deferred().resolve().promise();

        start.done(function () {
            state.running = true;
            var steps = buildSteps();

            $('#fts-setup').hide();
            $('#fts-progress').show();
            $('#fts-done-actions').hide();
            $('#fts-log').empty();
            $('#fts-progress-title').text(
                (state.direction === 'push' ? 'Pushing' : 'Pulling') + ' "' + state.template + '" ' +
                (state.direction === 'push' ? 'to' : 'from') + ' ' + envLabel(state.env) + '…'
            );

            var $steps = $('#fts-steps').empty();
            steps.forEach(function (step) {
                $steps.append(
                    $('<li>').attr('id', 'fts-step-' + step.id).attr('data-status', 'pending')
                        .append($('<span>').addClass('fts-step-icon').text('○'))
                        .append($('<span>').addClass('fts-step-label').text(step.label))
                        .append($('<span>').addClass('fts-step-note'))
                );
            });

            var idx = 0;
            var hadWarnings = false;

            function nextStep() {
                if (idx >= steps.length) {
                    state.running = false;
                    $('#fts-progress-title').text(hadWarnings ? 'Finished with warnings' : 'Finished ✓');
                    log(hadWarnings ? 'Done — review the warnings above.' : 'Done. Template "' + state.template + '" is in sync.');
                    $('#fts-done-actions').show();
                    return;
                }
                var step = steps[idx];
                setStepStatus(step, 'running', '');
                log('— ' + step.label);
                step.run(step)
                    .done(function (result) {
                        result = result || {};
                        if (result.failures && result.failures.length) {
                            hadWarnings = true;
                            setStepStatus(step, 'warn', result.note || '');
                        } else {
                            setStepStatus(step, 'done', result.note || '');
                        }
                        idx++;
                        nextStep();
                    })
                    .fail(function (xhr) {
                        state.running = false;
                        var msg = typeof xhr === 'string' ? xhr : errMessage(xhr);
                        setStepStatus(step, 'fail', msg);
                        log('✕ ' + step.label + ': ' + msg, 'fts-log-error');
                        $('#fts-progress-title').text('Stopped — "' + step.label + '" failed');
                        log('Fix the error and run again — every step is idempotent, re-running is safe.');
                        $('#fts-done-actions').show();
                    });
            }
            nextStep();
        });
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    $(function () {
        if (!config.restUrl) return;

        if (!populateEnvs()) {
            $('#fts-template').append($('<option>').text('—'));
            return;
        }

        $('#fts-direction').on('click', '.fts-toggle', function () {
            if (state.running) return;
            $('#fts-direction .fts-toggle').removeClass('active');
            $(this).addClass('active');
            state.direction = $(this).data('value');
            $('#fts-env-label').text(state.direction === 'push' ? 'To' : 'From');
            populateTemplates();
        });

        $('#fts-env').on('change', function () {
            state.env = $(this).val();
            populateTemplates();
        });

        $('#fts-template').on('change', function () {
            state.template = $(this).val();
            if (state.template) loadManifests();
        });

        $('#fts-refresh').on('click', populateTemplates);
        $('#fts-activate, #fts-mirror').on('change', readyRun);
        $('#fts-run').on('click', runPipeline);
        $('#fts-again').on('click', function () {
            $('#fts-progress').hide();
            $('#fts-setup').show();
            loadManifests();
        });

        populateTemplates();
    });
})(jQuery);
