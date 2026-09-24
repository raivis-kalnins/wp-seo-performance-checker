(function () {
    'use strict';

    if (!window.wp || !window.wp.element || !window.SEOPC_TOOLKIT) {
        return;
    }

    var el = window.wp.element;
    var h = el.createElement;
    var useState = el.useState;
    var useEffect = el.useEffect;
    var useRef = el.useRef;
    var config = window.SEOPC_TOOLKIT;

    function classNames() {
        return Array.prototype.slice.call(arguments).filter(Boolean).join(' ');
    }

    function formatBytes(bytes) {
        var value = Number(bytes || 0);
        if (!value) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        return (value / Math.pow(1024, power)).toFixed(power ? 2 : 0) + ' ' + units[power];
    }

    function safeText(value, fallback) {
        return value === null || value === undefined || value === '' ? (fallback || '—') : String(value);
    }

    function downloadBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename || 'download';
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
    }

    function downloadText(text, filename, type) {
        downloadBlob(new Blob([text], { type: type || 'text/plain;charset=utf-8' }), filename);
    }

    function csvCell(value) {
        var text = value === null || value === undefined ? '' : String(value);
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function loadScript(src, globalName) {
        if (globalName && window[globalName]) return Promise.resolve(window[globalName]);
        return new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[data-seopc-src="' + src + '"]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(globalName ? window[globalName] : true); }, { once: true });
                existing.addEventListener('error', function () { reject(new Error('Could not load document converter library.')); }, { once: true });
                return;
            }
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.seopcSrc = src;
            script.onload = function () { resolve(globalName ? window[globalName] : true); };
            script.onerror = function () { reject(new Error('Could not load document converter library.')); };
            document.head.appendChild(script);
        });
    }

    function lastSiteUrl() {
        try { return localStorage.getItem('seopc_last_site_url') || ''; } catch (e) { return ''; }
    }

    function rememberSiteUrl(url) {
        try { localStorage.setItem('seopc_last_site_url', url || ''); } catch (e) {}
    }

    async function decodeImageFile(file) {
        if (window.createImageBitmap) {
            return window.createImageBitmap(file);
        }
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var image = new Image();
            image.onload = function () {
                URL.revokeObjectURL(url);
                resolve(image);
            };
            image.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('The browser could not decode this image.'));
            };
            image.src = url;
        });
    }

    function filenameFromUrl(url, mime, fallback) {
        var path = '';
        try { path = new URL(url).pathname; } catch (e) { path = ''; }
        var base = path.split('/').pop() || fallback || 'media';
        if (base.indexOf('.') === -1 && mime) {
            var extMap = {
                'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/avif': 'avif', 'image/svg+xml': 'svg',
                'video/mp4': 'mp4', 'video/webm': 'webm', 'video/ogg': 'ogv'
            };
            base += '.' + (extMap[mime] || 'bin');
        }
        return base.replace(/[^a-z0-9._-]+/gi, '-');
    }

    async function request(action, data, token, responseType) {
        var form = new FormData();
        form.append('action', action);
        form.append('nonce', config.nonce);
        if (token) form.append('access_token', token);
        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== undefined && data[key] !== null) form.append(key, data[key]);
        });

        var response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
            body: form
        });

        if (responseType === 'blob') {
            if (!response.ok) {
                throw new Error((await response.text()) || 'Media request failed.');
            }
            return response.blob();
        }

        var json;
        try {
            json = await response.json();
        } catch (error) {
            throw new Error('The server returned an invalid response.');
        }
        if (!response.ok || !json.success) {
            var message = json && json.data && (json.data.message || json.data) ? (json.data.message || json.data) : config.strings.genericError;
            var requestError = new Error(message);
            requestError.status = response.status;
            throw requestError;
        }
        return json.data;
    }

    function Button(props) {
        return h('button', {
            type: props.type || 'button',
            className: classNames('seopc-btn', props.variant && 'seopc-btn-' + props.variant, props.className),
            disabled: props.disabled,
            onClick: props.onClick
        }, props.busy ? h('span', { className: 'seopc-spinner', 'aria-hidden': 'true' }) : null, props.children);
    }

    function AjaxFreshToggle(props) {
        return h('label', { className: 'seopc-ajax-toggle', title: 'Bypass the temporary server cache and request fresh data over AJAX.' },
            h('input', { type: 'checkbox', checked: !!props.checked, onChange: function (e) { props.onChange(e.target.checked); } }),
            h('span', { className: 'seopc-ajax-switch', 'aria-hidden': 'true' }),
            h('span', { className: 'seopc-ajax-toggle-copy' }, h('strong', null, 'Fresh'), h('small', null, 'skip cache'))
        );
    }

    function rangeProgressStyle(value, min, max) {
        var n = Number(value), lo = Number(min), hi = Number(max);
        var pct = hi > lo ? ((n - lo) / (hi - lo)) * 100 : 0;
        pct = Math.max(0, Math.min(100, pct));
        return { '--seopc-range-progress': pct.toFixed(2) + '%' };
    }

    function RangeField(props) {
        var display = props.display !== undefined ? props.display : props.value;
        return h('label', { className: 'seopc-range-field' },
            h('span', { className: 'seopc-range-head' }, h('span', null, props.label), h('strong', { className: 'seopc-range-value' }, display)),
            h('input', {
                className: 'seopc-range', type: 'range', min: props.min, max: props.max, step: props.step,
                value: props.value, style: rangeProgressStyle(props.value, props.min, props.max),
                onChange: props.onChange
            })
        );
    }

    function Notice(props) {
        if (!props.children) return null;
        return h('div', { className: classNames('seopc-notice', 'seopc-notice-' + (props.type || 'info')) }, props.children);
    }

    function scoreTone(value) {
        var score = Number(value);
        if (value === null || value === undefined || value === '' || isNaN(score)) return 'neutral';
        if (score >= 90) return 'good';
        if (score >= 50) return 'warn';
        return 'bad';
    }

    function scoreStatus(tone) {
        if (tone === 'good') return 'Good';
        if (tone === 'warn') return 'Needs improvement';
        if (tone === 'bad') return 'Poor';
        return 'Not available';
    }

    function ScoreCard(props) {
        var rawScore = props.score;
        var score = Number(rawScore);
        var hasScore = rawScore !== null && rawScore !== undefined && rawScore !== '' && !isNaN(score);
        var displayScore = hasScore ? Math.max(0, Math.min(100, Math.round(score))) : null;
        var tone = scoreTone(rawScore);
        var angle = hasScore ? (displayScore * 3.6) + 'deg' : '0deg';

        return h('div', {
                className: 'seopc-score-card seopc-score-' + tone,
                style: { '--seopc-score-angle': angle }
            },
            h('div', { className: 'seopc-score-ring', 'aria-label': props.label + ': ' + (hasScore ? displayScore + ' out of 100' : 'not available') },
                h('div', { className: 'seopc-score-ring-inner' },
                    h('strong', { className: 'seopc-score-number' }, hasScore ? displayScore : '—'),
                    hasScore ? h('span', null, '/100') : null
                )
            ),
            h('div', { className: 'seopc-score-copy' },
                h('div', { className: 'seopc-score-label' }, props.label),
                h('span', { className: 'seopc-score-status' }, scoreStatus(tone)),
                props.note ? h('small', null, props.note) : null
            )
        );
    }

    function checkTone(status) {
        if (status === 'warning') return 'warn';
        if (status === 'good' || status === 'warn' || status === 'bad') return status;
        return 'neutral';
    }

    function AuditCheck(props) {
        var check = props.check || {};
        var tone = checkTone(check.status);
        var icon = tone === 'good' ? '✓' : tone === 'bad' ? '×' : tone === 'warn' ? '!' : '•';
        var status = tone === 'good' ? 'Good' : tone === 'bad' ? 'Poor' : tone === 'warn' ? 'Needs attention' : 'Information';

        return h('article', { className: 'seopc-audit-check is-' + tone },
            h('span', { className: 'seopc-check-icon', 'aria-hidden': 'true' }, icon),
            h('div', { className: 'seopc-check-copy' },
                h('div', { className: 'seopc-check-heading' },
                    h('strong', null, safeText(check.label)),
                    h('span', { className: 'seopc-check-status' }, status)
                ),
                check.value ? h('b', { className: 'seopc-check-value' }, safeText(check.value)) : null,
                check.message ? h('p', null, safeText(check.message)) : null,
                check.ideal ? h('small', null, safeText(check.ideal)) : null
            )
        );
    }

    function fallbackSeoChecks(seo, response) {
        var titleLength = Number(seo.title_length || 0);
        var descriptionLength = Number(seo.description_length || 0);
        var h1Count = Number((seo.heading_counts || {}).h1 || 0);
        var missingAlt = Number((seo.images || {}).missing_alt || 0);
        var hierarchyCount = (seo.hierarchy_issues || []).length;
        var robots = safeText(seo.robots).toLowerCase();
        var statusCode = Number(response.status || 0);

        return [
            {
                label: 'H1 headings',
                status: h1Count === 1 ? 'good' : 'bad',
                value: h1Count + ' found',
                message: h1Count === 1 ? 'The page has one clear primary heading.' : (h1Count === 0 ? 'The page has no H1 heading.' : 'The page has multiple H1 headings.'),
                ideal: 'Recommended: exactly 1 H1 per page.'
            },
            {
                label: 'Title tag',
                status: !seo.title ? 'bad' : (titleLength >= 25 && titleLength <= 65 ? 'good' : 'warn'),
                value: titleLength + ' characters',
                message: !seo.title ? 'The title tag is missing.' : (titleLength >= 25 && titleLength <= 65 ? 'The title length is in the recommended range.' : 'The title is outside the usual recommended range.'),
                ideal: 'Recommended: about 25–65 characters.'
            },
            {
                label: 'Meta description',
                status: !seo.description ? 'bad' : (descriptionLength >= 70 && descriptionLength <= 170 ? 'good' : 'warn'),
                value: descriptionLength + ' characters',
                message: !seo.description ? 'The meta description is missing.' : (descriptionLength >= 70 && descriptionLength <= 170 ? 'The description length is in the recommended range.' : 'The description is outside the usual recommended range.'),
                ideal: 'Recommended: about 70–170 characters.'
            },
            {
                label: 'Canonical URL',
                status: seo.canonical ? 'good' : 'warn',
                value: seo.canonical ? 'Present' : 'Missing',
                message: seo.canonical ? 'A canonical URL was detected.' : 'No canonical link was detected.',
                ideal: 'Recommended: a self-referencing or intentional canonical URL.'
            },
            {
                label: 'Image alt attributes',
                status: missingAlt === 0 ? 'good' : (missingAlt <= 2 ? 'warn' : 'bad'),
                value: missingAlt + ' missing',
                message: missingAlt === 0 ? 'All detected images have an alt attribute.' : 'Some images are missing an alt attribute.',
                ideal: 'Recommended: meaningful alt text for informative images.'
            },
            {
                label: 'Heading hierarchy',
                status: hierarchyCount === 0 ? 'good' : 'warn',
                value: hierarchyCount + ' issue' + (hierarchyCount === 1 ? '' : 's'),
                message: hierarchyCount === 0 ? 'No skipped heading levels were detected.' : 'One or more heading levels are skipped.',
                ideal: 'Recommended: use headings in a logical H1 → H2 → H3 order.'
            },
            {
                label: 'Indexability',
                status: robots.indexOf('noindex') === -1 ? 'good' : 'bad',
                value: robots.indexOf('noindex') === -1 ? 'Indexable' : 'Noindex found',
                message: robots.indexOf('noindex') === -1 ? 'No noindex directive was detected.' : 'A noindex directive can prevent search-engine indexing.',
                ideal: 'Confirm noindex is intentional before publishing.'
            },
            {
                label: 'HTTP response',
                status: statusCode >= 200 && statusCode < 400 ? 'good' : 'bad',
                value: statusCode || 'Unknown',
                message: statusCode >= 200 && statusCode < 400 ? 'The page returned a successful response.' : 'The page returned an error or unexpected response.',
                ideal: 'Recommended: HTTP 200 for an indexable page.'
            }
        ];
    }

    function headingTone(key, count) {
        if (key === 'h1') return count === 1 ? 'good' : 'bad';
        if (key === 'h2') return count > 0 ? 'good' : 'warn';
        return 'neutral';
    }

    function Stat(props) {
        return h('div', { className: 'seopc-stat' },
            h('span', { className: 'seopc-stat-label' }, props.label),
            h('strong', null, safeText(props.value))
        );
    }

    function Section(props) {
        return h('section', { className: classNames('seopc-section', props.className) },
            h('div', { className: 'seopc-section-head' },
                h('h3', null, props.title),
                props.subtitle ? h('p', null, props.subtitle) : null
            ),
            props.children
        );
    }

    function KeyValueTable(props) {
        var entries = props.entries || [];
        return h('div', { className: 'seopc-table-wrap' },
            h('table', { className: 'seopc-table' },
                h('tbody', null, entries.map(function (entry, index) {
                    return h('tr', { key: index },
                        h('th', null, entry[0]),
                        h('td', null, entry[1] === false ? 'No' : entry[1] === true ? 'Yes' : safeText(entry[1]))
                    );
                }))
            )
        );
    }

    function Login(props) {
        var state = useState('');
        var password = state[0];
        var setPassword = state[1];
        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];
        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        useEffect(function () {
            if (!props.autoUnlock) return;
            var active = true;
            setBusy(true);
            request('seopc_toolkit_login', { password: '' }, '')
                .then(function (result) { if (active) props.onLogin(result.token); })
                .catch(function () { if (active) setBusy(false); });
            return function () { active = false; };
        }, [props.autoUnlock]);

        async function submit(event) {
            event.preventDefault();
            setBusy(true);
            setError('');
            try {
                var result = await request('seopc_toolkit_login', { password: password }, '');
                props.onLogin(result.token);
            } catch (err) {
                setError(err.message);
            } finally {
                setBusy(false);
            }
        }

        return h('div', { className: 'seopc-login-shell' },
            h('div', { className: 'seopc-login-card' },
                h('div', { className: 'seopc-logo-mark' }, 'SEO'),
                h('h2', null, 'Smart SEO & Media Toolkit'),
                h('p', null, 'Unlock the website audit, network tests, free-media search and browser optimization tools.'),
                error ? h(Notice, { type: 'error' }, error) : null,
                h('form', { onSubmit: submit },
                    h('label', { htmlFor: 'seopc-toolkit-password' }, 'Access password'),
                    h('input', {
                        id: 'seopc-toolkit-password', type: 'password', value: password, autoComplete: 'current-password', required: true,
                        onChange: function (event) { setPassword(event.target.value); }
                    }),
                    h(Button, { type: 'submit', busy: busy, disabled: busy || !password, variant: 'primary' }, busy ? 'Unlocking…' : 'Unlock toolkit')
                )
            )
        );
    }

    function reportRows(audit, psi) {
        var seo = audit.seo || {};
        var rows = [['Section', 'Metric', 'Value', 'Status / Notes']];
        Object.keys(audit.scores || {}).forEach(function (key) { rows.push(['Score', key, audit.scores[key], '']); });
        (seo.checks || []).forEach(function (check) { rows.push(['SEO check', check.label, check.value || '', (check.status || '') + (check.message ? ' - ' + check.message : '')]); });
        rows.push(['Page', 'Title', seo.title || '', (seo.title_length || 0) + ' chars']);
        rows.push(['Page', 'Meta description', seo.description || '', (seo.description_length || 0) + ' chars']);
        rows.push(['Page', 'Canonical', seo.canonical || '', '']);
        rows.push(['Content', 'Word count', seo.content && seo.content.word_count || 0, '']);
        rows.push(['Content', 'Vague link text', seo.content && seo.content.vague_link_text || 0, '']);
        rows.push(['Social', 'Share readiness', seo.social && seo.social.score || 0, '']);
        Object.entries((seo.social && seo.social.profiles) || {}).forEach(function (entry) { rows.push(['Social profile', entry[0], entry[1], '']); });
        if (psi && psi.categories) Object.entries(psi.categories).forEach(function (entry) { rows.push(['Lighthouse', entry[0], entry[1], psi.strategy || '']); });
        return rows;
    }

    function exportAuditCsv(audit, psi) {
        var csv = reportRows(audit, psi).map(function (row) { return row.map(csvCell).join(','); }).join('\r\n');
        var host = 'website';
        try { host = new URL(audit.url).hostname.replace(/[^a-z0-9.-]+/gi, '-'); } catch (e) {}
        downloadText('\ufeff' + csv, 'website-audit-' + host + '.csv', 'text/csv;charset=utf-8');
    }

    function exportAuditJson(audit, psi) {
        var payload = { exported_at: new Date().toISOString(), version: config.version || '', audit: audit, pagespeed: psi || null };
        var host = 'website';
        try { host = new URL(audit.url).hostname.replace(/[^a-z0-9.-]+/gi, '-'); } catch (e) {}
        downloadText(JSON.stringify(payload, null, 2), 'website-audit-' + host + '.json', 'application/json');
    }

    function categoryLabel(key) {
        var labels = {
            'performance': 'Performance',
            'accessibility': 'Accessibility',
            'best-practices': 'Best Practices',
            'seo': 'SEO',
            'agentic-browsing': 'Agentic Browsing',
            'agentic_browsing': 'Agentic Browsing',
            'pwa': 'PWA'
        };
        return labels[key] || String(key || '').replace(/[-_]+/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
    }

    function averageScore(values) {
        var nums = (values || []).map(Number).filter(function (value) { return !isNaN(value); });
        if (!nums.length) return null;
        return Math.round(nums.reduce(function (sum, value) { return sum + value; }, 0) / nums.length);
    }

    function reportOverallScore(audit, psi) {
        var values = [];
        var scores = audit && audit.scores || {};
        ['seo', 'quick_performance', 'security_headers'].forEach(function (key) {
            if (scores[key] !== null && scores[key] !== undefined) values.push(scores[key]);
        });
        Object.keys(psi && psi.categories || {}).forEach(function (key) {
            if ((psi.categories || {})[key] !== null && (psi.categories || {})[key] !== undefined) values.push(psi.categories[key]);
        });
        return averageScore(values);
    }

    function ScoreBars(props) {
        var entries = Object.entries(props.scores || {}).filter(function (entry) { return typeof entry[1] === 'number'; });
        return h('div', { className: 'seopc-chart-card seopc-report-block' },
            h('div', { className: 'seopc-chart-head' }, h('strong', null, props.title || 'Score overview'), h('span', null, '0–100')),
            h('div', { className: 'seopc-bars' }, entries.map(function (entry) {
                var score = Math.max(0, Math.min(100, Math.round(Number(entry[1]))));
                return h('div', { key: entry[0], className: 'seopc-bar-row' },
                    h('div', { className: 'seopc-bar-label' }, h('span', null, categoryLabel(entry[0])), h('strong', null, score)),
                    h('div', { className: 'seopc-bar-track' }, h('span', { className: 'is-' + scoreTone(score), style: { width: score + '%' } }))
                );
            }))
        );
    }

    function ReportMetricCard(props) {
        var tone = scoreTone(props.score);
        return h('div', { className: 'seopc-report-metric is-' + tone },
            h('div', { className: 'seopc-report-metric-top' },
                h('span', { className: 'seopc-report-metric-dot' }),
                h('strong', null, props.value || '—')
            ),
            h('span', { className: 'seopc-report-metric-label' }, props.label),
            props.note ? h('small', null, props.note) : null
        );
    }

    function categoryAuditTone(audit) {
        if (!audit || audit.score === null || audit.score === undefined) return 'neutral';
        return scoreTone(audit.score);
    }

    function CategoryAuditPanel(props) {
        var item = props.item || {};
        var audits = (item.audits || []).filter(function (audit) {
            return audit.score !== null && audit.score !== undefined && audit.score < 100;
        }).slice(0, 8);
        return h('article', { className: 'seopc-lh-category seopc-report-block' },
            h('div', { className: 'seopc-lh-category-head' },
                h('div', null, h('strong', null, item.title || categoryLabel(props.category)), h('small', null, 'Lighthouse category')),
                h('span', { className: 'seopc-mini-score is-' + scoreTone(item.score) }, item.score === null || item.score === undefined ? '—' : Math.round(item.score))
            ),
            item.description ? h('p', { className: 'seopc-lh-category-description' }, item.description) : null,
            audits.length ? h('div', { className: 'seopc-lh-audit-list' }, audits.map(function (audit) {
                return h('div', { key: audit.id, className: 'seopc-lh-audit-row is-' + categoryAuditTone(audit) },
                    h('span', { className: 'seopc-lh-audit-dot' }),
                    h('div', null,
                        h('strong', null, audit.title),
                        h('small', null, audit.display_value || (audit.score !== null && audit.score !== undefined ? audit.score + '/100' : 'Review'))
                    )
                );
            })) : h('div', { className: 'seopc-lh-all-good' }, 'No scored issues in this category.')
        );
    }

    function ReportInsightDashboard(props) {
        var audit = props.audit || {}, psi = props.psi || {}, seo = audit.seo || {}, resources = audit.resources || {};
        var checks = (seo.checks || []).length ? seo.checks : fallbackSeoChecks(seo, audit.response || {});
        var severity = { good: 0, warn: 0, bad: 0 };
        checks.forEach(function (check) { var t = check.tone || (check.pass === true ? 'good' : (check.pass === false ? 'bad' : 'warn')); severity[t] = (severity[t] || 0) + 1; });
        (psi.diagnostics || []).forEach(function (item) { var t = scoreTone(item.score); severity[t] = (severity[t] || 0) + 1; });
        var totalResources = Math.max(1, Number(resources.scripts || 0) + Number(resources.stylesheets || 0) + Number(resources.images || 0) + Number(resources.iframes || 0));
        var resourceRows = [
            ['Images', Number(resources.images || 0)], ['Scripts', Number(resources.scripts || 0)],
            ['Stylesheets', Number(resources.stylesheets || 0)], ['Iframes', Number(resources.iframes || 0)]
        ];
        var headingCounts = seo.heading_counts || {};
        return h('div', { className: 'seopc-report-insights seopc-report-block' },
            h('div', { className: 'seopc-insight-card' },
                h('div', { className: 'seopc-insight-head' }, h('strong', null, 'Issue mix'), h('span', null, 'Quick audit + Lighthouse diagnostics')),
                h('div', { className: 'seopc-severity-stack' },
                    h('span', { className: 'is-good', style: { flex: Math.max(1, severity.good) } }, severity.good + ' healthy'),
                    h('span', { className: 'is-warn', style: { flex: Math.max(1, severity.warn) } }, severity.warn + ' review'),
                    h('span', { className: 'is-bad', style: { flex: Math.max(1, severity.bad) } }, severity.bad + ' priority')
                )
            ),
            h('div', { className: 'seopc-insight-card' },
                h('div', { className: 'seopc-insight-head' }, h('strong', null, 'Resource mix'), h('span', null, totalResources + ' estimated assets')),
                h('div', { className: 'seopc-mini-bars' }, resourceRows.map(function (row) {
                    return h('div', { key: row[0] }, h('span', null, row[0]), h('i', null, h('b', { style: { width: Math.max(2, row[1] / totalResources * 100) + '%' } })), h('strong', null, row[1]));
                }))
            ),
            h('div', { className: 'seopc-insight-card' },
                h('div', { className: 'seopc-insight-head' }, h('strong', null, 'Content structure'), h('span', null, safeText(seo.content && seo.content.word_count, 0) + ' words')),
                h('div', { className: 'seopc-heading-spark' }, ['h1','h2','h3','h4','h5','h6'].map(function (key) {
                    var count = Number(headingCounts[key] || 0);
                    return h('div', { key: key }, h('b', { style: { height: Math.min(100, 14 + count * 7) + '%' } }), h('span', null, key.toUpperCase()), h('strong', null, count));
                }))
            )
        );
    }

    async function downloadAuditPdf(audit, psi) {
        // Dependency-free vector PDF report. Designed for predictable A4 alignment without CDN libraries.
        var host = 'website';
        try { host = new URL(audit.url).hostname; } catch (e) {}

        var PW = 595, PH = 842, ML = 36, MR = 36, BODY_W = PW - ML - MR;
        var TOP = 748, BOTTOM = 44;
        var pages = [], ops = [], y = TOP, pageNo = 0;

        function ascii(v) {
            return safeText(v, '').replace(/[–—·•→↗✓]/g, '-').replace(/[^\x20-\x7E]/g, '?');
        }
        function esc(v) {
            return ascii(v).replace(/[\\()]/g, function (m) { return '\\' + m; });
        }
        function fmt(n) { return Math.round(Number(n || 0) * 100) / 100; }
        function textWidth(text, size, bold) {
            var s = ascii(text), units = 0;
            for (var i = 0; i < s.length; i++) {
                var ch = s[i];
                if (/[MW@%&]/.test(ch)) units += 0.82;
                else if (/[ilI1\.,'`\|:;]/.test(ch)) units += 0.28;
                else if (/[A-Z0-9]/.test(ch)) units += 0.58;
                else units += 0.50;
            }
            return units * size * (bold ? 1.04 : 1);
        }
        function splitToken(token, maxWidth, size, bold) {
            var out = [], part = '';
            for (var i = 0; i < token.length; i++) {
                var next = part + token[i];
                if (part && textWidth(next, size, bold) > maxWidth) { out.push(part); part = token[i]; }
                else part = next;
            }
            if (part) out.push(part);
            return out;
        }
        function wrap(text, maxWidth, size, bold) {
            var source = ascii(text).replace(/\s+/g, ' ').trim();
            if (!source) return [''];
            var tokens = source.split(' '), out = [], line = '';
            tokens.forEach(function (token) {
                var pieces = textWidth(token, size, bold) > maxWidth ? splitToken(token, maxWidth, size, bold) : [token];
                pieces.forEach(function (piece) {
                    var next = line ? line + ' ' + piece : piece;
                    if (line && textWidth(next, size, bold) > maxWidth) { out.push(line); line = piece; }
                    else line = next;
                });
            });
            if (line) out.push(line);
            return out.length ? out : [''];
        }
        function fillRect(x, yy, w, h, color) { ops.push(color + ' rg ' + fmt(x) + ' ' + fmt(yy) + ' ' + fmt(w) + ' ' + fmt(h) + ' re f'); }
        function strokeLine(x1, y1, x2, y2, color, width) { ops.push(color + ' RG ' + (width || 1) + ' w ' + fmt(x1) + ' ' + fmt(y1) + ' m ' + fmt(x2) + ' ' + fmt(y2) + ' l S'); }
        function at(t, x, yy, size, bold, color) {
            ops.push((color || '0.08 0.20 0.17') + ' rg BT /' + (bold ? 'F2' : 'F1') + ' ' + (size || 9) + ' Tf ' + fmt(x) + ' ' + fmt(yy) + ' Td (' + esc(t) + ') Tj ET');
        }
        function linesAt(lines, x, yy, size, bold, color, leading) {
            leading = leading || size + 3;
            lines.forEach(function (line, i) { at(line, x, yy - i * leading, size, bold, color); });
            return lines.length * leading;
        }
        function startPage() {
            if (ops.length) pages.push(ops.join('\n'));
            ops = []; pageNo += 1;
            fillRect(0, 776, PW, 66, '0.035 0.20 0.16');
            fillRect(0, 776, 7, 66, '0.07 0.63 0.42');
            at('Website Growth Toolkit', ML, 812, 15, true, '1 1 1');
            at(host + ' - client website audit', ML, 793, 8, false, '0.76 0.92 0.85');
            at('WEBSITE AUDIT', 486, 812, 7, true, '0.76 0.92 0.85');
            y = TOP;
        }
        function ensure(h) { if (y - h < BOTTOM) startPage(); }
        function section(titleText, kicker, subtitle) {
            var subtitleLines = subtitle ? wrap(subtitle, BODY_W, 7.5, false) : [];
            var need = 34 + subtitleLines.length * 10;
            ensure(need);
            at((kicker || '').toUpperCase(), ML, y, 6.7, true, '0.05 0.52 0.37'); y -= 14;
            at(titleText, ML, y, 15, true, '0.07 0.20 0.17'); y -= 10;
            strokeLine(ML, y, PW - MR, y, '0.83 0.89 0.86', 0.8); y -= 13;
            if (subtitleLines.length) { y -= linesAt(subtitleLines, ML, y + 4, 7.5, false, '0.36 0.45 0.41', 10); y -= 2; }
        }
        function hero(overall) {
            ensure(94);
            var h = 82, yy = y - h + 8;
            fillRect(ML, yy, BODY_W, h, '0.955 0.978 0.967');
            fillRect(ML, yy, 6, h, '0.05 0.52 0.37');
            at('CLIENT WEBSITE AUDIT', ML + 18, y - 10, 6.5, true, '0.05 0.52 0.37');
            var hostLines = wrap(host, 350, 18, true).slice(0, 2);
            linesAt(hostLines, ML + 18, y - 30, 18, true, '0.07 0.20 0.17', 20);
            var tested = ascii(audit.final_url || audit.url || '');
            var testedLines = wrap(tested, 350, 7.5, false).slice(0, 2);
            linesAt(testedLines, ML + 18, y - 30 - hostLines.length * 20 - 5, 7.5, false, '0.38 0.47 0.43', 10);
            fillRect(472, yy + 12, 70, 58, '1 1 1');
            at(overall === null || overall === undefined ? '-' : String(Math.round(overall)), 491, yy + 40, 22, true, scoreColor(overall));
            at('OVERALL', 487, yy + 23, 6.5, true, '0.38 0.47 0.43');
            y = yy - 16;
        }
        function scoreColor(score) {
            score = Number(score);
            if (isNaN(score)) return '0.38 0.47 0.43';
            if (score >= 90) return '0.05 0.63 0.38';
            if (score >= 50) return '0.95 0.56 0.04';
            return '0.90 0.25 0.25';
        }
        function scoreGrid(items) {
            items = (items || []).filter(function (it) { return it && it.value !== null && it.value !== undefined && !isNaN(Number(it.value)); });
            if (!items.length) return;
            var cols = 4, gap = 8, w = (BODY_W - gap * (cols - 1)) / cols, cardH = 58;
            for (var i = 0; i < items.length; i += cols) {
                ensure(cardH + 12);
                var yy = y - cardH;
                items.slice(i, i + cols).forEach(function (it, idx) {
                    var x = ML + idx * (w + gap), score = Math.max(0, Math.min(100, Math.round(Number(it.value))));
                    fillRect(x, yy, w, cardH, '0.975 0.985 0.980');
                    fillRect(x, yy + cardH - 3, w, 3, scoreColor(score));
                    at(String(score), x + 12, yy + 29, 18, true, scoreColor(score));
                    var labelLines = wrap(it.label, w - 55, 7, true).slice(0, 2);
                    linesAt(labelLines, x + 55, yy + 35, 7, true, '0.10 0.22 0.19', 9);
                    at('/100', x + 13, yy + 17, 5.5, false, '0.45 0.52 0.49');
                });
                y = yy - 10;
            }
        }
        function kvGrid(rows) {
            var gap = 9, w = (BODY_W - gap) / 2;
            for (var i = 0; i < rows.length; i += 2) {
                var pair = rows.slice(i, i + 2), heights = pair.map(function (r) {
                    var valueLines = wrap(r[1], w - 18, 7.5, false);
                    return Math.max(42, 26 + valueLines.length * 9);
                });
                var h = Math.max.apply(null, heights);
                ensure(h + 9);
                var yy = y - h;
                pair.forEach(function (r, idx) {
                    var x = ML + idx * (w + gap);
                    fillRect(x, yy, w, h, '0.967 0.978 0.973');
                    at(String(r[0]).toUpperCase(), x + 10, yy + h - 13, 6.2, true, '0.39 0.48 0.44');
                    var valueLines = wrap(r[1], w - 20, 7.7, false);
                    linesAt(valueLines, x + 10, yy + h - 27, 7.7, false, '0.08 0.20 0.17', 9.5);
                });
                y = yy - 8;
            }
        }
        function statusList(items, limit) {
            (items || []).slice(0, limit || 30).forEach(function (item) {
                var tone = item.tone || (item.pass === true ? 'good' : (item.pass === false ? 'bad' : 'warn'));
                var label = item.label || item.title || 'Check';
                var detail = item.detail || item.message || '';
                var labelLines = wrap(label, BODY_W - 96, 8, true);
                var detailLines = detail ? wrap(detail, BODY_W - 96, 7.3, false) : [];
                var h = Math.max(38, 22 + labelLines.length * 10 + detailLines.length * 9);
                ensure(h + 7);
                var yy = y - h;
                fillRect(ML, yy, BODY_W, h, '0.978 0.984 0.981');
                fillRect(ML, yy, 5, h, tone === 'bad' ? '0.90 0.25 0.25' : tone === 'warn' ? '0.95 0.56 0.04' : '0.05 0.63 0.38');
                at(tone === 'bad' ? 'PRIORITY' : tone === 'warn' ? 'REVIEW' : 'GOOD', ML + 14, yy + h - 15, 6.2, true, tone === 'bad' ? '0.78 0.18 0.18' : tone === 'warn' ? '0.72 0.43 0.03' : '0.04 0.48 0.29');
                linesAt(labelLines, ML + 76, yy + h - 15, 8, true, '0.08 0.20 0.17', 10);
                if (detailLines.length) linesAt(detailLines, ML + 76, yy + h - 16 - labelLines.length * 10, 7.3, false, '0.35 0.44 0.40', 9);
                y = yy - 7;
            });
        }
        function callouts(items, tone, limit) {
            (items || []).slice(0, limit || 30).forEach(function (item) {
                var text = typeof item === 'string' ? item : (item.title || item.description || '');
                var lines = wrap(text, BODY_W - 34, 7.7, false), h = Math.max(31, 18 + lines.length * 9);
                ensure(h + 7);
                var yy = y - h;
                var bg = tone === 'bad' ? '0.995 0.952 0.948' : tone === 'warn' ? '0.997 0.977 0.910' : '0.953 0.985 0.968';
                var fg = tone === 'bad' ? '0.75 0.18 0.18' : tone === 'warn' ? '0.66 0.39 0.02' : '0.04 0.47 0.28';
                fillRect(ML, yy, BODY_W, h, bg); fillRect(ML, yy, 5, h, fg);
                linesAt(lines, ML + 16, yy + h - 17, 7.7, false, '0.14 0.25 0.22', 9);
                y = yy - 7;
            });
        }
        function imageIssueList(items, limit) {
            (items || []).slice(0, limit || 60).forEach(function (it, idx) {
                var issues = (it.issues || []).join(', ') || 'Review image';
                var issueLines = wrap(issues, BODY_W - 30, 8, true);
                var urlLines = wrap(it.url || 'image', BODY_W - 30, 6.8, false);
                var h = 22 + issueLines.length * 10 + urlLines.length * 8;
                ensure(h + 7);
                var yy = y - h;
                fillRect(ML, yy, BODY_W, h, idx % 2 ? '0.978 0.984 0.981' : '0.988 0.992 0.990');
                at(String(idx + 1).padStart(2, '0'), ML + 10, yy + h - 16, 6.4, true, '0.05 0.52 0.37');
                linesAt(issueLines, ML + 36, yy + h - 16, 8, true, '0.10 0.22 0.19', 10);
                linesAt(urlLines, ML + 36, yy + h - 18 - issueLines.length * 10, 6.8, false, '0.38 0.47 0.43', 8);
                y = yy - 5;
            });
        }
        function metricGrid(metrics) {
            var list = Object.values(metrics || {});
            if (!list.length) return;
            var cols = 3, gap = 8, w = (BODY_W - gap * 2) / 3, h = 48;
            for (var i = 0; i < list.length; i += cols) {
                ensure(h + 9);
                var yy = y - h;
                list.slice(i, i + cols).forEach(function (m, idx) {
                    var x = ML + idx * (w + gap);
                    fillRect(x, yy, w, h, '0.967 0.978 0.973');
                    at(ascii(m.display_value || m.score || '-'), x + 10, yy + 25, 12, true, '0.05 0.52 0.37');
                    var ls = wrap(m.title || 'Metric', w - 20, 6.6, true).slice(0, 2);
                    linesAt(ls, x + 10, yy + 13, 6.6, true, '0.25 0.35 0.31', 8);
                });
                y = yy - 8;
            }
        }
        function securityTable(data) {
            var entries = Object.entries(data || {});
            entries.forEach(function (entry, idx) {
                ensure(21);
                var yy = y - 18;
                fillRect(ML, yy, BODY_W, 18, idx % 2 ? '0.978 0.984 0.981' : '0.988 0.992 0.990');
                at(entry[0], ML + 10, yy + 6, 7.0, true, '0.35 0.44 0.40');
                var value = entry[1] || 'Missing';
                at(value, ML + 260, yy + 6, 7.2, false, value === 'Missing' ? '0.78 0.18 0.18' : '0.08 0.20 0.17');
                y = yy - 2;
            });
        }

        startPage();
        var overall = reportOverallScore(audit, psi);
        hero(overall);
        section('Executive scorecard', 'Summary', 'A client-ready view of search visibility, technical health and Lighthouse quality signals.');
        var scoreItems = [
            { label: 'Overall', value: overall },
            { label: 'SEO', value: audit.scores && audit.scores.seo },
            { label: 'Quick performance', value: audit.scores && audit.scores.quick_performance },
            { label: 'Security headers', value: audit.scores && audit.scores.security_headers }
        ];
        Object.entries(psi && psi.categories || {}).forEach(function (e) { scoreItems.push({ label: categoryLabel(e[0]), value: e[1] }); });
        scoreGrid(scoreItems);

        var seo = audit.seo || {}, resp = audit.response || {}, res = audit.resources || {};
        section('Page summary', 'Technical', 'Core response, resource and canonical information from the audited page.');
        kvGrid([
            ['Tested URL', audit.final_url || audit.url], ['HTTP status', resp.status],
            ['HTML transfer', formatBytes(resp.html_bytes)], ['Estimated requests', res.estimated_requests],
            ['Images', res.images], ['Scripts / styles', (res.scripts || 0) + ' / ' + (res.stylesheets || 0)],
            ['Server', resp.server], ['Compression', resp.content_encoding || 'Not detected'],
            ['Canonical', seo.canonical || 'Missing'], ['Generated', new Date().toLocaleString()]
        ]);

        var checks = (seo.checks || []).length ? seo.checks : fallbackSeoChecks(seo, resp);
        ensure(120);
        section('SEO health checks', 'SEO', 'Green items are healthy, amber items need review, and red items are priority fixes.');
        statusList(checks, 28);

        if ((seo.issues || []).length) {
            ensure(90);
            section('Priority fixes', 'Action plan', 'Resolve the highest-impact on-page and technical findings first.');
            callouts(seo.issues, 'bad', 35);
        }

        var imageIssues = (seo.images && seo.images.issues) || [];
        if (imageIssues.length) {
            ensure(105);
            section('Image issues', 'Media QA', 'Each affected asset is listed with its exact issue and URL for developer handoff.');
            imageIssueList(imageIssues, 80);
        }

        if (psi) {
            if (Object.keys(psi.metrics || {}).length) {
                ensure(110);
                section('Lighthouse metrics', 'Google', 'Key laboratory and field metrics returned by the current PageSpeed/Lighthouse response.');
                metricGrid(psi.metrics || {});
            }
            if ((psi.opportunities || []).length) {
                ensure(95);
                section('Performance opportunities', 'Lighthouse', 'Potential improvements surfaced by Lighthouse.');
                callouts(psi.opportunities.map(function (x) { return x.title + (x.display_value ? ' - ' + x.display_value : ''); }), 'warn', 22);
            }
            if ((psi.diagnostics || []).length) {
                ensure(95);
                section('Diagnostics', 'Lighthouse', 'Supporting diagnostics that may explain performance and quality issues.');
                callouts(psi.diagnostics.map(function (x) { return x.title + (x.display_value ? ' - ' + x.display_value : ''); }), 'warn', 30);
            }
            Object.entries(psi.category_details || {}).forEach(function (e) {
                var detail = e[1] || {};
                ensure(115);
                section(detail.title || categoryLabel(e[0]), 'Category detail', detail.description || 'Scored Lighthouse audits that need attention.');
                scoreGrid([{ label: 'Category score', value: detail.score }]);
                var rows = (detail.audits || []).filter(function (a) { return a.score !== null && a.score !== undefined && a.score < 100; }).map(function (a) {
                    return a.title + (a.display_value ? ' - ' + a.display_value : '');
                });
                callouts(rows, 'warn', 24);
            });
        }

        ensure(145);
        section('Security headers', 'Security', 'Recommended browser security headers detected on the audited response.');
        securityTable(audit.security_headers || {});

        if (ops.length) pages.push(ops.join('\n'));
        pages = pages.map(function (content, i) {
            return content +
                '\n0.84 0.90 0.87 RG 36 29 m 559 29 l S' +
                '\n0.38 0.47 0.43 rg BT /F1 6.8 Tf 36 17 Td (' + esc('Website Growth Toolkit - ' + host) + ') Tj ET' +
                '\nBT /F1 6.8 Tf 500 17 Td (' + esc('Page ' + (i + 1) + ' / ' + pages.length) + ') Tj ET';
        });

        var objects = [];
        function add(body) { objects.push(body); return objects.length; }
        var catalog = add('<< /Type /Catalog /Pages 2 0 R >>');
        add('PAGES_PLACEHOLDER');
        var font1 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        var font2 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>');
        var pageRefs = [];
        pages.forEach(function (content) {
            var stream = add('<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream');
            var p = add('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' + font1 + ' 0 R /F2 ' + font2 + ' 0 R >> >> /Contents ' + stream + ' 0 R >>');
            pageRefs.push(p + ' 0 R');
        });
        objects[1] = '<< /Type /Pages /Kids [' + pageRefs.join(' ') + '] /Count ' + pageRefs.length + ' >>';
        var out = '%PDF-1.4\n%WGT\n', offsets = [0];
        objects.forEach(function (body, i) { offsets.push(out.length); out += (i + 1) + ' 0 obj\n' + body + '\nendobj\n'; });
        var xref = out.length;
        out += 'xref\n0 ' + (objects.length + 1) + '\n0000000000 65535 f \n';
        for (var oi = 1; oi < offsets.length; oi++) out += String(offsets[oi]).padStart(10, '0') + ' 00000 n \n';
        out += 'trailer\n<< /Size ' + (objects.length + 1) + ' /Root ' + catalog + ' 0 R >>\nstartxref\n' + xref + '\n%%EOF';
        downloadBlob(new Blob([out], { type: 'application/pdf' }), 'website-audit-' + host.replace(/[^a-z0-9.-]+/gi, '-') + '.pdf');
    }
    function downloadImageIssuesCsv(items, host) {
        var rows=[['Image URL','Issues','Alt text','Width','Height','Loading']];
        (items||[]).forEach(function(it){rows.push([it.url||'',(it.issues||[]).join('; '),it.alt||'',it.width||'',it.height||'',it.loading||'']);});
        var csv=rows.map(function(r){return r.map(function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
        downloadBlob(new Blob([csv],{type:'text/csv;charset=utf-8'}),'image-issues-'+(host||'website').replace(/[^a-z0-9.-]+/gi,'-')+'.csv');
    }

    function AuditResults(props) {
        var audit = props.audit;
        var psi = props.psi;
        var pdfBusyState = useState(false);
        var pdfBusy = pdfBusyState[0];
        var setPdfBusy = pdfBusyState[1];
        var pdfErrorState = useState('');
        var pdfError = pdfErrorState[0];
        var setPdfError = pdfErrorState[1];
        if (!audit) return null;
        var seo = audit.seo || {};
        var resources = audit.resources || {};
        var response = audit.response || {};
        var headings = seo.headings || [];
        var issues = seo.issues || [];
        var checks = (seo.checks || []).length ? seo.checks : fallbackSeoChecks(seo, response);
        var thirdParty = Object.entries(resources.third_party_hosts || {});
        var overall = reportOverallScore(audit, psi);
        var reportHost = audit.url || '';
        try { reportHost = new URL(audit.url).hostname; } catch (e) {}

        async function savePdf() {
            setPdfBusy(true);
            setPdfError('');
            try { await downloadAuditPdf(audit, psi); }
            catch (err) { setPdfError(err.message || 'Could not build the PDF report.'); }
            finally { setPdfBusy(false); }
        }

        return h('div', { className: 'seopc-results' },
            h('div', { className: 'seopc-report-toolbar seopc-no-pdf' },
                h('div', null, h('strong', null, 'Client-ready agency report'), h('span', null, 'Designed for screen review, stakeholder handoff and A4 PDF export.')),
                h('div', { className: 'seopc-inline-actions' },
                    h(Button, { variant: 'light', onClick: function () { exportAuditCsv(audit, psi); } }, 'CSV'),
                    h(Button, { variant: 'light', onClick: function () { exportAuditJson(audit, psi); } }, 'JSON'),
                    h(Button, { variant: 'light', onClick: function () { window.print(); } }, 'Print'),
                    h(Button, { variant: 'primary', busy: pdfBusy, disabled: pdfBusy, onClick: savePdf }, pdfBusy ? 'Building PDF…' : 'Download PDF')
                )
            ),
            pdfError ? h(Notice, { type: 'error' }, pdfError) : null,

            h('div', { className: 'seopc-client-report' },
                h('section', { className: 'seopc-report-cover seopc-report-block' },
                    h('div', { className: 'seopc-report-cover-copy' },
                        h('span', { className: 'seopc-report-kicker' }, 'Website audit report'),
                        h('h2', null, reportHost || 'Website'),
                        h('p', null, 'SEO, performance, accessibility, best practices, agent readiness and technical quality in one client-friendly report.'),
                        h('div', { className: 'seopc-report-meta' },
                            h('span', null, 'Tested ' + safeText(audit.tested_at)),
                            h('span', null, safeText(audit.final_url || audit.url)),
                            psi ? h('span', null, 'Lighthouse ' + safeText(psi.lighthouse_version) + ' · ' + safeText(psi.strategy)) : null
                        )
                    ),
                    h('div', { className: 'seopc-report-overall' },
                        h('div', { className: 'seopc-score-ring seopc-score-' + scoreTone(overall), style: { '--seopc-score-angle': overall === null ? '0deg' : (overall * 3.6) + 'deg', '--seopc-score-color': overall === null ? '#94a3b8' : undefined } },
                            h('div', { className: 'seopc-score-ring-inner' }, h('strong', { className: 'seopc-score-number' }, overall === null ? '—' : overall), overall === null ? null : h('span', null, '/100'))
                        ),
                        h('strong', null, 'Overall snapshot'),
                        h('small', null, overall === null ? 'Awaiting complete data' : scoreStatus(scoreTone(overall)))
                    )
                ),

                h('section', { className: 'seopc-report-score-section seopc-report-block' },
                    h('div', { className: 'seopc-report-section-heading' }, h('div', null, h('span', null, 'Summary'), h('h3', null, 'Quality scores')), h('small', null, 'Green 90–100 · amber 50–89 · red 0–49')),
                    h('div', { className: 'seopc-score-grid seopc-score-grid-report' },
                        h(ScoreCard, { score: audit.scores && audit.scores.seo, label: 'SEO' }),
                        h(ScoreCard, { score: audit.scores && audit.scores.quick_performance, label: 'Quick speed', note: response.elapsed_ms + ' ms' }),
                        h(ScoreCard, { score: audit.scores && audit.scores.security_headers, label: 'Security headers' }),
                        psi && psi.categories ? Object.entries(psi.categories).map(function (entry) { return h(ScoreCard, { key: entry[0], score: entry[1], label: categoryLabel(entry[0]) }); }) : h(ScoreCard, { score: NaN, label: 'Lighthouse', note: props.psiError || 'Not available' })
                    )
                ),

                h(ScoreBars, { title: 'Marketing & technical score snapshot', scores: Object.assign({}, audit.scores || {}, psi && psi.categories ? psi.categories : {}) }),
                h(ReportInsightDashboard, { audit: audit, psi: psi }),
                audit.cached ? h(Notice, null, 'Quick audit loaded from the temporary cache.') : null,
                props.psiError ? h(Notice, { type: 'warning' }, props.psiError) : null,

                psi ? h(PageSpeedResults, { psi: psi }) : null,

                h(Section, { title: 'Page summary' },
                    h('div', { className: 'seopc-stat-grid' },
                        h(Stat, { label: 'HTTP status', value: response.status }),
                        h(Stat, { label: 'HTML transfer', value: formatBytes(response.html_bytes) }),
                        h(Stat, { label: 'Estimated requests', value: resources.estimated_requests }),
                        h(Stat, { label: 'Scripts', value: resources.scripts }),
                        h(Stat, { label: 'Stylesheets', value: resources.stylesheets }),
                        h(Stat, { label: 'Images', value: resources.images }),
                        h(Stat, { label: 'Iframes', value: resources.iframes }),
                        h(Stat, { label: 'Compression', value: response.content_encoding || 'Not detected' })
                    ),
                    h(KeyValueTable, { entries: [
                        ['Tested URL', audit.url], ['Final URL', audit.final_url], ['Server', response.server], ['Powered by', response.powered_by],
                        ['Cache-Control', response.cache_control], ['Content type', response.content_type], ['Tested at', audit.tested_at]
                    ] })
                ),

                h(Section, { title: 'SEO health checks', subtitle: 'Green is healthy, amber needs improvement, and red identifies a priority problem.' },
                    h('div', { className: 'seopc-check-grid' }, checks.map(function (check, index) {
                        return h(AuditCheck, { key: check.id || index, check: check });
                    })),
                    h('h4', null, 'Metadata details'),
                    h(KeyValueTable, { entries: [
                        ['Title (' + (seo.title_length || 0) + ')', seo.title],
                        ['Description (' + (seo.description_length || 0) + ')', seo.description],
                        ['Canonical', seo.canonical], ['Robots', seo.robots], ['Viewport', seo.viewport], ['Language', seo.lang],
                        ['Schema blocks', seo.schema_count], ['Schema types', (seo.schema_types || []).join(', ')],
                        ['Open Graph title', seo.open_graph && seo.open_graph.title], ['Twitter card', seo.twitter_card],
                        ['H1 detection', seo.heading_detection], ['Content words', seo.content && seo.content.word_count], ['Long paragraphs', seo.content && seo.content.long_paragraphs], ['Vague link text', seo.content && seo.content.vague_link_text]
                    ] }),
                    h('h4', null, 'Heading counts'),
                    h('div', { className: 'seopc-mini-grid' },
                        Object.keys(seo.heading_counts || {}).map(function (key) {
                            var count = Number(seo.heading_counts[key] || 0);
                            var tone = headingTone(key, count);
                            var note = key === 'h1' ? (count === 1 ? 'Correct: exactly one' : 'Use exactly one H1') : (key === 'h2' ? (count > 0 ? 'Section headings found' : 'Consider adding H2 sections') : 'Supporting headings');
                            return h('div', { key: key, className: 'seopc-heading-count is-' + tone },
                                h('strong', null, key.toUpperCase()),
                                h('span', { className: 'seopc-heading-value' }, count),
                                h('small', null, note)
                            );
                        })
                    ),
                    headings.length ? h('div', { className: 'seopc-heading-list' }, headings.map(function (item, index) {
                        return h('div', { key: index, className: 'seopc-heading-row' }, h('span', null, 'H' + item.level), h('p', null, item.text || '(empty heading)'));
                    })) : h(Notice, { type: 'warning' }, 'No headings found in the initial HTML.'),
                    (seo.hierarchy_issues || []).length ? h(Notice, { type: 'warning' },
                        h('strong', null, 'Heading hierarchy issues'),
                        h('ul', null, seo.hierarchy_issues.map(function (item, index) { return h('li', { key: index }, item); }))
                    ) : null
                ),

                h(Section, { title: 'Images, links and resources' },
                    h('div', { className: 'seopc-stat-grid' },
                        h(Stat, { label: 'Missing alt attribute', value: seo.images && seo.images.missing_alt }),
                        h(Stat, { label: 'Empty alt', value: seo.images && seo.images.empty_alt }),
                        h(Stat, { label: 'Missing dimensions', value: seo.images && seo.images.missing_dimensions }),
                        h(Stat, { label: 'Missing lazy loading', value: seo.images && seo.images.without_lazy }),
                        h(Stat, { label: 'Modern image formats', value: seo.images && seo.images.modern_format }),
                        h(Stat, { label: 'Internal links', value: seo.links && seo.links.internal }),
                        h(Stat, { label: 'External links', value: seo.links && seo.links.external }),
                        h(Stat, { label: 'Render-blocking CSS', value: resources.render_blocking_stylesheets })
                    ),
                    (seo.images && seo.images.issues && seo.images.issues.length) ? h('div', { className: 'seopc-image-issue-panel' },
                        h('div', { className: 'seopc-section-head-row' },
                            h('div', null, h('h4', null, 'Image issues (' + seo.images.issues.length + ')'), h('p', null, 'Detailed image URLs and the exact checks that need attention.')),
                            h(Button, { variant: 'light', onClick: function(){ downloadImageIssuesCsv(seo.images.issues, reportHost); } }, 'Download image issues CSV')
                        ),
                        h('div', { className: 'seopc-image-issue-list' }, seo.images.issues.map(function(it,idx){return h('div',{className:'seopc-image-issue-row',key:idx},h('div',null,h('strong',null,(it.issues||[]).join(' · ')),h('small',null,it.url||'Image URL unavailable'),it.alt?h('span',{className:'seopc-image-alt'},'alt: '+it.alt):null),it.url?h('a',{href:it.url,target:'_blank',rel:'noopener noreferrer',className:'seopc-btn seopc-btn-light'},'Open image'):null);}))
                    ) : null,
                    thirdParty.length ? h('div', null,
                        h('h4', null, 'Third-party resource hosts'),
                        h('div', { className: 'seopc-chip-list' }, thirdParty.map(function (entry) {
                            return h('span', { className: 'seopc-chip', key: entry[0] }, entry[0] + ' · ' + entry[1]);
                        }))
                    ) : null
                ),

                issues.length ? h(Section, { title: 'Priority fixes', subtitle: 'Resolve red checks first, then work through amber recommendations.' },
                    h('ol', { className: 'seopc-issue-list' }, issues.map(function (item, index) {
                        return h('li', { key: index }, h('span', { 'aria-hidden': 'true' }, '!'), h('div', null, item));
                    }))
                ) : h(Notice, { type: 'success' }, 'No major quick-audit SEO issues were detected.'),

                h(Section, { title: 'Security headers' },
                    h(KeyValueTable, { entries: Object.entries(audit.security_headers || {}).map(function (entry) {
                        return [entry[0], entry[1] || 'Missing'];
                    }) })
                ),

                h(Section, { title: 'Open in specialist tools', subtitle: 'These links open the tested URL in third-party services; this plugin does not scrape or impersonate those services.', className: 'seopc-no-pdf' },
                    h('div', { className: 'seopc-action-links seopc-no-pdf' }, Object.entries(audit.external_tools || {}).map(function (entry) {
                        return h('a', { key: entry[0], href: entry[1], target: '_blank', rel: 'noopener noreferrer', className: 'seopc-btn seopc-btn-light' }, entry[0].replace('_', ' '));
                    }))
                )
            )
        );
    }

    function PageSpeedResults(props) {
        var psi = props.psi;
        var metrics = Object.values(psi.metrics || {});
        var fieldMetrics = Object.values((psi.field_data && psi.field_data.url_metrics) || {});
        var categoryDetails = psi.category_details || {};
        var agenticAvailable = !!(psi.agentic_browsing_available || psi.categories && (psi.categories['agentic-browsing'] !== undefined || psi.categories.agentic_browsing !== undefined));
        return h('section', { className: 'seopc-psi-dashboard seopc-report-block' },
            h('div', { className: 'seopc-report-section-heading' },
                h('div', null, h('span', null, 'Google Lighthouse'), h('h3', null, 'PageSpeed & browser quality')), 
                h('small', null, safeText(psi.strategy) + ' · Lighthouse ' + safeText(psi.lighthouse_version))
            ),
            h('div', { className: 'seopc-score-grid seopc-score-grid-compact seopc-lh-score-grid' },
                Object.entries(psi.categories || {}).map(function (entry) { return h(ScoreCard, { key: entry[0], score: entry[1], label: categoryLabel(entry[0]) }); })
            ),
            !agenticAvailable ? h(Notice, { type: 'warning' }, 'Agentic Browsing was not returned by this PageSpeed endpoint. The plugin requested it and keeps the standard Performance, Accessibility, Best Practices and SEO results when Google has not enabled the new category on that API node yet.') : null,

            h('div', { className: 'seopc-psi-surface' },
                h('div', { className: 'seopc-psi-surface-head' }, h('strong', null, 'Lab metrics'), h('span', null, 'Measured in the Lighthouse test environment')),
                h('div', { className: 'seopc-report-metric-grid' }, metrics.map(function (metric, index) {
                    return h(ReportMetricCard, { key: metric.id || index, label: metric.title, value: metric.display_value, score: metric.score, note: metric.score === null || metric.score === undefined ? '' : metric.score + '/100 metric score' });
                }))
            ),

            fieldMetrics.length ? h('div', { className: 'seopc-psi-surface seopc-report-block' },
                h('div', { className: 'seopc-psi-surface-head' }, h('strong', null, 'Real-user field data'), h('span', null, 'Chrome UX Report percentile data when available')),
                h('div', { className: 'seopc-report-metric-grid' }, fieldMetrics.map(function (metric, index) {
                    var toneScore = metric.category === 'FAST' ? 100 : (metric.category === 'AVERAGE' ? 65 : (metric.category === 'SLOW' ? 20 : null));
                    return h(ReportMetricCard, { key: index, label: metric.title, value: safeText(metric.display_value), score: toneScore, note: safeText(metric.category, 'No category') });
                }))
            ) : null,

            (psi.opportunities || []).length ? h('div', { className: 'seopc-psi-list-card seopc-report-block' },
                h('div', { className: 'seopc-psi-list-head' }, h('strong', null, 'Opportunities'), h('span', null, 'Largest potential performance gains first')),
                h('div', { className: 'seopc-psi-list' }, psi.opportunities.map(function (item) {
                    return h('article', { key: item.id },
                        h('span', { className: 'seopc-psi-list-dot is-' + scoreTone(item.score) }),
                        h('div', null, h('strong', null, item.title), item.description ? h('p', null, item.description) : null),
                        h('b', null, item.display_value || (item.savings_ms ? item.savings_ms + ' ms' : safeText(item.score)))
                    );
                }))
            ) : null,

            (psi.diagnostics || []).length ? h('div', { className: 'seopc-psi-list-card seopc-report-block' },
                h('div', { className: 'seopc-psi-list-head' }, h('strong', null, 'Diagnostics'), h('span', null, 'Additional failed or partial Lighthouse audits')),
                h('div', { className: 'seopc-psi-list' }, psi.diagnostics.slice(0, 12).map(function (item) {
                    return h('article', { key: item.id },
                        h('span', { className: 'seopc-psi-list-dot is-' + scoreTone(item.score) }),
                        h('div', null, h('strong', null, item.title), item.description ? h('p', null, item.description) : null),
                        h('b', null, item.display_value || (item.score === null || item.score === undefined ? 'Review' : item.score + '/100'))
                    );
                }))
            ) : null,

            Object.keys(categoryDetails).length ? h('div', { className: 'seopc-lh-category-grid' }, Object.entries(categoryDetails).map(function (entry) {
                return h(CategoryAuditPanel, { key: entry[0], category: entry[0], item: entry[1] });
            })) : null
        );
    }

    function AuditTab(props) {
        var urlState = useState(lastSiteUrl());
        var url = urlState[0];
        var setUrl = urlState[1];
        var strategyState = useState('mobile');
        var strategy = strategyState[0];
        var setStrategy = strategyState[1];
        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];
        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];
        var auditState = useState(null);
        var audit = auditState[0];
        var setAudit = auditState[1];
        var psiState = useState(null);
        var psi = psiState[0];
        var setPsi = psiState[1];
        var psiErrorState = useState('');
        var psiError = psiErrorState[0];
        var setPsiError = psiErrorState[1];
        var historyState = useState(function () {
            try { return JSON.parse(localStorage.getItem('seopc_url_history') || '[]'); } catch (e) { return []; }
        });
        var history = historyState[0];
        var setHistory = historyState[1];
        var freshState = useState(false);
        var fresh = freshState[0];
        var setFresh = freshState[1];

        async function run(event) {
            event.preventDefault();
            setBusy(true); setError(''); setPsiError(''); setAudit(null); setPsi(null);
            var normalized = url.trim();
            rememberSiteUrl(normalized);
            try {
                var settled = await Promise.allSettled([
                    request('seopc_toolkit_analyze', { url: normalized, fresh: fresh ? '1' : '0' }, props.token),
                    request('seopc_toolkit_pagespeed', { url: normalized, strategy: strategy, fresh: fresh ? '1' : '0' }, props.token)
                ]);
                if (settled[0].status === 'rejected') throw settled[0].reason;
                setAudit(settled[0].value);
                if (settled[1].status === 'fulfilled') setPsi(settled[1].value);
                else setPsiError(settled[1].reason.message);
                try { localStorage.setItem('seopc_last_audit_payload', JSON.stringify({ audit: settled[0].value, pagespeed: settled[1].status === 'fulfilled' ? settled[1].value : null, saved_at: Date.now() })); } catch (storageError) {}
                var next = [normalized].concat(history.filter(function (item) { return item !== normalized; })).slice(0, 8);
                setHistory(next);
                localStorage.setItem('seopc_url_history', JSON.stringify(next));
            } catch (err) {
                setError(err.message);
                if (err.status === 403) props.onExpired();
            } finally {
                setBusy(false);
            }
        }

        function importAuditFile(file) {
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function () {
                try {
                    var parsed = JSON.parse(reader.result);
                    var nextAudit = parsed.audit || parsed;
                    if (!nextAudit || !nextAudit.seo || !nextAudit.scores) throw new Error('This does not look like a toolkit audit export.');
                    setAudit(nextAudit);
                    setPsi(parsed.pagespeed || null);
                    setUrl(nextAudit.url || url);
                    setError(''); setPsiError('');
                    if (nextAudit.url) rememberSiteUrl(nextAudit.url);
                } catch (e) { setError(e.message || 'Could not import this audit file.'); }
            };
            reader.onerror = function () { setError('Could not read this audit file.'); };
            reader.readAsText(file);
        }

        return h('div', null,
            h('div', { className: 'seopc-hero-panel' },
                h('div', null, h('span', { className: 'seopc-eyebrow' }, 'Live website audit'), h('h2', null, 'SEO, H1, resources and speed'), h('p', null, 'Run a fast server-side audit and an official Google Lighthouse test from one smart URL field.')),
                h('form', { className: 'seopc-url-form', onSubmit: run },
                    h('div', { className: 'seopc-input-grow' },
                        h('label', { htmlFor: 'seopc-audit-url' }, 'Website or page URL'),
                        h('input', { id: 'seopc-audit-url', type: 'url', inputMode: 'url', autoCapitalize: 'none', autoCorrect: 'off', spellCheck: false, list: 'seopc-url-history', placeholder: 'https://example.com/page/', value: url, required: true, onChange: function (e) { setUrl(e.target.value); } }),
                        h('datalist', { id: 'seopc-url-history' }, history.map(function (item) { return h('option', { key: item, value: item }); }))
                    ),
                    h('div', null,
                        h('label', { htmlFor: 'seopc-psi-strategy' }, 'Lighthouse device'),
                        h('select', { id: 'seopc-psi-strategy', value: strategy, onChange: function (e) { setStrategy(e.target.value); } },
                            h('option', { value: 'mobile' }, 'Mobile'), h('option', { value: 'desktop' }, 'Desktop')
                        )
                    ),
                    h(AjaxFreshToggle, { checked: fresh, onChange: setFresh }),
                    h(Button, { type: 'submit', variant: 'primary', busy: busy, disabled: busy || !url.trim() }, busy ? 'Testing…' : 'Analyze')
                )
            ),
            h('div', { className: 'seopc-compact-actions' },
                h('span', null, 'Agency workflow:'),
                h('label', { className: 'seopc-file-action' }, 'Import audit JSON', h('input', { type: 'file', accept: '.json,application/json', onChange: function (e) { importAuditFile(e.target.files && e.target.files[0]); e.target.value = ''; } })),
                h('button', { type: 'button', className: 'seopc-link-button', onClick: function () { try { var cached = JSON.parse(localStorage.getItem('seopc_last_audit_payload') || 'null'); if (cached && cached.audit) { setAudit(cached.audit); setPsi(cached.pagespeed || null); setUrl(cached.audit.url || url); } else setError('No previous browser audit is cached yet.'); } catch (e) { setError('Could not load the previous browser audit.'); } } }, 'Load last browser audit')
            ),
            error ? h(Notice, { type: 'error' }, error) : null,
            busy ? h('div', { className: 'seopc-loading-panel' }, h('span', { className: 'seopc-spinner seopc-spinner-large' }), h('p', null, 'Fetching HTML, DNS-safe resources and Lighthouse data…')) : null,
            h(AuditResults, { audit: audit, psi: psi, psiError: psiError })
        );
    }

    function DnsRecords(props) {
        var dns = props.dns || {};
        return h('div', { className: 'seopc-dns-grid' }, Object.entries(dns).map(function (entry) {
            var type = entry[0];
            var records = entry[1] || [];
            return h('article', { key: type, className: 'seopc-dns-card' },
                h('h4', null, type + ' (' + records.length + ')'),
                records.length ? h('pre', null, JSON.stringify(records, null, 2)) : h('p', null, 'No record returned')
            );
        }));
    }

    function NetworkTab(props) {
        var targetState = useState('');
        var target = targetState[0];
        var setTarget = targetState[1];
        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];
        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];
        var resultState = useState(null);
        var result = resultState[0];
        var setResult = resultState[1];
        var freshState = useState(false);
        var fresh = freshState[0];
        var setFresh = freshState[1];

        async function run(event) {
            event.preventDefault(); setBusy(true); setError(''); setResult(null);
            try { setResult(await request('seopc_toolkit_network', { target: target, fresh: fresh ? '1' : '0' }, props.token)); }
            catch (err) { setError(err.message); if (err.status === 403) props.onExpired(); }
            finally { setBusy(false); }
        }

        return h('div', null,
            h('div', { className: 'seopc-hero-panel' },
                h('div', null, h('span', { className: 'seopc-eyebrow' }, 'DNS and server intelligence'), h('h2', null, 'Reachability, hosting and TLS'), h('p', null, 'Checks public DNS records, server headers, ports 80/443, certificate dates, reverse DNS and available hosting clues.')),
                h('form', { className: 'seopc-url-form', onSubmit: run },
                    h('div', { className: 'seopc-input-grow' }, h('label', { htmlFor: 'seopc-network-target' }, 'Domain or URL'), h('input', { id: 'seopc-network-target', type: 'text', inputMode: 'url', autoCapitalize: 'none', autoCorrect: 'off', spellCheck: false, value: target, required: true, placeholder: 'example.com', onChange: function (e) { setTarget(e.target.value); } })),
                    h(AjaxFreshToggle, { checked: fresh, onChange: setFresh }),
                    h(Button, { type: 'submit', variant: 'primary', busy: busy, disabled: busy || !target.trim() }, busy ? 'Testing…' : 'Run network test')
                )
            ),
            error ? h(Notice, { type: 'error' }, error) : null,
            busy ? h('div', { className: 'seopc-loading-panel' }, h('span', { className: 'seopc-spinner seopc-spinner-large' }), h('p', null, 'Resolving DNS and checking connections…')) : null,
            result ? h('div', { className: 'seopc-results' },
                result.cached ? h(Notice, null, 'Network result loaded from the temporary cache.') : null,
                h(Notice, null, result.note),
                h(Section, { title: result.host },
                    h('div', { className: 'seopc-stat-grid' },
                        h(Stat, { label: 'DNS lookup', value: result.dns_latency_ms + ' ms' }),
                        h(Stat, { label: 'HTTP latency', value: result.http && result.http.latency_ms ? result.http.latency_ms + ' ms' : '—' }),
                        h(Stat, { label: 'HTTPS port', value: result.tcp && result.tcp['443'] && result.tcp['443'].open ? result.tcp['443'].latency_ms + ' ms' : 'Closed/unavailable' }),
                        h(Stat, { label: 'HTTP port', value: result.tcp && result.tcp['80'] && result.tcp['80'].open ? result.tcp['80'].latency_ms + ' ms' : 'Closed/unavailable' }),
                        h(Stat, { label: 'IP addresses', value: (result.ips || []).join(', ') }),
                        h(Stat, { label: 'CDN clue', value: result.http && result.http.cdn })
                    ),
                    h(KeyValueTable, { entries: [
                        ['HTTP status', result.http && result.http.status], ['Server', result.http && result.http.server], ['Powered by', result.http && result.http.powered_by],
                        ['Content type', result.http && result.http.content_type], ['Cache-Control', result.http && result.http.cache_control]
                    ] })
                ),
                h(Section, { title: 'Hosting and network clues' },
                    h(KeyValueTable, { entries: Object.entries(result.hosting || {}).map(function (entry) { return [entry[0], entry[1]]; }) }),
                    h('h4', null, 'Reverse DNS'),
                    h(KeyValueTable, { entries: Object.entries(result.reverse_dns || {}) })
                ),
                h(Section, { title: 'TLS certificate' },
                    result.tls && result.tls.available ? h(KeyValueTable, { entries: [
                        ['Subject', result.tls.subject], ['Issuer', result.tls.issuer], ['Valid from', result.tls.valid_from], ['Valid to', result.tls.valid_to], ['Days remaining', result.tls.days_remaining], ['Subject alternative names', result.tls.san]
                    ] }) : h(Notice, { type: 'warning' }, result.tls && result.tls.error ? result.tls.error : 'TLS details unavailable')
                ),
                h(Section, { title: 'DNS records' }, h(DnsRecords, { dns: result.dns }))
            ) : null
        );
    }

    function ImageOptimizer(props) {
        var fileState = useState(null);
        var file = fileState[0];
        var setFile = fileState[1];
        var previewState = useState('');
        var preview = previewState[0];
        var setPreview = previewState[1];
        var formatState = useState('image/avif');
        var format = formatState[0];
        var setFormat = formatState[1];
        var qualityState = useState(0.82);
        var quality = qualityState[0];
        var setQuality = qualityState[1];
        var widthState = useState(1920);
        var maxWidth = widthState[0];
        var setMaxWidth = widthState[1];
        var heightState = useState(1920);
        var maxHeight = heightState[0];
        var setMaxHeight = heightState[1];
        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];
        var resultState = useState(null);
        var result = resultState[0];
        var setResult = resultState[1];
        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        useEffect(function () {
            try {
                var probe = document.createElement('canvas'); probe.width = 1; probe.height = 1;
                if (probe.toDataURL('image/avif').indexOf('data:image/avif') !== 0) setFormat('image/webp');
            } catch (encoderError) { setFormat('image/webp'); }
        }, []);

        useEffect(function () {
            if (props.imported && props.imported.file) setFile(props.imported.file);
        }, [props.imported]);

        useEffect(function () {
            if (!file) { setPreview(''); return; }
            var url = URL.createObjectURL(file);
            setPreview(url); setResult(null); setError('');
            return function () { URL.revokeObjectURL(url); };
        }, [file]);

        async function optimize() {
            if (!file) return;
            setBusy(true); setError(''); setResult(null);
            try {
                var bitmap = await decodeImageFile(file);
                var sourceWidth = bitmap.width || bitmap.naturalWidth;
                var sourceHeight = bitmap.height || bitmap.naturalHeight;
                var scale = Math.min(1, Number(maxWidth || sourceWidth) / sourceWidth, Number(maxHeight || sourceHeight) / sourceHeight);
                var width = Math.max(1, Math.round(sourceWidth * scale));
                var height = Math.max(1, Math.round(sourceHeight * scale));
                var canvas = document.createElement('canvas');
                canvas.width = width; canvas.height = height;
                var context = canvas.getContext('2d', { alpha: format !== 'image/jpeg' });
                if (format === 'image/jpeg') { context.fillStyle = '#ffffff'; context.fillRect(0, 0, width, height); }
                context.drawImage(bitmap, 0, 0, width, height);
                if (bitmap.close) bitmap.close();

                var outputBlob;
                var outputType = format;
                var note = '';
                if (format === 'svg-wrapper') {
                    var pngData = canvas.toDataURL('image/png');
                    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '"><image width="100%" height="100%" href="' + pngData + '"/></svg>';
                    outputBlob = new Blob([svg], { type: 'image/svg+xml' });
                    outputType = 'image/svg+xml';
                    note = 'This preserves appearance inside SVG, but it is not true vector tracing.';
                } else {
                    outputBlob = await new Promise(function (resolve) { canvas.toBlob(resolve, format, Number(quality)); });
                    if (format === 'image/avif' && (!outputBlob || outputBlob.type !== 'image/avif')) {
                        outputType = 'image/webp';
                        outputBlob = await new Promise(function (resolve) { canvas.toBlob(resolve, 'image/webp', Number(quality)); });
                        note = 'This browser cannot encode AVIF from Canvas, so WebP was created instead.';
                    }
                    if (!outputBlob) throw new Error('This browser could not encode the selected format.');
                }
                var extMap = { 'image/avif': 'avif', 'image/webp': 'webp', 'image/jpeg': 'jpg', 'image/png': 'png', 'image/svg+xml': 'svg' };
                var base = file.name.replace(/\.[^.]+$/, '');
                var savedBytes = Number(file.size || 0) - Number(outputBlob.size || 0);
                var savedPct = file.size ? (savedBytes / Number(file.size)) * 100 : 0;
                setResult({
                    blob: outputBlob,
                    filename: base + '-optimized.' + (extMap[outputType] || 'img'),
                    width: width, height: height, type: outputType, note: note,
                    originalSize: Number(file.size || 0), savedBytes: savedBytes, savedPct: savedPct
                });
            } catch (err) { setError(err.message); }
            finally { setBusy(false); }
        }

        return h('div', null,
            h('div', { className: 'seopc-hero-panel' },
                h('div', null, h('span', { className: 'seopc-eyebrow' }, 'Private browser conversion'), h('h2', null, 'Image optimizer and format converter'), h('p', null, 'Resize and convert a local or searched image without saving it to the WordPress Media Library.')),
                h('label', { className: 'seopc-dropzone' },
                    h('input', { type: 'file', accept: 'image/*', onChange: function (e) { setFile(e.target.files && e.target.files[0] ? e.target.files[0] : null); } }),
                    h('strong', null, file ? file.name : 'Choose an image'),
                    h('span', null, file ? formatBytes(file.size) : 'PNG, JPG, WebP, AVIF, GIF first frame and other browser-readable formats')
                )
            ),
            error ? h(Notice, { type: 'error' }, error) : null,
            file ? h('div', { className: 'seopc-optimizer-layout' },
                h('div', { className: 'seopc-preview-card' }, preview ? h('img', { src: preview, alt: 'Selected image preview' }) : null),
                h('div', { className: 'seopc-controls-card' },
                    h('div', { className: 'seopc-field-grid' },
                        h('label', null, 'Output format', h('select', { value: format, onChange: function (e) { setFormat(e.target.value); } },
                            h('option', { value: 'image/avif' }, 'AVIF (when browser supports encoding)'), h('option', { value: 'image/webp' }, 'WebP'), h('option', { value: 'image/jpeg' }, 'JPG'), h('option', { value: 'image/png' }, 'PNG'), h('option', { value: 'svg-wrapper' }, 'SVG wrapper (not vector trace)')
                        )),
                        h(RangeField, { label: 'Quality', display: Math.round(quality * 100) + '%', min: '0.35', max: '1', step: '0.01', value: quality, onChange: function (e) { setQuality(e.target.value); } }),
                        h('label', null, 'Maximum width', h('input', { type: 'number', min: '1', max: '12000', value: maxWidth, onChange: function (e) { setMaxWidth(e.target.value); } })),
                        h('label', null, 'Maximum height', h('input', { type: 'number', min: '1', max: '12000', value: maxHeight, onChange: function (e) { setMaxHeight(e.target.value); } }))
                    ),
                    h(Button, { variant: 'primary', busy: busy, disabled: busy, onClick: optimize }, busy ? 'Optimizing…' : 'Optimize in browser'),
                    result ? h('div', { className: 'seopc-output-box seopc-optimizer-result' },
                        h('div', null, h('strong', null, result.filename), h('span', null, result.width + ' × ' + result.height + ' · ' + formatBytes(result.blob.size) + ' · ' + result.type)),
                        h('div',{className:'seopc-size-comparison'},
                            h('div',null,h('small',null,'Original'),h('strong',null,formatBytes(result.originalSize))),
                            h('div',null,h('small',null,'Optimized'),h('strong',null,formatBytes(result.blob.size))),
                            h('div',{className:result.savedBytes>=0?'is-saving':'is-larger'},h('small',null,result.savedBytes>=0?'Saved':'Difference'),h('strong',null,(result.savedBytes>=0?'-':'+')+formatBytes(Math.abs(result.savedBytes))),h('span',null,(result.savedPct>=0?Math.round(result.savedPct)+'% smaller':Math.abs(Math.round(result.savedPct))+'% larger')))
                        ),
                        result.savedBytes < 0 ? h(Notice,{type:'warning'},'The converted file is larger than the original. Try WebP/JPG, lower quality, or smaller dimensions for a real saving.') : null,
                        result.note ? h(Notice, { type: 'warning' }, result.note) : null,
                        h(Button, { variant: 'success', onClick: function () { downloadBlob(result.blob, result.filename); } }, 'Download optimized file')
                    ) : null
                )
            ) : null
        );
    }

    function VideoOptimizer(props) {
        var fileState = useState(null);
        var file = fileState[0];
        var setFile = fileState[1];
        var previewState = useState('');
        var preview = previewState[0];
        var setPreview = previewState[1];
        var widthState = useState(1280);
        var maxWidth = widthState[0];
        var setMaxWidth = widthState[1];
        var bitrateState = useState(1800);
        var bitrate = bitrateState[0];
        var setBitrate = bitrateState[1];
        var fpsState = useState(30);
        var fps = fpsState[0];
        var setFps = fpsState[1];
        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];
        var progressState = useState(0);
        var progress = progressState[0];
        var setProgress = progressState[1];
        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];
        var resultState = useState(null);
        var result = resultState[0];
        var setResult = resultState[1];
        var cancelRef = useRef(null);

        useEffect(function () { if (props.imported && props.imported.file) setFile(props.imported.file); }, [props.imported]);
        useEffect(function () {
            if (!file) { setPreview(''); return; }
            var url = URL.createObjectURL(file); setPreview(url); setResult(null); setError(''); setProgress(0);
            return function () { URL.revokeObjectURL(url); };
        }, [file]);

        async function compress() {
            if (!file) return;
            if (!window.MediaRecorder) { setError('MediaRecorder is not supported in this browser.'); return; }
            setBusy(true); setError(''); setResult(null); setProgress(0);
            var video = document.createElement('video');
            var sourceUrl = URL.createObjectURL(file);
            video.src = sourceUrl; video.preload = 'auto'; video.playsInline = true; video.crossOrigin = 'anonymous';
            try {
                await new Promise(function (resolve, reject) {
                    video.onloadedmetadata = resolve; video.onerror = function () { reject(new Error('The browser could not decode this video.')); };
                });
                var scale = Math.min(1, Number(maxWidth || video.videoWidth) / video.videoWidth);
                var width = Math.max(2, Math.round(video.videoWidth * scale / 2) * 2);
                var height = Math.max(2, Math.round(video.videoHeight * scale / 2) * 2);
                var canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
                var context = canvas.getContext('2d');
                if (!canvas.captureStream) throw new Error('Canvas video capture is not supported in this browser.');
                var canvasStream = canvas.captureStream(Number(fps));
                var mimeTypes = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
                var mime = mimeTypes.find(function (type) { return MediaRecorder.isTypeSupported(type); });
                if (!mime) throw new Error('This browser cannot encode WebM video.');

                var sourceStream = null;
                if (video.captureStream) sourceStream = video.captureStream();
                else if (video.mozCaptureStream) sourceStream = video.mozCaptureStream();
                if (sourceStream) sourceStream.getAudioTracks().forEach(function (track) { canvasStream.addTrack(track); });

                var recorder = new MediaRecorder(canvasStream, { mimeType: mime, videoBitsPerSecond: Number(bitrate) * 1000, audioBitsPerSecond: 128000 });
                var chunks = [];
                var cancelled = false;
                recorder.ondataavailable = function (event) { if (event.data && event.data.size) chunks.push(event.data); };
                var stopped = new Promise(function (resolve, reject) {
                    recorder.onstop = resolve; recorder.onerror = function (event) { reject(event.error || new Error('Video encoding failed.')); };
                });
                var finishPlayback;
                var playbackFinished = new Promise(function (resolve) {
                    finishPlayback = resolve;
                    video.onended = resolve;
                });
                cancelRef.current = function () {
                    cancelled = true;
                    try { video.pause(); } catch (e) {}
                    finishPlayback();
                };

                var lastProgressUpdate = 0;
                function draw() {
                    if (video.paused || video.ended) return;
                    context.drawImage(video, 0, 0, width, height);
                    var now = Date.now();
                    if (now - lastProgressUpdate > 250) {
                        lastProgressUpdate = now;
                        setProgress(video.duration ? Math.min(100, Math.round(video.currentTime / video.duration * 100)) : 0);
                    }
                    requestAnimationFrame(draw);
                }
                recorder.start(1000);
                await video.play();
                draw();
                await playbackFinished;
                if (recorder.state !== 'inactive') recorder.stop();
                await stopped;
                var blob = new Blob(chunks, { type: mime.split(';')[0] });
                if (!blob.size) throw new Error('No encoded video data was produced.');
                if (!cancelled) setProgress(100);
                setResult({
                    blob: blob,
                    filename: file.name.replace(/\.[^.]+$/, '') + (cancelled ? '-partial.webm' : '-optimized.webm'),
                    width: width,
                    height: height,
                    mime: mime,
                    note: cancelled ? 'Encoding was stopped early, so this is a partial video.' : ''
                });
            } catch (err) { setError(err.message); }
            finally {
                if (typeof canvasStream !== 'undefined' && canvasStream) canvasStream.getTracks().forEach(function (track) { track.stop(); });
                if (typeof sourceStream !== 'undefined' && sourceStream) sourceStream.getTracks().forEach(function (track) { track.stop(); });
                cancelRef.current = null;
                URL.revokeObjectURL(sourceUrl);
                setBusy(false);
            }
        }

        return h('div', null,
            h('div', { className: 'seopc-hero-panel' },
                h('div', null, h('span', { className: 'seopc-eyebrow' }, 'No server upload'), h('h2', null, 'Browser video compressor'), h('p', null, 'Resize and re-encode a video to WebM. Encoding plays through the video in real time and remains on this device.')),
                h('label', { className: 'seopc-dropzone' },
                    h('input', { type: 'file', accept: 'video/*', onChange: function (e) { setFile(e.target.files && e.target.files[0] ? e.target.files[0] : null); } }),
                    h('strong', null, file ? file.name : 'Choose a video'), h('span', null, file ? formatBytes(file.size) : 'MP4, WebM, MOV and other browser-readable video formats')
                )
            ),
            h(Notice, null, 'Output is WebM because browser-native MP4 encoding is not consistently available. Long videos take approximately their playback duration to process. Audio is preserved where the browser exposes an audio capture track.'),
            error ? h(Notice, { type: 'error' }, error) : null,
            file ? h('div', { className: 'seopc-optimizer-layout' },
                h('div', { className: 'seopc-preview-card' }, preview ? h('video', { src: preview, controls: true, preload: 'metadata' }) : null),
                h('div', { className: 'seopc-controls-card' },
                    h('div', { className: 'seopc-field-grid' },
                        h('label', null, 'Maximum width', h('select', { value: maxWidth, onChange: function (e) { setMaxWidth(e.target.value); } }, h('option', { value: '1920' }, '1920px'), h('option', { value: '1280' }, '1280px'), h('option', { value: '854' }, '854px'), h('option', { value: '640' }, '640px'))),
                        h('label', null, 'Video bitrate', h('select', { value: bitrate, onChange: function (e) { setBitrate(e.target.value); } }, h('option', { value: '4000' }, '4000 kbps'), h('option', { value: '2500' }, '2500 kbps'), h('option', { value: '1800' }, '1800 kbps'), h('option', { value: '1000' }, '1000 kbps'), h('option', { value: '600' }, '600 kbps'))),
                        h('label', null, 'Frame rate', h('select', { value: fps, onChange: function (e) { setFps(e.target.value); } }, h('option', { value: '30' }, '30 fps'), h('option', { value: '24' }, '24 fps'), h('option', { value: '15' }, '15 fps')))
                    ),
                    busy ? h('div', { className: 'seopc-progress' }, h('span', { style: { width: progress + '%' } }), h('strong', null, progress + '%')) : null,
                    h('div', { className: 'seopc-inline-actions' },
                        h(Button, { variant: 'primary', busy: busy, disabled: busy, onClick: compress }, busy ? 'Compressing…' : 'Compress video'),
                        busy ? h(Button, { variant: 'danger', onClick: function () { if (cancelRef.current) cancelRef.current(); } }, 'Stop') : null
                    ),
                    result ? h('div', { className: 'seopc-output-box' },
                        h('div', null, h('strong', null, result.filename), h('span', null, result.width + ' × ' + result.height + ' · ' + formatBytes(result.blob.size) + ' · ' + result.mime)),
                        result.note ? h(Notice, { type: 'warning' }, result.note) : null,
                        h(Button, { variant: 'success', onClick: function () { downloadBlob(result.blob, result.filename); } }, 'Download optimized video')
                    ) : null
                )
            ) : null
        );
    }

    function MediaCard(props) {
        var item = props.item;
        var previewFast = item.preview_fast_url || item.preview_url || '';
        var previewMain = item.preview_url || previewFast;
        var imageProps = {
            src: previewFast,
            alt: item.title || '',
            loading: props.index < 8 ? 'eager' : 'lazy',
            decoding: 'async',
            fetchPriority: props.index < 6 ? 'high' : 'auto'
        };
        if (previewFast && previewMain && previewFast !== previewMain) {
            imageProps.srcSet = previewFast + ' 320w, ' + previewMain + ' 720w';
            imageProps.sizes = '(max-width: 760px) 92vw, (max-width: 1200px) 44vw, 300px';
        }
        return h('article', { className: 'seopc-media-card' },
            h('div', { className: 'seopc-media-preview' },
                previewFast ? h('img', imageProps) : h('span', null, item.type === 'video' ? 'VIDEO' : 'IMAGE'),
                h('span', { className: 'seopc-provider-badge' }, item.provider),
                item.quality_score ? h('span',{className:'seopc-media-quality'},Math.round(item.quality_score)+' quality') : null
            ),
            h('div', { className: 'seopc-media-body' },
                h('h4', null, item.title || 'Untitled media'),
                h('p', null, item.creator ? 'By ' + item.creator + ' · ' + item.provider : 'Source: ' + item.provider),
                h('div', { className: 'seopc-media-meta' }, h('span', null, item.license || 'Check licence'), item.width && item.height ? h('span', null, item.width + ' × ' + item.height) : null),
                h('div', { className: 'seopc-media-actions' },
                    h(Button, { variant: 'primary', busy: props.busy === 'use', disabled: !!props.busy, onClick: function () { props.onUse(item); } }, 'Optimize'),
                    props.onEdit ? h(Button, { variant: 'success', busy: props.busy === 'edit', disabled: !!props.busy, onClick: function () { props.onEdit(item); } }, 'Edit') : null,
                    h(Button, { variant: 'light', busy: props.busy === 'download', disabled: !!props.busy, onClick: function () { props.onDownload(item); } }, 'Download'),
                    item.source_url ? h('a', { href: item.source_url, target: '_blank', rel: 'noopener noreferrer', className: 'seopc-text-link' }, 'Source') : null,
                    item.license_url ? h('a', { href: item.license_url, target: '_blank', rel: 'noopener noreferrer', className: 'seopc-text-link' }, 'Licence') : null
                )
            )
        );
    }

    function MediaSearchTab(props) {
        var queryState = useState(''); var query = queryState[0]; var setQuery = queryState[1];
        var typeState = useState('image'); var type = typeState[0]; var setType = typeState[1];
        var providerState = useState('all'); var provider = providerState[0]; var setProvider = providerState[1];
        var pageState = useState(1); var page = pageState[0]; var setPage = pageState[1];
        var busyState = useState(false); var busy = busyState[0]; var setBusy = busyState[1];
        var moreState = useState(false); var loadingMore = moreState[0]; var setLoadingMore = moreState[1];
        var hasMoreState = useState(false); var hasMore = hasMoreState[0]; var setHasMore = hasMoreState[1];
        var freshState = useState(false); var fresh = freshState[0]; var setFresh = freshState[1];
        var itemBusyState = useState({}); var itemBusy = itemBusyState[0]; var setItemBusy = itemBusyState[1];
        var errorState = useState(''); var error = errorState[0]; var setError = errorState[1];
        var resultState = useState(null); var result = resultState[0]; var setResult = resultState[1];

        var connected = config.mediaProviders || {};
        var providers = [
            {id:'all',label:'All sources',kind:'api',enabled:true},
            {id:'wpphotos',label:'WP Photos · CC0',kind:'web',enabled:true,priority:99},
            {id:'pexels',label:'Pexels',kind:'api',enabled:!!connected.pexels,priority:100},
            {id:'unsplash',label:'Unsplash',kind:'api',enabled:!!connected.unsplash,priority:98},
            {id:'pixabay',label:'Pixabay',kind:'api',enabled:!!connected.pixabay,priority:96},
            {id:'openverse',label:'Openverse',kind:'api',enabled:!!connected.openverse,priority:72},
            {id:'wikimedia',label:'Wikimedia',kind:'api',enabled:!!connected.wikimedia,priority:68},
            {id:'iconify',label:'Iconify',kind:'api',enabled:true,priority:90}
        ];
        var directory = [
            {name:'Pexels',type:'Photos + video',connected:!!connected.pexels,url:function(q){return 'https://www.pexels.com/search/'+encodeURIComponent(q)+'/';}},
            {name:'Pixabay',type:'Photos + illustrations + video',connected:!!connected.pixabay,url:function(q){return 'https://pixabay.com/images/search/'+encodeURIComponent(q)+'/';}},
            {name:'Unsplash',type:'High-quality photography',connected:!!connected.unsplash,url:function(q){return 'https://unsplash.com/s/photos/'+encodeURIComponent(q);}},
            {name:'Openverse',type:'Openly licensed media',connected:!!connected.openverse,url:function(q){return 'https://openverse.org/search/image?q='+encodeURIComponent(q);}},
            {name:'Wikimedia Commons',type:'Images + video + SVG',connected:!!connected.wikimedia,url:function(q){return 'https://commons.wikimedia.org/w/index.php?search='+encodeURIComponent(q)+'&title=Special:MediaSearch&type=image';}},
            {name:'Picjumbo',type:'Free stock photography',connected:false,url:function(q){return 'https://picjumbo.com/?s='+encodeURIComponent(q);}},
            {name:'Kaboompics',type:'Lifestyle + interior stock photos',connected:false,url:function(q){return 'https://kaboompics.com/gallery?search='+encodeURIComponent(q);}},
            {name:'Gratisography',type:'Creative high-resolution photos',connected:false,url:function(q){return 'https://gratisography.com/?s='+encodeURIComponent(q);}},
            {name:'Nappy',type:'Diverse people + lifestyle photos',connected:false,url:function(q){return 'https://nappy.co/?s='+encodeURIComponent(q);}},
            {name:'Foodiesfeed',type:'Food photography',connected:false,url:function(q){return 'https://www.foodiesfeed.com/?s='+encodeURIComponent(q);}},
            {name:'ISO Republic',type:'Stock photos + video',connected:false,url:function(q){return 'https://isorepublic.com/?s='+encodeURIComponent(q);}},
            {name:'Life of Pix',type:'Curated photography',connected:false,url:function(q){return 'https://www.lifeofpix.com/search/'+encodeURIComponent(q);}},
            {name:'Burst by Shopify',type:'Business + ecommerce photos',connected:false,url:function(q){return 'https://www.shopify.com/stock-photos/search?utf8=%E2%9C%93&q='+encodeURIComponent(q);}},
            {name:'StockSnap',type:'Free stock photos',connected:false,url:function(q){return 'https://stocksnap.io/search/'+encodeURIComponent(q);}},
            {name:'Reshot',type:'Photos + icons',connected:false,url:function(q){return 'https://www.reshot.com/search/'+encodeURIComponent(q);}},
            {name:'Mixkit',type:'Stock video + music',connected:false,url:function(q){return 'https://mixkit.co/free-stock-video/?q='+encodeURIComponent(q);}},
            {name:'Coverr',type:'Stock video',connected:false,url:function(q){return 'https://coverr.co/search?q='+encodeURIComponent(q);}},
            {name:'SVG Repo',type:'SVG icons + vectors',connected:false,url:function(q){return 'https://www.svgrepo.com/vectors/'+encodeURIComponent(q)+'/';}},
            {name:'unDraw',type:'Open-source illustrations',connected:false,url:function(q){return 'https://undraw.co/search?searchValue='+encodeURIComponent(q);}},
            {name:'Freepik',type:'Photos + vectors + PSD (free tier varies)',connected:false,url:function(q){return 'https://www.freepik.com/search?format=search&query='+encodeURIComponent(q);}},
            {name:'Rawpixel Public Domain',type:'Public-domain images + design assets',connected:false,url:function(q){return 'https://www.rawpixel.com/search/'+encodeURIComponent(q)+'?sort=curated&topic_group=_topics';}}
        ];

        function relevantProviderIds(forcedProvider) {
            var chosen = forcedProvider || provider;
            if (chosen !== 'all') return [chosen];
            var ids = providers.filter(function(p){
                if (p.id === 'all' || !p.enabled) return false;
                if (type === 'video') return p.id === 'pexels' || p.id === 'pixabay' || p.id === 'wikimedia';
                if (type === 'icon') return p.id === 'iconify';
                return p.id !== 'iconify';
            }).sort(function(a,b){ return Number(b.priority||0)-Number(a.priority||0); }).map(function(p){return p.id;});
            return ids.length ? ids : ['all'];
        }

        function mergeResults(old, data, reset) {
            var base = reset || !old ? {items:[],errors:[],notice:'',providers:{}} : old;
            var map = {};
            (base.items || []).forEach(function(item){ map[(item.provider||'')+'|'+(item.download_url||item.preview_url||'')] = item; });
            (data.items || []).forEach(function(item){ map[(item.provider||'')+'|'+(item.download_url||item.preview_url||'')] = item; });
            var items = Object.keys(map).map(function(k){return map[k];});
            items.sort(function(a,b){
                var score = Number(b.quality_score||0)-Number(a.quality_score||0);
                if (score) return score;
                return String(a.provider||'').localeCompare(String(b.provider||''));
            });
            var errors = (base.errors || []).concat(data.errors || []).filter(function(v,i,a){return v && a.indexOf(v)===i;});
            return Object.assign({}, base, data, {items:items,errors:errors,notice:data.notice||base.notice||'',loading:true});
        }

        async function search(event, requestedPage, append, forcedProvider) {
            if (event) event.preventDefault();
            if (query.trim().length < 2) return;
            var nextPage = requestedPage || (append ? page + 1 : 1);
            var providerKey = forcedProvider || provider;
            if (append) setLoadingMore(true); else { setBusy(true); setResult({items:[],errors:[],notice:'Loading the best previews from connected sources…',loading:true}); setPage(1); }
            setError('');
            var ids = relevantProviderIds(providerKey);
            var returned = 0;
            var tasks = ids.map(function(pid){
                return request('seopc_toolkit_search_media', { query: query.trim(), media_type: type, provider: pid, page: nextPage, fresh: fresh ? '1' : '0' }, props.token).then(function(data){
                    returned += (data.items || []).length;
                    setResult(function(old){ return mergeResults(old, data, !append && (!old || !(old.items||[]).length)); });
                    return data;
                }).catch(function(err){
                    if (err.status === 403) props.onExpired();
                    setResult(function(old){
                        var base = old || {items:[],errors:[]};
                        var errs = (base.errors || []).concat([(pid.charAt(0).toUpperCase()+pid.slice(1))+': '+err.message]);
                        return Object.assign({},base,{errors:errs.filter(function(v,i,a){return a.indexOf(v)===i;}),loading:true});
                    });
                    return null;
                });
            });
            await Promise.allSettled(tasks);
            setResult(function(old){ return old ? Object.assign({},old,{loading:false}) : old; });
            setPage(nextPage);
            setHasMore(returned > 0 && nextPage < 20);
            setBusy(false); setLoadingMore(false);
        }

        async function fetchItem(item, purpose) {
            var key = item.provider + '|' + item.download_url;
            setItemBusy(function (old) { var next = Object.assign({}, old); next[key] = purpose; return next; });
            try {
                var blob = null;
                if (!item.download_trigger_url) {
                    try {
                        var direct = await fetch(item.download_url, { mode: 'cors', credentials: 'omit' });
                        if (direct.ok) {
                            var contentLength = Number(direct.headers.get('content-length') || 0);
                            if (!contentLength || contentLength <= Number(config.maxMediaMb || 200) * 1024 * 1024) {
                                blob = await direct.blob();
                                if (blob.size > Number(config.maxMediaMb || 200) * 1024 * 1024) blob = null;
                            }
                        }
                    } catch (directError) { blob = null; }
                }
                if (!blob) {
                    blob = await request('seopc_toolkit_fetch_media', { url: item.download_url, download_trigger_url: item.download_trigger_url || '', media_sig: item.media_sig, media_exp: item.media_exp }, props.token, 'blob');
                }
                var name = filenameFromUrl(item.download_url, blob.type || item.mime, item.type);
                if (purpose === 'download') downloadBlob(blob, name);
                else if (purpose === 'edit') props.onEdit(item.type, new File([blob], name, { type: blob.type || item.mime || '' }), item);
                else props.onImport(item.type, new File([blob], name, { type: blob.type || item.mime || '' }), item);
            } catch (err) { setError(err.message); }
            finally { setItemBusy(function (old) { var next = Object.assign({}, old); delete next[key]; return next; }); }
        }

                return h('div', null,
            h('div', { className: 'seopc-hero-panel seopc-hero-media' },
                h('div', null, h('span', { className: 'seopc-eyebrow' }, 'No-key creative library'), h('h2', null, 'Free image, video & design search'), h('p', null, 'Search and download from no-key public sources directly. WordPress Photos adds moderated high-resolution CC0 photography; optional connected providers can add even more results.')),
                h('form', { className: 'seopc-url-form seopc-media-search-form', onSubmit: function(e){search(e,1,false);} },
                    h('div', { className: 'seopc-input-grow' }, h('label', { htmlFor: 'seopc-media-query' }, 'Search words'), h('input', { id: 'seopc-media-query', type: 'search', autoComplete: 'off', value: query, required: true, minLength: 2, placeholder: 'modern office workspace', onChange: function (e) { setQuery(e.target.value); } })),
                    h('div', null, h('label', { htmlFor: 'seopc-media-type' }, 'Media type'), h('select', { id: 'seopc-media-type', value: type, onChange: function (e) { var next=e.target.value; setType(next); setResult(null); setPage(1); setHasMore(false); if(next==='video' && (provider==='unsplash'||provider==='openverse'||provider==='iconify')) setProvider('all'); if(next==='icon' && provider!=='all'&&provider!=='iconify') setProvider('all'); } }, h('option', { value: 'image' }, 'Photos & images'), h('option', { value: 'video' }, 'Videos'), h('option', { value: 'icon' }, 'Icons / design assets'))),
                    h(AjaxFreshToggle, { checked: fresh, onChange: setFresh }),
                    h(Button, { type: 'submit', variant: 'primary', busy: busy, disabled: busy || query.trim().length < 2 }, busy ? 'Searching…' : 'Search media')
                )
            ),
            h('div', { className: 'seopc-provider-filter' }, providers.filter(function(p){if(p.id==='all')return true;if(!p.enabled)return false;if(type==='video')return p.id==='pexels'||p.id==='pixabay'||p.id==='wikimedia';if(type==='icon')return p.id==='iconify';return p.id!=='iconify';}).map(function(p){return h('button',{key:p.id,type:'button',className:provider===p.id?'is-active':'',onClick:function(){setProvider(p.id);setResult(null);setPage(1);setHasMore(false);if(query.trim().length>=2)search(null,1,false,p.id);}},h('span',{className:'seopc-provider-dot'}),p.label);})),
            h(Notice, { type: 'warning' }, 'Licences and API attribution rules differ. Confirm the source licence before commercial use; provider attribution is shown with connected results.'),
            error ? h(Notice, { type: 'error' }, error) : null,
            result ? h('div', { className: 'seopc-results' },
                result.notice ? h(Notice, null, result.notice) : null,
                (result.errors || []).length ? h(Notice, { type: 'warning' }, result.errors.join(' | ')) : null,
                h('div', { className: 'seopc-report-toolbar' }, h('div', null, h('strong', null, (result.items || []).length + ' quality-ranked results loaded'), h('span', null, provider === 'all' ? 'AJAX results from no-key and optional connected sources · strongest assets first' : provider)), result.loading ? h('span',{className:'seopc-media-live'},h('i',{className:'seopc-spinner'}),' Loading more sources…') : null),
                (result.items || []).length ? h('div', { className: 'seopc-media-grid' }, result.items.map(function (item, index) {
                    var key = item.provider + '|' + item.download_url;
                    return h(MediaCard, { key: key, index:index, item: item, busy: itemBusy[key] || '', onUse: function (media) { fetchItem(media, 'use'); }, onEdit: item.type === 'image' ? function (media) { fetchItem(media, 'edit'); } : null, onDownload: function (media) { fetchItem(media, 'download'); } });
                })) : (!result.loading ? h(Notice, { type: 'warning' }, 'No matching media was returned by the enabled providers.') : null),
                (result.items || []).length && hasMore ? h('div',{className:'seopc-load-more-wrap'},h(Button,{variant:'primary',busy:loadingMore,disabled:loadingMore||busy,onClick:function(){search(null,page+1,true);}},loadingMore?'Loading more…':'Load more results'),h('span',null,'Page '+page+' loaded · new results are appended without leaving the page')) : null
            ) : null,
            h(Section, { title: 'More free media sources', subtitle: 'More popular free libraries are grouped here. Providers with APIs stay searchable inside the toolkit; the rest open their native search with the same query because they do not expose a suitable public search API.' },
                h('div', { className: 'seopc-source-directory' }, directory.sort(function(a,b){return Number(b.connected)-Number(a.connected);}).map(function(source){
                    var href=source.url(query.trim() || 'business');
                    return h('a',{key:source.name,href:href,target:'_blank',rel:'noopener noreferrer',className:'seopc-source-card'},
                        h('span',{className:classNames('seopc-source-status',source.connected&&'is-connected')},source.connected?'Connected API':'Open search'),
                        h('strong',null,source.name),h('small',null,source.type),h('b',null,'↗'));
                }))
            )
        );
    }

    function buildSitemapHierarchy(urls, origin) {
        var root = { key: '/', label: '/', path: '/', url: origin || '', item: null, children: [] };
        var lookup = {'/': root};
        (urls || []).forEach(function(item){
            var path = item.path || '/';
            if (!path || path === '/') { root.item = item; root.url = item.url; return; }
            var parts = path.split('/').filter(Boolean);
            var current = root, built = '';
            parts.forEach(function(part, index){
                built += '/' + part;
                var key = built + (index === parts.length - 1 && /\/$/.test(path) ? '/' : '');
                var lookupKey = built;
                if (!lookup[lookupKey]) {
                    var node = { key: lookupKey, label: decodeURIComponent(part), path: built + '/', url: '', item: null, children: [] };
                    lookup[lookupKey] = node; current.children.push(node);
                }
                current = lookup[lookupKey];
                if (index === parts.length - 1) { current.item = item; current.url = item.url; current.path = path; }
            });
        });
        function sortNode(node){ node.children.sort(function(a,b){ return a.label.localeCompare(b.label); }); node.children.forEach(sortNode); }
        sortNode(root); return root;
    }

    function SitemapTreeNode(props) {
        var node = props.node, depth = props.depth || 0;
        var openState = useState(depth < 2); var open = openState[0]; var setOpen = openState[1];
        var scan = node.item && props.scans[node.item.url];
        var issues = scan && scan.seo ? (scan.seo.issues || []) : [];
        var canScan = !!node.item;
        return h('div', { className: 'seopc-map-branch', style: { '--seopc-depth': depth } },
            h('article', { className: classNames('seopc-page-node', scan && 'is-scanned', scan && scan.error && 'has-error') },
                h('div', { className: 'seopc-page-node-top' },
                    node.children.length ? h('button', { type: 'button', className: 'seopc-tree-toggle', onClick: function(){setOpen(!open);}, 'aria-expanded': open }, open ? '−' : '+') : h('span',{className:'seopc-tree-toggle is-empty'},'•'),
                    h('div', { className: 'seopc-page-node-title' }, h('strong', null, node.label), h('small', null, node.path)),
                    h('div', { className: 'seopc-page-node-badges' },
                        node.children.length ? h('span', { className: 'seopc-node-pill' }, node.children.length + ' child' + (node.children.length === 1 ? '' : 'ren')) : null,
                        scan && scan.scores ? h('span', { className: 'seopc-mini-score is-' + scoreTone(scan.scores.seo) }, Math.round(scan.scores.seo) + ' SEO') : null,
                        scan && scan.response ? h('span', { className: 'seopc-node-pill' }, 'HTTP ' + scan.response.status) : null
                    )
                ),
                node.item ? h('div', { className: 'seopc-page-node-meta' },
                    node.item.lastmod ? h('span', null, 'Updated ' + node.item.lastmod) : h('span', null, 'No lastmod'),
                    h('span', null, node.item.group ? 'Sitemap: ' + filenameFromUrl(node.item.group, '', 'sitemap') : ''),
                    h('div', { className: 'seopc-page-node-actions' },
                        h(Button, { variant: scan ? 'light' : 'primary', busy: props.scanning === node.item.url, disabled: !!props.scanning, onClick: function(){props.onScan(node.item);} }, scan ? 'Rescan page' : 'Scan page'),
                        h('a', { href: node.item.url, target: '_blank', rel: 'noopener noreferrer', className: 'seopc-text-link' }, 'Open')
                    )
                ) : null,
                scan ? h('div', { className: 'seopc-node-audit' },
                    scan.error ? h(Notice,{type:'error'},scan.error) : issues.length ? h('div',null,h('strong',null,issues.length+' issue'+(issues.length===1?'':'s')),h('ul',null,issues.slice(0,4).map(function(issue,i){return h('li',{key:i},issue);})),issues.length>4?h('small',null,'+'+(issues.length-4)+' more issues'):null) : h('span',{className:'seopc-node-clean'},'✓ No major SEO issues detected')
                ) : canScan ? h('div',{className:'seopc-node-unscanned'},'Not scanned yet') : null
            ),
            open && node.children.length ? h('div', { className: 'seopc-map-children' }, node.children.map(function(child){ return h(SitemapTreeNode,{key:child.key,node:child,depth:depth+1,scans:props.scans,scanning:props.scanning,onScan:props.onScan}); })) : null
        );
    }

    function SitemapTab(props) {
        var urlState = useState(lastSiteUrl()); var url = urlState[0]; var setUrl = urlState[1];
        var limitState = useState(250); var limit = limitState[0]; var setLimit = limitState[1];
        var resultState = useState(null); var result = resultState[0]; var setResult = resultState[1];
        var busyState = useState(false); var busy = busyState[0]; var setBusy = busyState[1];
        var scanState = useState({}); var scans = scanState[0]; var setScans = scanState[1];
        var scanBusyState = useState(false); var scanBusy = scanBusyState[0]; var setScanBusy = scanBusyState[1];
        var progressState = useState({done:0,total:0}); var scanProgress=progressState[0]; var setScanProgress=progressState[1];
        var scanningState = useState(''); var scanning = scanningState[0]; var setScanning = scanningState[1];
        var errorState = useState(''); var error = errorState[0]; var setError = errorState[1];
        var freshState = useState(false); var fresh = freshState[0]; var setFresh = freshState[1];

        async function load(event) {
            if (event) event.preventDefault(); setBusy(true); setError(''); setResult(null); setScans({}); setScanProgress({done:0,total:0}); rememberSiteUrl(url.trim());
            try { setResult(await request('seopc_toolkit_sitemap', { url: url.trim(), limit: limit, fresh: fresh ? '1' : '0' }, props.token)); }
            catch (err) { setError(err.message); if (err.status === 403) props.onExpired(); }
            finally { setBusy(false); }
        }
        async function scanPage(item) {
            if (!item || !item.url) return; setScanning(item.url); setError('');
            try { var data=await request('seopc_toolkit_analyze',{url:item.url,fresh:fresh?'1':'0'},props.token); setScans(function(old){var n=Object.assign({},old);n[item.url]=data;return n;}); }
            catch(err){setScans(function(old){var n=Object.assign({},old);n[item.url]={error:err.message};return n;});if(err.status===403)props.onExpired();}
            finally{setScanning('');}
        }
        async function quickScan() {
            if (!result || !(result.urls || []).length) return;
            setScanBusy(true); setError(''); var targets=result.urls.slice(0,25), next=Object.assign({},scans); setScanProgress({done:0,total:targets.length});
            for (var i=0;i<targets.length;i+=3) {
                var batch=targets.slice(i,i+3); var settled=await Promise.allSettled(batch.map(function(item){return request('seopc_toolkit_analyze',{url:item.url,fresh:fresh?'1':'0'},props.token);}));
                settled.forEach(function(item,idx){var target=batch[idx];next[target.url]=item.status==='fulfilled'?item.value:{error:item.reason.message};}); setScans(Object.assign({},next)); setScanProgress({done:Math.min(targets.length,i+batch.length),total:targets.length});
            }
            setScanBusy(false);
        }
        function exportSitemapCsv() {
            if (!result) return; var rows=[['URL','Path','Last modified','Sitemap','SEO score','HTTP','Title','H1','Issues']];
            (result.urls||[]).forEach(function(item){var scan=scans[item.url]||{},seo=scan.seo||{};rows.push([item.url,item.path||'',item.lastmod||'',item.group||'',scan.scores&&scan.scores.seo||'',scan.response&&scan.response.status||'',seo.title||'',seo.heading_counts&&seo.heading_counts.h1||'',seo.issues&&(seo.issues||[]).join(' | ')||(scan.error||'')]);});
            downloadText('\ufeff'+rows.map(function(row){return row.map(csvCell).join(',');}).join('\r\n'),'sitemap-audit.csv','text/csv;charset=utf-8');
        }
        var hierarchy=result?buildSitemapHierarchy(result.urls||[],result.origin||''):null;
        var analytics=result&&result.analytics||{}, pathTypes=Object.entries(analytics.path_types||{}).slice(0,8), freshness=analytics.freshness||{};
        var scanned=Object.keys(scans).length, issuePages=Object.values(scans).filter(function(x){return x&&x.seo&&(x.seo.issues||[]).length;}).length, strongPages=Object.values(scans).filter(function(x){return x&&x.scores&&x.scores.seo>=90;}).length;
        return h('div', null,
            h('div',{className:'seopc-hero-panel seopc-hero-green'},
                h('div',null,h('span',{className:'seopc-eyebrow'},'Visual website architecture'),h('h2',null,'Sitemap intelligence & page relationships'),h('p',null,'Discover every sitemap source, map URL families, measure freshness and scan page-level SEO directly inside the architecture view.')),
                h('form',{className:'seopc-url-form',onSubmit:load},h('div',{className:'seopc-input-grow'},h('label',null,'Website URL'),h('input',{type:'url',inputMode:'url',value:url,required:true,placeholder:'https://example.com',onChange:function(e){setUrl(e.target.value);}})),h('label',null,'Map size',h('select',{value:limit,onChange:function(e){setLimit(Number(e.target.value));}},h('option',{value:100},'100 URLs'),h('option',{value:250},'250 URLs'),h('option',{value:500},'500 URLs'),h('option',{value:1000},'1,000 URLs'))),h(AjaxFreshToggle,{checked:fresh,onChange:setFresh}),h(Button,{type:'submit',variant:'primary',busy:busy,disabled:busy||!url.trim()},busy?'Discovering…':'Map website'))
            ),
            error?h(Notice,{type:'error'},error):null,
            busy?h('div',{className:'seopc-loading-panel'},h('span',{className:'seopc-spinner seopc-spinner-large'}),h('p',null,'Following robots.txt, sitemap indexes and page sitemaps with retry protection…')):null,
            result?h('div',{className:'seopc-results'},
                result.cached?h(Notice,null,'Sitemap loaded from temporary cache. Enable Fresh to bypass it.'):null,
                (result.errors||[]).length?h(Notice,{type:'warning'},h('strong',null,(result.errors||[]).length+' sitemap fetch warning'+((result.errors||[]).length===1?'':'s')),h('details',null,h('summary',null,'Show technical details'),h('div',{className:'seopc-error-detail'},(result.errors||[]).map(function(msg,i){return h('p',{key:i},msg);})))):null,
                h('div',{className:'seopc-report-toolbar'},h('div',null,h('strong',null,(result.url_count||0)+' URLs discovered'),h('span',null,(result.sitemaps||[]).length+' sitemap files'+(result.truncated?' · map limit reached':''))),h('div',{className:'seopc-inline-actions'},h(Button,{variant:'primary',busy:scanBusy,disabled:scanBusy||!(result.urls||[]).length,onClick:quickScan},scanBusy?'Scanning '+scanProgress.done+'/'+scanProgress.total:'Scan first 25'),h(Button,{variant:'light',onClick:exportSitemapCsv},'Export CSV'))),
                scanBusy?h('div',{className:'seopc-scan-progress'},h('span',{style:{width:(scanProgress.total?scanProgress.done/scanProgress.total*100:0)+'%'}}),h('b',null,scanProgress.done+' / '+scanProgress.total+' pages')):null,
                h('div',{className:'seopc-sitemap-overview'},
                    h('div',{className:'seopc-sitemap-kpi'},h('small',null,'Discovered'),h('strong',null,result.url_count||0),h('span',null,'URLs')),
                    h('div',{className:'seopc-sitemap-kpi'},h('small',null,'Sitemaps'),h('strong',null,(result.sitemaps||[]).length),h('span',null,(analytics.failed_sitemaps||0)+' fetch issues')),
                    h('div',{className:'seopc-sitemap-kpi'},h('small',null,'Fresh ≤90d'),h('strong',null,Number(freshness['30d']||0)+Number(freshness['90d']||0)),h('span',null,'recently updated')),
                    h('div',{className:'seopc-sitemap-kpi'},h('small',null,'Audit coverage'),h('strong',null,scanned),h('span',null,issuePages+' with issues'))
                ),
                h('div',{className:'seopc-sitemap-analytics'},
                    h(Section,{title:'URL family distribution',subtitle:'Top first-level path families help SEO and dev teams spot content silos and oversized sections.'},pathTypes.length?h('div',{className:'seopc-family-bars'},pathTypes.map(function(entry){var max=Math.max.apply(null,pathTypes.map(function(x){return x[1];}))||1;return h('div',{key:entry[0]},h('span',null,'/'+entry[0]),h('i',null,h('b',{style:{width:(entry[1]/max*100)+'%'}})),h('strong',null,entry[1]));})):h(Notice,null,'No path families available.')),
                    h(Section,{title:'Sitemap health',subtitle:'Response time and status for each discovered XML source.'},h('div',{className:'seopc-sitemap-health-list'},(result.sitemaps||[]).map(function(map){return h('div',{key:map.url,className:map.error?'has-error':''},h('span',{className:'seopc-health-dot'}),h('div',null,h('strong',null,filenameFromUrl(map.url,'','sitemap.xml')),h('small',null,map.type+' · '+(map.count||0)+' URLs')),h('b',null,map.status?('HTTP '+map.status):'Failed'),h('em',null,(map.elapsed_ms||0)+' ms'));})))
                ),
                h(Section,{title:'Interactive page relationship map',subtitle:'URL-path relationships are shown as expandable parent/child boxes. Scanned pages surface HTTP, SEO score and priority issues in place.'},hierarchy?h('div',{className:'seopc-url-map'},h(SitemapTreeNode,{node:hierarchy,depth:0,scans:scans,scanning:scanning,onScan:scanPage})):null),
                h(Section,{title:'Audit coverage',subtitle:'Use this as a crawl triage board before a full crawler run.'},h('div',{className:'seopc-kpi-row'},h('div',null,h('strong',null,scanned),h('span',null,'Pages scanned')),h('div',null,h('strong',null,issuePages),h('span',null,'Pages with issues')),h('div',null,h('strong',null,strongPages),h('span',null,'Strong SEO pages'))))
            ):null
        );
    }

    function SocialTab(props) {
        var urlState=useState(lastSiteUrl());var url=urlState[0];var setUrl=urlState[1];
        var dataState=useState(null);var data=dataState[0];var setData=dataState[1];
        var profileState=useState(null);var profileAudit=profileState[0];var setProfileAudit=profileState[1];
        var busyState=useState(false);var busy=busyState[0];var setBusy=busyState[1];
        var errorState=useState('');var error=errorState[0];var setError=errorState[1];
        var freshState=useState(false);var fresh=freshState[0];var setFresh=freshState[1];
        var allPlatforms=['instagram','facebook','tiktok','linkedin','youtube','x','pinterest','threads'];
        async function scanProfiles(profiles){setProfileAudit(null);if(!profiles||!Object.keys(profiles).length)return;try{setProfileAudit(await request('seopc_toolkit_social_profiles',{profiles:JSON.stringify(profiles)},props.token));}catch(err){if(err.status===403)props.onExpired();}}
        async function run(event){event.preventDefault();setBusy(true);setError('');setData(null);setProfileAudit(null);rememberSiteUrl(url.trim());try{var next=await request('seopc_toolkit_analyze',{url:url.trim(),fresh:fresh?'1':'0'},props.token);setData(next);var social=next&&next.seo&&next.seo.social;if(social)scanProfiles(social.profiles||{});}catch(err){setError(err.message);if(err.status===403)props.onExpired();}finally{setBusy(false);}}
        var social=data&&data.seo&&data.seo.social;var profiles=social?Object.entries(social.profiles||{}):[];var sameAs=social?(social.same_as||[]):[];
        var schemaCoverage=profiles.length?Math.round((profiles.length-(social.linked_not_in_schema||[]).length)/profiles.length*100):0;
        var metaFields=social?[social.open_graph&&social.open_graph.title,social.open_graph&&social.open_graph.description,social.open_graph&&social.open_graph.image,social.open_graph&&social.open_graph.site_name,social.twitter&&social.twitter.card,social.twitter&&social.twitter.image]:[];
        var metaScore=metaFields.length?Math.round(metaFields.filter(Boolean).length/metaFields.length*100):0;
        var linkedMap={};profiles.forEach(function(e){linkedMap[e[0]]=e[1];});var audited={};(profileAudit&&profileAudit.items||[]).forEach(function(item){audited[item.platform]=item;});
        var missingPlatforms=allPlatforms.filter(function(p){return !linkedMap[p];});
        var profileScore=profileAudit&&profileAudit.average_profile_score;
        return h('div',null,
            h('div',{className:'seopc-hero-panel seopc-hero-social'},h('div',null,h('span',{className:'seopc-eyebrow'},'Brand footprint audit'),h('h2',null,'Social presence, entity consistency & share quality'),h('p',null,'Audit linked profiles, public metadata, structured-data identity, brand handles and share creatives without pretending blocked social networks are failures.')),h('form',{className:'seopc-url-form',onSubmit:run},h('div',{className:'seopc-input-grow'},h('label',null,'Company website'),h('input',{type:'url',inputMode:'url',value:url,required:true,placeholder:'https://example.com',onChange:function(e){setUrl(e.target.value);}})),h(AjaxFreshToggle,{checked:fresh,onChange:setFresh}),h(Button,{type:'submit',variant:'primary',busy:busy,disabled:busy||!url.trim()},busy?'Auditing…':'Audit brand'))),
            error?h(Notice,{type:'error'},error):null,
            data&&social?h('div',{className:'seopc-results'},
                h('div',{className:'seopc-score-grid seopc-score-grid-compact'},h(ScoreCard,{score:social.score,label:'Social readiness'}),h(ScoreCard,{score:Math.min(100,profiles.length/6*100),label:'Profile coverage',note:profiles.length+' linked profiles'}),h(ScoreCard,{score:schemaCoverage,label:'Schema identity',note:sameAs.length+' sameAs URLs'}),h(ScoreCard,{score:metaScore,label:'Share metadata'}),profileScore!==null&&profileScore!==undefined?h(ScoreCard,{score:profileScore,label:'Profile quality',note:'Only verifiable profiles'}):null),
                h(Section,{title:'Brand footprint matrix',subtitle:'Presence is based on links from the company website. Missing networks are opportunities to review, not automatic errors.'},h('div',{className:'seopc-social-footprint'},allPlatforms.map(function(platform){var link=linkedMap[platform],audit=audited[platform];return h('div',{key:platform,className:classNames('seopc-footprint-item',link&&'is-present',audit&&audit.state&&'is-'+audit.state)},h('span',{className:'seopc-social-icon'},platform.slice(0,1).toUpperCase()),h('div',null,h('strong',null,platform==='x'?'X / Twitter':platform),h('small',null,link?(audit?(audit.blocked?'Linked · verification blocked':('Linked · HTTP '+audit.status)):'Linked · checking profile'):'Not linked from website')),link?h('a',{href:link,target:'_blank',rel:'noopener noreferrer'},'Open'):h('span',{className:'seopc-muted-pill'},'Review'));}))),
                profiles.length?h(Section,{title:'Profile diagnostics',subtitle:'Where public profile HTML is available, the toolkit reviews reachability, metadata, imagery and handle quality.'},h('div',{className:'seopc-social-profile-audits'},profiles.map(function(entry){var audit=audited[entry[0]];return h('article',{key:entry[0],className:'seopc-social-profile-detail'},h('div',{className:'seopc-social-profile-title'},h('span',{className:'seopc-social-icon'},entry[0].slice(0,1).toUpperCase()),h('div',null,h('strong',null,entry[0]),h('small',null,audit&&audit.handle?('@'+audit.handle):entry[1])),audit&&audit.quality_score!==null&&audit.quality_score!==undefined?h('b',{className:'seopc-mini-score is-'+scoreTone(audit.quality_score)},audit.quality_score+'/100'):h('b',{className:'seopc-status-chip is-neutral'},audit?'Unverified':'Checking')),audit?h('div',{className:'seopc-profile-detail-body'},h('p',null,audit.message),audit.checks&&audit.checks.length?h('div',{className:'seopc-profile-checks'},audit.checks.map(function(check,i){return h('span',{key:i,className:check.pass?'is-good':'is-warn'},check.pass?'✓ ':'! ',check.label);})):null,(audit.title||audit.description)?h('div',{className:'seopc-profile-meta'},audit.title?h('div',null,h('small',null,'Public title'),h('strong',null,audit.title)):null,audit.description?h('div',null,h('small',null,'Public description'),h('p',null,audit.description)):null):null):h('p',null,'Profile check in progress…'));}))) : h(Notice,{type:'warning'},'No major social profile links were detected in the initial HTML.'),
                h('div',{className:'seopc-two-col'},
                    h(Section,{title:'Sharing preview'},h('div',{className:'seopc-share-preview'},social.open_graph&&social.open_graph.image?h('img',{src:social.open_graph.image,alt:'',loading:'lazy'}):h('div',{className:'seopc-share-preview-empty'},'No OG image'),h('div',null,h('small',null,social.open_graph&&social.open_graph.site_name||new URL(data.url).hostname),h('strong',null,social.open_graph&&social.open_graph.title||data.seo.title||'No share title'),h('p',null,social.open_graph&&social.open_graph.description||data.seo.description||'No social description'))),h('div',{className:'seopc-share-checks'},[['OG title',social.open_graph&&social.open_graph.title],['OG description',social.open_graph&&social.open_graph.description],['OG image',social.open_graph&&social.open_graph.image],['OG site name',social.open_graph&&social.open_graph.site_name],['X card',social.twitter&&social.twitter.card],['X image',social.twitter&&social.twitter.image||social.open_graph&&social.open_graph.image]].map(function(row){return h('span',{key:row[0],className:row[1]?'is-good':'is-warn'},row[1]?'✓ ':'! ',row[0]);}))),
                    h(Section,{title:'Entity & handle consistency',subtitle:'Compare linked profiles with Organization sameAs and visible profile handles.'},h(KeyValueTable,{entries:[['Linked profiles',profiles.length],['sameAs URLs',sameAs.length],['Profiles missing from sameAs',(social.linked_not_in_schema||[]).join(', ')||'None'],['Unlinked major networks',missingPlatforms.join(', ')||'None'],['OG site name',social.open_graph&&social.open_graph.site_name],['X/Twitter site',social.twitter&&social.twitter.site]]}),h('div',{className:'seopc-handle-cloud'},profiles.map(function(entry){var a=audited[entry[0]];return h('span',{key:entry[0]},entry[0]+': '+(a&&a.handle?('@'+a.handle):'linked'));})))
                ),
                h(Section,{title:'Recommendations to improve brand visibility'},(social.recommendations||[]).concat(profileAudit&&profileAudit.recommendations||[]).length?h('ol',{className:'seopc-issue-list'},(social.recommendations||[]).concat(profileAudit&&profileAudit.recommendations||[]).map(function(item,i){return h('li',{key:i},h('span',null,'↗'),h('div',null,item));})):h(Notice,{type:'success'},'The main social sharing and identity signals look complete.'),h('div',{className:'seopc-practice-grid'},h('div',null,h('strong',null,'Profile consistency'),h('p',null,'Use the same brand name, logo/avatar, website URL and concise description across active channels.')),h('div',null,h('strong',null,'Entity markup'),h('p',null,'Keep Organization sameAs aligned with profiles users can actually reach from the website.')),h('div',null,h('strong',null,'Share creative'),h('p',null,'Use a stable HTTPS Open Graph image with a clear focal point and test after major design changes.'))))
            ):null
        );
    }

    function DesignQATab(props) {
        var urlState=useState(lastSiteUrl());var url=urlState[0];var setUrl=urlState[1];
        var viewportState=useState(1024);var viewport=viewportState[0];var setViewport=viewportState[1];
        var gridState=useState(true);var grid=gridState[0];var setGrid=gridState[1];
        var freshState=useState(false);var fresh=freshState[0];var setFresh=freshState[1];
        var busyState=useState(false);var busy=busyState[0];var setBusy=busyState[1];
        var auditState=useState(null);var audit=auditState[0];var setAudit=auditState[1];
        var previewState=useState(null);var preview=previewState[0];var setPreview=previewState[1];
        var previewKeyState=useState(0);var previewKey=previewKeyState[0];var setPreviewKey=previewKeyState[1];
        var previewBusyState=useState(false);var previewBusy=previewBusyState[0];var setPreviewBusy=previewBusyState[1];
        var errorState=useState('');var error=errorState[0];var setError=errorState[1];
        var fileState=useState(null);var file=fileState[0];var setFile=fileState[1];
        var imageState=useState(null);var imageObj=imageState[0];var setImageObj=imageState[1];
        var measureState=useState(null);var measure=measureState[0];var setMeasure=measureState[1];
        var canvasRef=useRef(null);var dragRef=useRef(null);var scaleRef=useRef(1);
        useEffect(function(){if(!file){setImageObj(null);return;}var reader=new FileReader();reader.onload=function(){var img=new Image();img.onload=function(){setImageObj(img);};img.src=reader.result;};reader.readAsDataURL(file);},[file]);
        useEffect(function(){var canvas=canvasRef.current;if(!canvas||!imageObj)return;var max=1100,scale=Math.min(1,max/imageObj.width);scaleRef.current=scale;canvas.width=Math.round(imageObj.width*scale);canvas.height=Math.round(imageObj.height*scale);var ctx=canvas.getContext('2d');ctx.clearRect(0,0,canvas.width,canvas.height);ctx.drawImage(imageObj,0,0,canvas.width,canvas.height);if(grid){ctx.save();ctx.strokeStyle='rgba(16,185,129,.24)';ctx.lineWidth=1;var step=Math.max(4,8*scale);for(var x=0;x<canvas.width;x+=step){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,canvas.height);ctx.stroke();}for(var y=0;y<canvas.height;y+=step){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(canvas.width,y);ctx.stroke();}ctx.restore();}if(measure){ctx.save();ctx.strokeStyle='#6d4bc3';ctx.lineWidth=2;ctx.setLineDash([6,4]);ctx.strokeRect(measure.x,measure.y,measure.w,measure.h);ctx.fillStyle='#102a23';ctx.font='600 12px system-ui';ctx.fillText(Math.round(measure.w/scale)+' × '+Math.round(measure.h/scale)+' px',measure.x+6,Math.max(14,measure.y-7));ctx.restore();}},[imageObj,grid,measure]);
        async function run(event){event.preventDefault();setBusy(true);setError('');setAudit(null);setPreview(null);rememberSiteUrl(url.trim());try{var settled=await Promise.allSettled([request('seopc_toolkit_analyze',{url:url.trim(),fresh:fresh?'1':'0'},props.token),request('seopc_toolkit_design_preview',{url:url.trim(),fresh:fresh?'1':'0'},props.token)]);if(settled[0].status==='fulfilled')setAudit(settled[0].value);else throw settled[0].reason;if(settled[1].status==='fulfilled')setPreview(settled[1].value);else setError('Design checks completed, but preview snapshot could not load: '+settled[1].reason.message);}catch(err){setError(err.message);if(err.status===403)props.onExpired();}finally{setBusy(false);}}
        async function refreshSnapshot(){if(!url.trim())return;setPreviewBusy(true);setError('');try{var data=await request('seopc_toolkit_design_preview',{url:url.trim(),fresh:'1'},props.token);setPreview(data);setPreviewKey(function(v){return v+1;});}catch(err){setError('Preview refresh failed: '+err.message);if(err.status===403)props.onExpired();}finally{setPreviewBusy(false);}}
        function pos(e){var c=canvasRef.current,r=c.getBoundingClientRect();return{x:(e.clientX-r.left)*(c.width/r.width),y:(e.clientY-r.top)*(c.height/r.height)};}function down(e){if(!imageObj)return;dragRef.current=pos(e);setMeasure(null);}function move(e){if(!dragRef.current)return;var a=dragRef.current,b=pos(e);setMeasure({x:Math.min(a.x,b.x),y:Math.min(a.y,b.y),w:Math.abs(b.x-a.x),h:Math.abs(b.y-a.y)});}function up(){dragRef.current=null;}
        var design=audit&&audit.design,safeUrl=audit&&audit.url,originalScale=scaleRef.current||1;
        return h('div',null,
            h('div',{className:'seopc-hero-panel seopc-hero-design'},h('div',null,h('span',{className:'seopc-eyebrow'},'Design QA'),h('h2',null,'Responsive QA with a reliable sandbox preview'),h('p',null,'Use a safe server-HTML snapshot for responsive review, then combine automated layout-risk checks with a pixel-accurate screenshot ruler.')),h('form',{className:'seopc-url-form',onSubmit:run},h('div',{className:'seopc-input-grow'},h('label',null,'Website URL'),h('input',{type:'url',value:url,required:true,placeholder:'https://example.com',onChange:function(e){setUrl(e.target.value);}})),h('label',null,'Viewport',h('select',{value:viewport,onChange:function(e){setViewport(Number(e.target.value));}},h('option',{value:375},'375 mobile'),h('option',{value:768},'768 tablet'),h('option',{value:1024},'1024 laptop'),h('option',{value:1440},'1440 desktop'),h('option',{value:1920},'1920 wide'))),h(AjaxFreshToggle,{checked:fresh,onChange:setFresh}),h(Button,{type:'submit',variant:'primary',busy:busy,disabled:busy||!url.trim()},busy?'Building preview…':'Run design QA'))),
            error?h(Notice,{type:error.indexOf('completed')>=0?'warning':'error'},error):null,
            audit&&design?h('div',{className:'seopc-results'},
                h('div',{className:'seopc-design-scorebar'},h(ScoreCard,{score:design.score,label:'Design QA'}),h('div',{className:'seopc-design-kpis'},h('div',null,h('strong',null,design.fixed_width_count||0),h('span',null,'Fixed-width risks')),h('div',null,h('strong',null,design.missing_image_dimensions||0),h('span',null,'Images missing dimensions')),h('div',null,h('strong',null,design.unlabelled_controls||0),h('span',null,'Unlabelled controls')),h('div',null,h('strong',null,design.tiny_text_count||0),h('span',null,'Tiny inline text')))),
                h(Section,{title:'Sandbox breakpoint preview',subtitle:preview?preview.note:'Preparing a script-free server-HTML snapshot so X-Frame-Options and CSP cannot produce a broken grey frame.'},
                    h('div',{className:'seopc-preview-toolbar'},h('div',{className:'seopc-breakpoint-pills'},[375,768,1024,1440,1920].map(function(v){return h('button',{key:v,type:'button',className:viewport===v?'is-active':'',onClick:function(){setViewport(v);}},v+'px');})),h('div',{className:'seopc-inline-actions'},preview&&preview.html?h(Button,{variant:'light',onClick:function(){setPreviewKey(function(v){return v+1;});}},'Reload preview'):null,h(Button,{variant:'primary',busy:previewBusy,disabled:previewBusy||!url.trim(),onClick:refreshSnapshot},previewBusy?'Refreshing…':'Refresh snapshot'),safeUrl?h('a',{href:safeUrl,target:'_blank',rel:'noopener noreferrer',className:'seopc-btn seopc-btn-light'},'Open live page'):null)),
                    preview&&preview.html?h('div',{className:'seopc-browser-frame'},h('div',{className:'seopc-browser-bar'},h('span'),h('span'),h('span'),h('b',null,viewport+'px · server snapshot')),h('div',{className:'seopc-browser-stage seopc-browser-stage-srcdoc'},h('iframe',{key:previewKey,srcDoc:preview.html,title:'Website design preview snapshot',sandbox:'',referrerPolicy:'no-referrer',style:{width:viewport+'px'}}))):h('div',{className:'seopc-frame-blocked'},h('span',{className:'seopc-spinner'}),h('strong',null,'Preview snapshot unavailable'),h('p',null,'The automated checks still work. Refresh the snapshot or open the page directly if the host blocks server fetching.'),safeUrl?h('a',{href:safeUrl,target:'_blank',rel:'noopener noreferrer',className:'seopc-btn seopc-btn-primary'},'Open website'):null)
                ),
                h('div',{className:'seopc-two-col'},h(Section,{title:'Automated layout risks'},(design.issues||[]).length?h('ul',{className:'seopc-issue-list'},design.issues.map(function(issue,i){return h('li',{key:i},h('span',null,'!'),h('div',null,issue));})):h(Notice,{type:'success'},'No obvious server-HTML design risks were detected.')),h(Section,{title:'What this QA can verify'},h('div',{className:'seopc-practice-grid seopc-practice-grid-single'},h('div',null,h('strong',null,'Responsive snapshot'),h('p',null,'Renders fetched HTML and styles in a sandbox at common widths without executing target-site scripts.')),h('div',null,h('strong',null,'Layout-shift signals'),h('p',null,'Flags images without explicit dimensions and large inline fixed widths that can hurt responsive layouts.')),h('div',null,h('strong',null,'Form labelling'),h('p',null,'Flags form controls that appear to have no label or ARIA labelling in the initial HTML.')))))
            ):null,
            h(Section,{title:'Screenshot pixel ruler',subtitle:'Upload a screenshot from any browser/device, drag over an area and read the original-image pixel dimensions. The 8px grid helps spot inconsistent padding.'},h('div',{className:'seopc-design-controls'},h('label',{className:'seopc-dropzone seopc-dropzone-small'},h('input',{type:'file',accept:'image/*',onChange:function(e){setFile(e.target.files&&e.target.files[0]||null);}}),h('strong',null,file?file.name:'Upload screenshot'),h('span',null,'PNG, JPG, WebP')),h('label',{className:'seopc-toggle'},h('input',{type:'checkbox',checked:grid,onChange:function(e){setGrid(e.target.checked);}}),h('span',null,'8px spacing grid')),measure?h('div',{className:'seopc-measure-readout'},h('strong',null,Math.round(measure.w/originalScale)+' × '+Math.round(measure.h/originalScale)+' px'),h('span',null,'x '+Math.round(measure.x/originalScale)+' · y '+Math.round(measure.y/originalScale))):null),imageObj?h('div',{className:'seopc-canvas-wrap'},h('canvas',{ref:canvasRef,onMouseDown:down,onMouseMove:move,onMouseUp:up,onMouseLeave:up})):h('div',{className:'seopc-empty-canvas'},'Upload a screenshot to start measuring.'))
        );
    }

    function ImageStudioTab(props) {
        var fileState=useState(null);var file=fileState[0];var setFile=fileState[1];
        var baseState=useState(null);var base=baseState[0];var setBase=baseState[1];
        var layersState=useState([]);var layers=layersState[0];var setLayers=layersState[1];
        var selectedState=useState('');var selected=selectedState[0];var setSelected=selectedState[1];
        var cropState=useState({left:0,top:0,width:100,height:100});var crop=cropState[0];var setCrop=cropState[1];
        var transparentState=useState(true);var transparent=transparentState[0];var setTransparent=transparentState[1];
        var bgState=useState('#ffffff');var background=bgState[0];var setBackground=bgState[1];
        var toleranceState=useState(42);var tolerance=toleranceState[0];var setTolerance=toleranceState[1];
        var formatState=useState('image/png');var format=formatState[0];var setFormat=formatState[1];
        var qualityState=useState(.9);var quality=qualityState[0];var setQuality=qualityState[1];
        var scaleState=useState(100);var exportScale=scaleState[0];var setExportScale=scaleState[1];
        var textState=useState('Website graphic');var textDraft=textState[0];var setTextDraft=textState[1];
        var canvasRef=useRef(null);var dragRef=useRef(null);
        useEffect(function(){if(props&&props.imported&&props.imported.file)setFile(props.imported.file);},[props&&props.imported&&props.imported.stamp]);
        useEffect(function(){if(!file){setBase(null);setLayers([]);return;}var url=URL.createObjectURL(file),image=new Image();image.onload=function(){setBase(image);var id='base-'+Date.now();setLayers([{id:id,type:'image',name:file.name,image:image,visible:true,opacity:1,x:image.width/2,y:image.height/2,scale:1,rotation:0,flipX:false,flipY:false,brightness:100,contrast:100,saturation:100,grayscale:0,sepia:0,hue:0,blur:0,blend:'source-over',removeBg:false}]);setSelected(id);setCrop({left:0,top:0,width:100,height:100});};image.src=url;return function(){URL.revokeObjectURL(url);};},[file]);
        useEffect(function(){render();},[base,layers,crop,transparent,tolerance,background]);
        function updateLayer(id,patch){setLayers(function(old){return old.map(function(l){return l.id===id?Object.assign({},l,patch):l;});});}
        function selectedLayer(){return layers.find(function(l){return l.id===selected;})||null;}
        function keyImage(img,removeBg){if(!removeBg)return img;var c=document.createElement('canvas');c.width=img.width;c.height=img.height;var ctx=c.getContext('2d',{alpha:true});ctx.drawImage(img,0,0);var data=ctx.getImageData(0,0,c.width,c.height),d=data.data,r=d[0],g=d[1],b=d[2],tol=Number(tolerance);for(var i=0;i<d.length;i+=4){var diff=Math.sqrt(Math.pow(d[i]-r,2)+Math.pow(d[i+1]-g,2)+Math.pow(d[i+2]-b,2));if(diff<tol)d[i+3]=Math.round(d[i+3]*(diff/tol));}ctx.putImageData(data,0,0);return c;}
        function render(){var c=canvasRef.current;if(!c||!base)return;var sx=base.width*crop.left/100,sy=base.height*crop.top/100,sw=Math.max(1,base.width*crop.width/100),sh=Math.max(1,base.height*crop.height/100);c.width=Math.round(sw);c.height=Math.round(sh);var ctx=c.getContext('2d',{alpha:true});ctx.clearRect(0,0,c.width,c.height);if(!transparent){ctx.fillStyle=background;ctx.fillRect(0,0,c.width,c.height);}layers.forEach(function(layer){if(!layer.visible)return;ctx.save();ctx.globalAlpha=Number(layer.opacity);ctx.globalCompositeOperation=layer.blend||'source-over';ctx.translate(layer.x-sx,layer.y-sy);ctx.rotate((Number(layer.rotation)||0)*Math.PI/180);ctx.scale((layer.flipX?-1:1)*(Number(layer.scale)||1),(layer.flipY?-1:1)*(Number(layer.scale)||1));if(layer.type==='image'){ctx.filter='brightness('+(layer.brightness||100)+'%) contrast('+(layer.contrast||100)+'%) saturate('+(layer.saturation||100)+'%) grayscale('+(layer.grayscale||0)+'%) sepia('+(layer.sepia||0)+'%) hue-rotate('+(layer.hue||0)+'deg) blur('+(layer.blur||0)+'px)';var source=keyImage(layer.image,layer.removeBg);ctx.drawImage(source,-layer.image.width/2,-layer.image.height/2);ctx.filter='none';}else{ctx.font=(layer.fontWeight||700)+' '+(layer.fontSize||48)+'px system-ui';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillStyle=layer.color||'#102a23';ctx.fillText(layer.text||'Text',0,0);}ctx.restore();});}
        function addOverlay(files){Array.from(files||[]).forEach(function(f){var u=URL.createObjectURL(f),im=new Image();im.onload=function(){var id='layer-'+Date.now()+'-'+Math.random(),sc=Math.min(1,(base.width*.55)/im.width);setLayers(function(old){return old.concat([{id:id,type:'image',name:f.name,image:im,visible:true,opacity:1,x:base.width/2,y:base.height/2,scale:sc,rotation:0,flipX:false,flipY:false,brightness:100,contrast:100,saturation:100,grayscale:0,sepia:0,hue:0,blur:0,blend:'source-over',removeBg:false}]);});setSelected(id);URL.revokeObjectURL(u);};im.src=u;});}
        function addText(){if(!base||!textDraft.trim())return;var id='text-'+Date.now();setLayers(function(old){return old.concat([{id:id,type:'text',name:'Text',text:textDraft.trim(),visible:true,opacity:1,x:base.width/2,y:base.height/2,fontSize:48,fontWeight:700,color:'#102a23',scale:1,rotation:0,flipX:false,flipY:false,blend:'source-over'}]);});setSelected(id);}
        function moveLayer(direction){var idx=layers.findIndex(function(l){return l.id===selected;});if(idx<0)return;var next=idx+direction;if(next<0||next>=layers.length)return;var arr=layers.slice(),tmp=arr[idx];arr[idx]=arr[next];arr[next]=tmp;setLayers(arr);}
        function removeLayer(){if(layers.length<=1)return;setLayers(function(old){var next=old.filter(function(l){return l.id!==selected;});setSelected(next[next.length-1]&&next[next.length-1].id||'');return next;});}
        function duplicateLayer(){var l=selectedLayer();if(!l)return;var copy=Object.assign({},l,{id:l.id+'-copy-'+Date.now(),name:l.name+' copy',x:l.x+24,y:l.y+24});setLayers(function(old){return old.concat([copy]);});setSelected(copy.id);}
        function resetLayer(){var l=selectedLayer();if(!l||!base)return;updateLayer(l.id,{x:base.width/2,y:base.height/2,scale:1,rotation:0,flipX:false,flipY:false,opacity:1,brightness:100,contrast:100,saturation:100,grayscale:0,sepia:0,hue:0,blur:0});}function fitLayer(){var l=selectedLayer();if(!l||!base||l.type!=='image')return;var cw=base.width*crop.width/100,ch=base.height*crop.height/100,fit=Math.min(cw/l.image.width,ch/l.image.height);updateLayer(l.id,{x:base.width*(crop.left+crop.width/2)/100,y:base.height*(crop.top+crop.height/2)/100,scale:Math.max(.05,fit)});}
        function cropField(key,label){return h('div',{className:'seopc-crop-control'},h(RangeField,{label:label,display:crop[key]+'%',min:key==='left'||key==='top'?0:10,max:100,step:1,value:crop[key],onChange:function(e){var n=Object.assign({},crop);n[key]=Number(e.target.value);if(n.left+n.width>100)n.width=100-n.left;if(n.top+n.height>100)n.height=100-n.top;setCrop(n);}}));}
        function pointerPos(e){var c=canvasRef.current,r=c.getBoundingClientRect();return{x:(e.clientX-r.left)*(c.width/r.width),y:(e.clientY-r.top)*(c.height/r.height)};}function down(e){var l=selectedLayer();if(!l||!base)return;var p=pointerPos(e),sx=base.width*crop.left/100,sy=base.height*crop.top/100;dragRef.current={dx:(p.x+sx)-l.x,dy:(p.y+sy)-l.y};}function move(e){if(!dragRef.current||!base)return;var p=pointerPos(e),sx=base.width*crop.left/100,sy=base.height*crop.top/100;updateLayer(selected,{x:p.x+sx-dragRef.current.dx,y:p.y+sy-dragRef.current.dy});}function up(){dragRef.current=null;}
        async function makeBlob(){var source=canvasRef.current;if(!source)return null;var out=document.createElement('canvas'),ratio=Number(exportScale)/100;out.width=Math.max(1,Math.round(source.width*ratio));out.height=Math.max(1,Math.round(source.height*ratio));var ctx=out.getContext('2d',{alpha:format!=='image/jpeg'});if(format==='image/jpeg'||!transparent){ctx.fillStyle=background;ctx.fillRect(0,0,out.width,out.height);}ctx.drawImage(source,0,0,out.width,out.height);var actual=format,blob=await new Promise(function(resolve){out.toBlob(resolve,format,Number(quality));});if(!blob&&format==='image/avif'){actual='image/webp';blob=await new Promise(function(resolve){out.toBlob(resolve,'image/webp',Number(quality));});}return blob?{blob:blob,type:actual,width:out.width,height:out.height}:null;}
        async function exportImage(){var made=await makeBlob();if(!made)return;var ext={'image/png':'png','image/jpeg':'jpg','image/webp':'webp','image/avif':'avif'}[made.type]||'png';downloadBlob(made.blob,(file?file.name.replace(/\.[^.]+$/,''):'image')+'-studio.'+ext);}
        async function copyImage(){if(!navigator.clipboard||!window.ClipboardItem)return;var made=await makeBlob();if(!made)return;var png=made;if(made.type!=='image/png'){var img=await createImageBitmap(made.blob),c=document.createElement('canvas');c.width=img.width;c.height=img.height;c.getContext('2d').drawImage(img,0,0);var b=await new Promise(function(resolve){c.toBlob(resolve,'image/png');});png={blob:b,type:'image/png'};}await navigator.clipboard.write([new ClipboardItem({'image/png':png.blob})]);}
        var active=selectedLayer();
        return h('div',null,
            h('div',{className:'seopc-hero-panel seopc-hero-studio'},h('div',null,h('span',{className:'seopc-eyebrow'},'Browser image studio'),h('h2',null,'Fast layered image editor for web work'),h('p',null,'Compose images and text, adjust layers, remove simple backgrounds, crop precisely and export web-ready assets without uploading them to WordPress.')),h('label',{className:'seopc-dropzone'},h('input',{type:'file',accept:'image/*',onChange:function(e){setFile(e.target.files&&e.target.files[0]||null);}}),h('strong',null,file?file.name:'Choose a base image'),h('span',null,'Local editing · PNG / JPG / WebP / AVIF export'))),
            base?h('div',{className:'seopc-studio-workspace'},
                h('div',{className:'seopc-studio-main'},
                    h('div',{className:'seopc-studio-toolbar'},h('label',{className:'seopc-btn seopc-btn-light'},'Add image',h('input',{type:'file',accept:'image/*',multiple:true,onChange:function(e){addOverlay(e.target.files);e.target.value='';}})),h('div',{className:'seopc-add-text'},h('input',{type:'text',value:textDraft,onChange:function(e){setTextDraft(e.target.value);},placeholder:'Add text layer'}),h(Button,{variant:'light',onClick:addText},'Add text')),h(Button,{variant:'light',onClick:duplicateLayer,disabled:!active},'Duplicate'),h(Button,{variant:'light',onClick:function(){moveLayer(-1);},disabled:!active},'Down'),h(Button,{variant:'light',onClick:function(){moveLayer(1);},disabled:!active},'Up'),h(Button,{variant:'light',onClick:resetLayer,disabled:!active},'Reset'),h(Button,{variant:'danger',onClick:removeLayer,disabled:layers.length<=1},'Delete')),
                    h('div',{className:'seopc-canvas-editor seopc-studio-canvas'},h('canvas',{ref:canvasRef,onPointerDown:down,onPointerMove:move,onPointerUp:up,onPointerLeave:up})),
                    h('div',{className:'seopc-export-bar'},h('label',null,'Format',h('select',{value:format,onChange:function(e){setFormat(e.target.value);}},h('option',{value:'image/png'},'PNG'),h('option',{value:'image/webp'},'WebP'),h('option',{value:'image/jpeg'},'JPG'),h('option',{value:'image/avif'},'AVIF'))),h(RangeField,{label:'Quality',display:Math.round(quality*100)+'%',min:.35,max:1,step:.01,value:quality,onChange:function(e){setQuality(e.target.value);}}),h(RangeField,{label:'Output size',display:exportScale+'%',min:25,max:100,step:5,value:exportScale,onChange:function(e){setExportScale(Number(e.target.value));}}),h('div',{className:'seopc-export-options'},h('label',{className:'seopc-toggle'},h('input',{type:'checkbox',checked:transparent,onChange:function(e){setTransparent(e.target.checked);}}),h('span',null,'Transparent')),!transparent?h('label',{className:'seopc-color-field'},h('span',null,'Background'),h('input',{type:'color',value:background,onChange:function(e){setBackground(e.target.value);}})):null),h('div',{className:'seopc-export-actions'},navigator.clipboard&&window.ClipboardItem?h(Button,{variant:'light',onClick:copyImage},'Copy PNG'):null,h(Button,{variant:'success',onClick:exportImage},'Export image')))
                ),
                h('aside',{className:'seopc-layer-panel'},
                    h('div',{className:'seopc-layer-panel-head'},h('strong',null,'Layers & adjustments'),h('span',null,layers.length)),
                    h('div',{className:'seopc-layer-list'},layers.slice().reverse().map(function(l){return h('button',{key:l.id,type:'button',className:selected===l.id?'is-active':'',onClick:function(){setSelected(l.id);}},h('span',{className:'seopc-layer-kind'},l.type==='text'?'T':'▧'),h('span',null,l.name),h('i',null,l.visible?'●':'○'));})),
                    active?h('div',{className:'seopc-layer-controls'},
                        h('div',{className:'seopc-control-group'},h('div',{className:'seopc-control-group-title'},h('strong',null,'Layer'),h('label',{className:'seopc-toggle'},h('input',{type:'checkbox',checked:active.visible,onChange:function(e){updateLayer(active.id,{visible:e.target.checked});}}),h('span',null,'Visible'))),active.type==='text'?h('label',null,'Text',h('input',{type:'text',value:active.text,onChange:function(e){updateLayer(active.id,{text:e.target.value,name:e.target.value.slice(0,24)||'Text'});}})):null,h(RangeField,{label:'Opacity',display:Math.round(active.opacity*100)+'%',min:0,max:1,step:.01,value:active.opacity,onChange:function(e){updateLayer(active.id,{opacity:Number(e.target.value)});}}),h('label',null,'Blend mode',h('select',{value:active.blend||'source-over',onChange:function(e){updateLayer(active.id,{blend:e.target.value});}},h('option',{value:'source-over'},'Normal'),h('option',{value:'multiply'},'Multiply'),h('option',{value:'screen'},'Screen'),h('option',{value:'overlay'},'Overlay'),h('option',{value:'darken'},'Darken'),h('option',{value:'lighten'},'Lighten')))),
                        h('div',{className:'seopc-control-group'},h('div',{className:'seopc-control-group-title'},h('strong',null,'Transform'),h('div',{className:'seopc-mini-actions'},h('button',{type:'button',onClick:function(){updateLayer(active.id,{rotation:(Number(active.rotation)||0)-90});}},'↶ 90°'),h('button',{type:'button',onClick:function(){updateLayer(active.id,{rotation:(Number(active.rotation)||0)+90});}},'↷ 90°'),h('button',{type:'button',onClick:function(){updateLayer(active.id,{flipX:!active.flipX});}},'Flip H'),h('button',{type:'button',onClick:function(){updateLayer(active.id,{flipY:!active.flipY});}},'Flip V'),active.type==='image'?h('button',{type:'button',onClick:fitLayer},'Fit'):null,h('button',{type:'button',onClick:function(){updateLayer(active.id,{x:base.width/2,y:base.height/2});}},'Center'))),h(RangeField,{label:'Scale',display:Math.round(active.scale*100)+'%',min:.1,max:3,step:.05,value:active.scale,onChange:function(e){updateLayer(active.id,{scale:Number(e.target.value)});}}),h(RangeField,{label:'Rotation',display:Math.round(active.rotation)+'°',min:-180,max:180,step:1,value:active.rotation,onChange:function(e){updateLayer(active.id,{rotation:Number(e.target.value)});}})),
                        active.type==='text'?h('div',{className:'seopc-control-group'},h('div',{className:'seopc-control-group-title'},h('strong',null,'Typography')),h('div',{className:'seopc-field-grid'},h('label',null,'Font size',h('input',{type:'number',min:8,max:300,value:active.fontSize,onChange:function(e){updateLayer(active.id,{fontSize:Number(e.target.value)});}})),h('label',null,'Text colour',h('input',{type:'color',value:active.color,onChange:function(e){updateLayer(active.id,{color:e.target.value});}})))):h('div',{className:'seopc-control-group'},h('div',{className:'seopc-control-group-title'},h('strong',null,'Image adjustments')),h(RangeField,{label:'Brightness',display:(active.brightness||100)+'%',min:0,max:200,step:1,value:active.brightness||100,onChange:function(e){updateLayer(active.id,{brightness:Number(e.target.value)});}}),h(RangeField,{label:'Contrast',display:(active.contrast||100)+'%',min:0,max:200,step:1,value:active.contrast||100,onChange:function(e){updateLayer(active.id,{contrast:Number(e.target.value)});}}),h(RangeField,{label:'Saturation',display:(active.saturation||100)+'%',min:0,max:200,step:1,value:active.saturation||100,onChange:function(e){updateLayer(active.id,{saturation:Number(e.target.value)});}}),h(RangeField,{label:'Grayscale',display:(active.grayscale||0)+'%',min:0,max:100,step:1,value:active.grayscale||0,onChange:function(e){updateLayer(active.id,{grayscale:Number(e.target.value)});}}),h(RangeField,{label:'Sepia',display:(active.sepia||0)+'%',min:0,max:100,step:1,value:active.sepia||0,onChange:function(e){updateLayer(active.id,{sepia:Number(e.target.value)});}}),h(RangeField,{label:'Hue',display:(active.hue||0)+'°',min:-180,max:180,step:1,value:active.hue||0,onChange:function(e){updateLayer(active.id,{hue:Number(e.target.value)});}}),h(RangeField,{label:'Blur',display:(active.blur||0)+'px',min:0,max:20,step:.5,value:active.blur||0,onChange:function(e){updateLayer(active.id,{blur:Number(e.target.value)});}}),h('label',{className:'seopc-toggle'},h('input',{type:'checkbox',checked:!!active.removeBg,onChange:function(e){updateLayer(active.id,{removeBg:e.target.checked});}}),h('span',null,'Remove corner background')),active.removeBg?h(RangeField,{label:'Background tolerance',display:tolerance,min:5,max:120,step:1,value:tolerance,onChange:function(e){setTolerance(Number(e.target.value));}}):null)
                    ):null,
                    h('div',{className:'seopc-crop-panel'},h('div',{className:'seopc-control-group-title'},h('strong',null,'Crop output'),h('button',{type:'button',onClick:function(){setCrop({left:0,top:0,width:100,height:100});}},'Reset crop')),h('div',{className:'seopc-crop-grid'},cropField('left','Left'),cropField('top','Top'),cropField('width','Width'),cropField('height','Height')))
                )
            ):h(Section,{title:'Start with a base image',subtitle:'Then add image/text layers, adjust colour and transforms, remove simple backgrounds and export to web formats.'},h('div',{className:'seopc-practice-grid'},h('div',null,h('strong',null,'Layer controls'),h('p',null,'Reorder, duplicate, hide, flip, rotate, scale and reposition.')),h('div',null,h('strong',null,'Image adjustments'),h('p',null,'Brightness, contrast, saturation, grayscale, sepia, hue, blur and simple background removal.')),h('div',null,h('strong',null,'Flexible export'),h('p',null,'PNG, JPG, WebP or AVIF, transparent or coloured background, plus clipboard PNG.'))))
        );
    }

    function ConverterTab() {
        var filesState=useState([]);var files=filesState[0];var setFiles=filesState[1];
        var formatState=useState('image/webp');var format=formatState[0];var setFormat=formatState[1];
        var qualityState=useState(.84);var quality=qualityState[0];var setQuality=qualityState[1];
        var busyState=useState(false);var busy=busyState[0];var setBusy=busyState[1];
        var docState=useState(null);var doc=docState[0];var setDoc=docState[1];
        var docBusyState=useState(false);var docBusy=docBusyState[0];var setDocBusy=docBusyState[1];
        var errorState=useState('');var error=errorState[0];var setError=errorState[1];
        async function convertImages(){setBusy(true);setError('');try{for(var i=0;i<files.length;i++){var file=files[i];var bitmap=await decodeImageFile(file);var c=document.createElement('canvas');c.width=bitmap.width||bitmap.naturalWidth;c.height=bitmap.height||bitmap.naturalHeight;var ctx=c.getContext('2d',{alpha:format!=='image/jpeg'});if(format==='image/jpeg'){ctx.fillStyle='#fff';ctx.fillRect(0,0,c.width,c.height);}ctx.drawImage(bitmap,0,0);var blob=await new Promise(function(resolve){c.toBlob(resolve,format,Number(quality));});if(!blob)throw new Error('This browser cannot encode '+format);var ext={'image/webp':'webp','image/jpeg':'jpg','image/png':'png','image/avif':'avif'}[blob.type]||'img';downloadBlob(blob,file.name.replace(/\.[^.]+$/,'')+'.'+ext);if(bitmap.close)bitmap.close();}}catch(e){setError(e.message);}finally{setBusy(false);}}
        async function docxPdf(){if(!doc)return;setDocBusy(true);setError('');try{if(!/\.docx$/i.test(doc.name))throw new Error('Legacy .doc files are not safely readable in a browser. Save as DOCX first, then convert here.');await loadScript('https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js','mammoth');await loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js','html2pdf');var ab=await doc.arrayBuffer();var converted=await window.mammoth.convertToHtml({arrayBuffer:ab});var wrap=document.createElement('div');wrap.className='seopc-doc-export';wrap.innerHTML='<h1>'+doc.name.replace(/\.[^.]+$/,'')+'</h1>'+converted.value;document.body.appendChild(wrap);await window.html2pdf().set({margin:12,filename:doc.name.replace(/\.[^.]+$/,'')+'.pdf',image:{type:'jpeg',quality:.96},html2canvas:{scale:2},jsPDF:{unit:'mm',format:'a4',orientation:'portrait'}}).from(wrap).save();wrap.remove();}catch(e){setError(e.message);}finally{setDocBusy(false);}}
        return h('div',null,
            h('div',{className:'seopc-hero-panel seopc-hero-convert'},h('div',null,h('span',{className:'seopc-eyebrow'},'Fast conversion desk'),h('h2',null,'Batch image & document converter'),h('p',null,'Convert common web image formats in batches, or turn DOCX documents into PDF in the browser.')),h('div',{className:'seopc-convert-badges'},h('span',null,'JPG'),h('span',null,'PNG'),h('span',null,'WebP'),h('span',null,'AVIF'),h('span',null,'DOCX → PDF'))),
            error?h(Notice,{type:'error'},error):null,
            h('div',{className:'seopc-two-col'},
                h(Section,{title:'Batch image converter',subtitle:'Select multiple images; converted files are downloaded one-by-one without a server upload.'},h('label',{className:'seopc-dropzone seopc-dropzone-small'},h('input',{type:'file',multiple:true,accept:'image/*',onChange:function(e){setFiles(Array.from(e.target.files||[]));}}),h('strong',null,files.length?files.length+' images selected':'Choose images'),h('span',null,'Large local files stay on your device')),h('div',{className:'seopc-field-grid'},h('label',null,'Output format',h('select',{value:format,onChange:function(e){setFormat(e.target.value);}},h('option',{value:'image/webp'},'WebP'),h('option',{value:'image/avif'},'AVIF'),h('option',{value:'image/jpeg'},'JPG'),h('option',{value:'image/png'},'PNG'))),h(RangeField,{label:'Quality',display:Math.round(quality*100)+'%',min:.35,max:1,step:.01,value:quality,onChange:function(e){setQuality(e.target.value);}})),h(Button,{variant:'primary',busy:busy,disabled:busy||!files.length,onClick:convertImages},busy?'Converting…':'Convert selected images')),
                h(Section,{title:'DOCX to PDF',subtitle:'Uses browser-side document rendering. Your document is not uploaded to WordPress.'},h('label',{className:'seopc-dropzone seopc-dropzone-small'},h('input',{type:'file',accept:'.docx,.doc',onChange:function(e){setDoc(e.target.files&&e.target.files[0]||null);}}),h('strong',null,doc?doc.name:'Choose DOCX'),h('span',null,'DOCX supported · legacy DOC needs conversion to DOCX first')),h(Notice,null,'The first DOCX conversion loads document/PDF libraries from public CDNs. The document itself remains in your browser.'),h(Button,{variant:'primary',busy:docBusy,disabled:docBusy||!doc,onClick:docxPdf},docBusy?'Building PDF…':'Convert to PDF'))
            )
        );
    }

    function ResourcesTab() {
        var resources=[
            ['WordPress Photos','44k+ moderated CC0 photos','https://wordpress.org/photos/'],['Openverse','Images & audio','https://openverse.org/'],['Wikimedia Commons','Images, video, SVG','https://commons.wikimedia.org/'],['Pexels','Photos & video','https://www.pexels.com/'],['Pixabay','Photos, illustrations & video','https://pixabay.com/'],['Unsplash','Photography','https://unsplash.com/'],['Mixkit','Video & music','https://mixkit.co/'],['Coverr','Free stock video','https://coverr.co/'],['Reshot','Photos & icons','https://www.reshot.com/'],['unDraw','Open-source illustrations','https://undraw.co/illustrations'],['SVG Repo','SVG icons','https://www.svgrepo.com/']
        ];
        return h('div',null,h('div',{className:'seopc-hero-panel seopc-hero-resources'},h('div',null,h('span',{className:'seopc-eyebrow'},'Creative resources'),h('h2',null,'Free media & design resource library'),h('p',null,'A practical launchpad for agency-safe media research. Always verify the current licence before commercial use.'))),h('div',{className:'seopc-resource-grid'},resources.map(function(r){return h('a',{key:r[0],href:r[2],target:'_blank',rel:'noopener noreferrer',className:'seopc-resource-card'},h('span',{className:'seopc-resource-arrow'},'↗'),h('strong',null,r[0]),h('small',null,r[1]));})));
    }

    function ToolkitIcon(props) {
        var name=typeof props==='string'?props:(props&&props.name||'spark');
        var common={viewBox:'0 0 24 24',width:'18',height:'18',fill:'none','aria-hidden':'true',focusable:'false'};
        var stroke={stroke:'currentColor',strokeWidth:'1.8',strokeLinecap:'round',strokeLinejoin:'round'};
        var paths={
            audit:[h('path',Object.assign({key:'a',d:'M5 4h10l4 4v12H5z'},stroke)),h('path',Object.assign({key:'b',d:'M9 12h6M9 16h4M15 4v4h4'},stroke))],
            sitemap:[h('circle',Object.assign({key:'a',cx:'6',cy:'6',r:'2'},stroke)),h('circle',Object.assign({key:'b',cx:'18',cy:'6',r:'2'},stroke)),h('circle',Object.assign({key:'c',cx:'12',cy:'18',r:'2'},stroke)),h('path',Object.assign({key:'d',d:'M8 7.2l3 8M16 7.2l-3 8M8 6h8'},stroke))],
            social:[h('circle',Object.assign({key:'a',cx:'12',cy:'12',r:'8'},stroke)),h('circle',Object.assign({key:'b',cx:'12',cy:'12',r:'3'},stroke)),h('path',Object.assign({key:'c',d:'M12 4v5M20 12h-5M12 20v-5M4 12h5'},stroke))],
            design:[h('path',Object.assign({key:'a',d:'M4 7h16M7 4v16M17 4v16M4 17h16'},stroke)),h('path',Object.assign({key:'b',d:'M9 9h6v6H9z'},stroke))],
            studio:[h('path',Object.assign({key:'a',d:'M12 3v18M3 12h18'},stroke)),h('circle',Object.assign({key:'b',cx:'12',cy:'12',r:'5'},stroke))],
            image:[h('path',Object.assign({key:'a',d:'M5 5h14v14H5z'},stroke)),h('path',Object.assign({key:'b',d:'M7 16l4-4 3 3 2-2 2 3'},stroke)),h('circle',Object.assign({key:'c',cx:'9',cy:'9',r:'1.5'},stroke))],
            video:[h('rect',Object.assign({key:'a',x:'4',y:'6',width:'12',height:'12',rx:'2'},stroke)),h('path',Object.assign({key:'b',d:'M16 10l4-2v8l-4-2z'},stroke))],
            convert:[h('path',Object.assign({key:'a',d:'M5 8h12l-3-3M19 16H7l3 3'},stroke))],
            search:[h('circle',Object.assign({key:'a',cx:'10.5',cy:'10.5',r:'6'},stroke)),h('path',Object.assign({key:'b',d:'M15 15l5 5M10.5 7.5v6M7.5 10.5h6'},stroke))],
            network:[h('circle',Object.assign({key:'a',cx:'12',cy:'12',r:'8'},stroke)),h('path',Object.assign({key:'b',d:'M4 12h16M12 4c2.7 2.5 4 5.2 4 8s-1.3 5.5-4 8c-2.7-2.5-4-5.2-4-8s1.3-5.5 4-8z'},stroke))],
            spark:[h('path',Object.assign({key:'a',d:'M5 17l4-5 3 2 6-8'},stroke)),h('path',Object.assign({key:'b',d:'M14 6h4v4'},stroke))]
        };
        return h('svg',common,paths[name]||paths.spark);
    }

    function App() {
        var storedToken = '';
        try { storedToken = sessionStorage.getItem('seopc_toolkit_token') || ''; } catch (e) {}
        var tokenState = useState(config.passwordProtected ? storedToken : 'public');
        var token = tokenState[0];
        var setToken = tokenState[1];
        var tabState = useState('audit');
        var tab = tabState[0];
        var setTab = tabState[1];
        var importedImageState = useState(null);
        var importedImage = importedImageState[0];
        var setImportedImage = importedImageState[1];
        var importedVideoState = useState(null);
        var importedVideo = importedVideoState[0];
        var setImportedVideo = importedVideoState[1];
        var importedStudioState = useState(null);
        var importedStudio = importedStudioState[0];
        var setImportedStudio = importedStudioState[1];

        useEffect(function () {
            document.body.classList.add('seopc-toolkit-page', 'seopc-toolkit-mounted');
            if (config.fullScreen) document.body.classList.add('seopc-toolkit-fullscreen');
            if (config.hideThemeChrome) document.body.classList.add('seopc-toolkit-hide-theme-chrome');
        }, []);

        function login(nextToken) {
            var resolved = config.passwordProtected ? nextToken : 'public';
            setToken(resolved);
            if (config.passwordProtected) {
                try { sessionStorage.setItem('seopc_toolkit_token', resolved); } catch (e) {}
            }
        }
        function expired() {
            if (!config.passwordProtected) {
                setToken('public');
                return;
            }
            setToken('');
            try { sessionStorage.removeItem('seopc_toolkit_token'); } catch (e) {}
        }
        function importMediaToStudio(type, file, item) {
            if (type !== 'image') return;
            setImportedStudio({ file: file, item: item, stamp: Date.now() });
            setTab('studio');
        }
        function importMedia(type, file, item) {
            if (type === 'video') { setImportedVideo({ file: file, item: item, stamp: Date.now() }); setTab('video'); }
            else { setImportedImage({ file: file, item: item, stamp: Date.now() }); setTab('image'); }
            window.setTimeout(function () {
                var root = document.querySelector('.seopc-toolkit-shell');
                if (root) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 30);
        }

        if (config.passwordProtected && !token) return h(Login, { onLogin: login, autoUnlock: !!config.adminBypass });

        var tabs = [
            { id: 'audit', label: 'Website Audit', icon: 'audit' },
            { id: 'sitemap', label: 'Sitemap', icon: 'sitemap' },
            { id: 'social', label: 'Social', icon: 'social' },
            { id: 'design', label: 'Design QA', icon: 'design' },
            { id: 'studio', label: 'Image Studio', icon: 'studio' },
            { id: 'image', label: 'Optimize', icon: 'image' },
            { id: 'video', label: 'Video', icon: 'video' },
            { id: 'convert', label: 'Convert', icon: 'convert' },
            { id: 'search', label: 'Free Media', icon: 'search' },
            { id: 'network', label: 'DNS & Server', icon: 'network' }
        ];
        return h('div', { className: 'seopc-toolkit-shell' },
            h('header', { className: 'seopc-toolkit-header' },
                h('div', { className: 'seopc-brand' },
                    h('span', { className: 'seopc-brand-mark', 'aria-hidden': 'true' }, h(ToolkitIcon,{name:'spark'})),
                    h('div', null,
                        h('strong', null, 'Website Growth Toolkit'),
                        h('small', null, 'SEO · marketing · QA · media workflow')
                    )
                ),
                h('div', { className: 'seopc-header-actions' },
                    h('span', { className: classNames('seopc-access-pill', config.passwordProtected ? 'is-protected' : 'is-public') },
                        h('span', { className: 'seopc-status-dot', 'aria-hidden': 'true' }),
                        config.passwordProtected ? 'Protected access' : 'Public access'
                    ),
                    config.passwordProtected ? h(Button, { variant: 'ghost', onClick: expired }, 'Lock') : null
                )
            ),
            h('nav', { className: 'seopc-tabs', 'aria-label': 'Toolkit sections' }, tabs.map(function (item) {
                return h('button', {
                    key: item.id,
                    type: 'button',
                    className: tab === item.id ? 'is-active' : '',
                    onClick: function () { setTab(item.id); },
                    'aria-current': tab === item.id ? 'page' : undefined
                }, h('span', { className: 'seopc-tab-icon', 'aria-hidden': 'true' }, h(ToolkitIcon,{name:item.icon})), h('span', null, item.label));
            })),
            h('main', { className: 'seopc-toolkit-main' },
                tab === 'audit' ? h(AuditTab, { token: token, onExpired: expired }) : null,
                tab === 'sitemap' ? h(SitemapTab, { token: token, onExpired: expired }) : null,
                tab === 'social' ? h(SocialTab, { token: token, onExpired: expired }) : null,
                tab === 'design' ? h(DesignQATab, { token: token, onExpired: expired }) : null,
                tab === 'studio' ? h(ImageStudioTab, { imported: importedStudio }) : null,
                tab === 'image' ? h(ImageOptimizer, { imported: importedImage }) : null,
                tab === 'video' ? h(VideoOptimizer, { imported: importedVideo }) : null,
                tab === 'convert' ? h(ConverterTab) : null,
                tab === 'search' ? h(MediaSearchTab, { token: token, onExpired: expired, onImport: importMedia, onEdit: importMediaToStudio }) : null,
                tab === 'network' ? h(NetworkTab, { token: token, onExpired: expired }) : null
            ),
            h('footer', { className: 'seopc-toolkit-footer' },
                h('span', null, 'Privacy-first processing'),
                h('span', null, 'Temporary response caching only'),
                h('span', null, 'Media stays on this device')
            )
        );
    }

    document.querySelectorAll('.seopc-toolkit-root').forEach(function (root) {
        var shortcodeBlock = root.closest ? root.closest('.wp-block-shortcode') : null;
        if (shortcodeBlock) shortcodeBlock.classList.add('seopc-toolkit-shortcode-block');
        var postContent = root.closest ? root.closest('.wp-block-post-content') : null;
        if (postContent) postContent.classList.add('seopc-toolkit-post-content');
        var entryContent = root.closest ? root.closest('.entry-content, .post-content, .wp-block-post-content') : null;
        if (entryContent) entryContent.classList.add('seopc-toolkit-entry-content');
        var article = root.closest ? root.closest('article') : null;
        if (article) article.classList.add('seopc-toolkit-article');
        el.render(h(App), root);
    });
}());
