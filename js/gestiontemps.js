/**
 * Plugin gestiontemps — rendu du « disque poids lourd » (donut tachygraphe)
 * et minuteur AJAX. Sans dépendance externe (compatible GLPI 10 et 11).
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    /** Exécute fn quand le DOM est prêt (immédiatement s'il l'est déjà). */
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /** Échappement HTML minimal. */
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Secondes-depuis-minuit -> "HH:MM". */
    function toHHMM(sec) {
        sec = Math.max(0, Math.min(86400, sec | 0));
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    /**
     * Convertit un angle (degrés, 0 = haut) en coordonnées cartésiennes.
     */
    function polar(cx, cy, r, angleDeg) {
        var a = (angleDeg - 90) * Math.PI / 180.0;
        return { x: cx + r * Math.cos(a), y: cy + r * Math.sin(a) };
    }

    /**
     * Décrit un arc SVG (path "d") d'un secteur d'anneau.
     */
    function arcPath(cx, cy, rOuter, rInner, startAngle, endAngle) {
        var largeArc = (endAngle - startAngle) <= 180 ? 0 : 1;
        var p1 = polar(cx, cy, rOuter, endAngle);
        var p2 = polar(cx, cy, rOuter, startAngle);
        var p3 = polar(cx, cy, rInner, startAngle);
        var p4 = polar(cx, cy, rInner, endAngle);
        return [
            'M', p1.x, p1.y,
            'A', rOuter, rOuter, 0, largeArc, 0, p2.x, p2.y,
            'L', p3.x, p3.y,
            'A', rInner, rInner, 0, largeArc, 1, p4.x, p4.y,
            'Z'
        ].join(' ');
    }

    function el(name, attrs) {
        var node = document.createElementNS(SVG_NS, name);
        for (var k in attrs) {
            if (attrs.hasOwnProperty(k)) {
                node.setAttribute(k, attrs[k]);
            }
        }
        return node;
    }

    /**
     * Dessine le disque dans le conteneur fourni.
     */
    function drawDonut(container) {
        if (!container) { return; }
        var raw = container.getAttribute('data-values');
        var data;
        try { data = JSON.parse(raw); } catch (e) { data = null; }
        if (!data) { return; }

        var prodPct = Math.max(0, Math.min(100, parseFloat(data.production_pct) || 0));
        var total = (parseInt(data.production, 10) || 0) + (parseInt(data.manual, 10) || 0);

        container.innerHTML = '';

        var size = 240, cx = size / 2, cy = size / 2;
        var rOuter = 100, rInner = 62;

        var svg = el('svg', {
            viewBox: '0 0 ' + size + ' ' + size,
            width: '100%',
            height: 'auto',
            'class': 'gestiontemps-donut-svg'
        });

        // Fond de l'anneau.
        svg.appendChild(el('circle', {
            cx: cx, cy: cy, r: (rOuter + rInner) / 2,
            fill: 'none',
            'stroke-width': rOuter - rInner,
            'class': 'gt-ring-bg'
        }));

        if (total > 0) {
            var prodAngle = prodPct / 100 * 360;

            // Secteur production.
            if (prodAngle > 0) {
                svg.appendChild(el('path', {
                    d: arcPath(cx, cy, rOuter, rInner, 0, Math.min(prodAngle, 359.999)),
                    'class': 'gt-seg-prod'
                }));
            }
            // Secteur non-production.
            if (prodAngle < 360) {
                svg.appendChild(el('path', {
                    d: arcPath(cx, cy, rOuter, rInner, prodAngle, 360),
                    'class': 'gt-seg-manual'
                }));
            }
        }

        // Graduations façon disque de tachygraphe (24 ticks).
        for (var i = 0; i < 24; i++) {
            var ang = i * 15;
            var pOut = polar(cx, cy, rOuter + 6, ang);
            var pIn = polar(cx, cy, rOuter - 2, ang);
            svg.appendChild(el('line', {
                x1: pIn.x, y1: pIn.y, x2: pOut.x, y2: pOut.y,
                'class': (i % 6 === 0) ? 'gt-tick gt-tick-major' : 'gt-tick'
            }));
        }

        // Texte central : % production.
        var pctText = el('text', {
            x: cx, y: cy - 4,
            'text-anchor': 'middle',
            'class': 'gt-center-pct'
        });
        pctText.textContent = (total > 0 ? prodPct.toFixed(1) : '0') + ' %';
        svg.appendChild(pctText);

        var lbl = el('text', {
            x: cx, y: cy + 20,
            'text-anchor': 'middle',
            'class': 'gt-center-label'
        });
        lbl.textContent = 'production';
        svg.appendChild(lbl);

        container.appendChild(svg);
    }

    /**
     * Dessine le graphique à barres « jour par jour ».
     * Chaque jour : une barre = temps travaillé, un repère = temps théorique.
     * La barre est verte si travaillé >= théorique, orange sinon.
     */
    function drawBars(container) {
        if (!container) { return; }
        var days;
        try { days = JSON.parse(container.getAttribute('data-days')); } catch (e) { days = []; }
        if (!days || !days.length) {
            container.innerHTML = '<p class="text-muted">Aucune donnée sur la période.</p>';
            return;
        }

        container.innerHTML = '';

        var maxSec = 0;
        days.forEach(function (d) {
            maxSec = Math.max(maxSec, d.worked || 0, d.expected || 0);
        });
        if (maxSec <= 0) { maxSec = 3600; }

        var n = days.length;
        var barW = 16, gap = 7;
        var padL = 36, padR = 10, padT = 10, padB = 40;
        var plotH = 130;
        var w = padL + n * (barW + gap) + padR;
        var h = padT + plotH + padB;

        // On fixe la taille NATURELLE (en px) ; le CSS la plafonne à 100 %
        // de la largeur dispo. Ainsi la barre ne s'étire pas quand il y a
        // peu de jours.
        var svg = el('svg', {
            viewBox: '0 0 ' + w + ' ' + h,
            width: w,
            height: h,
            'class': 'gestiontemps-bars-svg',
            preserveAspectRatio: 'xMinYMin meet'
        });

        // Axe Y : quelques graduations en heures.
        var maxHours = Math.ceil(maxSec / 3600);
        var steps = Math.min(maxHours, 6) || 1;
        for (var s = 0; s <= steps; s++) {
            var frac = s / steps;
            var y = padT + plotH - frac * plotH;
            svg.appendChild(el('line', {
                x1: padL, y1: y, x2: w - padR, y2: y, 'class': 'gt-grid'
            }));
            var lbl = el('text', {
                x: padL - 6, y: y + 3, 'text-anchor': 'end', 'class': 'gt-axis-label'
            });
            lbl.textContent = Math.round(frac * maxHours) + 'h';
            svg.appendChild(lbl);
        }

        days.forEach(function (d, i) {
            var x = padL + i * (barW + gap);
            var worked = d.worked || 0;
            var expected = d.expected || 0;
            var barH = (worked / maxSec) * plotH;
            var y = padT + plotH - barH;

            var over = expected > 0 && worked >= expected;
            var cls = expected === 0
                ? 'gt-bar-neutral'
                : (over ? 'gt-bar-over' : 'gt-bar-under');

            var rect = el('rect', {
                x: x, y: y, width: barW, height: Math.max(0, barH),
                rx: 3, 'class': cls
            });
            var title = document.createElementNS(SVG_NS, 'title');
            title.textContent = d.date + ' — ' + fmt(worked) + ' / ' + fmt(expected);
            rect.appendChild(title);
            svg.appendChild(rect);

            // Repère du temps théorique.
            if (expected > 0) {
                var ey = padT + plotH - (expected / maxSec) * plotH;
                svg.appendChild(el('line', {
                    x1: x - 2, y1: ey, x2: x + barW + 2, y2: ey, 'class': 'gt-expected-mark'
                }));
            }

            // Étiquette de date (jour/mois), verticale pour tenir.
            var t = el('text', {
                x: x + barW / 2, y: padT + plotH + 14,
                'text-anchor': 'end', 'class': 'gt-day-label',
                transform: 'rotate(-60 ' + (x + barW / 2) + ' ' + (padT + plotH + 14) + ')'
            });
            t.textContent = d.date.slice(8, 10) + '/' + d.date.slice(5, 7);
            svg.appendChild(t);
        });

        container.appendChild(svg);
    }

    /** Formate une durée (secondes) en "Xh Ym" pour les infobulles. */
    function fmt(sec) {
        sec = Math.max(0, sec | 0);
        var hrs = Math.floor(sec / 3600);
        var min = Math.floor((sec % 3600) / 60);
        if (hrs && min) { return hrs + 'h ' + (min < 10 ? '0' : '') + min + 'm'; }
        if (hrs) { return hrs + 'h'; }
        return min + 'm';
    }

    /**
     * Disque journalier type tachygraphe : cadran 24 h (minuit en haut),
     * avec les segments de travail du jour (production / non-production).
     */
    function drawClock(container) {
        if (!container) { return; }
        var data;
        try { data = JSON.parse(container.getAttribute('data-segments')); } catch (e) { data = null; }
        if (!data) { return; }

        var segments = data.segments || [];
        var DAY = 86400;
        var sched = {};
        try { sched = JSON.parse(container.getAttribute('data-schedule')) || {}; } catch (e) { sched = {}; }

        container.innerHTML = '';

        var size = 264, cx = size / 2, cy = size / 2;
        var rOuter = 100, rInner = 66;

        var svg = el('svg', {
            viewBox: '0 0 ' + size + ' ' + size,
            width: '100%',
            height: 'auto',
            'class': 'gestiontemps-clock-svg'
        });

        // Motif hachuré : distingue visuellement les coupures (temps qui ne
        // compte pas dans le temps de travail) des autres natures.
        var defs = el('defs', {});
        var pat = el('pattern', {
            id: 'gt-hatch', width: 6, height: 6,
            patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)'
        });
        pat.appendChild(el('rect', { width: 6, height: 6, 'class': 'gt-hatch-bg' }));
        pat.appendChild(el('line', { x1: 0, y1: 0, x2: 0, y2: 6, 'class': 'gt-hatch-line' }));
        defs.appendChild(pat);
        svg.appendChild(defs);

        // Convertit un événement de clic en secondes depuis minuit.
        function eventToSeconds(evt) {
            var rect = svg.getBoundingClientRect();
            var scale = rect.width / size;
            var x = (evt.clientX - rect.left) / scale;
            var y = (evt.clientY - rect.top) / scale;
            var dx = x - cx, dy = y - cy;
            var ang = Math.atan2(dx, -dy) * 180 / Math.PI;
            if (ang < 0) { ang += 360; }
            return Math.round(ang / 360 * DAY);
        }

        // Fond de l'anneau : clic sur une zone LIBRE -> création.
        var bg = el('circle', {
            cx: cx, cy: cy, r: (rOuter + rInner) / 2,
            fill: 'none',
            'stroke-width': rOuter - rInner,
            'class': 'gt-ring-bg gt-clickable'
        });
        bg.addEventListener('click', function (evt) {
            var t = eventToSeconds(evt);
            // Bornes du créneau libre autour du clic.
            var gapStart = 0, gapEnd = DAY;
            segments.forEach(function (s) {
                var e = (s.start || 0) + (s.duration || 0);
                if (e <= t && e > gapStart) { gapStart = e; }
                if ((s.start || 0) >= t && (s.start || 0) < gapEnd) { gapEnd = s.start || 0; }
            });
            if (window.gestiontempsOnEmpty) {
                window.gestiontempsOnEmpty({ clicked: t, gapStart: gapStart, gapEnd: gapEnd });
            }
        });
        svg.appendChild(bg);

        // Répartition des segments en couches : deux temps qui se chevauchent
        // sont dessinés sur des anneaux concentriques distincts, pour montrer
        // qu'ils se superposent plutôt que de les masquer l'un l'autre.
        // Algorithme glouton : chaque segment prend la première couche dont le
        // dernier temps est terminé.
        var ordered = segments.slice().sort(function (a, b) {
            return (a.start || 0) - (b.start || 0);
        });
        var laneEnds = [];
        ordered.forEach(function (s) {
            var start = Math.max(0, s.start || 0);
            var end = start + Math.max(0, s.duration || 0);
            var lane = 0;
            while (lane < laneEnds.length && laneEnds[lane] > start) { lane++; }
            laneEnds[lane] = end;
            s._lane = lane;
        });
        var laneCount = Math.max(1, laneEnds.length);

        // Épaisseur d'une couche : l'anneau est partagé entre les couches, avec
        // un léger jeu pour que la superposition reste lisible.
        var ringWidth = rOuter - rInner;
        var laneGap = laneCount > 1 ? 1.5 : 0;
        var laneWidth = (ringWidth - laneGap * (laneCount - 1)) / laneCount;

        // Segments de travail (minuit = 0° en haut, sens horaire).
        ordered.forEach(function (s) {
            var start = Math.max(0, s.start || 0);
            var dur = Math.max(0, s.duration || 0);
            if (dur <= 0) { return; }
            var a0 = (start / DAY) * 360;
            var a1 = Math.min(360, ((start + dur) / DAY) * 360);
            if (a1 <= a0) { a1 = a0 + 0.5; }
            var cls = 'gt-seg-manual';
            if (s.source === 'break') { cls = 'gt-seg-break'; }
            else if (s.source === 'pause') { cls = 'gt-seg-pause'; }
            else if (s.type === 'production') { cls = 'gt-seg-prod'; }

            // Couche 0 = anneau extérieur, les suivantes s'empilent vers le centre.
            var lane = s._lane || 0;
            var segOuter = rOuter - lane * (laneWidth + laneGap);
            var segInner = segOuter - laneWidth;

            var path = el('path', {
                d: arcPath(cx, cy, segOuter, segInner, a0, a1),
                'class': cls + ' gt-clickable' + (lane > 0 ? ' gt-seg-stacked' : '')
            });
            var title = document.createElementNS(SVG_NS, 'title');
            title.textContent = hm(start) + ' → ' + hm(start + dur) + ' (' + fmt(dur) + ')'
                + (laneCount > 1 ? ' — couche ' + (lane + 1) + '/' + laneCount : '');
            path.appendChild(title);
            path.addEventListener('click', function (evt) {
                evt.stopPropagation();
                if (window.gestiontempsOnSegment) {
                    window.gestiontempsOnSegment(s);
                }
            });
            svg.appendChild(path);
        });

        // Repères de l'horaire théorique (matin / après-midi) : bande fine à
        // l'intérieur de l'anneau + traits aux bornes début/fin.
        Object.keys(sched).forEach(function (k) {
            var p = sched[k];
            if (!p || p.length < 2) { return; }
            var a0 = (p[0] / DAY) * 360, a1 = (p[1] / DAY) * 360;
            if (a1 > a0) {
                svg.appendChild(el('path', {
                    d: arcPath(cx, cy, rInner - 1, rInner - 6, a0, a1),
                    'class': 'gt-sched-band'
                }));
            }
            [p[0], p[1]].forEach(function (sec) {
                var ang = (sec / DAY) * 360;
                var pi = polar(cx, cy, rInner - 7, ang);
                var po = polar(cx, cy, rOuter + 7, ang);
                svg.appendChild(el('line', {
                    x1: pi.x, y1: pi.y, x2: po.x, y2: po.y, 'class': 'gt-sched-mark'
                }));
            });
        });

        // Graduations horaires : un trait ET un libellé pour CHAQUE heure (0..23).
        for (var i = 0; i < 24; i++) {
            var ang = i * 15;
            var major = (i % 6 === 0);
            var pOut = polar(cx, cy, rOuter + 5, ang);
            var pIn = polar(cx, cy, rOuter - 2, ang);
            svg.appendChild(el('line', {
                x1: pIn.x, y1: pIn.y, x2: pOut.x, y2: pOut.y,
                'class': major ? 'gt-tick gt-tick-major' : 'gt-tick'
            }));
            var pl = polar(cx, cy, rOuter + 14, ang);
            var t = el('text', {
                x: pl.x, y: pl.y + 3, 'text-anchor': 'middle',
                'class': major ? 'gt-hour-label gt-hour-major' : 'gt-hour-label'
            });
            t.textContent = i;
            svg.appendChild(t);
        }

        // Centre : total travaillé aujourd'hui.
        var c1 = el('text', { x: cx, y: cy - 2, 'text-anchor': 'middle', 'class': 'gt-center-pct' });
        c1.textContent = fmt(data.total || 0);
        svg.appendChild(c1);
        var c2 = el('text', { x: cx, y: cy + 18, 'text-anchor': 'middle', 'class': 'gt-center-label' });
        c2.textContent = "aujourd'hui";
        svg.appendChild(c2);

        container.appendChild(svg);
    }

    /** Formate des secondes-depuis-minuit en "HH:MM". */
    function hm(sec) {
        sec = Math.max(0, Math.min(86400, sec | 0));
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    // Exposition globale (appelée par dashboard.php).
    window.gestiontempsDrawDonut = drawDonut;
    window.gestiontempsDrawClock = drawClock;
    window.gestiontempsDrawBars = drawBars;

    // Auto-init de tout conteneur présent au chargement.
    ready(function () {
        var donuts = document.querySelectorAll('.gestiontemps-donut');
        for (var i = 0; i < donuts.length; i++) {
            drawDonut(donuts[i]);
        }
        var clocks = document.querySelectorAll('.gestiontemps-clock');
        for (var k = 0; k < clocks.length; k++) {
            drawClock(clocks[k]);
        }
        var bars = document.querySelectorAll('.gestiontemps-bars');
        for (var j = 0; j < bars.length; j++) {
            drawBars(bars[j]);
        }
    });

    // --- Chrono start/stop du tableau de bord --------------------------------
    ready(function () {
        var btn = document.getElementById('gt-timer-btn');
        if (!btn) { return; }

        var label = btn.querySelector('.gt-timer-label');
        var icon = btn.querySelector('i');
        var idleText = label ? label.textContent : 'Chrono';
        var modal = document.getElementById('gt-timer-modal');
        var form = document.getElementById('gt-timer-form');
        var durInput = document.getElementById('gt-timer-duration');
        var elapsedEl = document.getElementById('gt-timer-elapsed');
        var commentEl = document.getElementById('gt-timer-comment');
        var cancelBtn = document.getElementById('gt-timer-cancel');

        var startAt = null;
        var interval = null;

        function clk(sec) {
            sec = Math.max(0, sec | 0);
            var h = Math.floor(sec / 3600);
            var m = Math.floor((sec % 3600) / 60);
            var s = sec % 60;
            return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }
        function elapsedSec() { return Math.floor((Date.now() - startAt) / 1000); }
        function tick() { if (label) { label.textContent = clk(elapsedSec()); } }
        function setRunningUI() {
            btn.classList.remove('btn-success');
            btn.classList.add('btn-danger');
            if (icon) { icon.className = 'ti ti-player-stop'; }
            interval = setInterval(tick, 1000);
            tick();
        }
        function setIdleUI() {
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-success');
            if (icon) { icon.className = 'ti ti-player-play'; }
            if (label) { label.textContent = idleText; }
        }
        function reset() {
            if (interval) { clearInterval(interval); interval = null; }
            startAt = null;
            localStorage.removeItem('gt_timer_start');
            setIdleUI();
        }

        var saved = localStorage.getItem('gt_timer_start');
        if (saved) { startAt = parseInt(saved, 10); setRunningUI(); }

        btn.addEventListener('click', function () {
            if (startAt === null) {
                startAt = Date.now();
                localStorage.setItem('gt_timer_start', String(startAt));
                setRunningUI();
            } else {
                if (interval) { clearInterval(interval); interval = null; }
                var sec = elapsedSec();
                if (durInput) { durInput.value = sec; }
                if (elapsedEl) { elapsedEl.textContent = clk(sec); }
                if (modal) { modal.style.display = 'flex'; }
                if (commentEl) { commentEl.focus(); }
            }
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                if (modal) { modal.style.display = 'none'; }
                reset();
            });
        }

        // Mise à disposition / coupure : le commentaire n'est plus obligatoire.
        var natureSel = document.getElementById('gt-timer-nature');
        if (natureSel && commentEl) {
            natureSel.addEventListener('change', function () {
                commentEl.required = (natureSel.value === '');
            });
        }

        if (form) {
            form.addEventListener('submit', function () {
                localStorage.removeItem('gt_timer_start');
            });
        }
    });

    // --- Interactions du disque journalier (info / création) -----------------
    ready(function () {
        var infoModal = document.getElementById('gt-clock-info');
        var createModal = document.getElementById('gt-clock-create');
        if (!infoModal && !createModal) { return; }

        window.gestiontempsOnSegment = function (s) {
            if (!infoModal) { return; }
            var tl = (s.source === 'break')
                ? 'Coupure (hors temps de travail)'
                : (s.source === 'pause')
                    ? 'Mise à disposition'
                    : (s.type === 'production' ? 'Production' : 'Non-production');
            var start = s.start || 0, end = start + (s.duration || 0);
            var html = "<table class='tab_cadre_fixe'>"
                + "<tr class='tab_bg_1'><td>Nature</td><td>" + escapeHtml(tl) + "</td></tr>"
                + "<tr class='tab_bg_1'><td>Début</td><td>" + toHHMM(start) + "</td></tr>"
                + "<tr class='tab_bg_1'><td>Fin</td><td>" + toHHMM(end) + "</td></tr>"
                + "<tr class='tab_bg_1'><td>Durée</td><td>" + fmt(s.duration || 0) + "</td></tr>"
                + "<tr class='tab_bg_1'><td>Commentaire</td><td>"
                + (s.comment ? escapeHtml(s.comment) : '-') + "</td></tr>"
                + "</table>";
            var body = document.getElementById('gt-clock-info-body');
            if (body) { body.innerHTML = html; }
            infoModal.style.display = 'flex';
        };

        window.gestiontempsOnEmpty = function (info) {
            if (!createModal) { return; }
            var st = document.getElementById('gt-create-start');
            var en = document.getElementById('gt-create-end');
            if (st) { st.value = toHHMM(info.gapStart); }
            if (en) {
                // Fin = fin du créneau libre, ou +1h si le créneau va jusqu'à minuit.
                var endSec = (info.gapEnd >= 86400) ? Math.min(86340, info.clicked + 3600) : info.gapEnd;
                en.value = toHHMM(endSec);
            }
            createModal.style.display = 'flex';
        };

        var ic = document.getElementById('gt-clock-info-close');
        if (ic) { ic.addEventListener('click', function () { infoModal.style.display = 'none'; }); }
        var cc = document.getElementById('gt-clock-create-cancel');
        if (cc) { cc.addEventListener('click', function () { createModal.style.display = 'none'; }); }

        var cn = document.getElementById('gt-create-nature');
        var ccom = document.getElementById('gt-create-comment');
        if (cn && ccom) {
            cn.addEventListener('change', function () { ccom.required = (cn.value === ''); });
        }
    });

    /**
     * Minuteur AJAX (optionnel, utilisable depuis un bouton avec
     * data-gestiontemps-timer). Non instancié automatiquement.
     */

    /**
     * Rafraîchissement automatique du tableau de bord.
     *
     * Interroge ajax/dashboard.php à intervalle régulier et redessine les
     * disques + indicateurs, sans recharger la page. Le rafraîchissement est
     * suspendu quand l'onglet est masqué (inutile de solliciter le serveur) et
     * quand une popup est ouverte (pour ne pas la faire disparaître sous les
     * doigts de l'utilisateur).
     */
    function initLiveRefresh() {
        var holder = document.getElementById('gt-live');
        if (!holder) { return; }

        var cfg;
        try { cfg = JSON.parse(holder.getAttribute('data-live')); } catch (e) { cfg = null; }
        if (!cfg || !cfg.url) { return; }

        var INTERVAL = 30000; // 30 s : assez réactif, sans matraquer le serveur.

        function setText(id, value) {
            var node = document.getElementById(id);
            if (node && value != null && node.textContent !== String(value)) {
                node.textContent = value;
            }
        }

        function modalOpen() {
            var ids = ['gt-timer-modal', 'gt-clock-info', 'gt-clock-create'];
            for (var i = 0; i < ids.length; i++) {
                var m = document.getElementById(ids[i]);
                if (m && m.style.display !== 'none' && m.style.display !== '') { return true; }
            }
            return false;
        }

        function apply(d) {
            if (!d) { return; }

            var donut = document.getElementById('gestiontemps-donut');
            if (donut && d.donut) {
                donut.setAttribute('data-values', JSON.stringify(d.donut));
                drawDonut(donut);
            }

            // Le disque journalier n'est rafraîchi que si l'on regarde
            // aujourd'hui : un jour passé ne bouge plus.
            var clock = document.getElementById('gestiontemps-clock');
            if (clock && cfg.is_today && d.clock) {
                clock.setAttribute('data-segments', JSON.stringify(d.clock));
                clock.setAttribute('data-schedule', JSON.stringify(d.schedule || {}));
                drawClock(clock);
            }

            if (d.indicators) {
                setText('gt-ind-pct', d.indicators.production_pct + ' %');
                setText('gt-ind-prod', d.indicators.production_human);
                setText('gt-ind-manual', d.indicators.manual_human);
                setText('gt-ind-total', d.indicators.total_human);
            }

            if (d.attendance) {
                setText('gt-att-over', d.attendance.over_human);
                setText('gt-att-late', d.attendance.late_human);
                setText('gt-att-net', d.attendance.net_human);
                var netWrap = document.getElementById('gt-att-net-wrap');
                if (netWrap) {
                    netWrap.className = netWrap.className.replace(/text-(green|red)/, '')
                        + ((d.attendance.net >= 0) ? ' text-green' : ' text-red');
                }
                var bars = document.getElementById('gestiontemps-bars');
                if (bars && d.attendance.days) {
                    bars.setAttribute('data-days', JSON.stringify(d.attendance.days));
                    drawBars(bars);
                }
            }

            var stamp = document.getElementById('gt-live-stamp');
            if (stamp && d.now) { stamp.textContent = d.now; }
        }

        function tick() {
            if (document.hidden || modalOpen()) { return; }
            var qs = 'from=' + encodeURIComponent(cfg.from)
                + '&to=' + encodeURIComponent(cfg.to)
                + '&day=' + encodeURIComponent(cfg.day)
                + '&users_id=' + encodeURIComponent(cfg.users_id || '');
            fetch(cfg.url + '?' + qs, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(apply)
                .catch(function () { /* réseau indisponible : on réessaiera */ });
        }

        setInterval(tick, INTERVAL);
        // Retour sur l'onglet : on resynchronise immédiatement.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { tick(); }
        });
    }

    ready(initLiveRefresh);

    window.gestiontempsTimer = {
        start: function (ajaxUrl, ticketsId, csrf) {
            return fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=start&tickets_id=' + encodeURIComponent(ticketsId || 0) +
                      '&_glpi_csrf_token=' + encodeURIComponent(csrf || '')
            }).then(function (r) { return r.json(); });
        },
        stop: function (ajaxUrl, comment, csrf) {
            return fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=stop&comment=' + encodeURIComponent(comment || '') +
                      '&_glpi_csrf_token=' + encodeURIComponent(csrf || '')
            }).then(function (r) { return r.json(); });
        }
    };
})();
