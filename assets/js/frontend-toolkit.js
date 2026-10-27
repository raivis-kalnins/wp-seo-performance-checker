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


    function saveReportPart(key, data) {
        try {
            localStorage.setItem('seopc_report_' + key, JSON.stringify({ data: data, saved_at: Date.now() }));
        } catch (e) {}
    }

    async function ensureReportPdfLibraries(withCanvas) {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
        }
        if (withCanvas && !window.html2canvas) {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', 'html2canvas');
        }
        if (!window.jspdf || !window.jspdf.jsPDF) throw new Error('Could not load the PDF library.');
        if (withCanvas && !window.html2canvas) throw new Error('Could not load the screenshot library.');
    }


    async function ensureZipLibrary() {
        await ensureReportPdfLibraries(true);
        if (!window.JSZip) {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js', 'JSZip');
        }
        if (!window.JSZip) throw new Error('Could not load the ZIP library.');
    }

    function slugifyLabel(value, fallback) {
        var text = String(value || fallback || 'item').toLowerCase();
        text = text.replace(/https?:\/\//g, '');
        text = text.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        return (text || fallback || 'item').slice(0, 80);
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise(function (resolve) {
            try {
                canvas.toBlob(function (blob) { resolve(blob || null); }, type, quality);
            } catch (e) {
                resolve(null);
            }
        });
    }

    function loadImageFromDataUrl(dataUrl) {
        return new Promise(function (resolve, reject) {
            var image = new Image();
            image.onload = function () { resolve(image); };
            image.onerror = function () { reject(new Error('Could not decode a captured screenshot.')); };
            image.src = dataUrl;
        });
    }

    function designDeviceFamily(device) {
        var key = String(device && device.key || '');
        if (key.indexOf('ipad') === 0 || key === 'tablet') return 'tablet';
        if (key.indexOf('laptop') === 0) return 'laptop';
        if (key === 'desktop' || key === 'qhd') return 'desktop';
        return 'mobile';
    }

    function roundedRectPath(ctx, x, y, w, h, r) {
        var radius = Math.max(0, Math.min(Number(r || 0), w / 2, h / 2));
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.arcTo(x + w, y, x + w, y + h, radius);
        ctx.arcTo(x + w, y + h, x, y + h, radius);
        ctx.arcTo(x, y + h, x, y, radius);
        ctx.arcTo(x, y, x + w, y, radius);
        ctx.closePath();
    }

    function drawLabelPill(ctx, x, y, w, h, title, subtitle) {
        ctx.save();
        ctx.shadowColor = 'rgba(15, 23, 42, 0.10)';
        ctx.shadowBlur = Math.round(h * 0.2);
        ctx.shadowOffsetY = Math.round(h * 0.05);
        roundedRectPath(ctx, x, y, w, h, h / 2);
        ctx.fillStyle = 'rgba(255,255,255,0.94)';
        ctx.fill();
        ctx.restore();
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#12342a';
        ctx.font = '600 ' + Math.max(16, Math.round(h * 0.21)) + 'px Arial';
        ctx.fillText(title, x + w / 2, y + h * 0.44);
        ctx.fillStyle = '#5f6f69';
        ctx.font = '400 ' + Math.max(12, Math.round(h * 0.16)) + 'px Arial';
        ctx.fillText(subtitle, x + w / 2, y + h * 0.74);
        ctx.restore();
    }

    function silverFrameGradient(ctx, x, y, w, h) {
        var gradient = ctx.createLinearGradient(x, y, x + w, y + h * 0.65);
        gradient.addColorStop(0, '#151c27');
        gradient.addColorStop(0.18, '#485260');
        gradient.addColorStop(0.40, '#202a38');
        gradient.addColorStop(0.70, '#626c79');
        gradient.addColorStop(1, '#111925');
        return gradient;
    }

    async function composeFramedDeviceScreenshot(screenshotDataUrl, device, pageTitle, options) {
        if (!screenshotDataUrl) return null;
        var image = await loadImageFromDataUrl(screenshotDataUrl);
        options = options || {};
        var family = designDeviceFamily(device);
        // The screenshot is already rasterized at its target resolution. Avoid an expensive second upscale.
        var exportScale = 1;
        var labelTitle = (pageTitle || 'Page').replace(/\s+/g, ' ').trim();
        var labelSub = device.name + ' · ' + device.width + '×' + device.height;
        var screenW = Math.max(1, Math.round(image.naturalWidth * exportScale));
        var screenH = Math.max(1, Math.round(image.naturalHeight * exportScale)); // Match the physical viewport, not a potentially oversized HTML capture.
        var metrics;
        if (family === 'mobile') {
            metrics = { side: Math.round(screenW * 0.115), top: Math.round(screenW * 0.13), bottom: Math.round(screenW * 0.13), radius: Math.round(screenW * 0.10), outer: '#111827', stroke: 'rgba(255,255,255,0.14)' };
        } else if (family === 'tablet') {
            metrics = { side: Math.round(screenW * 0.085), top: Math.round(screenW * 0.09), bottom: Math.round(screenW * 0.095), radius: Math.round(screenW * 0.055), outer: '#0f172a', stroke: 'rgba(255,255,255,0.12)' };
        } else if (family === 'laptop') {
            metrics = { side: Math.round(screenW * 0.045), top: Math.round(screenW * 0.045), bottom: Math.round(screenW * 0.065), radius: Math.round(screenW * 0.02), outer: '#0f172a', stroke: 'rgba(255,255,255,0.12)' };
        } else {
            metrics = { side: Math.round(screenW * 0.038), top: Math.round(screenW * 0.04), bottom: Math.round(screenW * 0.05), radius: Math.round(screenW * 0.018), outer: '#0f172a', stroke: 'rgba(255,255,255,0.10)' };
        }
        var labelH = Math.max(70, Math.round(screenW * 0.105));
        metrics.bottom = Math.max(metrics.bottom, labelH + Math.round(screenW * (family === 'mobile' || family === 'tablet' ? 0.065 : 0.015)));
        if (family === 'laptop' || family === 'desktop') {
            labelH = Math.max(34, Math.round(screenW * 0.042));
            metrics.side = Math.round(screenW * (family === 'laptop' ? 0.014 : 0.017));
            metrics.top = Math.round(screenW * 0.017);
            metrics.bottom = Math.round(screenW * 0.013);
            metrics.radius = Math.round(screenW * 0.009);
        }
        var pad = Math.max(40, Math.round(screenW * 0.06));
        var bodyExtra = 0;
        if (family === 'laptop') bodyExtra = Math.round(screenW * 0.085);
        if (family === 'desktop') bodyExtra = Math.round(screenW * 0.15);
        var canvas = document.createElement('canvas');
        canvas.width = screenW + metrics.side * 2 + pad * 2;
        canvas.height = screenH + metrics.top + metrics.bottom + bodyExtra + pad * 2;
        var ctx = canvas.getContext('2d', { alpha: true });
        var frameX = pad;
        var frameY = pad;
        var frameW = screenW + metrics.side * 2;
        var frameH = screenH + metrics.top + metrics.bottom;
        var screenX = frameX + metrics.side;
        var screenY = frameY + metrics.top;
        var bodyY = frameY + frameH;

        ctx.save();
        ctx.shadowColor = 'rgba(15, 23, 42, 0.16)';
        ctx.shadowBlur = Math.round(Math.max(frameW, frameH) * 0.05);
        ctx.shadowOffsetY = Math.round(Math.max(frameW, frameH) * 0.015);

        if (family === 'mobile' || family === 'tablet') {
            roundedRectPath(ctx, frameX, frameY, frameW, frameH, metrics.radius);
            ctx.fillStyle = silverFrameGradient(ctx, frameX, frameY, frameW, frameH);
            ctx.fill();
            ctx.restore();
            ctx.save();
            roundedRectPath(ctx, frameX + 2, frameY + 2, frameW - 4, frameH - 4, Math.max(8, metrics.radius - 2));
            ctx.strokeStyle = metrics.stroke;
            ctx.lineWidth = 2;
            ctx.stroke();
            ctx.restore();
            ctx.save();
            roundedRectPath(ctx, screenX, screenY, screenW, screenH, Math.max(18, Math.round(metrics.radius * 0.62)));
            ctx.clip();
            ctx.fillStyle='#ffffff';
            ctx.fillRect(screenX,screenY,screenW,screenH);
            ctx.drawImage(image, screenX, screenY, screenW, screenH);
            ctx.restore();
            ctx.save();
            ctx.fillStyle = '#070d16';
            if (family === 'mobile') {
                var notchW = Math.round(frameW * 0.28), notchH = Math.max(12, Math.round(metrics.top * 0.42));
                roundedRectPath(ctx, frameX + (frameW - notchW) / 2, frameY + Math.round(metrics.top * 0.18), notchW, notchH, Math.round(notchH / 2));
                ctx.fill();
            } else {
                ctx.beginPath();
                ctx.arc(frameX + frameW / 2, frameY + Math.round(metrics.top * 0.45), Math.max(5, Math.round(metrics.top * 0.08)), 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.restore();
        } else if (family === 'laptop') {
            // Realistic thin-bezel display, hinge and tapered aluminium keyboard deck.
            roundedRectPath(ctx, frameX, frameY, frameW, frameH, metrics.radius);
            ctx.fillStyle = silverFrameGradient(ctx, frameX, frameY, frameW, frameH);
            ctx.fill();
            ctx.restore();
            ctx.save();
            roundedRectPath(ctx, screenX, screenY, screenW, screenH, Math.max(4, metrics.radius * 0.4));
            ctx.clip();
            ctx.fillStyle='#ffffff';
            ctx.fillRect(screenX,screenY,screenW,screenH);
            ctx.drawImage(image, screenX, screenY, screenW, screenH);
            ctx.restore();
            ctx.save();
            ctx.fillStyle = '#080d16';
            ctx.beginPath();
            ctx.arc(frameX + frameW / 2, frameY + metrics.top * 0.52, Math.max(2, metrics.top * 0.15), 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#536170';
            ctx.fillRect(frameX + frameW * 0.04, bodyY, frameW * 0.92, Math.max(3, bodyExtra * 0.09));
            var deck = ctx.createLinearGradient(0, bodyY, 0, bodyY + bodyExtra * 0.75);
            deck.addColorStop(0, '#aab4c0'); deck.addColorStop(0.35, '#e3e7eb'); deck.addColorStop(0.8, '#9da9b7'); deck.addColorStop(1, '#687483');
            ctx.fillStyle = deck;
            ctx.beginPath();
            ctx.moveTo(frameX + frameW * 0.04, bodyY + bodyExtra * 0.09);
            ctx.lineTo(frameX + frameW * 0.96, bodyY + bodyExtra * 0.09);
            ctx.lineTo(frameX + frameW, bodyY + bodyExtra * 0.70);
            ctx.lineTo(frameX, bodyY + bodyExtra * 0.70);
            ctx.closePath(); ctx.fill();
            ctx.fillStyle = 'rgba(45,55,68,0.16)';
            roundedRectPath(ctx, frameX + frameW * 0.40, bodyY + bodyExtra * 0.20, frameW * 0.20, bodyExtra * 0.27, Math.max(3, bodyExtra * 0.035));
            ctx.fill();
            ctx.fillStyle = '#667383';
            roundedRectPath(ctx, frameX + frameW * 0.005, bodyY + bodyExtra * 0.68, frameW * 0.99, Math.max(3, bodyExtra * 0.065), Math.max(2, bodyExtra * 0.025));
            ctx.fill();
            ctx.restore();
        } else {
            // Thin widescreen monitor with a narrow pedestal and weighted foot.
            roundedRectPath(ctx, frameX, frameY, frameW, frameH, metrics.radius);
            ctx.fillStyle = silverFrameGradient(ctx, frameX, frameY, frameW, frameH);
            ctx.fill();
            ctx.restore();
            ctx.save();
            roundedRectPath(ctx, screenX, screenY, screenW, screenH, Math.max(3, metrics.radius * 0.45));
            ctx.clip();
            ctx.fillStyle='#ffffff';
            ctx.fillRect(screenX,screenY,screenW,screenH);
            ctx.drawImage(image, screenX, screenY, screenW, screenH);
            ctx.restore();
            ctx.save();
            var stem = ctx.createLinearGradient(frameX + frameW * 0.47, 0, frameX + frameW * 0.53, 0);
            stem.addColorStop(0, '#566273'); stem.addColorStop(0.5, '#b6c0cb'); stem.addColorStop(1, '#4c5969');
            ctx.fillStyle = stem;
            ctx.beginPath();
            ctx.moveTo(frameX + frameW * 0.475, bodyY);
            ctx.lineTo(frameX + frameW * 0.525, bodyY);
            ctx.lineTo(frameX + frameW * 0.545, bodyY + bodyExtra * 0.70);
            ctx.lineTo(frameX + frameW * 0.455, bodyY + bodyExtra * 0.70);
            ctx.closePath(); ctx.fill();
            var foot = ctx.createLinearGradient(0, bodyY + bodyExtra * 0.70, 0, bodyY + bodyExtra * 0.88);
            foot.addColorStop(0, '#c2cad4'); foot.addColorStop(1, '#586676');
            ctx.fillStyle = foot;
            ctx.beginPath();
            ctx.moveTo(frameX + frameW * 0.38, bodyY + bodyExtra * 0.71);
            ctx.lineTo(frameX + frameW * 0.62, bodyY + bodyExtra * 0.71);
            ctx.lineTo(frameX + frameW * 0.68, bodyY + bodyExtra * 0.84);
            ctx.lineTo(frameX + frameW * 0.32, bodyY + bodyExtra * 0.84);
            ctx.closePath(); ctx.fill();
            ctx.restore();
        }

        // The label belongs to the device itself, not an external floating badge.
        // Position it in the enlarged bottom bezel so it never overlaps the screenshot.
        var labelW = frameW * 0.86;
        var labelX = frameX + (frameW - labelW) / 2;
        var labelY = frameY + frameH - metrics.bottom + Math.max(4, (metrics.bottom - labelH) / 2);
        if (family === 'laptop' || family === 'desktop') {
            labelY = bodyY + bodyExtra * 0.87;
            labelH = Math.min(labelH, Math.max(24, canvas.height - labelY - 2));
        }
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = '#f4f7fb';
        ctx.font = '600 ' + Math.max(13, Math.round(labelH * 0.25)) + 'px Arial';
        var safeTitle = labelTitle.length > 48 ? labelTitle.slice(0, 45) + '…' : labelTitle;
        ctx.fillText(safeTitle, labelX + labelW / 2, labelY + labelH * 0.41, labelW);
        ctx.fillStyle = '#c1cbd6';
        ctx.font = '400 ' + Math.max(11, Math.round(labelH * 0.19)) + 'px Arial';
        ctx.fillText(labelSub, labelX + labelW / 2, labelY + labelH * 0.73, labelW);
        ctx.restore();

        var preferredType = 'image/avif';
        var blob = await canvasToBlob(canvas, preferredType, ({ fast: 0.72, balanced: 0.82, high: 0.88, print: 0.92 }[options.quality || 'balanced']) || 0.82);
        var type = preferredType;
        if (!blob || blob.type !== preferredType) {
            type = 'image/webp';
            blob = await canvasToBlob(canvas, type, 0.86);
        }
        if (!blob || blob.type !== 'image/webp') {
            type = 'image/png';
            blob = await canvasToBlob(canvas, type, 0.96);
        }
        if (!blob) return null;
        return { blob: blob, type: type, width: canvas.width, height: canvas.height };
    }

    function pdfHost(url) {
        try { return new URL(url).hostname || 'website'; } catch (e) { return String(url || 'website').replace(/^https?:\/\//,'').split('/')[0] || 'website'; }
    }

    function pdfScalar(value) {
        if (Array.isArray(value)) return value.join(', ');
        if (value && typeof value === 'object') return Object.keys(value).map(function (key) { return key + ': ' + safeText(value[key], ''); }).join('; ');
        return safeText(value, '—');
    }

    async function downloadSectionPdf(options) {
        await ensureReportPdfLibraries(false);
        var jsPDF = window.jspdf.jsPDF;
        var doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
        var W = 210, H = 297, ML = 16, MR = 16, y = 18, page = 1;
        var host = pdfHost(options.url || 'website');
        var accent = [18, 116, 93], dark = [15, 58, 47], muted = [94, 113, 105], border = [222, 234, 229], pale = [246, 250, 248];
        function footer() {
            doc.setDrawColor.apply(doc, border); doc.line(ML, 282, W-MR, 282);
            doc.setTextColor.apply(doc, muted); doc.setFont('helvetica','normal'); doc.setFontSize(7.5);
            doc.text('Website Growth Toolkit - ' + host, ML, 288);
            doc.text('Page ' + page, W-MR, 288, {align:'right'});
        }
        function newPage() { footer(); doc.addPage(); page += 1; y = 18; headerBand(); }
        function ensure(height) { if (y + height > 276) newPage(); }
        function headerBand() {
            doc.setFillColor.apply(doc, dark); doc.rect(0,0,W,24,'F');
            doc.setFillColor.apply(doc, accent); doc.rect(0,0,4,24,'F');
            doc.setTextColor(255,255,255); doc.setFont('helvetica','bold'); doc.setFontSize(12); doc.text('Website Growth Toolkit', ML, 10);
            doc.setFont('helvetica','normal'); doc.setFontSize(8); doc.setTextColor(202,233,220); doc.text(options.shortTitle || options.title || 'Website report', ML, 17);
            y = 34;
        }
        function wrapped(text, width, size, style, color) {
            doc.setFont('helvetica', style || 'normal'); doc.setFontSize(size || 9); doc.setTextColor.apply(doc, color || dark);
            var lines = doc.splitTextToSize(pdfScalar(text), width); return lines;
        }
        function sectionTitle(title, subtitle) {
            ensure(24);
            doc.setTextColor.apply(doc, accent); doc.setFont('helvetica','bold'); doc.setFontSize(7); doc.text('WEBSITE QA', ML, y); y += 5;
            doc.setTextColor.apply(doc, dark); doc.setFontSize(15); doc.text(title, ML, y); y += 3;
            doc.setDrawColor.apply(doc, border); doc.line(ML, y+2, W-MR, y+2); y += 8;
            if (subtitle) { var l=wrapped(subtitle,W-ML-MR,8,'normal',muted); doc.text(l,ML,y); y += l.length*4 + 4; }
        }
        function scoreCards(cards) {
            cards=(cards||[]).filter(Boolean); if(!cards.length)return;
            var cols=3, gap=4, cw=(W-ML-MR-gap*(cols-1))/cols, ch=27;
            for(var i=0;i<cards.length;i+=cols){ ensure(ch+5); for(var j=0;j<cols && i+j<cards.length;j++){
                var c=cards[i+j], x=ML+j*(cw+gap), value=Number(c.value), shown=isNaN(value)?pdfScalar(c.value):Math.round(value)+'/100';
                doc.setFillColor.apply(doc,pale); doc.setDrawColor.apply(doc,border); doc.roundedRect(x,y,cw,ch,2.5,2.5,'FD');
                doc.setTextColor.apply(doc,muted); doc.setFont('helvetica','bold'); doc.setFontSize(7); doc.text(String(c.label||'Score').toUpperCase(),x+4,y+6);
                doc.setTextColor.apply(doc,dark); doc.setFontSize(16); doc.text(shown,x+4,y+15);
                if(c.note){doc.setTextColor.apply(doc,muted);doc.setFont('helvetica','normal');doc.setFontSize(6.7);var nl=doc.splitTextToSize(pdfScalar(c.note),cw-8).slice(0,2);doc.text(nl,x+4,y+21);}
            } y+=ch+5; }
        }
        function keyRows(rows) {
            (rows||[]).forEach(function(row){
                var label=pdfScalar(row[0]), value=pdfScalar(row[1]); var vl=doc.splitTextToSize(value,112); var rh=Math.max(9,vl.length*4+5); ensure(rh+1);
                doc.setFillColor.apply(doc,pale); doc.setDrawColor.apply(doc,border); doc.roundedRect(ML,y,W-ML-MR,rh,1.5,1.5,'FD');
                doc.setFont('helvetica','bold'); doc.setFontSize(7.5); doc.setTextColor.apply(doc,dark); doc.text(label,ML+4,y+5.5);
                doc.setFont('helvetica','normal'); doc.setTextColor.apply(doc,muted); doc.text(vl,ML+58,y+5.5); y+=rh+2;
            }); y+=2;
        }
        function bullets(items) {
            (items||[]).forEach(function(item){ var text=pdfScalar(item); var lines=doc.splitTextToSize(text,W-ML-MR-9); var rh=Math.max(7,lines.length*4.2+2); ensure(rh+1); doc.setFillColor(231,248,240); doc.circle(ML+2,y+2.2,1.2,'F'); doc.setTextColor.apply(doc,dark); doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.text(lines,ML+7,y+3.5); y+=rh; }); y+=2;
        }
        headerBand();
        doc.setTextColor.apply(doc,dark); doc.setFont('helvetica','bold'); doc.setFontSize(23); var titleLines=doc.splitTextToSize(options.title || 'Website report', 150); doc.text(titleLines,ML,y); y+=titleLines.length*9+3;
        doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor.apply(doc,muted); var sub=doc.splitTextToSize(options.subtitle||'',W-ML-MR); if(sub.length){doc.text(sub,ML,y);y+=sub.length*4.5+5;}
        doc.setFillColor.apply(doc,pale); doc.roundedRect(ML,y,W-ML-MR,20,2.5,2.5,'F'); doc.setFontSize(8); doc.setTextColor.apply(doc,muted); doc.text('Website',ML+5,y+6); doc.setFont('helvetica','bold'); doc.setTextColor.apply(doc,dark); doc.text(host,ML+5,y+12); doc.setFont('helvetica','normal'); doc.setTextColor.apply(doc,muted); doc.text('Generated ' + new Date().toLocaleString(),W-MR-5,y+12,{align:'right'}); y+=28;
        scoreCards(options.scores||[]);
        (options.sections||[]).forEach(function(sec){ sectionTitle(sec.title||'Details',sec.subtitle||''); if(sec.rows)keyRows(sec.rows); if(sec.bullets)bullets(sec.bullets); if(sec.note){ensure(18);doc.setFillColor(242,248,245);doc.setDrawColor.apply(doc,border);var note=doc.splitTextToSize(pdfScalar(sec.note),W-ML-MR-10);var nh=note.length*4+8;doc.roundedRect(ML,y,W-ML-MR,nh,2,2,'FD');doc.setTextColor.apply(doc,muted);doc.setFont('helvetica','normal');doc.setFontSize(8);doc.text(note,ML+5,y+6);y+=nh+5;} });
        footer();
        doc.save((options.filename||('website-report-'+host))+'.pdf');
    }

    function waitMs(ms){return new Promise(function(resolve){setTimeout(resolve,ms);});}

    function prepareCaptureHtml(previewHtml) {
        if (!previewHtml) return '';
        var html=String(previewHtml)
            .replace(/<link\b[^>]*\brel=["']?preload["']?[^>]*\bas=["']?font["']?[^>]*>/gi,'')
            .replace(/<link\b[^>]*\bas=["']?font["']?[^>]*\brel=["']?preload["']?[^>]*>/gi,'');
        var captureCss='<style id="seopc-capture-optimise">*{font-family:Arial,Helvetica,sans-serif!important;animation:none!important;transition:none!important;caret-color:transparent!important;box-sizing:border-box}html,body{margin:0!important;padding:0!important;background:#fff!important;scroll-behavior:auto!important}html{overflow:hidden!important}body{overflow:hidden!important}video,audio{visibility:hidden!important}</style>';
        if (/<\/head>/i.test(html)) return html.replace(/<\/head>/i,captureCss+'</head>');
        return captureCss+html;
    }

    async function capturePreviewHtml(previewHtml, device, qualityMode) {
        if (!previewHtml) return null;
        await ensureReportPdfLibraries(true);
        var frame=document.createElement('iframe');
        frame.setAttribute('sandbox','allow-same-origin');
        frame.setAttribute('referrerpolicy','no-referrer');
        frame.style.position='fixed'; frame.style.left='0'; frame.style.top='0';
        frame.style.width=device.width+'px'; frame.style.height=device.height+'px';
        frame.width=String(device.width); frame.height=String(device.height);
        frame.style.border='0'; frame.style.opacity='0'; frame.style.pointerEvents='none'; frame.style.zIndex='-2147483647';
        document.body.appendChild(frame);
        try {
            await new Promise(function(resolve){
                var done=false, timer=setTimeout(function(){if(!done){done=true;resolve();}},1800);
                frame.onload=function(){if(!done){done=true;clearTimeout(timer);resolve();}};
                frame.srcdoc=prepareCaptureHtml(previewHtml);
            });
            var doc=frame.contentDocument;
            if(!doc||!doc.documentElement)return null;
            var imgs=Array.prototype.slice.call(doc.images||[]).slice(0,50);
            if(imgs.length){await Promise.race([Promise.all(imgs.map(function(img){return img.complete?Promise.resolve():new Promise(function(r){var t=setTimeout(r,650);img.addEventListener('load',function(){clearTimeout(t);r();},{once:true});img.addEventListener('error',function(){clearTimeout(t);r();},{once:true});});})),waitMs(750)]);}
            await waitMs(80);
            var root=doc.documentElement; // Capture the complete viewport, not a shrink-wrapped body.
            var mode=qualityMode||'balanced';
            var cfg={
                fast:{scale:0.88,max:1250,jpeg:0.76,timeout:7000,imageTimeout:750},
                balanced:{scale:1.08,max:1650,jpeg:0.84,timeout:9000,imageTimeout:900},
                high:{scale:1.32,max:2000,jpeg:0.89,timeout:12000,imageTimeout:1100},
                print:{scale:1.62,max:2400,jpeg:0.92,timeout:16000,imageTimeout:1400}
            }[mode]||{scale:1.08,max:1650,jpeg:0.84,timeout:9000,imageTimeout:900};
            try{
                if(doc.documentElement){doc.documentElement.style.width=device.width+'px';doc.documentElement.style.height=device.height+'px';doc.documentElement.style.minHeight=device.height+'px';doc.documentElement.style.maxWidth='none';doc.documentElement.style.minWidth=device.width+'px';doc.documentElement.style.margin='0';doc.documentElement.style.padding='0';doc.documentElement.style.background='#fff';doc.documentElement.style.overflow='hidden';doc.documentElement.style.boxSizing='border-box';}
                if(doc.body){doc.body.style.width=device.width+'px';doc.body.style.height=device.height+'px';doc.body.style.minHeight=device.height+'px';doc.body.style.maxWidth='none';doc.body.style.minWidth=device.width+'px';doc.body.style.margin='0';doc.body.style.padding='0';doc.body.style.background='#fff';doc.body.style.overflow='hidden';doc.body.style.boxSizing='border-box';}
                if(doc.defaultView){try{doc.defaultView.dispatchEvent(new doc.defaultView.Event('resize'));}catch(er){}}
                void frame.offsetWidth;
            }catch(e){}
            await waitMs(mode==='fast'?70:110);
            // Do not render html2canvas below 1 CSS pixel / output pixel. In embedded
            // desktop snapshots a fractional scale also reduced the painted DOM region,
            // leaving a transparent strip along the right and bottom of the monitor.
            // Capture at native viewport scale first; downsample the finished raster only.
            var outputScale=Math.max(0.55,Math.min(cfg.scale,cfg.max/Math.max(1,device.width)));
            var renderScale=Math.max(1,outputScale);
            var jpegQuality=cfg.jpeg;
            try{if(doc.defaultView){doc.defaultView.scrollTo(0,0);}}catch(e){}
            var opts={backgroundColor:'#ffffff',scale:renderScale,useCORS:true,allowTaint:false,logging:false,width:device.width,height:device.height,windowWidth:device.width,windowHeight:device.height,x:0,y:0,scrollX:0,scrollY:0,imageTimeout:cfg.imageTimeout,removeContainer:true,onclone:function(clonedDoc){
                // html2canvas creates its own document. Lock the clone to the exact device viewport.
                var h=clonedDoc.documentElement,b=clonedDoc.body;
                [h,b].forEach(function(el){if(!el)return;el.style.setProperty('width',device.width+'px','important');el.style.setProperty('min-width',device.width+'px','important');el.style.setProperty('max-width',device.width+'px','important');el.style.setProperty('height',device.height+'px','important');el.style.setProperty('min-height',device.height+'px','important');el.style.setProperty('margin','0','important');el.style.setProperty('padding','0','important');el.style.setProperty('overflow','hidden','important');});
                try{clonedDoc.defaultView.scrollTo(0,0);}catch(e){}
            }};
            var timeoutMs=cfg.timeout;
            async function rasterize(localOpts){
                return Promise.race([
                    window.html2canvas(root,localOpts),
                    new Promise(function(_,reject){setTimeout(function(){reject(new Error('capture-timeout'));},timeoutMs);})
                ]);
            }
            var canvas;
            try { canvas=await rasterize(opts); }
            catch(e1){
                try { opts.foreignObjectRendering=true; opts.imageTimeout=600; canvas=await rasterize(opts); }
                catch(e2){ return null; }
            }
            try {
                // Always export a uniformly scaled, opaque viewport. A high-resolution
                // render is reduced only after html2canvas has painted the full CSS view;
                // do not truncate the top-left and leave alpha pixels over the device frame.
                var expectedW=Math.max(1,Math.round(device.width*outputScale));
                var expectedH=Math.max(1,Math.round(device.height*outputScale));
                var normalized=document.createElement('canvas');
                normalized.width=expectedW;
                normalized.height=expectedH;
                var nctx=normalized.getContext('2d',{alpha:false});
                if(!nctx)return null;
                nctx.fillStyle='#ffffff';
                nctx.fillRect(0,0,expectedW,expectedH);
                nctx.imageSmoothingEnabled=true;
                nctx.imageSmoothingQuality='high';
                // Exact viewport source dimensions: no crop or letterboxing.
                nctx.drawImage(canvas,0,0,canvas.width,canvas.height,0,0,expectedW,expectedH);
                canvas.width=1;canvas.height=1;
                var data=normalized.toDataURL('image/webp',jpegQuality);
                if(data.indexOf('data:image/webp')!==0)data=normalized.toDataURL('image/jpeg',jpegQuality);
                normalized.width=1;normalized.height=1;
                return data;
            } catch(e3) { return null; }
        } finally { if(frame&&frame.parentNode)frame.parentNode.removeChild(frame); }
    }


    async function capturePreviewHtmlBatch(previewHtml, devices, qualityMode, progressCb, shouldCancel) {
        devices=(devices||[]).filter(Boolean);
        if (!previewHtml || !devices.length) return devices.map(function(){return null;});
        await ensureReportPdfLibraries(true);
        shouldCancel=typeof shouldCancel==='function'?shouldCancel:function(){return false;};
        var frame=document.createElement('iframe');
        frame.setAttribute('sandbox','allow-same-origin');
        frame.setAttribute('referrerpolicy','no-referrer');
        frame.style.position='fixed';frame.style.left='-12000px';frame.style.top='0';
        frame.style.width=devices[0].width+'px';frame.style.height=devices[0].height+'px';
        frame.width=String(devices[0].width);frame.height=String(devices[0].height);
        frame.style.border='0';frame.style.opacity='0.01';frame.style.pointerEvents='none';
        document.body.appendChild(frame);
        var out=[];
        try {
            await new Promise(function(resolve){
                var done=false,timer=setTimeout(function(){if(!done){done=true;resolve();}},2000);
                frame.onload=function(){if(!done){done=true;clearTimeout(timer);resolve();}};
                frame.srcdoc=prepareCaptureHtml(previewHtml);
            });
            var doc=frame.contentDocument;
            if(!doc||!doc.documentElement)return devices.map(function(){return null;});
            var imgs=Array.prototype.slice.call(doc.images||[]).slice(0,80);
            if(imgs.length){await Promise.race([Promise.all(imgs.map(function(img){return img.complete?Promise.resolve():new Promise(function(r){var t=setTimeout(r,850);img.addEventListener('load',function(){clearTimeout(t);r();},{once:true});img.addEventListener('error',function(){clearTimeout(t);r();},{once:true});});})),waitMs(950)]);}
            var root=doc.documentElement; // Capture the complete viewport, not a shrink-wrapped body.
            var mode=qualityMode||'fast';
            var cfg={
                fast:{scale:0.72,max:1050,jpeg:0.68,timeout:11000,retry:true,settle:110,imageTimeout:900},
                balanced:{scale:0.9,max:1250,jpeg:0.73,timeout:13000,retry:true,settle:120,imageTimeout:1000},
                high:{scale:1.08,max:1450,jpeg:0.78,timeout:17000,retry:true,settle:140,imageTimeout:1200},
                print:{scale:1.35,max:1850,jpeg:0.84,timeout:24000,retry:true,settle:160,imageTimeout:1500}
            }[mode]||{scale:0.72,max:1050,jpeg:0.68,timeout:11000,retry:true,settle:110,imageTimeout:900};
            for(var i=0;i<devices.length;i++){
                if(shouldCancel())throw new Error('Report cancelled.');
                var d=devices[i];
                frame.style.width=d.width+'px';frame.style.height=d.height+'px';
                frame.width=String(d.width);frame.height=String(d.height);
                try{
                    if(doc.documentElement){doc.documentElement.style.width=d.width+'px';doc.documentElement.style.maxWidth=d.width+'px';}
                    if(doc.body){doc.body.style.width=d.width+'px';doc.body.style.maxWidth=d.width+'px';}
                    if(doc.defaultView){
                        try{doc.defaultView.dispatchEvent(new doc.defaultView.Event('resize'));}catch(er){}
                    }
                    void frame.offsetWidth;
                }catch(e){}
                await waitMs(cfg.settle);
                var scale=Math.min(cfg.scale,cfg.max/Math.max(1,d.width));scale=Math.max(0.55,scale);
                var opts={backgroundColor:'#ffffff',scale:scale,useCORS:true,allowTaint:false,logging:false,width:d.width,height:d.height,windowWidth:d.width,windowHeight:d.height,scrollX:0,scrollY:0,imageTimeout:cfg.imageTimeout,removeContainer:true};
                async function rasterize(localOpts){return Promise.race([window.html2canvas(root,localOpts),new Promise(function(_,reject){setTimeout(function(){reject(new Error('capture-timeout'));},cfg.timeout);})]);}
                var canvas=null;
                try{canvas=await rasterize(opts);}catch(e1){
                    if(cfg.retry){
                        try{var retryOpts=Object.assign({},opts,{foreignObjectRendering:true,imageTimeout:Math.max(700,Math.round(cfg.imageTimeout*0.75))});canvas=await rasterize(retryOpts);}catch(e2){canvas=null;}
                    }
                }
                var data=null;
                if(canvas){
                    try{data=canvas.toDataURL('image/jpeg',cfg.jpeg);}catch(e3){data=null;}
                    finally{canvas.width=1;canvas.height=1;}
                }
                // Safety fallback: v3.0.2's per-device path is slower, but proved reliable.
                // Only use it for the specific viewport that failed in the fast/reused iframe path.
                if(!data && !shouldCancel()){
                    try{data=await capturePreviewHtml(previewHtml,d,mode==='fast'?'balanced':mode);}catch(e4){data=null;}
                }
                out.push(data||null);
                if(progressCb)progressCb(i,d,!!data);
                await waitMs(20);
            }
            return out;
        } finally {if(frame&&frame.parentNode)frame.parentNode.removeChild(frame);}
    }

    async function captureDesignIframe(frame, device) {
        if (!frame || !frame.contentDocument) return null;
        try {
            var html='<!doctype html>'+frame.contentDocument.documentElement.outerHTML;
            return await capturePreviewHtml(html,device);
        } catch(e){ return null; }
    }


    async function downloadDesignQaDeviceZip(reportPages, device, progressCb, options) {
        await ensureZipLibrary();
        reportPages = Array.isArray(reportPages) ? reportPages : [];
        if (!reportPages.length) throw new Error('No tested pages are available for the screenshot ZIP.');
        if (!device) throw new Error('Choose a device size for the screenshot ZIP.');
        options = options || {};
        var qualityMode = options.quality || 'balanced';
        var shouldCancel = typeof options.shouldCancel === 'function' ? options.shouldCancel : function () { return false; };
        var includeFindings = options.includeFindings !== false;
        var zip = new window.JSZip();
        var host = pdfHost((reportPages[0] && reportPages[0].url) || 'website');
        var deviceSlug = slugifyLabel(device.name, 'device');
        var extMap = { 'image/avif': 'avif', 'image/webp': 'webp', 'image/png': 'png' };
        var summaryRows = [['page_index','page_title','page_url','device','viewport','content_type','design_score','issues']];
        for (var i = 0; i < reportPages.length; i++) {
            if (shouldCancel()) throw new Error('Report cancelled.');
            var rp = reportPages[i] || {};
            var label = (function(url){try{var u=new URL(url);return u.pathname==='/'?'Home':decodeURIComponent(u.pathname.replace(/^\/+|\/+$/g,''))||'Home';}catch(e){return url||('Page '+(i+1));}})(rp.url || '');
            if (progressCb) progressCb({ page: i + 1, pages: reportPages.length, label: label + ' · ' + device.name });
            var capture = await capturePreviewHtml(rp.preview && rp.preview.html || '', device, qualityMode);
            if (!capture) throw new Error('A screenshot could not be created for ' + label + ' on ' + device.name + '.');
            var composed = await composeFramedDeviceScreenshot(capture, device, label, { quality: qualityMode });
            if (!composed || !composed.blob) throw new Error('A framed image could not be prepared for ' + label + '.');
            var ext = extMap[composed.type] || 'png';
            var filename = String(i + 1).padStart(2, '0') + '-' + slugifyLabel(label, 'page') + '-' + deviceSlug + '.' + ext;
            zip.file(filename, composed.blob);
            var design = rp.audit && rp.audit.design || {};
            summaryRows.push([
                i + 1,
                '"' + String(label).replace(/"/g, '""') + '"',
                '"' + String(rp.url || '').replace(/"/g, '""') + '"',
                '"' + String(device.name).replace(/"/g, '""') + '"',
                '"' + (device.width + 'x' + device.height) + '"',
                '"' + String(rp.content_type || '').replace(/"/g, '""') + '"',
                isFinite(Number(design.score)) ? Math.round(Number(design.score)) : '',
                '"' + String((design.issues || []).join(' | ')).replace(/"/g, '""') + '"'
            ]);
        }
        zip.file('README.txt', [
            'Website Growth Toolkit – Design QA screenshot package',
            'Host: ' + host,
            'Device: ' + device.name + ' (' + device.width + '×' + device.height + ')',
            'Pages: ' + reportPages.length,
            'Quality: ' + qualityMode,
            'Format preference: AVIF (with WebP/PNG fallback if the browser cannot encode AVIF)',
            'Storage note: the screenshot ZIP is generated locally in the browser and is not kept as a server-side file. Preview caches expire automatically.'
        ].join('\r\n'));
        if (includeFindings) {
            zip.file('design-qa-summary.csv', summaryRows.map(function (row) { return Array.isArray(row) ? row.join(',') : row; }).join('\r\n'));
        }
        var zipBlob = await zip.generateAsync({ type: 'blob', compression: 'DEFLATE', compressionOptions: { level: 6 } });
        downloadBlob(zipBlob, 'design-qa-' + deviceSlug + '-' + slugifyLabel(host, 'website') + '.zip');
    }

    async function downloadDesignQaDevicePdf(reportPages, device, manualChecks, progressCb, options) {
        await ensureReportPdfLibraries(true);
        reportPages=Array.isArray(reportPages)?reportPages:[];
        if(!reportPages.length) throw new Error('No tested pages are available for the PDF.');
        if(!device) throw new Error('Choose a device size for the PDF.');
        options=options||{};
        var qualityMode=options.quality||'balanced';
        var shouldCancel=typeof options.shouldCancel==='function'?options.shouldCancel:function(){return false;};
        var includeFindings=options.includeFindings!==false;
        var jsPDF=window.jspdf.jsPDF;
        var orientation=device.width>device.height?'landscape':'portrait';
        var doc=new jsPDF({unit:'mm',format:'a4',orientation:orientation,compress:true});
        var ML=12,MR=12,accent=[18,116,93],dark=[15,58,47],muted=[94,113,105],border=[222,234,229],pale=[246,250,248];
        var first=reportPages[0]||{},host=pdfHost(first.url||''),pageNo=1;
        function PW(){return doc.internal.pageSize.getWidth();}
        function PH(){return doc.internal.pageSize.getHeight();}
        function pageLabel(url){try{var u=new URL(url);return u.pathname==='/'?'Home':decodeURIComponent(u.pathname.replace(/^\/+|\/+$/g,''))||'Home';}catch(e){return url;}}
        function footer(){var W=PW(),H=PH();doc.setDrawColor.apply(doc,border);doc.line(ML,H-10,W-MR,H-10);doc.setFont('helvetica','normal');doc.setFontSize(6.8);doc.setTextColor.apply(doc,muted);doc.text(device.name+' responsive QA - '+host,ML,H-5);doc.text('Page '+pageNo,W-MR,H-5,{align:'right'});}
        function addPage(label){footer();doc.addPage('a4',orientation);pageNo++;var W=PW();doc.setFillColor.apply(doc,dark);doc.rect(0,0,W,18,'F');doc.setFillColor.apply(doc,accent);doc.rect(0,0,4,18,'F');doc.setTextColor(255,255,255);doc.setFont('helvetica','bold');doc.setFontSize(9);doc.text('Website Growth Toolkit',ML,8);doc.setFont('helvetica','normal');doc.setFontSize(6.5);doc.setTextColor(202,233,220);doc.text(label||device.name,ML,13.5);}
        var scores=reportPages.map(function(p){var v=p.audit&&p.audit.design&&Number(p.audit.design.score);return isFinite(v)?v:null;}).filter(function(v){return v!==null;});
        var avg=scores.length?Math.round(scores.reduce(function(a,b){return a+b;},0)/scores.length):null;
        var W=PW(),H=PH();
        doc.setFillColor.apply(doc,dark);doc.rect(0,0,W,60,'F');doc.setFillColor.apply(doc,accent);doc.rect(0,0,5,60,'F');
        doc.setTextColor(190,233,214);doc.setFont('helvetica','bold');doc.setFontSize(8);doc.text('RESPONSIVE VISUAL QA',ML,15);
        doc.setTextColor(255,255,255);doc.setFontSize(22);doc.text(device.name,ML,29);doc.setFont('helvetica','normal');doc.setFontSize(10);doc.setTextColor(222,244,235);doc.text(device.width+' x '+device.height+' CSS px',ML,39);doc.setFontSize(8);doc.text(reportPages.length+' pages · '+qualityMode+' quality · '+(includeFindings?'visual + QA findings':'visual-only'),ML,49);
        doc.setFillColor(255,255,255);doc.roundedRect(W-48,17,34,27,3,3,'F');doc.setTextColor.apply(doc,accent);doc.setFont('helvetica','bold');doc.setFontSize(avg===null?11:18);doc.text(avg===null?'VISUAL':String(avg),W-31,30,{align:'center'});doc.setFontSize(5.8);doc.setTextColor.apply(doc,muted);doc.text(avg===null?'REPORT':'AVG DESIGN SCORE',W-31,37,{align:'center'});
        doc.setFillColor.apply(doc,pale);doc.roundedRect(ML,72,W-ML-MR,23,3,3,'F');doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(8);doc.text(host,ML+5,80);doc.setFont('helvetica','normal');doc.setTextColor.apply(doc,muted);doc.text('Generated '+new Date().toLocaleString(),ML+5,88);doc.text('One fresh viewport capture per page - no cross-device iframe resizing',W-MR-5,88,{align:'right'});
        var cy=107;doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(13);doc.text('Pages included',ML,cy);cy+=7;doc.setFont('helvetica','normal');doc.setFontSize(7.2);doc.setTextColor.apply(doc,muted);
        reportPages.slice(0,30).forEach(function(p,i){if(cy>H-18)return;doc.text((i+1)+'. '+doc.splitTextToSize(pageLabel(p.url),W-ML-MR-22)[0],ML,cy);if(p.audit&&p.audit.design){doc.setTextColor.apply(doc,accent);doc.text(String(Math.round(Number(p.audit.design.score||0)))+'/100',W-MR,cy,{align:'right'});doc.setTextColor.apply(doc,muted);}cy+=4.8;});

        for(var i=0;i<reportPages.length;i++){
            if(shouldCancel())throw new Error('Report cancelled.');
            var rp=reportPages[i],design=rp.audit&&rp.audit.design||{};
            addPage('Page '+(i+1)+' of '+reportPages.length+' · '+pageLabel(rp.url));
            if(progressCb)progressCb({page:i+1,pages:reportPages.length,label:pageLabel(rp.url)+' · '+device.name});
            var img=null;
            try{img=await capturePreviewHtml(rp.preview&&rp.preview.html||'',device,qualityMode);}catch(e){img=null;}
            W=PW();H=PH();var top=24;
            doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(12);doc.text(doc.splitTextToSize(pageLabel(rp.url),W-ML-MR-42)[0],ML,top+2);
            doc.setFont('helvetica','normal');doc.setFontSize(6.3);doc.setTextColor.apply(doc,muted);doc.text(doc.splitTextToSize(rp.url||'',W-ML-MR-42)[0],ML,top+7);
            if(includeFindings&&rp.audit&&rp.audit.design){doc.setFillColor.apply(doc,pale);doc.roundedRect(W-MR-29,top-2,29,14,2,2,'F');doc.setTextColor.apply(doc,accent);doc.setFont('helvetica','bold');doc.setFontSize(11);doc.text(String(Math.round(Number(design.score||0))),W-MR-14.5,top+4,{align:'center'});doc.setFontSize(5);doc.setTextColor.apply(doc,muted);doc.text('DESIGN',W-MR-14.5,top+9,{align:'center'});}
            var issueY=top+13;
            if(includeFindings&&design.issues&&design.issues.length){doc.setFont('helvetica','normal');doc.setFontSize(5.8);doc.setTextColor.apply(doc,muted);var issue=doc.splitTextToSize('Top finding: '+pdfScalar(design.issues[0]),W-ML-MR);doc.text(issue.slice(0,2),ML,issueY);issueY+=Math.min(2,issue.length)*3+2;}
            var shotTop=Math.max(top+15,issueY),maxW=W-ML-MR,maxH=H-shotTop-16,ratio=device.width/device.height,shotW=maxW,shotH=shotW/ratio;
            if(shotH>maxH){shotH=maxH;shotW=shotH*ratio;}
            var x=(W-shotW)/2;
            if(img){doc.setFillColor(238,243,241);doc.roundedRect(x-1.5,shotTop-1.5,shotW+3,shotH+3,2.5,2.5,'F');doc.addImage(img,'JPEG',x,shotTop,shotW,shotH,undefined,qualityMode==='print'?'MEDIUM':'FAST');}
            else{doc.setFillColor.apply(doc,pale);doc.setDrawColor.apply(doc,border);doc.roundedRect(ML,shotTop,maxW,55,3,3,'FD');doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(9);doc.text('Screenshot unavailable',ML+6,shotTop+14);doc.setFont('helvetica','normal');doc.setFontSize(7);doc.setTextColor.apply(doc,muted);doc.text(doc.splitTextToSize('The visual snapshot could not be rasterized for this page. The page audit remains included.',maxW-12),ML+6,shotTop+23);}
            await waitMs(25);
        }
        addPage('QA checklist');
        W=PW();var y=30;doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(15);doc.text('Manual visual QA checklist',ML,y);y+=10;doc.setFontSize(8.2);
        Object.keys(manualChecks||{}).forEach(function(label){var pass=!!manualChecks[label];if(y>PH()-24)return;doc.setFillColor(pass?225:255,pass?247:248,pass?236:235);doc.roundedRect(ML,y,W-ML-MR,9,2,2,'F');doc.setTextColor(pass?22:146,pass?101:64,pass?52:14);doc.text((pass?'PASS  ':'REVIEW  ')+label,ML+4,y+6);y+=11;});
        footer();
        var safeDevice=(device.key||device.name).replace(/[^a-z0-9_-]+/gi,'-').toLowerCase();
        doc.save('design-qa-'+safeDevice+'-'+host.replace(/[^a-z0-9.-]+/gi,'-')+'.pdf');
    }

    async function downloadDesignQaPdf(reportPages, devices, manualChecks, progressCb, options) {
        await ensureReportPdfLibraries(true);
        reportPages=Array.isArray(reportPages)?reportPages:[];
        if(!reportPages.length) throw new Error('No tested pages are available for the PDF.');
        devices=(devices||[]).filter(function(d){return d.enabled!==false;});
        if(!devices.length) throw new Error('Select at least one device size for the PDF.');
        options=options||{};
        var qualityMode=options.quality||'high';
        var shouldCancel=typeof options.shouldCancel==='function'?options.shouldCancel:function(){return false;};
        var jsPDF=window.jspdf.jsPDF, doc=new jsPDF({unit:'mm',format:'a4',orientation:'portrait',compress:true}), ML=14,MR=14;
        var first=reportPages[0]||{}, host=pdfHost(first.url||''), accent=[18,116,93], dark=[15,58,47], muted=[94,113,105], border=[222,234,229], pale=[246,250,248];
        var pageNo=1;
        function PW(){return doc.internal.pageSize.getWidth();}
        function PH(){return doc.internal.pageSize.getHeight();}
        function footer(){var W=PW(),H=PH();doc.setDrawColor.apply(doc,border);doc.line(ML,H-15,W-MR,H-15);doc.setFont('helvetica','normal');doc.setFontSize(7.2);doc.setTextColor.apply(doc,muted);doc.text('Design QA - '+host,ML,H-9);doc.text('Page '+pageNo,W-MR,H-9,{align:'right'});}
        function newPage(label,orientation){if(pageNo>=1)footer();doc.addPage('a4',orientation||'portrait');pageNo++;var W=PW();doc.setFillColor.apply(doc,dark);doc.rect(0,0,W,24,'F');doc.setFillColor.apply(doc,accent);doc.rect(0,0,4,24,'F');doc.setTextColor(255,255,255);doc.setFont('helvetica','bold');doc.setFontSize(11);doc.text('Website Growth Toolkit',ML,10);doc.setFont('helvetica','normal');doc.setFontSize(7.5);doc.setTextColor(202,233,220);doc.text(label||'Responsive & Design QA',ML,17);}
        function pageLabel(url){try{var u=new URL(url);return u.pathname==='/'?'Home':decodeURIComponent(u.pathname.replace(/^\/+|\/+$/g,''))||'Home';}catch(e){return url;}}
        function typeLabel(type){return {page:'Pages',post:'Posts',product:'Products',product_category:'Product categories',category:'Categories',tag:'Tags',author:'Authors',other:'Other'}[type]||'Other';}
        var scores=reportPages.map(function(p){var v=p.audit&&p.audit.design&&Number(p.audit.design.score);return isFinite(v)?v:null;}).filter(function(v){return v!==null;});
        var avg=scores.length?Math.round(scores.reduce(function(a,b){return a+b;},0)/scores.length):null;
        var W=PW();
        doc.setFillColor.apply(doc,dark);doc.rect(0,0,W,84,'F');doc.setFillColor.apply(doc,accent);doc.rect(0,0,5,84,'F');doc.setTextColor(190,233,214);doc.setFont('helvetica','bold');doc.setFontSize(8);doc.text('MULTI-PAGE RESPONSIVE & DESIGN QA',ML,17);doc.setTextColor(255,255,255);doc.setFontSize(24);doc.text(doc.splitTextToSize(host,130),ML,31);doc.setFont('helvetica','normal');doc.setFontSize(9);doc.setTextColor(222,244,235);doc.text(reportPages.length+' pages · '+devices.length+' device views · '+qualityMode+' quality',ML,58);
        doc.setFillColor(255,255,255);doc.roundedRect(160,20,34,34,4,4,'F');doc.setTextColor.apply(doc,accent);doc.setFont('helvetica','bold');doc.setFontSize(avg===null?13:21);doc.text(avg===null?'VISUAL':String(avg),177,38,{align:'center'});doc.setFontSize(6.5);doc.setTextColor.apply(doc,muted);doc.text(avg===null?'REPORT MODE':'AVG DESIGN SCORE',177,47,{align:'center'});
        doc.setFillColor.apply(doc,pale);doc.roundedRect(ML,94,W-ML-MR,25,3,3,'F');doc.setFont('helvetica','bold');doc.setFontSize(8);doc.setTextColor.apply(doc,dark);doc.text('Report scope',ML+5,102);doc.setFont('helvetica','normal');doc.setTextColor.apply(doc,muted);doc.text(reportPages.length+' pages selected from '+host,ML+5,110);doc.text('Generated '+new Date().toLocaleString(),W-MR-5,110,{align:'right'});
        var counts={};reportPages.forEach(function(p){var t=p.content_type||'other';counts[t]=(counts[t]||0)+1;});
        var y=130;doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(14);doc.text('Included content',ML,y);y+=8;Object.keys(counts).forEach(function(k){doc.setFillColor.apply(doc,pale);doc.roundedRect(ML,y,55,12,2,2,'F');doc.setTextColor.apply(doc,dark);doc.setFontSize(8);doc.text(typeLabel(k),ML+4,y+5);doc.setTextColor.apply(doc,accent);doc.setFontSize(11);doc.text(String(counts[k]),ML+49,y+8,{align:'right'});y+=15;});
        y+=2;doc.setTextColor.apply(doc,dark);doc.setFontSize(12);doc.text('Selected pages',ML,y);y+=6;doc.setFont('helvetica','normal');doc.setFontSize(7.3);
        reportPages.slice(0,18).forEach(function(p,i){if(y>270){newPage('Selected pages');y=36;}doc.setTextColor.apply(doc,muted);doc.text((i+1)+'. '+doc.splitTextToSize(pageLabel(p.url),145)[0],ML,y);if(p.audit&&p.audit.design){doc.setTextColor.apply(doc,accent);doc.text(String(Math.round(Number(p.audit.design.score||0)))+'/100',PW()-MR,y,{align:'right'});}y+=5.5;});
        if(reportPages.length>18){doc.setTextColor.apply(doc,muted);doc.text('… '+(reportPages.length-18)+' more pages included',ML,y);}

        for(var pi=0;pi<reportPages.length;pi++){
            if(shouldCancel())throw new Error('Report cancelled.');
            var rp=reportPages[pi], design=rp.audit&&rp.audit.design||{};
            newPage('Page '+(pi+1)+' of '+reportPages.length+' · '+pageLabel(rp.url));
            W=PW();var sy=35;doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(17);doc.text(doc.splitTextToSize(pageLabel(rp.url),150),ML,sy);doc.setFont('helvetica','normal');doc.setFontSize(7.5);doc.setTextColor.apply(doc,muted);doc.text(doc.splitTextToSize(rp.url||'',175),ML,sy+10);
            if(rp.audit&&rp.audit.design){doc.setFillColor.apply(doc,pale);doc.roundedRect(W-50,32,34,25,3,3,'F');doc.setTextColor.apply(doc,accent);doc.setFont('helvetica','bold');doc.setFontSize(17);doc.text(String(Math.round(Number(design.score||0))),W-33,44,{align:'center'});doc.setFontSize(6);doc.setTextColor.apply(doc,muted);doc.text('DESIGN SCORE',W-33,51,{align:'center'});}
            sy=68;var issues=(design.issues||[]).slice(0,5);if(issues.length){doc.setFont('helvetica','bold');doc.setFontSize(10);doc.setTextColor.apply(doc,dark);doc.text('Key findings',ML,sy);sy+=6;doc.setFont('helvetica','normal');doc.setFontSize(7.2);issues.forEach(function(issue){var lines=doc.splitTextToSize('• '+pdfScalar(issue),W-ML-MR);doc.setTextColor.apply(doc,muted);doc.text(lines,ML,sy);sy+=lines.length*3.6+1;});}
            var captureImages=await capturePreviewHtmlBatch(rp.preview&&rp.preview.html||'',devices,qualityMode,function(di,d){if(progressCb)progressCb({page:pi+1,pages:reportPages.length,device:di+1,devices:devices.length,label:pageLabel(rp.url)+' · '+d.name});},shouldCancel);
            for(var di=0;di<devices.length;di++){
                if(shouldCancel())throw new Error('Report cancelled.');
                var d=devices[di],img=captureImages[di]||null;
                var landscape=d.width>d.height;
                newPage(pageLabel(rp.url)+' · '+d.name+' '+d.width+'×'+d.height,landscape?'landscape':'portrait');
                W=PW();var H=PH();var yy=34;doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(16);doc.text(d.name,ML,yy);doc.setFont('helvetica','normal');doc.setFontSize(7.5);doc.setTextColor.apply(doc,muted);doc.text(d.width+' × '+d.height+' CSS px · '+pageLabel(rp.url),ML,yy+7);yy+=14;
                if(img){var maxW=W-ML-MR-4,maxH=H-yy-24,ratio=d.width/d.height,w=maxW,h=w/ratio;if(h>maxH){h=maxH;w=h*ratio;}var x=(W-w)/2;doc.setFillColor(28,39,35);doc.roundedRect(x-2,yy-2,w+4,h+4,3,3,'F');doc.addImage(img,'JPEG',x,yy,w,h,undefined,qualityMode==='print'?'MEDIUM':'FAST');}
                else {doc.setFillColor.apply(doc,pale);doc.setDrawColor.apply(doc,border);doc.roundedRect(ML,yy,W-ML-MR,70,3,3,'FD');doc.setFont('helvetica','bold');doc.setTextColor.apply(doc,dark);doc.text('Visual capture could not be embedded',ML+7,yy+15);doc.setFont('helvetica','normal');doc.setTextColor.apply(doc,muted);doc.text(doc.splitTextToSize('The page test is included, but browser security or a remote asset prevented screenshot rasterization. The live preview remains available in Design QA.',W-ML-MR-14),ML+7,yy+24);}
            }
        }
        newPage('QA checklist & recommendations');W=PW();var cy=36;doc.setTextColor.apply(doc,dark);doc.setFont('helvetica','bold');doc.setFontSize(16);doc.text('Manual visual QA checklist',ML,cy);cy+=9;doc.setFontSize(8.5);
        Object.keys(manualChecks||{}).forEach(function(label){var pass=!!manualChecks[label];if(cy>270){newPage('QA checklist');cy=36;}doc.setFillColor(pass?225:255,pass?247:248,pass?236:235);doc.roundedRect(ML,cy,PW()-ML-MR,9,2,2,'F');doc.setTextColor(pass?22:146,pass?101:64,pass?52:14);doc.setFont('helvetica','bold');doc.text((pass?'PASS  ':'REVIEW  ')+label,ML+4,cy+6);cy+=11;});
        cy+=4;doc.setTextColor.apply(doc,dark);doc.setFontSize(12);doc.text('Report note',ML,cy);cy+=7;doc.setFont('helvetica','normal');doc.setFontSize(8);doc.setTextColor.apply(doc,muted);doc.text(doc.splitTextToSize('Pages are grouped by sitemap content type. Wide device screenshots use landscape A4 pages to maximise readable visual area. Test results are cached during this session so changing Export scope or quality does not force every page audit to run again.',PW()-ML-MR),ML,cy);footer();
        doc.save('design-qa-multipage-'+host.replace(/[^a-z0-9.-]+/gi,'-')+'.pdf');
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
                saveReportPart('audit', { audit: settled[0].value, pagespeed: settled[1].status === 'fulfilled' ? settled[1].value : null });
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
        var pdfBusyState = useState(false);
        var pdfBusy = pdfBusyState[0];
        var setPdfBusy = pdfBusyState[1];
        var pdfErrorState = useState('');
        var pdfError = pdfErrorState[0];
        var setPdfError = pdfErrorState[1];
        var resultState = useState(null);
        var result = resultState[0];
        var setResult = resultState[1];
        var freshState = useState(false);
        var fresh = freshState[0];
        var setFresh = freshState[1];

        async function run(event) {
            event.preventDefault(); setBusy(true); setError(''); setPdfError(''); setResult(null);
            try { var next = await request('seopc_toolkit_network', { target: target, fresh: fresh ? '1' : '0' }, props.token); setResult(next); saveReportPart('network', next); }
            catch (err) { setError(err.message); if (err.status === 403) props.onExpired(); }
            finally { setBusy(false); }
        }
        async function exportNetworkPdf(){
            if(!result)return;setPdfBusy(true);setPdfError('');
            try{
                var httpsOpen=result.tcp&&result.tcp['443']&&result.tcp['443'].open,httpOpen=result.tcp&&result.tcp['80']&&result.tcp['80'].open,httpOk=result.http&&result.http.status>=200&&result.http.status<400;
                var dnsRows=[];Object.keys(result.dns||{}).forEach(function(type){dnsRows.push([type,(result.dns[type]||[]).length+' record(s)']);});
                await downloadSectionPdf({title:'DNS, Server & TLS Report',shortTitle:'Infrastructure QA',subtitle:'Public DNS, reachability, server response and TLS certificate signals for the tested host.',url:'https://'+result.host,filename:'dns-server-report-'+pdfHost('https://'+result.host),scores:[
                    {label:'DNS response',value:Math.max(0,100-Math.min(100,Number(result.dns_latency_ms||0)/5)),note:(result.dns_latency_ms||0)+' ms lookup'},
                    {label:'HTTP reachability',value:httpOk?100:25,note:'HTTP '+safeText(result.http&&result.http.status)},
                    {label:'HTTPS port',value:httpsOpen?100:0,note:httpsOpen?'Port 443 open':'Unavailable'},
                    {label:'TLS certificate',value:result.tls&&result.tls.available?(Number(result.tls.days_remaining||0)>30?100:60):0,note:result.tls&&result.tls.available?(result.tls.days_remaining+' days remaining'):'Unavailable'}
                ],sections:[
                    {title:'Reachability summary',rows:[['Host',result.host],['DNS latency',(result.dns_latency_ms||0)+' ms'],['HTTP latency',result.http&&result.http.latency_ms?(result.http.latency_ms+' ms'):'Unavailable'],['Port 443',httpsOpen?((result.tcp['443'].latency_ms||0)+' ms'):'Closed/unavailable'],['Port 80',httpOpen?((result.tcp['80'].latency_ms||0)+' ms'):'Closed/unavailable'],['IP addresses',(result.ips||[]).join(', ')||'None returned'],['CDN clue',result.http&&result.http.cdn||'Not detected']]},
                    {title:'Server response',rows:[['HTTP status',result.http&&result.http.status],['Server',result.http&&result.http.server||'Not disclosed'],['Powered by',result.http&&result.http.powered_by||'Not disclosed'],['Content type',result.http&&result.http.content_type],['Cache-Control',result.http&&result.http.cache_control||'Not returned']]},
                    {title:'TLS certificate',rows:result.tls&&result.tls.available?[['Subject',result.tls.subject],['Issuer',result.tls.issuer],['Valid from',result.tls.valid_from],['Valid to',result.tls.valid_to],['Days remaining',result.tls.days_remaining],['Subject alternative names',result.tls.san]]:[['Status',result.tls&&result.tls.error||'TLS details unavailable']]},
                    {title:'DNS record coverage',rows:dnsRows,note:result.note||'DNS and hosting results are public infrastructure observations and should not be treated as a security penetration test.'}
                ]});
            }catch(e){setPdfError(e.message||'Could not create the DNS/server PDF.');}finally{setPdfBusy(false);}
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
                h('div',{className:'seopc-report-toolbar'},h('div',null,h('strong',null,'DNS & Server report'),h('span',null,'Export this infrastructure test as a separate PDF.')),h(Button,{variant:'primary',busy:pdfBusy,disabled:pdfBusy,onClick:exportNetworkPdf},pdfBusy?'Building PDF…':'Download PDF')),
                pdfError?h(Notice,{type:'error'},pdfError):null,
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
        var pdfBusyState = useState(false); var pdfBusy = pdfBusyState[0]; var setPdfBusy = pdfBusyState[1];
        var pdfErrorState = useState(''); var pdfError = pdfErrorState[0]; var setPdfError = pdfErrorState[1];
        var freshState = useState(false); var fresh = freshState[0]; var setFresh = freshState[1];

        async function load(event) {
            if (event) event.preventDefault(); setBusy(true); setError(''); setResult(null); setScans({}); setScanProgress({done:0,total:0}); rememberSiteUrl(url.trim());
            try { var next = await request('seopc_toolkit_sitemap', { url: url.trim(), limit: limit, fresh: fresh ? '1' : '0' }, props.token); setResult(next); saveReportPart('sitemap', next); }
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
        async function exportSitemapPdf() {
            if (!result) return; setPdfBusy(true); setPdfError('');
            try {
                var total=Number(result.url_count||0), recent=Number(freshness['30d']||0)+Number(freshness['90d']||0), failed=Number(analytics.failed_sitemaps||0);
                var scanScores=Object.values(scans).map(function(x){return x&&x.scores?Number(x.scores.seo):NaN;}).filter(function(x){return !isNaN(x);});
                var avgScan=scanScores.length?Math.round(scanScores.reduce(function(a,b){return a+b;},0)/scanScores.length):null;
                await downloadSectionPdf({title:'Sitemap & Website Architecture Report',shortTitle:'Sitemap QA',subtitle:'Sitemap discovery, URL structure, freshness and sampled page-level SEO checks.',url:result.origin||url,filename:'sitemap-report-'+pdfHost(result.origin||url),scores:[
                    {label:'Sitemap health',value:Math.max(0,100-failed*20),note:failed+' fetch issues'},
                    {label:'Fresh content',value:total?Math.round(recent/total*100):0,note:recent+' URLs updated within 90 days'},
                    {label:'Audit coverage',value:total?Math.round(scanned/total*100):0,note:scanned+' of '+total+' URLs scanned'},
                    avgScan===null?null:{label:'Sample SEO',value:avgScan,note:scanScores.length+' scanned pages'}
                ],sections:[
                    {title:'Architecture summary',rows:[['URLs discovered',total],['Sitemap files',(result.sitemaps||[]).length],['Map limit reached',result.truncated?'Yes':'No'],['Pages with sampled issues',issuePages],['Strong sampled pages',strongPages]]},
                    {title:'Sitemap sources',bullets:(result.sitemaps||[]).slice(0,20).map(function(m){return (m.url||'sitemap')+' - '+(m.status?('HTTP '+m.status):'Failed')+' - '+(m.count||0)+' URLs - '+(m.elapsed_ms||0)+' ms';})},
                    {title:'Top URL families',rows:pathTypes.map(function(e){return ['/'+e[0],e[1]+' URLs'];})},
                    {title:'Sampled page issues',bullets:Object.keys(scans).slice(0,25).reduce(function(out,key){var x=scans[key]||{},issues=x.seo&&x.seo.issues||[];if(issues.length)out.push(key+' - '+issues.join(' | '));else if(x.error)out.push(key+' - '+x.error);return out;},[]),note:'This sitemap report summarizes discovery and the pages scanned in this session. It is not a substitute for a full crawler when thousands of URLs need validation.'}
                ]});
            } catch(e) { setPdfError(e.message||'Could not create the sitemap PDF.'); }
            finally { setPdfBusy(false); }
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
                h('div',{className:'seopc-report-toolbar'},h('div',null,h('strong',null,(result.url_count||0)+' URLs discovered'),h('span',null,(result.sitemaps||[]).length+' sitemap files'+(result.truncated?' · map limit reached':''))),h('div',{className:'seopc-inline-actions'},h(Button,{variant:'primary',busy:scanBusy,disabled:scanBusy||!(result.urls||[]).length,onClick:quickScan},scanBusy?'Scanning '+scanProgress.done+'/'+scanProgress.total:'Scan first 25'),h(Button,{variant:'light',onClick:exportSitemapCsv},'Export CSV'),h(Button,{variant:'primary',busy:pdfBusy,disabled:pdfBusy,onClick:exportSitemapPdf},pdfBusy?'Building PDF…':'Download PDF'))),
                pdfError?h(Notice,{type:'error'},pdfError):null,
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
        var pdfBusyState=useState(false);var pdfBusy=pdfBusyState[0];var setPdfBusy=pdfBusyState[1];
        var pdfErrorState=useState('');var pdfError=pdfErrorState[0];var setPdfError=pdfErrorState[1];
        var freshState=useState(false);var fresh=freshState[0];var setFresh=freshState[1];
        var allPlatforms=['instagram','facebook','tiktok','linkedin','youtube','x','pinterest','threads'];
        async function scanProfiles(profiles){setProfileAudit(null);if(!profiles||!Object.keys(profiles).length)return;try{setProfileAudit(await request('seopc_toolkit_social_profiles',{profiles:JSON.stringify(profiles)},props.token));}catch(err){if(err.status===403)props.onExpired();}}
        async function run(event){event.preventDefault();setBusy(true);setError('');setData(null);setProfileAudit(null);rememberSiteUrl(url.trim());try{var next=await request('seopc_toolkit_analyze',{url:url.trim(),fresh:fresh?'1':'0'},props.token);setData(next);saveReportPart('social',{audit:next,profiles:null});var social=next&&next.seo&&next.seo.social;if(social)scanProfiles(social.profiles||{});}catch(err){setError(err.message);if(err.status===403)props.onExpired();}finally{setBusy(false);}}
        var social=data&&data.seo&&data.seo.social;var profiles=social?Object.entries(social.profiles||{}):[];var sameAs=social?(social.same_as||[]):[];
        var schemaCoverage=profiles.length?Math.round((profiles.length-(social.linked_not_in_schema||[]).length)/profiles.length*100):0;
        var metaFields=social?[social.open_graph&&social.open_graph.title,social.open_graph&&social.open_graph.description,social.open_graph&&social.open_graph.image,social.open_graph&&social.open_graph.site_name,social.twitter&&social.twitter.card,social.twitter&&social.twitter.image]:[];
        var metaScore=metaFields.length?Math.round(metaFields.filter(Boolean).length/metaFields.length*100):0;
        var linkedMap={};profiles.forEach(function(e){linkedMap[e[0]]=e[1];});var audited={};(profileAudit&&profileAudit.items||[]).forEach(function(item){audited[item.platform]=item;});
        var missingPlatforms=allPlatforms.filter(function(p){return !linkedMap[p];});
        var profileScore=profileAudit&&profileAudit.average_profile_score;
        async function exportSocialPdf(){
            if(!data||!social)return;setPdfBusy(true);setPdfError('');
            try{
                var recs=(social.recommendations||[]).concat(profileAudit&&profileAudit.recommendations||[]);
                await downloadSectionPdf({title:'Social & Brand Presence Report',shortTitle:'Social QA',subtitle:'Brand footprint, social profile coverage, entity consistency and link-sharing metadata.',url:data.url||url,filename:'social-brand-report-'+pdfHost(data.url||url),scores:[
                    {label:'Social readiness',value:social.score,note:'Website metadata and identity signals'},
                    {label:'Profile coverage',value:Math.min(100,profiles.length/6*100),note:profiles.length+' linked profiles'},
                    {label:'Schema identity',value:schemaCoverage,note:sameAs.length+' Organization sameAs URLs'},
                    {label:'Share metadata',value:metaScore,note:'Open Graph and X/Twitter fields'},
                    profileScore===null||profileScore===undefined?null:{label:'Profile quality',value:profileScore,note:'Only publicly verifiable profiles'}
                ],sections:[
                    {title:'Linked social profiles',rows:profiles.length?profiles.map(function(e){var a=audited[e[0]];return [e[0],a?(a.blocked?'Linked - verification blocked':('Linked - HTTP '+a.status)):'Linked'];}):[['Profiles','No major profile links detected']]},
                    {title:'Entity consistency',rows:[['Organization sameAs',sameAs.length],['Linked profiles missing from schema',(social.linked_not_in_schema||[]).join(', ')||'None'],['Major networks not linked',missingPlatforms.join(', ')||'None'],['OG site name',social.open_graph&&social.open_graph.site_name||'Missing'],['X/Twitter site',social.twitter&&social.twitter.site||'Missing']]},
                    {title:'Share preview metadata',rows:[['OG title',social.open_graph&&social.open_graph.title||'Missing'],['OG description',social.open_graph&&social.open_graph.description||'Missing'],['OG image',social.open_graph&&social.open_graph.image||'Missing'],['X card',social.twitter&&social.twitter.card||'Missing'],['X image',social.twitter&&social.twitter.image||social.open_graph&&social.open_graph.image||'Missing']]},
                    {title:'Recommendations',bullets:recs.length?recs:['No major social sharing or brand-identity gaps were returned by the automated checks.'],note:'Some social networks block automated requests or require login. A blocked profile is treated as unverified, not automatically as a failed profile.'}
                ]});
            }catch(e){setPdfError(e.message||'Could not create the social PDF.');}finally{setPdfBusy(false);}
        }
        return h('div',null,
            h('div',{className:'seopc-hero-panel seopc-hero-social'},h('div',null,h('span',{className:'seopc-eyebrow'},'Brand footprint audit'),h('h2',null,'Social presence, entity consistency & share quality'),h('p',null,'Audit linked profiles, public metadata, structured-data identity, brand handles and share creatives without pretending blocked social networks are failures.')),h('form',{className:'seopc-url-form',onSubmit:run},h('div',{className:'seopc-input-grow'},h('label',null,'Company website'),h('input',{type:'url',inputMode:'url',value:url,required:true,placeholder:'https://example.com',onChange:function(e){setUrl(e.target.value);}})),h(AjaxFreshToggle,{checked:fresh,onChange:setFresh}),h(Button,{type:'submit',variant:'primary',busy:busy,disabled:busy||!url.trim()},busy?'Auditing…':'Audit brand'))),
            error?h(Notice,{type:'error'},error):null,
            data&&social?h('div',{className:'seopc-results'},
                h('div',{className:'seopc-report-toolbar'},h('div',null,h('strong',null,'Social & brand report'),h('span',null,'Export the results on this page as a separate client-ready PDF.')),h(Button,{variant:'primary',busy:pdfBusy,disabled:pdfBusy,onClick:exportSocialPdf},pdfBusy?'Building PDF…':'Download PDF')),
                pdfError?h(Notice,{type:'error'},pdfError):null,
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
        var freshState=useState(false);var fresh=freshState[0];var setFresh=freshState[1];
        var busyState=useState(false);var busy=busyState[0];var setBusy=busyState[1];
        var pagesBusyState=useState(false);var pagesBusy=pagesBusyState[0];var setPagesBusy=pagesBusyState[1];
        var pagesState=useState([]);var pages=pagesState[0];var setPages=pagesState[1];
        var selectedState=useState({});var selected=selectedState[0];var setSelected=selectedState[1];
        var pageFilterState=useState('');var pageFilter=pageFilterState[0];var setPageFilter=pageFilterState[1];
        var typeFilterState=useState('all');var typeFilter=typeFilterState[0];var setTypeFilter=typeFilterState[1];
        var pdfScopeState=useState('selected');var pdfScope=pdfScopeState[0];var setPdfScope=pdfScopeState[1];
        var pdfQualityState=useState('balanced');var pdfQuality=pdfQualityState[0];var setPdfQuality=pdfQualityState[1];
        var pdfDetailState=useState('visual');var pdfDetail=pdfDetailState[0];var setPdfDetail=pdfDetailState[1];
        var auditState=useState(null);var audit=auditState[0];var setAudit=auditState[1];
        var previewState=useState(null);var preview=previewState[0];var setPreview=previewState[1];
        var previewKeyState=useState(0);var previewKey=previewKeyState[0];var setPreviewKey=previewKeyState[1];
        var pdfBusyState=useState('');var pdfBusy=pdfBusyState[0];var setPdfBusy=pdfBusyState[1];
        var pdfProgressState=useState('');var pdfProgress=pdfProgressState[0];var setPdfProgress=pdfProgressState[1];
        var errorState=useState('');var error=errorState[0];var setError=errorState[1];
        var pdfErrorState=useState('');var pdfError=pdfErrorState[0];var setPdfError=pdfErrorState[1];
        var deviceState=useState({phone:true,tablet:true,laptop:true,desktop:true,iphone_se:false,iphone_max:false,pixel:false,ipad_mini:false,ipad_pro:false,laptop_hd:false,qhd:false});var deviceSelection=deviceState[0];var setDeviceSelection=deviceState[1];
        var auditCacheRef=useRef({});
        var pdfCancelRef=useRef(false);
        var imageCacheRef=useRef({});
        var imageGalleryState=useState([]);var imageGallery=imageGalleryState[0];var setImageGallery=imageGalleryState[1];
        var manualState=useState({'Header and navigation fit without overlap':false,'Hero artwork and key imagery are not cropped':false,'No horizontal scrolling or clipped content':false,'Buttons and links have comfortable touch spacing':false,'Forms remain readable and usable on mobile':false,'Sticky/fixed UI does not cover important content':false,'Typography remains readable at phone and tablet sizes':false,'Footer and cookie/PWA overlays do not collide':false});var manualChecks=manualState[0];var setManualChecks=manualState[1];
        var devices=[{key:'phone',name:'iPhone 15',width:393,height:852},{key:'tablet',name:'iPad Air',width:820,height:1180},{key:'laptop',name:'MacBook Air 13',width:1440,height:900},{key:'desktop',name:'Desktop HD',width:1920,height:1080},{key:'iphone_se',name:'iPhone SE',width:375,height:667},{key:'iphone_max',name:'iPhone 15 Pro Max',width:430,height:932},{key:'pixel',name:'Pixel 8',width:412,height:915},{key:'ipad_mini',name:'iPad mini',width:768,height:1024},{key:'ipad_pro',name:'iPad Pro 12.9',width:1024,height:1366},{key:'laptop_hd',name:'Laptop HD',width:1366,height:768},{key:'qhd',name:'Desktop QHD',width:2560,height:1440}];
        var typeDefs=[['all','All'],['page','Pages'],['post','Posts'],['product','Products'],['product_category','Product categories'],['category','Categories'],['tag','Tags'],['author','Authors'],['other','Other']];
        function classifyPage(p){if(p.content_type)return p.content_type;var group=String(p.group||'').toLowerCase(),path=String(p.path||'').toLowerCase();if(group.indexOf('product_cat')>=0||group.indexOf('product-category')>=0||path.indexOf('/product-category/')===0)return 'product_category';if(group.indexOf('product')>=0||path.indexOf('/product/')===0)return 'product';if(group.indexOf('page')>=0)return 'page';if(group.indexOf('post')>=0)return 'post';if(group.indexOf('category')>=0||path.indexOf('/category/')===0)return 'category';if(group.indexOf('tag')>=0||path.indexOf('/tag/')===0)return 'tag';if(group.indexOf('author')>=0||path.indexOf('/author/')===0)return 'author';return path==='/'||path===''?'page':'other';}
        function normalizePage(x){return {url:x.url,path:x.path||'',lastmod:x.lastmod||'',group:x.group||'',content_type:x.content_type||classifyPage(x)};}
        async function loadPages(){if(!url.trim())return;setPagesBusy(true);setError('');rememberSiteUrl(url.trim());try{var data=await request('seopc_toolkit_sitemap',{url:url.trim(),limit:'500',fresh:fresh?'1':'0'},props.token);var list=(data.urls||[]).map(normalizePage);if(!list.some(function(x){return x.url.replace(/\/$/,'')===url.trim().replace(/\/$/,'');}))list.unshift(normalizePage({url:url.trim(),path:'/',lastmod:'',content_type:'page'}));setPages(list);var pick={};list.slice(0,Math.min(12,list.length)).forEach(function(x){pick[x.url]=true;});setSelected(pick);setTypeFilter('all');setPdfScope('selected');}catch(err){setError('Could not load website pages: '+err.message);if(err.status===403)props.onExpired();}finally{setPagesBusy(false);}}
        async function run(e){if(e)e.preventDefault();if(!url.trim())return;setBusy(true);setError('');rememberSiteUrl(url.trim());try{var results=await Promise.allSettled([request('seopc_toolkit_analyze',{url:url.trim(),fresh:fresh?'1':'0'},props.token),request('seopc_toolkit_design_preview',{url:url.trim(),fresh:fresh?'1':'0'},props.token)]);if(results[0].status==='rejected')throw results[0].reason;setAudit(results[0].value);if(results[1].status==='fulfilled'){setPreview(results[1].value);setPreviewKey(function(v){return v+1;});}else{setPreview(null);setError('The automated audit completed, but the visual preview could not be built: '+results[1].reason.message);}}catch(err){setError(err.message);if(err.status===403)props.onExpired();}finally{setBusy(false);}}
        function clearScreenshotGallery(){if(pdfBusy)return;Object.keys(imageCacheRef.current).forEach(function(k){var item=imageCacheRef.current[k];if(item&&item.blobUrl)try{URL.revokeObjectURL(item.blobUrl);}catch(e){}});imageCacheRef.current={};setImageGallery([]);setPdfProgress('Generated screenshots cleared.');}
        function clearCurrentDeviceImages(){if(pdfBusy)return;var keys={};devices.filter(function(d){return deviceSelection[d.key];}).forEach(function(d){keys[d.key+':']=true;});Object.keys(imageCacheRef.current).forEach(function(k){if(Object.keys(keys).some(function(prefix){return k.indexOf(prefix)===0;})){var item=imageCacheRef.current[k];if(item&&item.blobUrl)try{URL.revokeObjectURL(item.blobUrl);}catch(e){}delete imageCacheRef.current[k];}});setImageGallery(function(old){return old.filter(function(item){return !Object.keys(keys).some(function(prefix){return item.key.indexOf(prefix)===0;});});});setPdfProgress('Selected device screenshots cleared.');}
        function clearPreviewCache(){if(pdfBusy)return;auditCacheRef.current={};setAudit(null);setPreview(null);setPdfProgress('Local website preview cache cleared. Server cache may remain until expiry.');}
        async function freshScanPages(){if(pdfBusy||!url.trim())return;clearPreviewCache();setFresh(true);setPagesBusy(true);setError('');try{var data=await request('seopc_toolkit_sitemap',{url:url.trim(),limit:'500',fresh:'1'},props.token);var list=(data.urls||[]).map(normalizePage);setPages(list);setPdfProgress('Fresh website scan complete. Existing screenshots preserved.');}catch(err){setError('Fresh scan failed: '+err.message);}finally{setPagesBusy(false);}}
        function toggleSelected(pageUrl){setSelected(function(old){var next=Object.assign({},old);next[pageUrl]=!next[pageUrl];return next;});}
        function setSelectionFor(list,value){setSelected(function(old){var next=Object.assign({},old);list.forEach(function(p){next[p.url]=value;});return next;});}
        function toggleManual(label){setManualChecks(function(old){var next=Object.assign({},old);next[label]=!next[label];return next;});}
        function toggleDevice(key){setDeviceSelection(function(old){var next=Object.assign({},old);next[key]=!next[key];return next;});}
        function pageName(p){try{var u=new URL(p.url);return u.pathname==='/'?'Home':decodeURIComponent(u.pathname.replace(/^\/+|\/+$/g,''))||'Home';}catch(e){return p.url;}}
        var typeCounts={};pages.forEach(function(p){var t=classifyPage(p);typeCounts[t]=(typeCounts[t]||0)+1;});
        var filteredPages=pages.filter(function(p){var q=pageFilter.trim().toLowerCase(),typeOk=typeFilter==='all'||classifyPage(p)===typeFilter;return typeOk&&(!q||p.url.toLowerCase().indexOf(q)>=0||String(p.path||'').toLowerCase().indexOf(q)>=0);});
        var selectedCount=pages.filter(function(p){return !!selected[p.url];}).length;
        function scopePages(){if(pdfScope==='selected'&&typeFilter!=='all')return filteredPages.filter(function(p){return !!selected[p.url];});if(pdfScope==='all')return pages.slice();if(pdfScope==='visible')return filteredPages.slice();if(pdfScope.indexOf('type:')===0){var t=pdfScope.slice(5);return pages.filter(function(p){return classifyPage(p)===t;});}return pages.filter(function(p){return !!selected[p.url];});}
        var scopedCount=scopePages().length;
        var activeDeviceCount=devices.filter(function(d){return !!deviceSelection[d.key];}).length;
        var totalCaptureCount=scopedCount*activeDeviceCount;
        async function mapLimit(items,limit,worker){var out=new Array(items.length),next=0,done=0;async function runner(){while(true){var i=next++;if(i>=items.length)return;out[i]=await worker(items[i],i);done++;setPdfProgress('Testing pages '+done+'/'+items.length+' · cached results are reused');}}var jobs=[];for(var j=0;j<Math.min(limit,items.length);j++)jobs.push(runner());await Promise.all(jobs);return out;}
        async function testReportPage(p,includeAudit){var detailKey=includeAudit?'full':'visual';var cacheKey=(fresh?'fresh:':'cached:')+detailKey+':'+p.url;if(!fresh&&auditCacheRef.current[cacheKey])return auditCacheRef.current[cacheKey];var previewReq=request('seopc_toolkit_design_preview',{url:p.url,fresh:fresh?'1':'0'},props.token);var item;if(includeAudit){var settled=await Promise.allSettled([request('seopc_toolkit_analyze',{url:p.url,fresh:fresh?'1':'0'},props.token),previewReq]);item={url:p.url,content_type:classifyPage(p),audit:settled[0].status==='fulfilled'?settled[0].value:{url:p.url,design:{score:0,issues:['Could not complete automated design analysis for this page: '+settled[0].reason.message]}},preview:settled[1].status==='fulfilled'?settled[1].value:null};}else{var previewResult=await previewReq;item={url:p.url,content_type:classifyPage(p),audit:null,preview:previewResult};}if(!fresh)auditCacheRef.current[cacheKey]=item;return item;}
        async function saveDevicePdf(device){
            var chosen=scopePages();
            if(!chosen.length){setPdfError('Select at least one page.');return;}
            if(!device){setPdfError('Choose a device size.');return;}
            var includeAudit=pdfDetail==='full';
            var keyPrefix=device.key+':'+pdfQuality+':'+(fresh?'fresh':'cached')+':';
            pdfCancelRef.current=false;
            setPdfBusy(device.key);setPdfError('');
            setImageGallery(function(old){var known={};old.forEach(function(item){known[item.key]=item;});chosen.forEach(function(p){var key=keyPrefix+p.url;var entry=imageCacheRef.current[key];known[key]={url:p.url,label:pageName(p),device:device.name,key:key,ready:!!entry,blobUrl:entry?entry.blobUrl:null};});return Object.keys(known).map(function(key){return known[key];});});
            try{
                for(var i=0;i<chosen.length;i++){
                    if(pdfCancelRef.current)throw new Error('Report cancelled.');
                    var page=chosen[i],key=keyPrefix+page.url;
                    if(!imageCacheRef.current[key]){
                        setPdfProgress('Preparing '+device.name+' · '+(i+1)+'/'+chosen.length+' · '+pageName(page));
                        var report=await testReportPage(page,includeAudit);
                        if(!report.preview||!report.preview.html)throw new Error('No snapshot available for '+pageName(page));
                        var capture=await capturePreviewHtml(report.preview.html,device,pdfQuality);
                        if(!capture)throw new Error('Screenshot failed for '+pageName(page));
                        var composed=await composeFramedDeviceScreenshot(capture,device,pageName(page),{quality:pdfQuality});
                        if(!composed||!composed.blob)throw new Error('Image encoding failed for '+pageName(page));
                        imageCacheRef.current[key]={blob:composed.blob,blobUrl:URL.createObjectURL(composed.blob),type:composed.type};
                    }
                    var cache=imageCacheRef.current[key];
                    setImageGallery(function(old){return old.map(function(item){return item.key===key?Object.assign({},item,{ready:true,blobUrl:cache.blobUrl}):item;});});
                    // Yield to the browser so thumbnails and Cancel remain responsive.
                    await new Promise(function(resolve){setTimeout(resolve,35);});
                }
                setPdfProgress('Screenshots ready · '+chosen.length+' image'+(chosen.length===1?'':'s')+'. '+(chosen.length===1?'Download the individual image when needed.':'Download a device ZIP when needed.'));
            }catch(e){if(e.message!=='Report cancelled.')setPdfError(e.message||'Could not generate images.');else setPdfProgress('Generation cancelled. Already completed images remain available.');}
            finally{setPdfBusy('');}
        }
        function downloadCachedDeviceImage(device, page){
            var prefix=device.key+':'+pdfQuality+':'+(fresh?'fresh':'cached')+':';
            var entry=imageCacheRef.current[prefix+page.url];
            if(!entry||!entry.blob){setPdfError('Generate this device screenshot first.');return;}
            var extension=entry.type==='image/avif'?'avif':entry.type==='image/webp'?'webp':'png';
            downloadBlob(entry.blob,'design-qa-'+slugifyLabel(pageName(page),'page')+'-'+slugifyLabel(device.name,'device')+'.'+extension);
        }
        function downloadGalleryImage(item){
            var entry=imageCacheRef.current[item.key];
            if(!entry||!entry.blob){setPdfError('This screenshot is not available.');return;}
            var extension=entry.type==='image/avif'?'avif':entry.type==='image/webp'?'webp':'png';
            downloadBlob(entry.blob,'design-qa-'+slugifyLabel(item.label,'page')+'-'+slugifyLabel(item.device,'device')+'.'+extension);
        }
        async function downloadCachedDeviceZip(device){
            var chosen=scopePages();var prefix=device.key+':'+pdfQuality+':'+(fresh?'fresh':'cached')+':';
            var entries=chosen.map(function(p){return {page:p,entry:imageCacheRef.current[prefix+p.url]};});
            if(entries.some(function(x){return !x.entry;})){setPdfError('Generate this device first. Some screenshots are missing.');return;}
            setPdfError('');setPdfBusy(device.key);
            try{
                await ensureZipLibrary();
                var zip=new window.JSZip(),ext={'image/avif':'avif','image/webp':'webp','image/png':'png'};
                entries.forEach(function(item,i){zip.file(String(i+1).padStart(2,'0')+'-'+slugifyLabel(pageName(item.page),'page')+'-'+slugifyLabel(device.name,'device')+'.'+(ext[item.entry.type]||'png'),item.entry.blob);});
                zip.file('README.txt','Website Growth Toolkit – responsive screenshots\nDevice: '+device.name+' ('+device.width+'x'+device.height+')\nAll screenshots were generated in the browser. No ZIP is uploaded to the server.');
                var result=await zip.generateAsync({type:'blob',compression:'STORE'});
                downloadBlob(result,'design-qa-'+slugifyLabel(device.name,'device')+'-'+slugifyLabel(pdfHost(url),'website')+'.zip');
            }catch(e){setPdfError(e.message||'ZIP export failed.');}
            finally{setPdfBusy('');}
        }
        function cancelPdf(){pdfCancelRef.current=true;setPdfProgress('Stopping after the current capture…');}
        var design=audit&&audit.design,safeUrl=audit&&audit.url;
        function signalCard(label,value,note,tone){return h('div',{className:'seopc-design-signal is-'+(tone||'neutral')},h('strong',null,value),h('span',null,label),note?h('small',null,note):null);}
        return h('div',null,
            h('div',{className:'seopc-hero-panel seopc-hero-design'},h('div',null,h('span',{className:'seopc-eyebrow'},'Design QA'),h('h2',null,'Multi-page responsive QA & visual export'),h('p',null,'Discover the website, filter by content type, select whole groups or individual URLs, then export framed screenshot packs as compact AVIF image ZIPs.')),h('form',{className:'seopc-url-form',onSubmit:function(e){e.preventDefault();loadPages();}},h('div',{className:'seopc-input-grow'},h('label',null,'Website URL'),h('input',{type:'url',value:url,required:true,placeholder:'https://example.com',onChange:function(e){setUrl(e.target.value);}})),h(AjaxFreshToggle,{checked:fresh,onChange:setFresh}),h(Button,{type:'submit',variant:'primary',busy:pagesBusy,disabled:pagesBusy||!url.trim()},pagesBusy?'Loading pages…':'1. Load website pages'))),
            error?h(Notice,{type:error.indexOf('completed')>=0?'warning':'error'},error):null,
            h('div',{className:'seopc-external-test-links'},h('span',null,'Real-browser links:'),h('a',{target:'_blank',rel:'noopener noreferrer',href:'https://responsivetool.com/?url='+encodeURIComponent((safeUrl||url.trim())||'')+'&phone=iphone-15&desktop=macbook-air-13&ipad=ipad-air'},'ResponsiveTool'),h('a',{target:'_blank',rel:'noopener noreferrer',href:'https://responsivly.com/'},'Responsivly'),h('a',{target:'_blank',rel:'noopener noreferrer',href:'https://wavespeed.ai/free-tools/responsive-tester'},'WaveSpeed'),h('a',{target:'_blank',rel:'noopener noreferrer',href:'https://www.eazystudio.com/device-preview-tool/'},'EazyStudio'),h('a',{target:'_blank',rel:'noopener noreferrer',href:'https://www.browserstack.com/live'},'BrowserStack')),
            pages.length?h(Section,{title:'2. Choose pages for screenshots',subtitle:'Filter by WordPress/WooCommerce content type, select complete groups, or tick individual URLs.'},
                h('div',{className:'seopc-page-type-filters'},typeDefs.map(function(def){var count=def[0]==='all'?pages.length:(typeCounts[def[0]]||0);return h('button',{type:'button',key:def[0],className:typeFilter===def[0]?'is-active':'',onClick:function(){setTypeFilter(def[0]);}},h('span',null,def[1]),h('b',null,count));})),
                h('div',{className:'seopc-page-picker-toolbar'},h('input',{type:'search',value:pageFilter,placeholder:'Filter URLs or paths…',onChange:function(e){setPageFilter(e.target.value);}}),h('div',{className:'seopc-inline-actions'},h(Button,{variant:'light',onClick:function(){setSelectionFor(pages,true);}},'Select all'),h(Button,{variant:'light',onClick:function(){setSelectionFor(pages,false);}},'Unselect all'),h(Button,{variant:'light',onClick:function(){setSelectionFor(filteredPages,true);}},'Select filtered'),h(Button,{variant:'light',onClick:function(){setSelectionFor(filteredPages,false);}},'Clear filtered'),h('strong',null,selectedCount+' selected / '+pages.length+' found'))),
                h('div',{className:'seopc-page-picker'},filteredPages.map(function(p){return h('label',{key:p.url,className:selected[p.url]?'is-selected':''},h('input',{type:'checkbox',checked:!!selected[p.url],onChange:function(){toggleSelected(p.url);}}),h('span',{className:'seopc-page-picker-copy'},h('strong',null,pageName(p)),h('small',null,p.url)),h('span',{className:'seopc-page-type-badge'},(typeDefs.find(function(d){return d[0]===classifyPage(p);})||['','Other'])[1]),p.lastmod?h('time',null,p.lastmod.slice(0,10)):null);})),
                h('div',{className:'seopc-report-options'},h('label',null,h('span',null,'Export scope'),h('select',{value:pdfScope,onChange:function(e){setPdfScope(e.target.value);}},h('option',{value:'selected'},'Selected in current filter ('+(typeFilter==='all'?selectedCount:filteredPages.filter(function(p){return !!selected[p.url];}).length)+')'),h('option',{value:'visible'},'Current filtered view ('+filteredPages.length+')'),h('option',{value:'all'},'All discovered pages ('+pages.length+')'),typeDefs.filter(function(d){return d[0]!=='all'&&(typeCounts[d[0]]||0)>0;}).map(function(d){return h('option',{key:d[0],value:'type:'+d[0]},d[1]+' only ('+(typeCounts[d[0]]||0)+')');}))),h('label',null,h('span',null,'Capture quality'),h('select',{value:pdfQuality,onChange:function(e){setPdfQuality(e.target.value);}},h('option',{value:'fast'},'Fast · smaller images'),h('option',{value:'balanced'},'Smart · recommended'),h('option',{value:'high'},'High · client quality'),h('option',{value:'print'},'Print · sharpest / slower'))),h('label',null,h('span',null,'Export content'),h('select',{value:pdfDetail,onChange:function(e){setPdfDetail(e.target.value);}},h('option',{value:'visual'},'Visual only · fastest'),h('option',{value:'full'},'Visual + QA findings'))),h('div',{className:'seopc-scope-count'},h('strong',null,scopedCount),h('span',null,'pages will be included in the current export'))),
                h('div',{className:'seopc-device-choice'},h('strong',null,'Screenshot device views'),h('small',null,'Choose the device screenshot packs you need. Each device is exported as a separate ZIP with framed AVIF images for better speed, stability and visual quality.'),devices.map(function(d){return h('label',{key:d.key},h('input',{type:'checkbox',checked:!!deviceSelection[d.key],onChange:function(){toggleDevice(d.key);}}),h('span',null,d.name+' '+d.width+'×'+d.height));})),h('div',{className:'seopc-capture-estimate'},h('strong',null,scopedCount+' screenshots per device export'),h('span',null,activeDeviceCount+' device views selected. One page downloads as an image; multiple pages download as a ZIP.')),
                h('div',{className:'seopc-report-toolbar seopc-report-toolbar-spaced'},h('div',null,h('strong',null,'3. Generate and preview device screenshots'),h('span',null,'Generate one device at a time. Download a single image directly, or use ZIP for multiple pages. Completed screenshots remain available below.')),h('div',{className:'seopc-report-actions seopc-device-pdf-actions'},devices.filter(function(d){return !!deviceSelection[d.key];}).map(function(d){var prefix=d.key+':'+pdfQuality+':'+(fresh?'fresh':'cached')+':';var ready=scopePages().length>0&&scopePages().every(function(p){return !!imageCacheRef.current[prefix+p.url];});return h('div',{key:d.key,className:'seopc-device-export-buttons'},h(Button,{variant:'primary',onClick:function(){saveDevicePdf(d);},busy:pdfBusy===d.key,disabled:!!pdfBusy||scopedCount===0},pdfBusy===d.key?'Generating '+d.name+'…':ready?'View / refresh · '+d.name:'Generate · '+d.name),h(Button,{variant:'light',onClick:function(){if(scopePages().length===1){downloadCachedDeviceImage(d,scopePages()[0]);}else{downloadCachedDeviceZip(d);}},disabled:!!pdfBusy||!ready},(scopedCount===1?'Download image · ':'Download ZIP · ')+d.name));}),pdfBusy?h(Button,{onClick:cancelPdf},'Cancel'):null)),
                h('div',{className:'seopc-inline-actions seopc-gallery-maintenance'},h(Button,{variant:'light',disabled:!!pdfBusy,onClick:clearScreenshotGallery},'Clear all screenshots'),h(Button,{variant:'light',disabled:!!pdfBusy,onClick:clearCurrentDeviceImages},'Clear selected devices'),h(Button,{variant:'light',disabled:!!pdfBusy,onClick:clearPreviewCache},'Clear preview cache'),h(Button,{variant:'primary',disabled:!!pdfBusy||pagesBusy||!url.trim(),onClick:freshScanPages},pagesBusy?'Scanning…':'Fresh scan website')),
                imageGallery.length?h('div',{className:'seopc-image-gallery'},h('h3',null,'Generated screenshots · '+imageGallery.filter(function(x){return x.ready;}).length+' ready across devices'),h('div',{className:'seopc-image-gallery-grid'},imageGallery.map(function(item){return h('div',{key:item.key,className:'seopc-image-gallery-item'},item.ready?h('a',{href:item.blobUrl,target:'_blank',rel:'noopener noreferrer'},h('img',{src:item.blobUrl,loading:'lazy',decoding:'async',alt:item.label+' '+item.device})):h('div',{className:'seopc-image-gallery-pending'},'Waiting…'),h('strong',null,item.label),h('small',null,item.device),item.ready?h('button',{type:'button',className:'seopc-gallery-image-download',onClick:function(){downloadGalleryImage(item);}},'Download image'):null);}))):null,
                pdfProgress?h(Notice,{type:'info'},pdfProgress):null,pdfError?h(Notice,{type:'error'},pdfError):null
            ):h(Section,{title:'Page discovery'},h(Notice,{type:'info'},'Enter the website URL and click “Load website pages”. The sitemap list will appear here with content-type filters and PDF controls.')),
            h(Section,{title:'Single-page live QA',subtitle:'Use this when you want to inspect one page interactively before generating device screenshots.'},h('form',{className:'seopc-url-form seopc-url-form-compact',onSubmit:run},h('div',{className:'seopc-input-grow seopc-qa-field'},h('label',{htmlFor:'seopc-qa-page-url'},'Page URL'),h('input',{id:'seopc-qa-page-url',type:'url',value:url,required:true,onChange:function(e){setUrl(e.target.value);}})),h('div',{className:'seopc-qa-field seopc-qa-width'},h('label',{htmlFor:'seopc-qa-viewport'},'Preview width'),h('select',{id:'seopc-qa-viewport',value:viewport,onChange:function(e){setViewport(Number(e.target.value));}},[375,393,430,768,820,1024,1366,1440,1920].map(function(v){return h('option',{key:v,value:v},v+' px');}))),h(Button,{type:'submit',variant:'light',busy:busy,disabled:busy||!url.trim()},busy?'Testing…':'Run single-page QA'))),
            audit&&design?h('div',{className:'seopc-results'},
                h('div',{className:'seopc-design-scorebar'},h(ScoreCard,{score:design.score,label:'Design QA'}),h('div',{className:'seopc-design-kpis'},h('div',null,h('strong',null,design.fixed_width_count||0),h('span',null,'Fixed-width risks')),h('div',null,h('strong',null,design.missing_image_dimensions||0),h('span',null,'Missing dimensions')),h('div',null,h('strong',null,design.unlabelled_controls||0),h('span',null,'Unlabelled controls')),h('div',null,h('strong',null,design.duplicate_id_count||0),h('span',null,'Duplicate IDs')))),
                h(Section,{title:'Automated responsive & UX signals'},h('div',{className:'seopc-design-signal-grid'},signalCard('Viewport meta',design.viewport?'Present':'Missing',design.viewport||'Required for mobile layouts',design.viewport?'good':'bad'),signalCard('Responsive images',design.responsive_image_count||0,(design.missing_srcset_count||0)+' without srcset/sizes',(design.missing_srcset_count||0)>3?'warn':'good'),signalCard('Fixed / sticky UI',(design.fixed_position_count||0)+' / '+(design.sticky_position_count||0),'Review overlays at small heights',(design.fixed_position_count||0)>3?'warn':'neutral'),signalCard('Unnamed actions',design.unnamed_action_count||0,'Links/buttons without accessible names',(design.unnamed_action_count||0)?'bad':'good'))),
                preview&&preview.html?h(Section,{title:'Current page responsive preview'},h('div',{className:'seopc-browser-frame'},h('div',{className:'seopc-browser-bar'},h('span'),h('span'),h('span'),h('b',null,viewport+'px - server snapshot')),h('div',{className:'seopc-browser-stage seopc-browser-stage-srcdoc'},h('iframe',{key:previewKey,srcDoc:preview.html,title:'Website design preview snapshot',sandbox:'allow-same-origin',referrerPolicy:'no-referrer',style:{width:viewport+'px'}})))):null,
                h('div',{className:'seopc-two-col'},h(Section,{title:'Automated findings'},(design.issues||[]).length?h('ul',{className:'seopc-issue-list'},design.issues.map(function(issue,i){return h('li',{key:i},h('span',null,'!'),h('div',null,issue));})):h(Notice,{type:'success'},'No obvious server-HTML design risks were detected.')),h(Section,{title:'Manual visual QA checklist'},h('div',{className:'seopc-design-checklist'},Object.keys(manualChecks).map(function(label){return h('label',{key:label,className:manualChecks[label]?'is-checked':''},h('input',{type:'checkbox',checked:!!manualChecks[label],onChange:function(){toggleManual(label);}}),h('span',null,label));}))))
            ):null)
        ;
    }

    function ImageStudioTab(props) {
        // Browser-only, non-destructive editor: the original image is never uploaded or modified.
        var fileState = useState(null), file = fileState[0], setFile = fileState[1];
        var sizeState = useState({w:0,h:0}), documentSize = sizeState[0], setDocumentSize = sizeState[1];
        var layerState = useState([]), layers = layerState[0], setLayers = layerState[1];
        var selectedState = useState(''), selected = selectedState[0], setSelected = selectedState[1];
        var toolState = useState('move'), tool = toolState[0], setTool = toolState[1];
        var zoomState = useState(100), zoom = zoomState[0], setZoom = zoomState[1];
        var cropState = useState(null), cropSelection = cropState[0], setCropSelection = cropState[1];
        var cropAspectState = useState('free'), cropAspect = cropAspectState[0], setCropAspect = cropAspectState[1];
        var textState = useState('Your text'), textDraft = textState[0], setTextDraft = textState[1];
        var inlineEditorState = useState(null), inlineEditor = inlineEditorState[0], setInlineEditor = inlineEditorState[1];
        var colorState = useState('#e21b86'), brushColor = colorState[0], setBrushColor = colorState[1];
        var brushState = useState(18), brushSize = brushState[0], setBrushSize = brushState[1];
        var shapeFillState = useState('#e21b86'), shapeFill = shapeFillState[0], setShapeFill = shapeFillState[1];
        var strokeState = useState('#ffffff'), strokeColor = strokeState[0], setStrokeColor = strokeState[1];
        var strokeWidthState = useState(0), strokeWidth = strokeWidthState[0], setStrokeWidth = strokeWidthState[1];
        var tabState = useState('layers'), sidebarTab = tabState[0], setSidebarTab = tabState[1];
        var showGridState = useState(false), showGrid = showGridState[0], setShowGrid = showGridState[1];
        var transparentState = useState(true), transparent = transparentState[0], setTransparent = transparentState[1];
        var bgState = useState('#ffffff'), background = bgState[0], setBackground = bgState[1];
        var toleranceState = useState(42), tolerance = toleranceState[0], setTolerance = toleranceState[1];
        var formatState = useState('image/webp'), format = formatState[0], setFormat = formatState[1];
        var qualityState = useState(.9), quality = qualityState[0], setQuality = qualityState[1];
        var exportScaleState = useState(100), exportScale = exportScaleState[0], setExportScale = exportScaleState[1];
        var errorState = useState(''), error = errorState[0], setError = errorState[1];
        var resizeState = useState(null), resizeDraft = resizeState[0], setResizeDraft = resizeState[1];
        var newState = useState(null), newDraft = newState[0], setNewDraft = newState[1];
        var busyState = useState(false), busy = busyState[0], setBusy = busyState[1];
        var historyState = useState({undo:0,redo:0}), historyCount = historyState[0], setHistoryCount = historyState[1];
        var canvasRef = useRef(null), stageRef = useRef(null), fileInputRef = useRef(null), overlayInputRef = useRef(null), inlineInputRef = useRef(null), brushCursorRef = useRef(null);
        var layersRef = useRef([]), sizeRef = useRef({w:0,h:0}), interactionRef = useRef(null), historyRef = useRef({past:[],future:[],group:'',at:0});
        var cropRef = useRef(null), selectedRef = useRef(''), toolRef = useRef('move');
        var paintCacheRef = useRef({}), keyCacheRef = useRef(null), loadCounterRef = useRef(0), hoverRef = useRef(null);

        function setLayerData(next) { layersRef.current = next; setLayers(next); }
        function setSizeData(next) { sizeRef.current = next; setDocumentSize(next); }
        function snapshot() { return {layers:layersRef.current,size:sizeRef.current}; }
        function paintState() { var x=historyRef.current; setHistoryCount({undo:x.past.length,redo:x.future.length}); }
        function saveUndo(state, group) {
            var history = historyRef.current, now = Date.now();
            if (!(group && history.group === group && now-history.at < 450)) {
                history.past.push(state || snapshot());
                if (history.past.length>45) history.past.shift();
            }
            history.future = []; history.group = group || ''; history.at = now; paintState();
        }
        function commitLayers(next, group) { saveUndo(snapshot(),group); setLayerData(next); }
        function changeLayer(id, patch, group) {
            var current=layersRef.current, found=false;
            var next=current.map(function(l){if(l.id!==id)return l;found=true;return Object.assign({},l,patch);});
            if(found) commitLayers(next,group);
        }
        function undo() {
            var history=historyRef.current;
            if(!history.past.length)return;
            var previous=history.past.pop(); history.future.push(snapshot()); history.group='';
            setLayerData(previous.layers); setSizeData(previous.size); setCropSelection(null); cropRef.current=null; paintState();
        }
        function redo() {
            var history=historyRef.current;
            if(!history.future.length)return;
            var next=history.future.pop(); history.past.push(snapshot()); history.group='';
            setLayerData(next.layers); setSizeData(next.size); setCropSelection(null); cropRef.current=null; paintState();
        }
        function makeId(prefix) { return prefix+'-'+Date.now()+'-'+Math.random().toString(36).slice(2,7); }
        function cleanName(value) {return String(value||'').slice(0,180);}
        function presetLayer(img, name) {
            return {id:makeId('image'),type:'image',name:cleanName(name||'Image'),image:img,x:(sizeRef.current.w||img.naturalWidth||img.width)/2,y:(sizeRef.current.h||img.naturalHeight||img.height)/2,scale:1,rotation:0,visible:true,locked:false,opacity:1,flipX:false,flipY:false,blend:'source-over',brightness:100,contrast:100,saturation:100,grayscale:0,sepia:0,hue:0,blur:0,removeBg:false};
        }
        function fit() {
            var stage=stageRef.current,s=sizeRef.current;
            if(!stage||!s.w||!s.h)return;
            var availW=Math.max(150,stage.clientWidth-95),availH=Math.max(150,stage.clientHeight-95);
            setZoom(Math.max(10,Math.min(200,Math.floor(Math.min(availW/s.w,availH/s.h)*100))));
        }
        function startBlank(w,h) {
            w=Math.round(Number(w)||1080);h=Math.round(Number(h)||1080);
            if(w<16||h<16||w>8192||h>8192||w*h>65000000){setError('Invalid canvas size.');return;}
            ++loadCounterRef.current;setFile(null);setSizeData({w:w,h:h});
            var id=makeId('paint');setLayerData([{id:id,type:'paint',name:'Drawing layer',strokes:[],visible:true,locked:false,opacity:1,blend:'source-over'}]);
            setSelected(id);selectedRef.current=id;setTransparent(false);setBackground('#ffffff');setTool('move');toolRef.current='move';
            setCropSelection(null);cropRef.current=null;historyRef.current={past:[],future:[],group:'',at:0};paintState();setError('');
            window.requestAnimationFrame(function(){window.requestAnimationFrame(fit);});
        }
        function loadImage(f) {
            if(!f || !/^image\//.test(f.type)){setError('Please choose a supported image file.');return;}
            var seq=++loadCounterRef.current,objectUrl=URL.createObjectURL(f),img=new Image();
            img.onload=function(){URL.revokeObjectURL(objectUrl);if(seq!==loadCounterRef.current)return;
                var size={w:img.naturalWidth,h:img.naturalHeight};
                if(!size.w||!size.h){setError('Could not read the image dimensions.');return;}
                if(size.w*size.h>65000000){setError('Image is too large for a browser canvas. Resize the image before editing.');return;}
                setFile(f);setSizeData(size);
                var base=presetLayer(img,f.name);base.x=size.w/2;base.y=size.h/2;
                setLayerData([base]);setSelected(base.id);setTool('move');setCropSelection(null);cropRef.current=null;
                historyRef.current={past:[],future:[],group:'',at:0};paintState();setError('');
                window.requestAnimationFrame(function(){window.requestAnimationFrame(fit);});
            };
            img.onerror=function(){URL.revokeObjectURL(objectUrl);setError('Could not open this image. Try JPG, PNG or WebP.');};
            img.src=objectUrl;
        }
        useEffect(function(){if(props&&props.imported&&props.imported.file)loadImage(props.imported.file);},[props&&props.imported&&props.imported.stamp]);
        useEffect(function(){selectedRef.current=selected;toolRef.current=tool;},[selected,tool]);
        useEffect(function(){cropRef.current=cropSelection;},[cropSelection]);
        useEffect(function(){if(sizeRef.current.w)renderCanvas();},[layers,documentSize,cropSelection,transparent,background,tolerance,showGrid,selected,tool]);
        useEffect(function(){if(inlineEditor&&inlineInputRef.current){try{inlineInputRef.current.focus();inlineInputRef.current.select();}catch(err){}}},[inlineEditor&&inlineEditor.id]);
        useEffect(function(){refreshBrushCursor();},[tool,zoom,brushSize,documentSize]);

        function imageSource(img,removeBg) {
            if(!removeBg)return img;
            var entry=keyCacheRef.current;
            if(entry&&entry.image===img&&entry.tolerance===tolerance)return entry.canvas;
            var sourceW=img.naturalWidth||img.width,sourceH=img.naturalHeight||img.height;
            var c=document.createElement('canvas');c.width=sourceW;c.height=sourceH;
            var context=c.getContext('2d',{willReadFrequently:true});context.drawImage(img,0,0);
            var info=context.getImageData(0,0,sourceW,sourceH),d=info.data,r=d[0],g=d[1],b=d[2],tol=Math.max(1,tolerance);
            for(var i=0;i<d.length;i+=4){var dist=Math.hypot(d[i]-r,d[i+1]-g,d[i+2]-b);if(dist<tol)d[i+3]=Math.round(d[i+3]*(dist/tol));}
            context.putImageData(info,0,0);keyCacheRef.current={image:img,tolerance:tolerance,canvas:c};return c;
        }
        function dim(layer, ctx) {
            if(layer.type==='image')return {w:layer.image.naturalWidth||layer.image.width,h:layer.image.naturalHeight||layer.image.height};
            if(layer.type==='shape')return {w:layer.w,h:layer.h};
            if(layer.type==='text'){
                var font=(layer.italic?'italic ':'')+String(layer.fontWeight||((layer.bold?700:400)))+' '+(layer.fontSize||56)+'px '+(layer.font||'Arial');
                ctx.font=font;
                var lines=String(layer.text||'Text').split('\n'),width=20;
                lines.forEach(function(line){width=Math.max(width,ctx.measureText(line).width);});
                return {w:width+12,h:Math.max(1,lines.length)*(layer.fontSize||56)*1.2};
            }
            if(layer.type==='paint')return {w:sizeRef.current.w,h:sizeRef.current.h};
            return {w:1,h:1};
        }
        function drawPaint(ctx, layer) {
            // Cached bitmap is derived from immutable stroke objects, so Undo never mutates past steps.
            var cache=paintCacheRef.current[layer.id];
            var w=sizeRef.current.w,h=sizeRef.current.h;
            if(!cache||cache.layer!==layer||cache.w!==w||cache.h!==h){
                var c=document.createElement('canvas');c.width=w;c.height=h;
                var p=c.getContext('2d',{alpha:true});
                (layer.strokes||[]).forEach(function(stroke){
                    p.save();p.globalCompositeOperation=stroke.eraser?'destination-out':'source-over';
                    p.lineWidth=stroke.size;p.lineCap='round';p.lineJoin='round';p.strokeStyle=stroke.eraser?'#000':stroke.color;
                    var pts=stroke.points||[];
                    if(pts.length){p.beginPath();p.moveTo(pts[0].x,pts[0].y);pts.slice(1).forEach(function(q){p.lineTo(q.x,q.y);});if(pts.length===1)p.lineTo(pts[0].x+.01,pts[0].y+.01);p.stroke();}
                    p.restore();
                });
                cache={layer:layer,w:w,h:h,canvas:c};paintCacheRef.current[layer.id]=cache;
            }
            ctx.drawImage(cache.canvas,0,0);
        }
        function paintOne(ctx,layer) {
            if(!layer.visible||layer.opacity<=0)return;
            ctx.save();ctx.globalAlpha=Number(layer.opacity===undefined?1:layer.opacity);ctx.globalCompositeOperation=layer.blend||'source-over';
            if(layer.type==='paint'){drawPaint(ctx,layer);ctx.restore();return;}
            ctx.translate(layer.x,layer.y);ctx.rotate((layer.rotation||0)*Math.PI/180);
            var flipX=layer.flipX?-1:1,flipY=layer.flipY?-1:1;
            ctx.scale((layer.scale||1)*flipX,(layer.scale||1)*flipY);
            if(layer.type==='image'){
                var d=dim(layer,ctx);
                ctx.filter='brightness('+(layer.brightness===undefined?100:layer.brightness)+'%) contrast('+(layer.contrast===undefined?100:layer.contrast)+'%) saturate('+(layer.saturation===undefined?100:layer.saturation)+'%) grayscale('+(layer.grayscale||0)+'%) sepia('+(layer.sepia||0)+'%) hue-rotate('+(layer.hue||0)+'deg) blur('+(layer.blur||0)+'px)';
                ctx.drawImage(imageSource(layer.image,layer.removeBg),-d.w/2,-d.h/2,d.w,d.h);ctx.filter='none';
            } else if(layer.type==='shape'){
                var w=layer.w,h=layer.h;
                ctx.beginPath();
                if(layer.shape==='ellipse')ctx.ellipse(0,0,w/2,h/2,0,0,Math.PI*2);
                else ctx.rect(-w/2,-h/2,w,h);
                if(layer.fill&&layer.fill!=='none'){ctx.fillStyle=layer.fill;ctx.fill();}
                if(layer.strokeWidth>0){ctx.strokeStyle=layer.stroke||'#ffffff';ctx.lineWidth=layer.strokeWidth;ctx.stroke();}
            } else if(layer.type==='text'){
                var fontSize=layer.fontSize||56;
                ctx.font=(layer.italic?'italic ':'')+String(layer.fontWeight||((layer.bold?700:400)))+' '+fontSize+'px '+(layer.font||'Arial');
                ctx.textAlign=layer.align||'center';ctx.textBaseline='middle';ctx.fillStyle=layer.color||'#111827';
                var lines=String(layer.text||'Text').split('\n'),lineH=fontSize*1.2;
                lines.forEach(function(line,index){ctx.fillText(line,0,(index-(lines.length-1)/2)*lineH);});
            }
            ctx.restore();
        }
        function drawSelection(ctx, layer) {
            if(!layer||!layer.visible||layer.type==='paint')return;
            var d=dim(layer,ctx),s=layer.scale||1;
            ctx.save();ctx.translate(layer.x,layer.y);ctx.rotate((layer.rotation||0)*Math.PI/180);
            ctx.setLineDash([Math.max(4,6/Math.max(.1,zoom/100)),Math.max(3,4/Math.max(.1,zoom/100))]);
            ctx.strokeStyle='#0891b2';ctx.lineWidth=Math.max(1,2/Math.max(.1,zoom/100));ctx.strokeRect(-d.w*s/2-3,-d.h*s/2-3,d.w*s+6,d.h*s+6);
            ctx.restore();
        }
        function renderCanvas() {
            var c=canvasRef.current,s=sizeRef.current;
            if(!c||!s.w||!s.h)return;
            if(c.width!==s.w)c.width=s.w;if(c.height!==s.h)c.height=s.h;
            var ctx=c.getContext('2d',{alpha:true});ctx.clearRect(0,0,s.w,s.h);
            if(!transparent){ctx.fillStyle=background;ctx.fillRect(0,0,s.w,s.h);}
            var pointer=interactionRef.current;
            layersRef.current.forEach(function(layer){
                if(pointer&&pointer.type==='paint'&&pointer.stroke&&pointer.layerId===layer.id){
                    var preview=Object.assign({},layer,{strokes:(layer.strokes||[]).concat([pointer.stroke])});
                    paintOne(ctx,preview);
                }else paintOne(ctx,layer);
            });
            var current=layersRef.current.find(function(l){return l.id===selectedRef.current;});
            if(toolRef.current==='move')drawSelection(ctx,current);
            var box=cropRef.current;
            if(box&&box.w>2&&box.h>2){
                ctx.save();ctx.fillStyle='rgba(6,20,30,.40)';ctx.beginPath();ctx.rect(0,0,s.w,s.h);ctx.rect(box.x,box.y,box.w,box.h);ctx.fill('evenodd');
                ctx.strokeStyle='#ffffff';ctx.lineWidth=Math.max(1.4,2/(zoom/100));ctx.strokeRect(box.x,box.y,box.w,box.h);ctx.restore();
            }
            if(showGrid){ctx.save();ctx.strokeStyle='rgba(13,148,136,.27)';ctx.lineWidth=Math.max(1,1/(zoom/100));
                for(var x=s.w/3;x<s.w;x+=s.w/3){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,s.h);ctx.stroke();}
                for(var y=s.h/3;y<s.h;y+=s.h/3){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(s.w,y);ctx.stroke();}ctx.restore();}
        }
        function canvasPoint(event){
            var c=canvasRef.current,r=c.getBoundingClientRect(),s=sizeRef.current;
            return {x:Math.max(0,Math.min(s.w,(event.clientX-r.left)*s.w/r.width)),y:Math.max(0,Math.min(s.h,(event.clientY-r.top)*s.h/r.height))};
        }
        function hitTest(pos){
            var c=canvasRef.current,ctx=c&&c.getContext('2d');if(!ctx)return null;
            for(var i=layersRef.current.length-1;i>=0;i--){var l=layersRef.current[i];if(!l.visible||l.locked||l.type==='paint')continue;
                var theta=-(l.rotation||0)*Math.PI/180,dx=pos.x-l.x,dy=pos.y-l.y,s=l.scale||1;
                var rx=(dx*Math.cos(theta)-dy*Math.sin(theta))/s,ry=(dx*Math.sin(theta)+dy*Math.cos(theta))/s;
                var d=dim(l,ctx);if(Math.abs(rx)<=d.w/2&&Math.abs(ry)<=d.h/2)return l;
            }
            return null;
        }
        function selectTool(next){setTool(next);toolRef.current=next;setError('');if(next!=='brush'&&next!=='eraser'&&brushCursorRef.current)brushCursorRef.current.style.display='none';}
        function refreshBrushCursor(event){
            var cursor=brushCursorRef.current, canvas=canvasRef.current;
            if(!cursor||!canvas)return;
            if(event&&Number.isFinite(event.clientX)&&Number.isFinite(event.clientY))hoverRef.current={x:event.clientX,y:event.clientY};
            var pointer=hoverRef.current;
            if(!pointer||(toolRef.current!=='brush'&&toolRef.current!=='eraser')){cursor.style.display='none';return;}
            var rect=canvas.getBoundingClientRect();
            if(pointer.x<rect.left||pointer.x>rect.right||pointer.y<rect.top||pointer.y>rect.bottom){cursor.style.display='none';return;}
            var host=canvas.parentElement, hostRect=host.getBoundingClientRect();
            var diameter=Math.max(7,Math.min(550,brushSize*(rect.width/(sizeRef.current.w||1))));
            cursor.style.left=(pointer.x-hostRect.left)+'px';
            cursor.style.top=(pointer.y-hostRect.top)+'px';
            cursor.style.width=diameter+'px';
            cursor.style.height=diameter+'px';
            cursor.style.display='block';
            cursor.classList.toggle('is-eraser',toolRef.current==='eraser');
        }
        function imageLocalPoint(layer,p) {
            var theta=-(layer.rotation||0)*Math.PI/180,dx=p.x-layer.x,dy=p.y-layer.y;
            var rotatedX=dx*Math.cos(theta)-dy*Math.sin(theta),rotatedY=dx*Math.sin(theta)+dy*Math.cos(theta);
            var sx=(layer.scale||1)*(layer.flipX?-1:1),sy=(layer.scale||1)*(layer.flipY?-1:1);
            var d=dim(layer,canvasRef.current.getContext('2d'));
            return {x:rotatedX/sx+d.w/2,y:rotatedY/sy+d.h/2};
        }
        function eraseImageSegment(interaction, point) {
            var canvas=interaction.canvas,ctx=canvas.getContext('2d'),current=imageLocalPoint(interaction.layer,point),from=interaction.previous||current;
            var size=Math.max(1,interaction.size/Math.max(.05,interaction.layer.scale||1));
            ctx.save();ctx.globalCompositeOperation='destination-out';ctx.strokeStyle='#000';ctx.lineWidth=size;ctx.lineCap='round';ctx.lineJoin='round';
            ctx.beginPath();ctx.moveTo(from.x,from.y);ctx.lineTo(current.x+.001,current.y+.001);ctx.stroke();ctx.restore();
            interaction.previous=current;keyCacheRef.current=null;
        }
        function ensurePaintLayer(){
            var current=layersRef.current,active=current.find(function(l){return l.id===selectedRef.current;});
            if(active&&active.type==='paint'&&!active.locked)return active;
            var top=current[current.length-1];if(top&&top.type==='paint'&&!top.locked)return top;
            var blank={id:makeId('paint'),type:'paint',name:'Paint layer',strokes:[],visible:true,locked:false,opacity:1,blend:'source-over'};
            // The stroke operation will make a single undo entry, including creation of the layer.
            setLayerData(current.concat([blank]));setSelected(blank.id);selectedRef.current=blank.id;return blank;
        }
        function down(e){if(!sizeRef.current.w)return;if(e.button!==undefined&&e.button!==0)return;
            var p=canvasPoint(e),mode=toolRef.current,snap=snapshot();refreshBrushCursor(e);
            if(canvasRef.current.setPointerCapture)try{canvasRef.current.setPointerCapture(e.pointerId);}catch(err){}
            if(mode==='eyedrop'){
                var ctx=canvasRef.current.getContext('2d'),px=ctx.getImageData(Math.max(0,Math.min(sizeRef.current.w-1,Math.floor(p.x))),Math.max(0,Math.min(sizeRef.current.h-1,Math.floor(p.y))),1,1).data;
                var hex='#'+Array.from(px).slice(0,3).map(function(x){return Number(x).toString(16).padStart(2,'0');}).join('');
                setBrushColor(hex);setShapeFill(hex);selectTool('brush');return;
            }
            if(mode==='hand') {interactionRef.current={type:'hand',px:e.clientX,py:e.clientY,left:stageRef.current.scrollLeft,top:stageRef.current.scrollTop};return;}
            if(mode==='move'){
                var target=hitTest(p);if(target){setSelected(target.id);selectedRef.current=target.id;interactionRef.current={type:'move',id:target.id,start:p,x:target.x,y:target.y,snap:snap};}
                else{setSelected('');selectedRef.current='';interactionRef.current=null;}
            } else if(mode==='crop'||mode==='rect'||mode==='ellipse'){
                interactionRef.current={type:mode,start:p,snap:snap};
                if(mode==='crop'){var blank={x:p.x,y:p.y,w:0,h:0};cropRef.current=blank;setCropSelection(blank);}
            } else if(mode==='brush'||mode==='eraser'){
                if(mode==='eraser'){
                    var activeLayer=layersRef.current.find(function(l){return l.id===selectedRef.current;});
                    if(activeLayer&&activeLayer.type==='image'&&!activeLayer.locked){
                        var editable=document.createElement('canvas'),d=dim(activeLayer,canvasRef.current.getContext('2d'));
                        editable.width=d.w;editable.height=d.h;
                        editable.getContext('2d').drawImage(imageSource(activeLayer.image,activeLayer.removeBg),0,0);
                        var updatedLayer=Object.assign({},activeLayer,{image:editable,removeBg:false});
                        setLayerData(layersRef.current.map(function(l){return l.id===updatedLayer.id?updatedLayer:l;}));
                        var edit={type:'eraser-image',layerId:activeLayer.id,layer:updatedLayer,canvas:editable,size:brushSize,snap:snap};
                        interactionRef.current=edit;eraseImageSegment(edit,p);renderCanvas();return;
                    }
                }
                var paint=ensurePaintLayer();
                interactionRef.current={type:'paint',layerId:paint.id,snap:snap,stroke:{points:[p],size:Math.max(1,brushSize),color:brushColor,eraser:mode==='eraser'}};
                renderCanvas();
            } else if(mode==='text'){
                addText(p);selectTool('move');
            }
        }
        function rectangleFrom(a,b,lockRatio){
            var x1=a.x,y1=a.y,x2=b.x,y2=b.y,w=Math.abs(x2-x1),h=Math.abs(y2-y1);
            if(lockRatio){var ar=Number(lockRatio);if(ar>0){if(w>h*ar)h=w/ar;else w=h*ar;x2=x1+(x2>=x1?w:-w);y2=y1+(y2>=y1?h:-h);}}
            var left=Math.min(x1,x2),top=Math.min(y1,y2),s=sizeRef.current;
            return {x:Math.max(0,left),y:Math.max(0,top),w:Math.min(w,s.w-Math.max(0,left)),h:Math.min(h,s.h-Math.max(0,top))};
        }
        function move(e){var drag=interactionRef.current;var p=canvasPoint(e);refreshBrushCursor(e);
            if(!drag)return;
            if(drag.type==='hand') {stageRef.current.scrollLeft=drag.left+(drag.px-e.clientX);stageRef.current.scrollTop=drag.top+(drag.py-e.clientY);return;}
            if(drag.type==='move'){
                var dx=p.x-drag.start.x,dy=p.y-drag.start.y;
                var next=layersRef.current.map(function(l){return l.id===drag.id?Object.assign({},l,{x:drag.x+dx,y:drag.y+dy}):l;});
                setLayerData(next);return;
            }
            if(drag.type==='crop'){
                var ar={square:1,landscape:16/9,portrait:4/5,story:9/16}[cropAspect]||null;
                var box=rectangleFrom(drag.start,p,ar);cropRef.current=box;setCropSelection(box);return;
            }
            if(drag.type==='rect'||drag.type==='ellipse'){
                drag.end=p;renderCanvas();
                var box=rectangleFrom(drag.start,p);
                var ctx=canvasRef.current.getContext('2d');ctx.save();ctx.strokeStyle='#0d9488';ctx.lineWidth=2;ctx.setLineDash([8,6]);
                if(drag.type==='rect')ctx.strokeRect(box.x,box.y,box.w,box.h);
                else{ctx.beginPath();ctx.ellipse(box.x+box.w/2,box.y+box.h/2,Math.max(0,box.w/2),Math.max(0,box.h/2),0,0,Math.PI*2);ctx.stroke();}
                ctx.restore();return;
            }
            if(drag.type==='eraser-image'){eraseImageSegment(drag,p);renderCanvas();return;}
            if(drag.type==='paint'){
                var pts=drag.stroke.points,prev=pts[pts.length-1];if(Math.hypot(prev.x-p.x,prev.y-p.y)>=.7){pts.push(p);renderCanvas();}
            }
        }
        function leave(){hoverRef.current=null;refreshBrushCursor();}
        function doubleClick(e){if(!sizeRef.current.w)return;var p=canvasPoint(e),target=hitTest(p);if(target&&target.type==='text'){openInlineEditor(target);}}
        function up(e){var drag=interactionRef.current;if(!drag)return;
            var type=drag.type;interactionRef.current=null;
            if(type==='eraser-image'){saveUndo(drag.snap);renderCanvas();return;}
            if(type==='move'){
                var now=layersRef.current.find(function(l){return l.id===drag.id;});
                if(now&&(now.x!==drag.x||now.y!==drag.y))saveUndo(drag.snap);
            }else if(type==='paint'){
                var updated=layersRef.current.map(function(l){return l.id===drag.layerId?Object.assign({},l,{strokes:(l.strokes||[]).concat([drag.stroke])}):l;});
                saveUndo(drag.snap);setLayerData(updated);
            }else if(type==='rect'||type==='ellipse'){
                var end=drag.end||(e&&canvasPoint(e))||drag.start,box=rectangleFrom(drag.start,end);
                if(box.w>=4&&box.h>=4){
                    var shape={id:makeId('shape'),type:'shape',name:type==='rect'?'Rectangle':'Ellipse',shape:type==='rect'?'rectangle':'ellipse',x:box.x+box.w/2,y:box.y+box.h/2,w:box.w,h:box.h,fill:shapeFill,stroke:strokeColor,strokeWidth:strokeWidth,scale:1,rotation:0,opacity:1,visible:true,locked:false,blend:'source-over'};
                    commitLayers(layersRef.current.concat([shape]));setSelected(shape.id);selectedRef.current=shape.id;selectTool('move');
                }
            }
            renderCanvas();
        }
        function addText(at){var text=String(textDraft||'').trim();if(!text)return;
            var size=sizeRef.current,l={id:makeId('text'),type:'text',name:text.slice(0,28),text:text,x:at?at.x:size.w/2,y:at?at.y:size.h/2,fontSize:Math.max(24,Math.round(Math.min(size.w,size.h)*.09)),font:'Arial',fontWeight:500,bold:false,italic:false,align:'center',color:brushColor,visible:true,locked:false,opacity:1,blend:'source-over',rotation:0,scale:1,flipX:false,flipY:false};
            commitLayers(layersRef.current.concat([l]));setSelected(l.id);selectedRef.current=l.id;setSidebarTab('properties');openInlineEditor(l);
        }
        function updateLayerTextLive(id,value){
            var safe=String(value==null?'':value);
            var current=layersRef.current.find(function(layer){return layer.id===id;});if(!current||current.text===safe)return;
            setLayerData(layersRef.current.map(function(layer){return layer.id===id?Object.assign({},layer,{text:safe,name:safe.slice(0,28)||'Text'}):layer;}));
        }
        function openInlineEditor(layer){
            if(!layer||layer.type!=='text')return;
            setSelected(layer.id);selectedRef.current=layer.id;
            setInlineEditor({id:layer.id,value:String(layer.text||''),before:snapshot()});
        }
        function closeInlineEditor(save){
            var editor=inlineEditor;if(!editor)return;
            if(save){
                var beforeLayer=(editor.before.layers||[]).find(function(l){return l.id===editor.id;});
                if(beforeLayer&&String(beforeLayer.text||'')!==String(editor.value||''))saveUndo(editor.before,'inline-text-'+editor.id);
            }else if(editor.before){
                setLayerData(editor.before.layers);setSizeData(editor.before.size);
            }
            setInlineEditor(null);
        }
        function inlineEditorStyle(){
            if(!inlineEditor||!canvasRef.current)return null;
            var layer=layersRef.current.find(function(l){return l.id===inlineEditor.id;});
            if(!layer||layer.type!=='text')return null;
            var ctx=canvasRef.current.getContext('2d'),d=dim(layer,ctx),scale=(layer.scale||1)*(zoom/100),canvas=canvasRef.current;
            var left=canvas.offsetLeft+((layer.x-(d.w*(layer.scale||1))/2)*(zoom/100));
            var top=canvas.offsetTop+((layer.y-(d.h*(layer.scale||1))/2)*(zoom/100));
            return {left:left+'px',top:top+'px',width:Math.max(140,d.w*scale+26)+'px',minHeight:Math.max(48,d.h*scale+20)+'px',fontSize:Math.max(14,(layer.fontSize||32)*(zoom/100))+'px',fontFamily:layer.font||'Arial',fontWeight:String(layer.fontWeight||((layer.bold?700:400))),fontStyle:layer.italic?'italic':'normal',lineHeight:1.2,color:layer.color||'#17372c',textAlign:layer.align||'center',transform:'rotate('+(layer.rotation||0)+'deg)',transformOrigin:'center center'};
        }
        function addOverlay(files) {Array.from(files||[]).forEach(function(f){if(!/^image\//.test(f.type))return;
            var u=URL.createObjectURL(f),img=new Image();img.onload=function(){URL.revokeObjectURL(u);if(!sizeRef.current.w)return;
                var l=presetLayer(img,f.name);l.scale=Math.min(1,Math.min(sizeRef.current.w*.68/(img.naturalWidth||1),sizeRef.current.h*.68/(img.naturalHeight||1)));
                commitLayers(layersRef.current.concat([l]));setSelected(l.id);selectedRef.current=l.id;selectTool('move');};
            img.onerror=function(){URL.revokeObjectURL(u);setError('Could not open '+f.name);};img.src=u;
        });}
        function applyCrop(){var b=cropRef.current;if(!b||b.w<5||b.h<5){setError('Drag a crop rectangle first.');return;}
            var left=Math.round(b.x),top=Math.round(b.y),w=Math.max(1,Math.round(b.w)),h=Math.max(1,Math.round(b.h));
            saveUndo();setLayerData(layersRef.current.map(function(l){
                if(l.type==='paint'){
                    var strokes=(l.strokes||[]).map(function(s){return Object.assign({},s,{points:s.points.map(function(p){return {x:p.x-left,y:p.y-top};})});});
                    return Object.assign({},l,{strokes:strokes});
                }
                return Object.assign({},l,{x:l.x-left,y:l.y-top});
            }));setSizeData({w:w,h:h});cropRef.current=null;setCropSelection(null);selectTool('move');window.requestAnimationFrame(fit);setError('');
        }
        function duplicateLayer(){var l=layersRef.current.find(function(a){return a.id===selectedRef.current;});if(!l)return;
            var index=layersRef.current.findIndex(function(a){return a.id===l.id;});
            var copy=Object.assign({},l,{id:makeId(l.type),name:l.name+' copy',x:l.type==='paint'?l.x:l.x+24,y:l.type==='paint'?l.y:l.y+24});
            var next=layersRef.current.slice();next.splice(index+1,0,copy);commitLayers(next);setSelected(copy.id);
        }
        function deleteLayer(){var a=layersRef.current;if(a.length<=1)return;
            var next=a.filter(function(l){return l.id!==selectedRef.current;});
            if(next.length===a.length)return;commitLayers(next);setSelected(next[next.length-1].id);
        }
        function reorderLayer(direction){var index=layersRef.current.findIndex(function(l){return l.id===selectedRef.current;}),target=index+direction;
            if(index<0||target<0||target>=layersRef.current.length)return;
            var arr=layersRef.current.slice(),temp=arr[index];arr[index]=arr[target];arr[target]=temp;commitLayers(arr);
        }
        function applyPreset(preset){var l=layersRef.current.find(function(x){return x.id===selectedRef.current;});if(!l||l.type!=='image')return;
            var presets={original:{brightness:100,contrast:100,saturation:100,grayscale:0,sepia:0,hue:0,blur:0},vivid:{brightness:105,contrast:115,saturation:135,grayscale:0,sepia:0,hue:0,blur:0},warm:{brightness:103,contrast:108,saturation:110,grayscale:0,sepia:16,hue:-8,blur:0},vintage:{brightness:105,contrast:92,saturation:83,grayscale:0,sepia:35,hue:-8,blur:0},bw:{brightness:105,contrast:118,saturation:0,grayscale:100,sepia:0,hue:0,blur:0},soft:{brightness:105,contrast:93,saturation:93,grayscale:0,sepia:0,hue:0,blur:.6}};
            if(presets[preset])changeLayer(l.id,presets[preset]);
        }
        function resetAdjustments(){var l=layersRef.current.find(function(x){return x.id===selectedRef.current;});if(l&&l.type==='image')applyPreset('original');}
        function applyResize(){if(!resizeDraft)return;var old=sizeRef.current,w=Math.round(Number(resizeDraft.w)),h=Math.round(Number(resizeDraft.h));
            if(!Number.isFinite(w)||!Number.isFinite(h)||w<16||h<16||w>8192||h>8192||w*h>65000000){setError('Enter dimensions between 16 and 8192 px (max 65 MP).');return;}
            var sx=w/old.w,sy=h/old.h;
            var next=layersRef.current.map(function(l){
                if(l.type==='paint')return Object.assign({},l,{strokes:(l.strokes||[]).map(function(s){return Object.assign({},s,{points:s.points.map(function(p){return {x:p.x*sx,y:p.y*sy};}),size:s.size*(sx+sy)/2});})});
                var patch={x:l.x*sx,y:l.y*sy};
                if(l.type==='shape'){patch.w=l.w*sx;patch.h=l.h*sy;}
                else if(l.type==='text'){patch.fontSize=Math.round(l.fontSize*(sx+sy)/2);}
                else if(l.type==='image'){patch.scale=(l.scale||1)*(sx+sy)/2;}
                return Object.assign({},l,patch);
            });
            saveUndo();setLayerData(next);setSizeData({w:w,h:h});setResizeDraft(null);setError('');window.requestAnimationFrame(fit);
        }
        function fieldGroup(label,children){return h('div',{className:'seopc-pe-field'},h('label',null,label),children);}
        function slider(label,field,value,min,max,step,suffix,id){return h('div',{className:'seopc-pe-slider'},h('div',{className:'seopc-pe-slider-heading'},h('span',null,label),h('b',null,(Math.round(Number(value)*100)/100)+(suffix||''))),h('input',{type:'range',min:min,max:max,step:step,value:value,onChange:function(e){var patch={};patch[field]=Number(e.target.value);changeLayer(id||selected,patch,'slider-'+field+'-'+(id||selected));}}));}
        function changeSelected(patch,group){if(selectedRef.current)changeLayer(selectedRef.current,patch,group);}
        function eyedropColor(e){setBrushColor(e.target.value);}
        function resetLayer(){var l=layersRef.current.find(function(x){return x.id===selectedRef.current;});if(!l)return;
            var patch={rotation:0,scale:1,flipX:false,flipY:false,opacity:1};if(l.type==='image')Object.assign(patch,{brightness:100,contrast:100,saturation:100,sepia:0,grayscale:0,hue:0,blur:0});changeLayer(l.id,patch);
        }
        async function makeBlob(){
            if(!sizeRef.current.w)throw new Error('Open an image first.');
            var s=sizeRef.current,scale=Number(exportScale)/100;
            var output=document.createElement('canvas');output.width=Math.max(1,Math.round(s.w*scale));output.height=Math.max(1,Math.round(s.h*scale));
            var ctx=output.getContext('2d',{alpha:format!=='image/jpeg'});ctx.setTransform(scale,0,0,scale,0,0);
            if(format==='image/jpeg'||!transparent){ctx.fillStyle=background;ctx.fillRect(0,0,s.w,s.h);}
            layersRef.current.forEach(function(layer){paintOne(ctx,layer);});
            var preferred=format;
            var blob=await canvasToBlob(output,preferred,Number(quality));
            if(!blob)throw new Error('Could not encode the selected image.');
            // Some browsers silently export PNG when AVIF is unsupported.
            var actual=blob.type||'image/png';
            if(actual!==preferred&&preferred==='image/avif'){
                preferred='image/webp';blob=await canvasToBlob(output,preferred,Number(quality));
                actual=blob&&blob.type||'image/png';
            }
            if(!blob)throw new Error('This browser cannot export the chosen format.');
            return {blob:blob,type:actual,w:output.width,h:output.height};
        }
        async function exportImage(){setBusy(true);setError('');try{var result=await makeBlob();
            var exts={'image/png':'png','image/jpeg':'jpg','image/webp':'webp','image/avif':'avif'};
            downloadBlob(result.blob,(file?file.name.replace(/\.[^.]+$/,''):'image')+'-edited.'+(exts[result.type]||'png'));
        }catch(err){setError(err.message);}finally{setBusy(false);}}
        async function copyPng(){if(!navigator.clipboard||!window.ClipboardItem)return;
            try{var original=format;var s=sizeRef.current,c=document.createElement('canvas');c.width=s.w;c.height=s.h;
                var ctx=c.getContext('2d');if(!transparent){ctx.fillStyle=background;ctx.fillRect(0,0,s.w,s.h);}
                layersRef.current.forEach(function(l){paintOne(ctx,l);});
                var b=await canvasToBlob(c,'image/png',1);await navigator.clipboard.write([new ClipboardItem({'image/png':b})]);
            }catch(err){setError('Clipboard unavailable: '+err.message);}
        }
        function onDrop(e){e.preventDefault();var files=e.dataTransfer&&e.dataTransfer.files;
            if(!files||!files.length)return;if(!sizeRef.current.w)loadImage(files[0]);else addOverlay(files);
        }
        function onPaste(e){var tags=['INPUT','TEXTAREA'];if(tags.indexOf((document.activeElement&&document.activeElement.tagName)||'')>=0)return;
            var items=e.clipboardData&&e.clipboardData.items;if(!items)return;
            for(var i=0;i<items.length;i++){if(items[i].type.indexOf('image/')===0){var pasted=items[i].getAsFile();if(pasted){e.preventDefault();if(sizeRef.current.w)addOverlay([pasted]);else loadImage(pasted);}return;}}
        }
        useEffect(function(){
            function key(e){if((e.target&&(['INPUT','TEXTAREA','SELECT'].indexOf(e.target.tagName)>=0||e.target.isContentEditable)))return;
                var meta=e.ctrlKey||e.metaKey,k=String(e.key||'').toLowerCase();
                if(meta&&k==='z'){e.preventDefault();if(e.shiftKey)redo();else undo();return;}
                if(meta&&k==='y'){e.preventDefault();redo();return;}
                if(meta&&k==='d'){e.preventDefault();duplicateLayer();return;}
                if((k==='='||k==='+'||k==='add')&&!meta&&!e.altKey){e.preventDefault();setZoom(function(z){return Math.min(400,z+10);});return;}
                if((k==='-'||k==='subtract')&&!meta&&!e.altKey){e.preventDefault();setZoom(function(z){return Math.max(10,z-10);});return;}
                if(k==='delete'||k==='backspace'){e.preventDefault();deleteLayer();return;}
                if(meta||e.altKey)return;
                var shortcuts={v:'move',c:'crop',b:'brush',e:'eraser',r:'rect',o:'ellipse',t:'text',i:'eyedrop',h:'hand'};
                if(shortcuts[k]){selectTool(shortcuts[k]);return;}
                if(k==='escape'){if(inlineEditor){closeInlineEditor(false);return;}setCropSelection(null);cropRef.current=null;selectTool('move');}
            }
            window.addEventListener('keydown',key);window.addEventListener('paste',onPaste);
            return function(){window.removeEventListener('keydown',key);window.removeEventListener('paste',onPaste);};
        });

        var active=layers.find(function(l){return l.id===selected;})||null;
        var buttons=[{id:'move',icon:'↖',label:'Select / Move',key:'V'}, {id:'crop',icon:'⌗',label:'Crop',key:'C'}, {id:'brush',icon:'✎',label:'Brush',key:'B'}, {id:'eraser',icon:'◩',label:'Eraser',key:'E'}, {id:'rect',icon:'▭',label:'Rectangle',key:'R'}, {id:'ellipse',icon:'◯',label:'Ellipse',key:'O'}, {id:'text',icon:'T',label:'Text',key:'T'}, {id:'eyedrop',icon:'◉',label:'Colour picker',key:'I'}, {id:'hand',icon:'✥',label:'Pan canvas',key:'H'}];
        function opt(value,label){return h('option',{value:value},label);}
        function toolButton(btn){return h('button',{key:btn.id,type:'button',className:'seopc-pe-tool'+(tool===btn.id?' is-active':''),title:btn.label+' ('+btn.key+')',onClick:function(){selectTool(btn.id);},'aria-label':btn.label},h('span',null,btn.icon),h('small',null,btn.label));}
        function filterControls(){if(!active||active.type!=='image')return h('div',{className:'seopc-pe-help'},'Select an image layer to adjust colours.');
            var presets=[['original','Original'],['vivid','Vibrant'],['warm','Warm'],['vintage','Vintage'],['bw','B&W'],['soft','Soft']];
            return h('div',{className:'seopc-pe-properties'},h('div',{className:'seopc-pe-prop-title'},'Quick looks'),h('div',{className:'seopc-pe-preset-grid'},presets.map(function(item){return h('button',{key:item[0],type:'button',onClick:function(){applyPreset(item[0]);}},item[1]);})),
                slider('Brightness','brightness',active.brightness,0,200,1,'%'),slider('Contrast','contrast',active.contrast,0,200,1,'%'),slider('Saturation','saturation',active.saturation,0,200,1,'%'),slider('Hue','hue',active.hue,-180,180,1,'°'),
                slider('Grayscale','grayscale',active.grayscale,0,100,1,'%'),slider('Sepia','sepia',active.sepia,0,100,1,'%'),slider('Blur','blur',active.blur,0,15,.25,'px'),
                h('button',{className:'seopc-pe-minor-btn',type:'button',onClick:resetAdjustments},'Reset colour adjustments'),
                h('label',{className:'seopc-pe-check'},h('input',{type:'checkbox',checked:!!active.removeBg,onChange:function(e){changeSelected({removeBg:e.target.checked});}}),'Remove sampled corner colour'),
                active.removeBg?h('div',{className:'seopc-pe-field'},h('label',null,'Tolerance · '+tolerance),h('input',{type:'range',min:5,max:120,step:1,value:tolerance,onChange:function(e){setTolerance(Number(e.target.value));}})):null,
                h('p',{className:'seopc-pe-hint'},'Corner-colour removal is simple colour keying, not AI subject isolation.')
            );
        }
        function layerProperties(){if(!active)return h('div',{className:'seopc-pe-help'},'Choose a layer or use a tool to add new content.');
            return h('div',{className:'seopc-pe-properties'},
                fieldGroup('Layer name',h('input',{type:'text',value:active.name,onChange:function(e){changeSelected({name:e.target.value},'name-'+selected);}})),
                h('div',{className:'seopc-pe-two-cols'},fieldGroup('Opacity',h('input',{type:'number',min:0,max:100,value:Math.round(active.opacity*100),onChange:function(e){changeSelected({opacity:Math.min(1,Math.max(0,Number(e.target.value)/100))},'opacity-'+selected);}})),fieldGroup('Blend mode',h('select',{value:active.blend||'source-over',onChange:function(e){changeSelected({blend:e.target.value});}},opt('source-over','Normal'),opt('multiply','Multiply'),opt('screen','Screen'),opt('overlay','Overlay'),opt('darken','Darken'),opt('lighten','Lighten')))),
                active.type==='text'?h('div',null,
                    fieldGroup('Text',h('textarea',{rows:3,value:active.text,onChange:function(e){changeSelected({text:e.target.value,name:e.target.value.slice(0,28)},'text-'+selected);}})),
                    h('div',{className:'seopc-pe-two-cols'},fieldGroup('Font size',h('input',{type:'number',min:8,max:480,value:active.fontSize,onChange:function(e){changeSelected({fontSize:Math.max(8,Number(e.target.value))},'fontsize-'+selected);}})),fieldGroup('Text colour',h('input',{type:'color',value:active.color,onChange:function(e){changeSelected({color:e.target.value},'textcolor-'+selected);}}))),
                    h('div',{className:'seopc-pe-two-cols'},fieldGroup('Font family',h('select',{value:active.font,onChange:function(e){changeSelected({font:e.target.value});}},opt('Arial','Arial'),opt('Inter','Inter'),opt('Helvetica','Helvetica'),opt('Verdana','Verdana'),opt('Tahoma','Tahoma'),opt('Trebuchet MS','Trebuchet'),opt('Georgia','Georgia'),opt('Times New Roman','Times'),opt('Courier New','Courier'))),fieldGroup('Font weight',h('select',{value:active.fontWeight||((active.bold?700:400)),onChange:function(e){var val=Number(e.target.value)||400;changeSelected({fontWeight:val,bold:val>=700},'fontweight-'+selected);}},opt(400,'Normal'),opt(500,'Medium'),opt(600,'Semibold'),opt(700,'Bold')))),
                    h('div',{className:'seopc-pe-inline'},h('button',{type:'button',className:(active.fontWeight||((active.bold?700:400)))===400?'is-active':'',onClick:function(){changeSelected({fontWeight:400,bold:false},'fontweight-'+selected);}},'Normal'),h('button',{type:'button',className:(active.fontWeight||((active.bold?700:400)))===500?'is-active':'',onClick:function(){changeSelected({fontWeight:500,bold:false},'fontweight-'+selected);}},'Medium'),h('button',{type:'button',className:(active.fontWeight||((active.bold?700:400)))>=700?'is-active':'',onClick:function(){changeSelected({fontWeight:700,bold:true},'fontweight-'+selected);}},'Bold'),h('button',{type:'button',className:active.italic?'is-active':'',onClick:function(){changeSelected({italic:!active.italic});}},'Italic'),h('button',{type:'button',className:(active.align||'center')==='left'?'is-active':'',onClick:function(){changeSelected({align:'left'});}},'Left'),h('button',{type:'button',className:(active.align||'center')==='center'?'is-active':'',onClick:function(){changeSelected({align:'center'});}},'Center'),h('button',{type:'button',className:(active.align||'center')==='right'?'is-active':'',onClick:function(){changeSelected({align:'right'});}},'Right'),h('button',{type:'button',onClick:function(){openInlineEditor(active);}},'Edit on canvas'))
                ):null,
                active.type==='shape'?h('div',{className:'seopc-pe-two-cols'},fieldGroup('Fill',h('input',{type:'color',value:active.fill,onChange:function(e){changeSelected({fill:e.target.value});}})),fieldGroup('Stroke',h('input',{type:'color',value:active.stroke||'#ffffff',onChange:function(e){changeSelected({stroke:e.target.value});}})),fieldGroup('Stroke px',h('input',{type:'number',min:0,max:100,value:active.strokeWidth||0,onChange:function(e){changeSelected({strokeWidth:Math.max(0,Number(e.target.value))});}}))):null,
                h('div',{className:'seopc-pe-prop-title'},'Transform'),
                active.type==='paint'?h('div',{className:'seopc-pe-help'},'Draw with Brush or Eraser. The eraser affects paint layers only.'):h('div',null,
                    h('div',{className:'seopc-pe-two-cols'},fieldGroup('X position',h('input',{type:'number',value:Math.round(active.x),onChange:function(e){changeSelected({x:Number(e.target.value)},'x-'+selected);}})),fieldGroup('Y position',h('input',{type:'number',value:Math.round(active.y),onChange:function(e){changeSelected({y:Number(e.target.value)},'y-'+selected);}}))),
                    slider('Scale','scale',active.scale||1,.1,4,.05,'×'),slider('Rotation','rotation',active.rotation||0,-180,180,1,'°'),
                    h('div',{className:'seopc-pe-inline'},h('button',{type:'button',onClick:function(){changeSelected({rotation:(active.rotation||0)-90});}},'↶ 90°'),h('button',{type:'button',onClick:function(){changeSelected({rotation:(active.rotation||0)+90});}},'↷ 90°'),h('button',{type:'button',onClick:function(){changeSelected({flipX:!active.flipX});}},'Flip H'),h('button',{type:'button',onClick:function(){changeSelected({flipY:!active.flipY});}},'Flip V'),h('button',{type:'button',onClick:resetLayer},'Reset'))
                ),
                active.type==='image'?h('button',{type:'button',className:'seopc-pe-minor-btn',onClick:function(){var d=dim(active,canvasRef.current.getContext('2d'));changeSelected({scale:Math.min(sizeRef.current.w*.85/d.w,sizeRef.current.h*.85/d.h),x:sizeRef.current.w/2,y:sizeRef.current.h/2});}},'Fit image to canvas'):null
            );
        }
        function advancedSettings(){return h('div',{className:'seopc-pe-properties'},
            h('div',{className:'seopc-pe-prop-title'},'Canvas'),h('div',{className:'seopc-pe-help'},documentSize.w+' × '+documentSize.h+' px · '+layers.length+' layer'+(layers.length===1?'':'s')),
            h('button',{className:'seopc-pe-minor-btn',type:'button',onClick:function(){setResizeDraft({w:documentSize.w,h:documentSize.h});}},'Resize canvas'),
            fieldGroup('Background',h('label',{className:'seopc-pe-check'},h('input',{type:'checkbox',checked:transparent,onChange:function(e){setTransparent(e.target.checked);}}),'Transparent background')),
            !transparent?fieldGroup('Background colour',h('input',{type:'color',value:background,onChange:function(e){setBackground(e.target.value);}})):null,
            h('label',{className:'seopc-pe-check'},h('input',{type:'checkbox',checked:showGrid,onChange:function(e){setShowGrid(e.target.checked);}}),'Rule of thirds grid'),
            h('div',{className:'seopc-pe-prop-title'},'Export'),
            fieldGroup('Format',h('select',{value:format,onChange:function(e){setFormat(e.target.value);}},opt('image/webp','WebP · recommended'),opt('image/png','PNG · transparency'),opt('image/jpeg','JPG · photographs'),opt('image/avif','AVIF · compact'))),
            h('div',{className:'seopc-pe-field'},h('label',null,'Quality '+Math.round(quality*100)+'%'),h('input',{type:'range',min:.35,max:1,step:.01,value:quality,onChange:function(e){setQuality(Number(e.target.value));}})),
            fieldGroup('Export size',h('select',{value:exportScale,onChange:function(e){setExportScale(Number(e.target.value));}},opt(25,'25%'),opt(50,'50%'),opt(75,'75%'),opt(100,'100% · original'),opt(150,'150%'),opt(200,'200%'))),
            h('button',{className:'seopc-pe-export',type:'button',onClick:exportImage,disabled:busy},busy?'Exporting…':'Export image'),
            h('button',{className:'seopc-pe-minor-btn',type:'button',onClick:copyPng},'Copy PNG to clipboard'),
            h('p',{className:'seopc-pe-hint'},'All editing runs locally in your browser. AVIF falls back to WebP if encoding is unavailable.')
        );}
        return h('div',{className:'seopc-pe-root'},
            h('div',{className:'seopc-pe-heading'},h('div',null,h('span',{className:'seopc-eyebrow'},'BROWSER IMAGE STUDIO'),h('h2',null,'Photo Editor'),h('p',null,'Easy Photoshop-style tools for website images, banners and social graphics. Everything stays in your browser.')),
                h('div',{className:'seopc-pe-heading-actions'},h('button',{type:'button',className:'seopc-pe-minor-btn',onClick:function(){setNewDraft({w:1080,h:1080});}},'New blank'),h('button',{type:'button',className:'seopc-pe-minor-btn',onClick:function(){fileInputRef.current&&fileInputRef.current.click();}},'Open image'),h('button',{type:'button',className:'seopc-pe-export',disabled:!documentSize.w||busy,onClick:exportImage},busy?'Exporting…':'Export image'))),
            h('input',{ref:fileInputRef,type:'file',accept:'image/*',style:{display:'none'},onChange:function(e){if(e.target.files&&e.target.files[0])loadImage(e.target.files[0]);e.target.value='';}}),
            h('input',{ref:overlayInputRef,type:'file',accept:'image/*',multiple:true,style:{display:'none'},onChange:function(e){addOverlay(e.target.files);e.target.value='';}}),
            error?h('div',{className:'seopc-pe-alert',role:'alert'},error,h('button',{type:'button','aria-label':'Dismiss error',onClick:function(){setError('');}},'×')):null,
            documentSize.w?h('div',{className:'seopc-pe-editor'},
                h('div',{className:'seopc-pe-topbar'},
                    h('div',{className:'seopc-pe-toolbar-group'},h('button',{type:'button',onClick:function(){overlayInputRef.current&&overlayInputRef.current.click();}},'＋ Add image'),h('button',{type:'button',onClick:function(){addText();}},'T Add text'),h('button',{type:'button',onClick:undo,disabled:!historyCount.undo,title:'Ctrl+Z'},'↶ Undo'),h('button',{type:'button',onClick:redo,disabled:!historyCount.redo,title:'Ctrl+Y'},'↷ Redo')),
                    h('div',{className:'seopc-pe-toolbar-group'},h('button',{type:'button',onClick:function(){setResizeDraft({w:documentSize.w,h:documentSize.h});}},'Resize'),h('button',{type:'button',onClick:fit},'Fit'),h('button',{type:'button',title:'Zoom out (-)', 'aria-label':'Zoom out',onClick:function(){setZoom(Math.max(10,zoom-10));}},'−'),h('strong',{'aria-live':'polite'},zoom+'%'),h('button',{type:'button',title:'Zoom in (+)', 'aria-label':'Zoom in',onClick:function(){setZoom(Math.min(400,zoom+10));}},'+'),h('button',{type:'button',title:'Actual size (100%)',onClick:function(){setZoom(100);}},'100%'),h('label',{className:'seopc-pe-check seopc-pe-grid-toggle'},h('input',{type:'checkbox',checked:showGrid,onChange:function(e){setShowGrid(e.target.checked);}}),'Grid'))),
                h('div',{className:'seopc-pe-layout'},
                    h('nav',{className:'seopc-pe-tools','aria-label':'Editor tools'},buttons.map(toolButton)),
                    h('section',{className:'seopc-pe-center'},
                        h('div',{className:'seopc-pe-context'},
                            h('div',{className:'seopc-pe-context-inner'},
                                h('strong',null,buttons.find(function(t){return t.id===tool;}).label),
                                tool==='crop'?h('div',{className:'seopc-pe-tool-options'},h('select',{value:cropAspect,onChange:function(e){setCropAspect(e.target.value);}},opt('free','Free crop'),opt('square','1:1 Square'),opt('landscape','16:9'),opt('portrait','4:5'),opt('story','9:16')),h('button',{type:'button',disabled:!cropSelection||cropSelection.w<5,onClick:applyCrop},'Apply crop'),h('button',{type:'button',onClick:function(){setCropSelection(null);cropRef.current=null;setTool('move');}},'Cancel')):null,
                                (tool==='brush'||tool==='eraser')?h('div',{className:'seopc-pe-tool-options'},h('label',null,'Size',h('input',{type:'range',min:1,max:120,value:brushSize,onChange:function(e){setBrushSize(Number(e.target.value));}})),h('small',{className:'seopc-pe-brush-diameter'},h('i',{style:{width:Math.min(26,Math.max(7,brushSize/4))+'px',height:Math.min(26,Math.max(7,brushSize/4))+'px'}}),brushSize+'px'),tool==='brush'?h('input',{type:'color',value:brushColor,onChange:eyedropColor,'aria-label':'Brush colour'}):null):null,
                                (tool==='rect'||tool==='ellipse')?h('div',{className:'seopc-pe-tool-options'},h('label',null,'Fill',h('input',{type:'color',value:shapeFill,onChange:function(e){setShapeFill(e.target.value);}})),h('label',null,'Stroke',h('input',{type:'color',value:strokeColor,onChange:function(e){setStrokeColor(e.target.value);}})),h('label',null,'Border',h('input',{type:'number',min:0,max:50,value:strokeWidth,onChange:function(e){setStrokeWidth(Math.max(0,Number(e.target.value)));}}))):null,
                                tool==='text'?h('div',{className:'seopc-pe-tool-options'},h('input',{type:'text',value:textDraft,onChange:function(e){setTextDraft(e.target.value);},placeholder:'Type text and click canvas…'}),h('small',null,'Then double-click a text layer for inline editing.')):null,
                                tool==='move'?h('span',null,'Click a layer to select and drag. Use the panel to edit its properties.'):null,
                                tool==='eyedrop'?h('span',null,'Click the image to sample a colour.'):null,
                                tool==='hand'?h('span',null,'Drag to pan the canvas.'):null,
                                tool==='eraser'?h('span',null,'Erases the selected image or paint layer.'):null
                            )),
                        h('div',{className:'seopc-pe-stage',ref:stageRef,onDragOver:function(e){e.preventDefault();},onDrop:onDrop},
                            h('div',{className:'seopc-pe-stage-inner'},
                                h('canvas',{ref:canvasRef,width:documentSize.w,height:documentSize.h,style:{width:Math.round(documentSize.w*zoom/100)+'px',height:Math.round(documentSize.h*zoom/100)+'px',cursor:({move:'move',crop:'crosshair',brush:'none',eraser:'none',rect:'crosshair',ellipse:'crosshair',text:'text',eyedrop:'crosshair',hand:'grab'})[tool]},onPointerDown:down,onPointerMove:move,onPointerUp:up,onPointerCancel:up,onPointerLeave:leave,onDoubleClick:doubleClick}),
                                h('div',{ref:brushCursorRef,className:'seopc-pe-brush-outline','aria-hidden':'true'}),
                                inlineEditor&&inlineEditorStyle()?h('div',{className:'seopc-pe-inline-editor',style:inlineEditorStyle(),onPointerDown:function(e){e.stopPropagation();}},
                                    h('textarea',{ref:inlineInputRef,value:inlineEditor.value,onChange:function(e){var value=e.target.value;setInlineEditor(Object.assign({},inlineEditor,{value:value}));updateLayerTextLive(inlineEditor.id,value);},onBlur:function(){closeInlineEditor(true);},rows:Math.max(2,String(inlineEditor.value||'').split('\n').length),placeholder:'Edit text…'}),
                                    h('div',{className:'seopc-pe-inline-editor-actions'},h('button',{type:'button',onMouseDown:function(e){e.preventDefault();},onClick:function(){closeInlineEditor(false);}},'Cancel'),h('button',{type:'button',className:'seopc-pe-export',onMouseDown:function(e){e.preventDefault();},onClick:function(){closeInlineEditor(true);}},'Apply'))
                                ):null
                            )),
                        h('div',{className:'seopc-pe-status'},h('span',null,documentSize.w+' × '+documentSize.h+' px'),h('span',null,layers.length+' layer'+(layers.length===1?'':'s')),h('span',null,'Shortcuts: V Move · C Crop · B Brush · T Text · + / - Zoom · Ctrl+Z Undo · Double-click text to edit'))
                    ),
                    h('aside',{className:'seopc-pe-sidebar'},
                        h('div',{className:'seopc-pe-tabs'},[['layers','Layers'],['properties','Properties'],['adjust','Adjust'],['export','Export']].map(function(t){return h('button',{type:'button',key:t[0],className:sidebarTab===t[0]?'is-active':'',onClick:function(){setSidebarTab(t[0]);}},t[1]);})),
                        sidebarTab==='layers'?h('div',null,
                            h('div',{className:'seopc-pe-layer-list'},layers.slice().reverse().map(function(l){return h('div',{key:l.id,className:'seopc-pe-layer'+(selected===l.id?' is-selected':'')},
                                h('button',{type:'button',title:l.visible?'Hide layer':'Show layer',onClick:function(){changeLayer(l.id,{visible:!l.visible});}},l.visible?'◉':'○'),
                                h('button',{type:'button',className:'seopc-pe-layer-name',onClick:function(){setSelected(l.id);}},h('b',null,l.type==='image'?'▧':l.type==='text'?'T':l.type==='paint'?'✎':'▢'),h('span',null,l.name)),
                                h('button',{type:'button',title:l.locked?'Unlock layer':'Lock layer',onClick:function(){changeLayer(l.id,{locked:!l.locked});}},l.locked?'🔒':'♧')
                            );})),
                            h('div',{className:'seopc-pe-layer-actions'},h('button',{type:'button',onClick:duplicateLayer,disabled:!active},'Duplicate'),h('button',{type:'button',onClick:function(){reorderLayer(1);},disabled:!active},'Up'),h('button',{type:'button',onClick:function(){reorderLayer(-1);},disabled:!active},'Down'),h('button',{type:'button',onClick:deleteLayer,disabled:layers.length<=1},'Delete')),
                            h('div',{className:'seopc-pe-properties'},active?h('div',null,h('h4',null,active.name),h('p',null,'Select Properties to edit transform, colours or text.'),h('button',{type:'button',className:'seopc-pe-minor-btn',onClick:function(){setSidebarTab('properties');}},'Edit selected layer →')):null)
                        ):sidebarTab==='properties'?layerProperties():sidebarTab==='adjust'?filterControls():advancedSettings()
                    )
                )
            ):h('div',{className:'seopc-pe-empty'},h('div',{className:'seopc-pe-empty-icon'},'▧'),h('h3',null,'Open an image to start editing'),h('p',null,'Drop a JPG, PNG, WebP or AVIF here, or open a file. Then use the easy tools for crop, text, drawing, shapes, adjustments and layers.'),h('div',{className:'seopc-pe-heading-actions'},h('button',{type:'button',className:'seopc-pe-export',onClick:function(){fileInputRef.current&&fileInputRef.current.click();}},'Choose an image'),h('button',{type:'button',className:'seopc-pe-minor-btn',onClick:function(){startBlank(1080,1080);}},'Create blank 1080×1080')),h('span',null,'No upload · No account · Editing happens locally'),h('div',{className:'seopc-pe-empty-drop',onDragOver:function(e){e.preventDefault();},onDrop:onDrop},'Drop an image here')),
            newDraft?h('div',{className:'seopc-pe-modal-backdrop'},h('div',{className:'seopc-pe-modal',role:'dialog','aria-modal':'true','aria-label':'Create blank canvas'},
                h('h3',null,'Create a blank canvas'),h('p',null,'Choose a preset or enter custom dimensions. Your current unsaved artwork will be replaced.'),
                h('div',{className:'seopc-pe-preset-grid'},[['1080','1080','Square'],['1920','1080','Landscape'],['1080','1920','Story'],['1200','630','Social']].map(function(s){return h('button',{key:s[2],type:'button',onClick:function(){setNewDraft({w:Number(s[0]),h:Number(s[1])});}},s[2]);})),
                h('div',{className:'seopc-pe-two-cols'},fieldGroup('Width (px)',h('input',{type:'number',min:16,max:8192,value:newDraft.w,onChange:function(e){setNewDraft(Object.assign({},newDraft,{w:e.target.value}));}})),fieldGroup('Height (px)',h('input',{type:'number',min:16,max:8192,value:newDraft.h,onChange:function(e){setNewDraft(Object.assign({},newDraft,{h:e.target.value}));}}))),
                h('div',{className:'seopc-pe-modal-actions'},h('button',{type:'button',onClick:function(){setNewDraft(null);}},'Cancel'),h('button',{type:'button',className:'seopc-pe-export',onClick:function(){startBlank(newDraft.w,newDraft.h);setNewDraft(null);}},'Create canvas'))
            )):null,
            resizeDraft?h('div',{className:'seopc-pe-modal-backdrop'},h('div',{className:'seopc-pe-modal',role:'dialog','aria-modal':'true','aria-label':'Resize image'},h('h3',null,'Resize canvas'),h('p',null,'Changes the output dimensions and scales the artwork proportionally.'),h('div',{className:'seopc-pe-two-cols'},fieldGroup('Width (px)',h('input',{type:'number',min:16,max:8192,value:resizeDraft.w,onChange:function(e){setResizeDraft(Object.assign({},resizeDraft,{w:e.target.value}));}})),fieldGroup('Height (px)',h('input',{type:'number',min:16,max:8192,value:resizeDraft.h,onChange:function(e){setResizeDraft(Object.assign({},resizeDraft,{h:e.target.value}));}}))),h('div',{className:'seopc-pe-modal-actions'},h('button',{type:'button',onClick:function(){setResizeDraft(null);}},'Cancel'),h('button',{type:'button',className:'seopc-pe-export',onClick:applyResize},'Apply resize')))):null
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
        try {
            storedToken = localStorage.getItem('seopc_toolkit_token') || sessionStorage.getItem('seopc_toolkit_token') || '';
            if (storedToken) {
                localStorage.setItem('seopc_toolkit_token', storedToken);
                sessionStorage.removeItem('seopc_toolkit_token');
            }
        } catch (e) {}
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
                try { localStorage.setItem('seopc_toolkit_token', resolved); } catch (e) {}
            }
        }
        function expired() {
            if (!config.passwordProtected) {
                setToken('public');
                return;
            }
            setToken('');
            try { localStorage.removeItem('seopc_toolkit_token'); sessionStorage.removeItem('seopc_toolkit_token'); } catch (e) {}
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
            { id: 'network', label: 'DNS & Server', icon: 'network' },
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
