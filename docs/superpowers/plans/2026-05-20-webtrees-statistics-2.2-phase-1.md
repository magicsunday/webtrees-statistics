# webtrees-statistics — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `magicsunday/webtrees-statistics` cleanly runnable on webtrees 2.2.* by porting away from the removed `Statistics\Repository\*` types, re-using `StatisticsData` from core where it exists, achieving tooling parity with the other chart modules, and extracting four reusable chart widgets into `magicsunday/webtrees-chart-lib` v1.6.0.

**Architecture:** Two repos change in sequence. First, chart-lib (pure JS / npm) gets four data-agnostic widgets (DonutChart, WorldMap, ProgressList, TagCloud) and a v1.6.0 tag. Second, webtrees-statistics is restructured to consume them, drops its vendored d3, switches to `Fisharebest\Webtrees\StatisticsData`, replaces all hardcoded German + placeholder values, and gains stub actions for the five missing tabs. webtrees-statistics stays on `main` at `1.0.0-dev` — no release tag in Phase 1.

**Tech Stack:** PHP 8.3–8.5, webtrees ^2.2, Symfony/PSR-7, Illuminate DB Query Builder; JS ES2022, modular d3 (`d3-selection`, `d3-shape`, `d3-geo`, `d3-scale`, `d3-array`, `d3-scale-chromatic`), Rollup, Biome, Jest 30 + jest-environment-jsdom; PHPUnit ^12||^13, phpstan ^2 with strict rules, rector ^2.

**Spec:** [`../specs/2026-05-20-webtrees-statistics-2.2-phase-1-design.md`](../specs/2026-05-20-webtrees-statistics-2.2-phase-1-design.md)

**Working trees:**
- chart-lib repo: `/volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib`
- statistics repo: `/volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics`

---

## Audit-loop policy (mandatory, applies to every commit)

Before **every** `git commit` in either repo, run the audit-loop. Skipping this is a plan violation.

1. **Spawn the reviewer set in parallel.** Always-on:
   - `compound-engineering:ce-correctness-reviewer`
   - `compound-engineering:ce-maintainability-reviewer`
   - `compound-engineering:ce-testing-reviewer`
   - `compound-engineering:ce-project-standards-reviewer`

   Conditional (trigger only when the diff matches):
   - `php-reviewer` (user local PHP rules) — any `*.php` change
   - `webtrees-frontend-reviewer` — any `*.js`, `*.css`, D3/SVG, or admin-UI change
   - `compound-engineering:ce-julik-frontend-races-reviewer` — async JS or DOM-timing changes
   - `compound-engineering:ce-security-reviewer` — input handling, routes, escaping
   - `compound-engineering:ce-reliability-reviewer` — SQL, DB queries, external IO
   - `compound-engineering:ce-adversarial-reviewer` — diff ≥ 50 lines or sensitive surface
   - `webtrees-test-quality-reviewer` — `tests/**` changes
2. **Apply every actionable finding.** No "preexisting / not my change" excuses (memory `feedback_no_preexisting_excuse`).
3. **Re-spawn the full set.** Iterate until **two consecutive clean rounds** (memory `feedback_per_issue_double_audit_loop`).
4. **Run `composer ci:test` via buildbox** using the `ci-test-buildbox` skill (memory `feedback_ci_test_workflow`). Must be green.
5. **Browser-verify if UI changed.** Playwright against `webtrees.nas.lan`, screenshots into `/tmp/statistics-phase-1/` (memory `feedback_no_screenshots_root`).
6. **Commit.** Subject is a capitalised verb, no Conventional-Commits prefix, no Co-Authored-By trailer (memory `feedback_commit_message_style` + `feedback_no_coauthor`). Use `git -C <repo-path> commit` (memory `feedback_pwd_before_git`).

If the agent dispatches reviewers, each reviewer must be given:
- the target repo path
- the staged diff (`git -C <path> diff --staged`)
- the in-flight task's commit subject (so reviewers know the intended scope)

---

## File-Structure overview

### chart-lib repo (additions only — no existing file deleted)

```
src/chart/widgets/
├── base-widget.js               # constructor, target resolution, dimensions, empty-state
├── donut-chart.js               # extends BaseWidget; d3-shape arc + pie
├── world-map.js                 # extends BaseWidget; d3-geo + d3-scale
├── progress-list.js             # extends BaseWidget; HTML, no SVG
└── tag-cloud.js                 # extends BaseWidget; own SVG layout, d3-scale

tests/widgets/
├── base-widget.test.js
├── donut-chart.test.js
├── world-map.test.js
├── progress-list.test.js
└── tag-cloud.test.js

src/index.js                     # MODIFIED — append five exports
package.json                     # MODIFIED — version, new dependencies
README.md                        # MODIFIED — "Widgets" section
```

### webtrees-statistics repo

```
composer.json                    # MODIFIED — name fix, php range, webtrees ~2.2, module-base, dev deps
package.json                     # MODIFIED — chart-lib npm pin, modular d3 deps
module.php                       # MODIFIED — drop chart-lib PSR-4 (it has no PHP)
src/Module.php                   # MODIFIED — interfaces, stub actions, action cleanup
src/Statistic.php                # MODIFIED — inject StatisticsData, I18N, drop placeholders
src/Repository/IndividualRepository.php   # MODIFIED — thin delegation
src/Repository/FamilyRepository.php       # MODIFIED — delegation + new Widowed/Divorced
src/Repository/NameRepository.php         # MODIFIED — thin delegation
src/Repository/EventRepository.php        # MODIFIED — delegation, country mapping, dead code purge

# New tooling files (copied from fan-chart, edited only where module-specific)
Makefile
biome.json
phpstan.neon
phpunit.xml
rollup.config.js
.php-cs-fixer.dist.php
.phplint.yml
rector.php
jsconfig.json
.jscpd.json
.github/workflows/ci.yml

# New views
resources/views/modules/statistics-chart/Templates/ComingSoon.phtml
resources/views/modules/statistics-chart/Templates/Relationships.phtml
resources/views/modules/statistics-chart/Templates/Age.phtml
resources/views/modules/statistics-chart/Templates/Weddings.phtml
resources/views/modules/statistics-chart/Templates/Divorces.phtml
resources/views/modules/statistics-chart/Templates/Children.phtml

# New CSS
resources/css/webtrees-statistics.css

# New JS structure
resources/js/modules/index.js                        # MODIFIED — data-widget driver
resources/js/modules/widgets/donut.js                # NEW
resources/js/modules/widgets/world-map.js            # NEW
resources/js/modules/widgets/progress-list.js        # NEW
resources/js/modules/widgets/tag-cloud.js            # NEW
resources/js/modules/lib/d3.js                       # DELETED
resources/js/modules/lib/base-chart.js               # DELETED
resources/js/modules/lib/chart.js                    # DELETED
resources/js/modules/lib/chart/donut-chart.js        # DELETED
resources/js/modules/lib/chart/world-map.js          # DELETED

# New tests
tests/ModuleTest.php
tests/StatisticTest.php
tests/Repository/IndividualRepositoryTest.php
tests/Repository/FamilyRepositoryTest.php
tests/Repository/EventRepositoryTest.php
tests/Repository/NameRepositoryTest.php
resources/js/tests/widgets/donut.test.js
resources/js/tests/widgets/world-map.test.js
resources/js/tests/widgets/progress-list.test.js
resources/js/tests/widgets/tag-cloud.test.js
```

---

# Part A — chart-lib v1.6.0

Repo path used in commands: `/volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib` (referenced below as `${LIB}`).

## Task A1: BaseWidget scaffold + first test

**Files:**
- Create: `${LIB}/src/chart/widgets/base-widget.js`
- Create: `${LIB}/tests/widgets/base-widget.test.js`

- [ ] **Step 1: Write the failing test.**

Create `${LIB}/tests/widgets/base-widget.test.js`:

```js
import { describe, expect, test } from "@jest/globals";
import BaseWidget from "../../src/chart/widgets/base-widget.js";

describe("BaseWidget", () => {
    test("resolves target from id string with leading #", () => {
        document.body.innerHTML = '<div id="t1"></div>';
        const w = new BaseWidget("#t1", {});
        expect(w.target).toBe(document.getElementById("t1"));
    });

    test("resolves target from id string without #", () => {
        document.body.innerHTML = '<div id="t2"></div>';
        const w = new BaseWidget("t2", {});
        expect(w.target).toBe(document.getElementById("t2"));
    });

    test("accepts an HTMLElement directly", () => {
        const el = document.createElement("div");
        document.body.appendChild(el);
        const w = new BaseWidget(el, {});
        expect(w.target).toBe(el);
    });

    test("throws when target does not exist", () => {
        document.body.innerHTML = "";
        expect(() => new BaseWidget("#missing", {})).toThrow(
            /target not found/i,
        );
    });

    test("computes width from container when option absent", () => {
        const el = document.createElement("div");
        Object.defineProperty(el, "clientWidth", { value: 320 });
        document.body.appendChild(el);
        const w = new BaseWidget(el, {});
        expect(w.dimensions(250, 250).width).toBe(320);
    });

    test("renders empty state when draw([]) is called via helper", () => {
        document.body.innerHTML = '<div id="t3"></div>';
        const w = new BaseWidget("#t3", {});
        const node = w.renderEmptyState("No data");
        expect(node.textContent).toMatch(/no data/i);
        expect(document.querySelector("#t3 .chart-empty-state")).not.toBeNull();
    });
});
```

- [ ] **Step 2: Run test to verify it fails.**

Run: `cd ${LIB} && npm test -- tests/widgets/base-widget.test.js`
Expected: FAIL — `Cannot find module '../../src/chart/widgets/base-widget.js'`

- [ ] **Step 3: Write the minimal BaseWidget implementation.**

Create `${LIB}/src/chart/widgets/base-widget.js`:

```js
/**
 * Common base class for chart-lib widgets.
 *
 * Provides target resolution (id or HTMLElement), dimension computation, and
 * an empty-state renderer so consumers can call draw([]) without guarding.
 */
export default class BaseWidget {
    /**
     * @param {string|HTMLElement} target  Either a DOM id (with or without leading #) or an HTMLElement.
     * @param {object} options             Widget-specific options. See subclasses.
     */
    constructor(target, options = {}) {
        this.target = this._resolveTarget(target);
        this.options = options;
    }

    /**
     * @param {string|HTMLElement} target
     * @returns {HTMLElement}
     */
    _resolveTarget(target) {
        if (target instanceof HTMLElement) {
            return target;
        }
        const id = typeof target === "string" && target.startsWith("#")
            ? target.slice(1)
            : target;
        const el = document.getElementById(id);
        if (el === null) {
            throw new Error(`BaseWidget: target not found for "${target}"`);
        }
        return el;
    }

    /**
     * Resolve effective width/height from options first, then the container.
     *
     * @param {number} defaultWidth
     * @param {number} defaultHeight
     * @returns {{width: number, height: number}}
     */
    dimensions(defaultWidth, defaultHeight) {
        const w = this.options.width && this.options.width > 0
            ? this.options.width
            : this.target.clientWidth || defaultWidth;
        const h = this.options.height && this.options.height > 0
            ? this.options.height
            : this.target.clientHeight || defaultHeight;
        return { width: w, height: h };
    }

    /**
     * Append a neutral empty-state element to the target and return it.
     *
     * @param {string} message
     * @returns {HTMLElement}
     */
    renderEmptyState(message) {
        const el = document.createElement("div");
        el.className = "chart-empty-state";
        el.textContent = message;
        this.target.appendChild(el);
        return el;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass.**

Run: `cd ${LIB} && npm test -- tests/widgets/base-widget.test.js`
Expected: PASS — 6 tests.

- [ ] **Step 5: Run the audit-loop.**

Spawn reviewers per the audit-loop policy. Iterate until 2× clean.

- [ ] **Step 6: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib add \
    src/chart/widgets/base-widget.js tests/widgets/base-widget.test.js
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib commit -m "Add BaseWidget scaffold for chart-lib widget family

Resolves target as id-string or HTMLElement, derives effective dimensions
from options/container, exposes a shared empty-state renderer so widget
draw([]) callers do not need to guard."
```

---

## Task A2: DonutChart widget

**Files:**
- Create: `${LIB}/src/chart/widgets/donut-chart.js`
- Create: `${LIB}/tests/widgets/donut-chart.test.js`

- [ ] **Step 1: Write the failing test.**

Create `${LIB}/tests/widgets/donut-chart.test.js`:

```js
import { describe, expect, test } from "@jest/globals";
import DonutChart from "../../src/chart/widgets/donut-chart.js";

const sample = [
    { label: "Male",   value: 120, class: "male" },
    { label: "Female", value: 105, class: "female" },
    { label: "Unknown", value: 5,  class: "unknown" },
];

describe("DonutChart", () => {
    test("draw([]) renders empty-state with neutral message", () => {
        document.body.innerHTML = '<div id="t"></div>';
        new DonutChart("#t", {}).draw([]);
        expect(document.querySelector("#t .chart-empty-state")).not.toBeNull();
    });

    test("draw(sample) creates one path per slice", () => {
        document.body.innerHTML = '<div id="t"></div>';
        new DonutChart("#t", {}).draw(sample);
        expect(document.querySelectorAll("#t svg path")).toHaveLength(3);
    });

    test("each slice carries the provided class", () => {
        document.body.innerHTML = '<div id="t"></div>';
        new DonutChart("#t", {}).draw(sample);
        const classes = [...document.querySelectorAll("#t svg path")].map(
            (p) => p.getAttribute("class"),
        );
        expect(classes).toEqual([
            "slice male",
            "slice female",
            "slice unknown",
        ]);
    });

    test("each slice has a <title> for native tooltip", () => {
        document.body.innerHTML = '<div id="t"></div>';
        new DonutChart("#t", {}).draw(sample);
        const titles = [...document.querySelectorAll("#t svg path title")].map(
            (t) => t.textContent,
        );
        expect(titles).toEqual([
            "Male: 120",
            "Female: 105",
            "Unknown: 5",
        ]);
    });

    test("holeSize option drives inner radius", () => {
        document.body.innerHTML = '<div id="t"></div>';
        const widget = new DonutChart("#t", { holeSize: 0 });
        widget.draw(sample);
        expect(widget._holeSize).toBe(0);
    });
});
```

- [ ] **Step 2: Run test to verify it fails.**

Run: `cd ${LIB} && npm test -- tests/widgets/donut-chart.test.js`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement DonutChart.**

Create `${LIB}/src/chart/widgets/donut-chart.js`:

```js
import { select } from "d3-selection";
import { arc as d3Arc, pie as d3Pie } from "d3-shape";

import BaseWidget from "./base-widget.js";

/**
 * D3-powered donut chart. Renders one <path> per data row with caller-provided
 * CSS classes and native <title> tooltips. Empty data triggers the
 * neutral empty-state from BaseWidget.
 */
export default class DonutChart extends BaseWidget {
    /**
     * @param {string|HTMLElement} target
     * @param {{holeSize?: number, margin?: number, width?: number, height?: number, emptyMessage?: string}} options
     */
    constructor(target, options = {}) {
        super(target, options);
        const { width, height } = this.dimensions(250, 250);
        this._width = Math.min(width, height);
        this._height = this._width;
        this._margin = this.options.margin ?? 1;
        this._radius = (this._width >> 1) - this._margin;
        this._holeSize = this.options.holeSize ?? this._radius - this._radius / 10;
    }

    /**
     * @param {Array<{label: string, value: number, class?: string, fill?: string}>} data
     * @returns {SVGSVGElement|HTMLElement}
     */
    draw(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return this.renderEmptyState(
                this.options.emptyMessage ?? "No data available",
            );
        }

        const arc = d3Arc()
            .innerRadius(this._holeSize)
            .outerRadius(this._radius);

        const pie = d3Pie()
            .padAngle(1 / this._radius)
            .sort(null)
            .value((d) => d.value);

        const svg = select(this.target)
            .append("svg")
            .attr("class", "donut-chart")
            .attr("width", this._width)
            .attr("height", this._height)
            .attr(
                "viewBox",
                [
                    -this._width / 2,
                    -this._height / 2,
                    this._width,
                    this._height,
                ].join(" "),
            )
            .attr("style", "max-width: 100%; height: auto;");

        const slices = svg
            .append("g")
            .selectAll("path")
            .data(pie(data))
            .join("path")
            .attr("d", arc)
            .attr("class", (d) =>
                d.data.class ? `slice ${d.data.class}` : "slice",
            )
            .attr("fill", (d) => d.data.fill ?? null);

        slices
            .append("title")
            .text((d) => `${d.data.label}: ${d.data.value.toLocaleString()}`);

        return svg.node();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass.**

Run: `cd ${LIB} && npm test -- tests/widgets/donut-chart.test.js`
Expected: PASS — 5 tests.

- [ ] **Step 5: Audit-loop.**

- [ ] **Step 6: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib add \
    src/chart/widgets/donut-chart.js tests/widgets/donut-chart.test.js
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib commit -m "Add DonutChart widget with class + native-title tooltips

One <path> per row, caller-controlled CSS class names, viewBox-scaled SVG,
holeSize option overrides the default inner radius. Empty data delegates
to BaseWidget.renderEmptyState."
```

---

## Task A3: WorldMap widget

**Files:**
- Create: `${LIB}/src/chart/widgets/world-map.js`
- Create: `${LIB}/tests/widgets/world-map.test.js`

- [ ] **Step 1: Write the failing test.**

Create `${LIB}/tests/widgets/world-map.test.js`:

```js
import { describe, expect, test } from "@jest/globals";
import WorldMap from "../../src/chart/widgets/world-map.js";

const fakeGeo = {
    type: "FeatureCollection",
    features: [
        {
            type: "Feature",
            properties: { iso_a2: "DE", name: "Germany" },
            geometry: { type: "Polygon", coordinates: [[[0, 0], [1, 0], [1, 1], [0, 0]]] },
        },
        {
            type: "Feature",
            properties: { iso_a2: "FR", name: "France" },
            geometry: { type: "Polygon", coordinates: [[[2, 2], [3, 2], [3, 3], [2, 2]]] },
        },
    ],
};

describe("WorldMap", () => {
    test("draw([]) renders empty-state when no data is provided", () => {
        document.body.innerHTML = '<div id="m"></div>';
        new WorldMap("#m", { geojson: fakeGeo }).draw([]);
        expect(document.querySelector("#m .chart-empty-state")).not.toBeNull();
    });

    test("throws when geojson option is missing", () => {
        document.body.innerHTML = '<div id="m"></div>';
        expect(() => new WorldMap("#m", {})).toThrow(/geojson/i);
    });

    test("renders one <path> per geojson feature", () => {
        document.body.innerHTML = '<div id="m"></div>';
        new WorldMap("#m", { geojson: fakeGeo }).draw([
            { countryCode: "DE", label: "Germany", count: 5 },
        ]);
        expect(document.querySelectorAll("#m svg path.country")).toHaveLength(2);
    });

    test("matches data to features case-insensitively by iso_a2", () => {
        document.body.innerHTML = '<div id="m"></div>';
        new WorldMap("#m", { geojson: fakeGeo }).draw([
            { countryCode: "de", label: "Germany", count: 5 },
            { countryCode: "FR", label: "France",  count: 2 },
        ]);
        const counts = [...document.querySelectorAll("#m svg path.country")].map(
            (p) => p.getAttribute("data-count"),
        );
        expect(counts).toEqual(["5", "2"]);
    });

    test("country without data has data-count='0'", () => {
        document.body.innerHTML = '<div id="m"></div>';
        new WorldMap("#m", { geojson: fakeGeo }).draw([
            { countryCode: "DE", label: "Germany", count: 5 },
        ]);
        const fr = [...document.querySelectorAll("#m svg path.country")].find(
            (p) => p.getAttribute("data-iso") === "FR",
        );
        expect(fr.getAttribute("data-count")).toBe("0");
    });
});
```

- [ ] **Step 2: Run test to verify it fails.**

Run: `cd ${LIB} && npm test -- tests/widgets/world-map.test.js`
Expected: FAIL.

- [ ] **Step 3: Implement WorldMap.**

Create `${LIB}/src/chart/widgets/world-map.js`:

```js
import { extent } from "d3-array";
import { geoEquirectangular, geoPath } from "d3-geo";
import { scaleSequential } from "d3-scale";
import { interpolateBlues } from "d3-scale-chromatic";
import { select } from "d3-selection";

import BaseWidget from "./base-widget.js";

/**
 * D3-powered choropleth world map. Geojson is consumer-owned (not bundled).
 * Country lookup is by iso_a2 (case-insensitive) matched against
 * `feature.properties.iso_a2`. Caller decides projection / color scale.
 */
export default class WorldMap extends BaseWidget {
    /**
     * @param {string|HTMLElement} target
     * @param {{
     *     geojson: object,
     *     projection?: any,
     *     colorScale?: any,
     *     emptyMessage?: string,
     *     width?: number,
     *     height?: number
     * }} options
     */
    constructor(target, options = {}) {
        super(target, options);
        if (!options.geojson || typeof options.geojson !== "object") {
            throw new Error("WorldMap: options.geojson is required");
        }
        const { width, height } = this.dimensions(640, 320);
        this._width = width;
        this._height = height;
    }

    /**
     * @param {Array<{countryCode: string, label: string, count: number}>} data
     * @returns {SVGSVGElement|HTMLElement}
     */
    draw(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return this.renderEmptyState(
                this.options.emptyMessage ?? "No data available",
            );
        }

        const byIso = new Map(
            data.map((row) => [row.countryCode.toUpperCase(), row]),
        );

        const projection = (this.options.projection ?? geoEquirectangular())
            .fitSize([this._width, this._height], this.options.geojson);
        const path = geoPath(projection);

        const colorDomain = extent(data, (d) => d.count);
        const color =
            this.options.colorScale ??
            scaleSequential(interpolateBlues).domain(colorDomain);

        const svg = select(this.target)
            .append("svg")
            .attr("class", "world-map")
            .attr("width", this._width)
            .attr("height", this._height)
            .attr("viewBox", `0 0 ${this._width} ${this._height}`)
            .attr("style", "max-width: 100%; height: auto;");

        svg
            .append("g")
            .selectAll("path")
            .data(this.options.geojson.features)
            .join("path")
            .attr("class", "country")
            .attr("d", path)
            .attr("data-iso", (f) => f.properties?.iso_a2 ?? "")
            .attr("data-count", (f) => {
                const iso = (f.properties?.iso_a2 ?? "").toUpperCase();
                return String(byIso.get(iso)?.count ?? 0);
            })
            .attr("fill", (f) => {
                const iso = (f.properties?.iso_a2 ?? "").toUpperCase();
                const row = byIso.get(iso);
                return row ? color(row.count) : "var(--chart-empty-fill, #eee)";
            })
            .append("title")
            .text((f) => {
                const iso = (f.properties?.iso_a2 ?? "").toUpperCase();
                const row = byIso.get(iso);
                const name = row?.label ?? f.properties?.name ?? iso;
                const count = row?.count ?? 0;
                return `${name}: ${count.toLocaleString()}`;
            });

        return svg.node();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass.**

Run: `cd ${LIB} && npm test -- tests/widgets/world-map.test.js`
Expected: PASS — 5 tests.

- [ ] **Step 5: Audit-loop.**

- [ ] **Step 6: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib add \
    src/chart/widgets/world-map.js tests/widgets/world-map.test.js
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib commit -m "Add WorldMap widget — choropleth with consumer-owned geojson

Map projection and color scale are overridable, data joins via iso_a2
(case-insensitive), countries without data carry data-count='0' and use
a neutral fill via CSS variable. Geojson is required and not bundled."
```

---

## Task A4: ProgressList widget

**Files:**
- Create: `${LIB}/src/chart/widgets/progress-list.js`
- Create: `${LIB}/tests/widgets/progress-list.test.js`

- [ ] **Step 1: Write the failing test.**

Create `${LIB}/tests/widgets/progress-list.test.js`:

```js
import { describe, expect, test } from "@jest/globals";
import ProgressList from "../../src/chart/widgets/progress-list.js";

const sample = [
    { label: "Sonntag", value: 12 },
    { label: "Schmidt", value: 9 },
    { label: "Meier",   value: 6 },
];

describe("ProgressList", () => {
    test("draw([]) renders empty-state", () => {
        document.body.innerHTML = '<div id="l"></div>';
        new ProgressList("#l", {}).draw([]);
        expect(document.querySelector("#l .chart-empty-state")).not.toBeNull();
    });

    test("renders <li> per row", () => {
        document.body.innerHTML = '<div id="l"></div>';
        new ProgressList("#l", {}).draw(sample);
        expect(document.querySelectorAll("#l ul.progress-list > li")).toHaveLength(3);
    });

    test("first row has 100% bar relative to dataset max", () => {
        document.body.innerHTML = '<div id="l"></div>';
        new ProgressList("#l", {}).draw(sample);
        const bar = document.querySelector(
            "#l ul.progress-list > li:first-child .progress-bar-fill",
        );
        expect(bar.style.width).toBe("100%");
    });

    test("maxItems trims the list", () => {
        document.body.innerHTML = '<div id="l"></div>';
        new ProgressList("#l", { maxItems: 2 }).draw(sample);
        expect(document.querySelectorAll("#l ul.progress-list > li")).toHaveLength(2);
    });

    test("formatter customises value display", () => {
        document.body.innerHTML = '<div id="l"></div>';
        new ProgressList("#l", { formatter: (v) => `${v}×` }).draw(sample);
        const first = document.querySelector(
            "#l ul.progress-list > li:first-child .progress-value",
        );
        expect(first.textContent).toBe("12×");
    });
});
```

- [ ] **Step 2: Run test to verify it fails.**

Run: `cd ${LIB} && npm test -- tests/widgets/progress-list.test.js`
Expected: FAIL.

- [ ] **Step 3: Implement ProgressList.**

Create `${LIB}/src/chart/widgets/progress-list.js`:

```js
import BaseWidget from "./base-widget.js";

/**
 * Plain-HTML labelled progress-bar list. SVG is overkill for label+bar+value;
 * HTML allows native wrapping, accessibility, and the chart-css-variable
 * pattern to drive bar fill colors.
 */
export default class ProgressList extends BaseWidget {
    /**
     * @param {string|HTMLElement} target
     * @param {{maxItems?: number, formatter?: (value: number) => string, emptyMessage?: string}} options
     */
    constructor(target, options = {}) {
        super(target, options);
        this._maxItems = options.maxItems ?? Number.POSITIVE_INFINITY;
        this._formatter = options.formatter ?? ((v) => v.toLocaleString());
    }

    /**
     * @param {Array<{label: string, value: number, total?: number}>} data
     * @returns {HTMLUListElement|HTMLElement}
     */
    draw(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return this.renderEmptyState(
                this.options.emptyMessage ?? "No data available",
            );
        }

        const rows = data.slice(0, this._maxItems);
        const datasetMax = rows.reduce(
            (max, row) => Math.max(max, row.value),
            0,
        );

        const ul = document.createElement("ul");
        ul.className = "progress-list";

        for (const row of rows) {
            const denominator = row.total ?? datasetMax;
            const pct = denominator > 0 ? (row.value / denominator) * 100 : 0;

            const li = document.createElement("li");
            li.innerHTML = `
                <span class="progress-label">${escapeHtml(row.label)}</span>
                <span class="progress-bar"><span class="progress-bar-fill" style="width: ${pct.toFixed(1)}%"></span></span>
                <span class="progress-value">${escapeHtml(this._formatter(row.value))}</span>
            `;
            ul.appendChild(li);
        }

        this.target.appendChild(ul);
        return ul;
    }
}

/**
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    return String(str)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#39;");
}
```

- [ ] **Step 4: Run tests to verify they pass.**

Run: `cd ${LIB} && npm test -- tests/widgets/progress-list.test.js`
Expected: PASS — 5 tests.

- [ ] **Step 5: Audit-loop.**

- [ ] **Step 6: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib add \
    src/chart/widgets/progress-list.js tests/widgets/progress-list.test.js
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib commit -m "Add ProgressList widget — plain HTML list with percent bars

Bar widths are relative to per-row total when present, otherwise to dataset
max. maxItems trims, formatter customises value text. HTML beats SVG here
for native wrapping, accessibility, and CSS-variable theming."
```

---

## Task A5: TagCloud widget

**Files:**
- Create: `${LIB}/src/chart/widgets/tag-cloud.js`
- Create: `${LIB}/tests/widgets/tag-cloud.test.js`

- [ ] **Step 1: Write the failing test.**

Create `${LIB}/tests/widgets/tag-cloud.test.js`:

```js
import { describe, expect, test } from "@jest/globals";
import TagCloud from "../../src/chart/widgets/tag-cloud.js";

const sample = [
    { label: "Schmidt",  value: 18 },
    { label: "Müller",   value: 12 },
    { label: "Sonntag",  value: 4 },
];

describe("TagCloud", () => {
    test("draw([]) renders empty-state", () => {
        document.body.innerHTML = '<div id="c"></div>';
        new TagCloud("#c", {}).draw([]);
        expect(document.querySelector("#c .chart-empty-state")).not.toBeNull();
    });

    test("renders one <span> per tag", () => {
        document.body.innerHTML = '<div id="c"></div>';
        new TagCloud("#c", {}).draw(sample);
        expect(document.querySelectorAll("#c .tag-cloud > span")).toHaveLength(3);
    });

    test("highest-value tag uses maxFont, lowest uses minFont", () => {
        document.body.innerHTML = '<div id="c"></div>';
        new TagCloud("#c", { minFont: 10, maxFont: 40 }).draw(sample);
        const spans = [...document.querySelectorAll("#c .tag-cloud > span")];
        const first = parseFloat(spans[0].style.fontSize);
        const last = parseFloat(spans[spans.length - 1].style.fontSize);
        expect(first).toBe(40);
        expect(last).toBe(10);
    });

    test("equal values render at maxFont", () => {
        document.body.innerHTML = '<div id="c"></div>';
        new TagCloud("#c", { minFont: 10, maxFont: 40 }).draw([
            { label: "A", value: 5 },
            { label: "B", value: 5 },
        ]);
        const sizes = [
            ...document.querySelectorAll("#c .tag-cloud > span"),
        ].map((s) => parseFloat(s.style.fontSize));
        expect(sizes).toEqual([40, 40]);
    });
});
```

- [ ] **Step 2: Run test to verify it fails.**

Run: `cd ${LIB} && npm test -- tests/widgets/tag-cloud.test.js`
Expected: FAIL.

- [ ] **Step 3: Implement TagCloud.**

Create `${LIB}/src/chart/widgets/tag-cloud.js`:

```js
import { extent } from "d3-array";
import { scaleLinear } from "d3-scale";

import BaseWidget from "./base-widget.js";

/**
 * Simple flow-layout tag cloud. No d3-cloud dependency — that package
 * adds ~50 KB and forces a layout pass; for our use case (≤ 20 surnames)
 * native CSS flow with linear font-size scaling is enough.
 */
export default class TagCloud extends BaseWidget {
    /**
     * @param {string|HTMLElement} target
     * @param {{minFont?: number, maxFont?: number, emptyMessage?: string}} options
     */
    constructor(target, options = {}) {
        super(target, options);
        this._minFont = options.minFont ?? 10;
        this._maxFont = options.maxFont ?? 48;
    }

    /**
     * @param {Array<{label: string, value: number}>} data
     * @returns {HTMLDivElement|HTMLElement}
     */
    draw(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return this.renderEmptyState(
                this.options.emptyMessage ?? "No data available",
            );
        }

        const [min, max] = extent(data, (d) => d.value);
        const scale =
            min === max
                ? () => this._maxFont
                : scaleLinear()
                      .domain([min, max])
                      .range([this._minFont, this._maxFont]);

        const wrapper = document.createElement("div");
        wrapper.className = "tag-cloud";

        for (const row of data) {
            const span = document.createElement("span");
            span.textContent = row.label;
            span.style.fontSize = `${scale(row.value)}px`;
            span.setAttribute(
                "title",
                `${row.label}: ${row.value.toLocaleString()}`,
            );
            wrapper.appendChild(span);
        }

        this.target.appendChild(wrapper);
        return wrapper;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass.**

Run: `cd ${LIB} && npm test -- tests/widgets/tag-cloud.test.js`
Expected: PASS — 4 tests.

- [ ] **Step 5: Audit-loop.**

- [ ] **Step 6: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib add \
    src/chart/widgets/tag-cloud.js tests/widgets/tag-cloud.test.js
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib commit -m "Add TagCloud widget — linear-scale font sizing without d3-cloud

Plain flow layout, font size linearly scales from minFont to maxFont
across value extent. Equal-value sets clamp to maxFont. Skips the
d3-cloud dependency (~50 KB) — overkill for the surname use case."
```

---

## Task A6: Export widgets + add modular d3 dependencies

**Files:**
- Modify: `${LIB}/src/index.js`
- Modify: `${LIB}/package.json`

- [ ] **Step 1: Append the five new exports.**

Open `${LIB}/src/index.js` and append at the end (after existing exports, preserving sort):

```js
export { default as BaseWidget } from "./chart/widgets/base-widget.js";
export { default as DonutChart } from "./chart/widgets/donut-chart.js";
export { default as ProgressList } from "./chart/widgets/progress-list.js";
export { default as TagCloud } from "./chart/widgets/tag-cloud.js";
export { default as WorldMap } from "./chart/widgets/world-map.js";
```

- [ ] **Step 2: Add modular d3 deps to `package.json`.**

In `${LIB}/package.json`, add to `dependencies` (create the block if missing — fan-chart-style):

```json
"dependencies": {
    "d3-array": "^3.2",
    "d3-geo": "^3.1",
    "d3-scale": "^4.0",
    "d3-scale-chromatic": "^3.0",
    "d3-shape": "^3.2"
}
```

Verify `d3-selection` is already present in dependencies — if not, add it.

- [ ] **Step 3: Run `npm install` to update lockfile.**

Run: `cd ${LIB} && npm install --no-audit --no-fund`
Expected: lockfile updated, no errors.

- [ ] **Step 4: Run the full widget test suite.**

Run: `cd ${LIB} && npm test -- tests/widgets/`
Expected: All five widget tests pass (24 tests in total).

- [ ] **Step 5: Run lint + format.**

Run: `cd ${LIB} && npm run lint && npm run format:check`
Expected: clean.

- [ ] **Step 6: Audit-loop.**

- [ ] **Step 7: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib add \
    src/index.js package.json package-lock.json
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib commit -m "Export new widgets + add modular d3 dependencies

Widgets are reachable as named imports from the chart-lib root. Pins
d3-array, d3-geo, d3-scale, d3-scale-chromatic, d3-shape at the same
caret ranges fan-chart uses for its own modular d3 set."
```

---

## Task A7: Bump version + README widgets section

**Files:**
- Modify: `${LIB}/package.json`
- Modify: `${LIB}/README.md`

- [ ] **Step 1: Bump version.**

In `${LIB}/package.json`, change `"version": "1.5.1"` to `"version": "1.6.0"`.

- [ ] **Step 2: Add README section.**

In `${LIB}/README.md`, add (insert before the License section, or at the end if there's none yet):

```markdown
## Widgets

Data-agnostic chart primitives. Each widget shares the same constructor + `draw(data)` shape and renders an empty-state element when `draw([])` is called.

### DonutChart

```js
import { DonutChart } from "@magicsunday/webtrees-chart-lib";

new DonutChart("#target", { holeSize: 80 })
    .draw([
        { label: "Male",   value: 120, class: "male" },
        { label: "Female", value: 105, class: "female" },
    ]);
```

Options: `holeSize`, `margin`, `width`, `height`, `emptyMessage`.

### WorldMap

```js
import { WorldMap } from "@magicsunday/webtrees-chart-lib";

new WorldMap("#target", { geojson: myGeoJson })
    .draw([{ countryCode: "DE", label: "Germany", count: 5 }]);
```

Options: `geojson` (required), `projection`, `colorScale`, `width`, `height`, `emptyMessage`. Geojson is consumer-owned and not bundled.

### ProgressList

```js
import { ProgressList } from "@magicsunday/webtrees-chart-lib";

new ProgressList("#target", { maxItems: 10 })
    .draw([{ label: "Sonntag", value: 12 }]);
```

Options: `maxItems`, `formatter`, `emptyMessage`.

### TagCloud

```js
import { TagCloud } from "@magicsunday/webtrees-chart-lib";

new TagCloud("#target", { minFont: 10, maxFont: 48 })
    .draw([{ label: "Sonntag", value: 12 }]);
```

Options: `minFont`, `maxFont`, `emptyMessage`.
```

- [ ] **Step 3: Audit-loop.**

- [ ] **Step 4: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib add \
    package.json README.md
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib commit -m "Bump chart-lib to 1.6.0 + document widget API

Minor bump — additive only. README gains a Widgets section covering
DonutChart, WorldMap, ProgressList, TagCloud with usage examples and
option summaries."
```

---

## Task A8: Tag + GitHub release

- [ ] **Step 1: Push the branch.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib push origin main
```

- [ ] **Step 2: Tag v1.6.0.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib tag -a v1.6.0 -m "Release v1.6.0 — chart widgets"
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-chart-lib push origin v1.6.0
```

- [ ] **Step 3: Create GitHub release.**

```bash
gh release create v1.6.0 \
    --repo magicsunday/webtrees-chart-lib \
    --title "v1.6.0 — Chart widgets" \
    --notes "Adds data-agnostic chart widgets: DonutChart, WorldMap, ProgressList, TagCloud. Existing exports unchanged. See README §Widgets for usage."
```

- [ ] **Step 4: Verify release.**

```bash
gh release view v1.6.0 --repo magicsunday/webtrees-chart-lib
```

Expected: release listed with tag `v1.6.0`.

---

# Part B — webtrees-statistics port to 2.2

Repo path used in commands: `/volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics` (referenced below as `${STAT}`).

## Task B1: composer.json — pin webtrees 2.2 + correct package name

**Files:**
- Modify: `${STAT}/composer.json`

- [ ] **Step 1: Rewrite composer.json.**

Replace the entire content of `${STAT}/composer.json` with:

```json
{
    "name": "magicsunday/webtrees-statistics",
    "description": "This module provides SVG-based statistics for the [webtrees](https://www.webtrees.net) genealogy application.",
    "license": "GPL-3.0-or-later",
    "type": "webtrees-module",
    "keywords": [
        "webtrees",
        "module",
        "statistic",
        "chart"
    ],
    "authors": [
        {
            "name": "Rico Sonntag",
            "email": "mail@ricosonntag.de",
            "homepage": "https://ricosonntag.de",
            "role": "Developer"
        }
    ],
    "config": {
        "bin-dir": ".build/bin",
        "vendor-dir": ".build/vendor",
        "discard-changes": true,
        "sort-packages": true,
        "optimize-autoloader": true,
        "allow-plugins": {
            "magicsunday/webtrees-module-installer-plugin": true
        }
    },
    "require": {
        "php": "8.3 - 8.5",
        "ext-dom": "*",
        "ext-json": "*",
        "fisharebest/webtrees": "~2.2.0 || dev-main",
        "magicsunday/webtrees-module-base": "^2.2",
        "magicsunday/webtrees-module-installer-plugin": "^1.3"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.50",
        "overtrue/phplint": "^9.0",
        "phpstan/phpstan": "^2.0",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpstan/phpstan-phpunit": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "phpunit/phpunit": "^12.0 || ^13.0",
        "rector/rector": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "MagicSunday\\Webtrees\\Statistic\\": "src/"
        }
    },
    "scripts": {
        "ci:cgl": [
            "php-cs-fixer fix --config .php-cs-fixer.dist.php --diff --verbose"
        ],
        "ci:rector": [
            "rector process --config rector.php"
        ],
        "ci:test:php:cgl": [
            "@ci:cgl --dry-run"
        ],
        "ci:test:php:lint": [
            "phplint --configuration .phplint.yml"
        ],
        "ci:test:php:phpstan": [
            "phpstan analyze --configuration phpstan.neon --memory-limit=-1"
        ],
        "ci:test:php:phpstan:baseline": [
            "phpstan analyze --configuration phpstan.neon --memory-limit=-1 --generate-baseline phpstan-baseline.neon --allow-empty-baseline"
        ],
        "ci:test:php:rector": [
            "@ci:rector --dry-run"
        ],
        "ci:test:php:unit": [
            "phpunit --configuration phpunit.xml --display-all-issues"
        ],
        "ci:test:js:lint": [
            "npm run lint"
        ],
        "ci:test:js:format": [
            "npm run format:check"
        ],
        "ci:test:js:unit": [
            "node --no-warnings --experimental-vm-modules node_modules/jest/bin/jest.js --passWithNoTests"
        ],
        "ci:test": [
            "@ci:test:php:lint",
            "@ci:test:js:lint",
            "@ci:test:js:format",
            "@ci:test:php:phpstan",
            "@ci:test:php:rector",
            "@ci:test:php:unit",
            "@ci:test:js:unit"
        ]
    }
}
```

- [ ] **Step 2: Validate composer.json.**

Run via buildbox:

```bash
docker compose run --rm buildbox bash -c "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer validate --no-check-publish"
```

Expected: `./composer.json is valid`.

- [ ] **Step 3: Audit-loop.**

- [ ] **Step 4: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add composer.json
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Pin webtrees 2.2 + correct package name to webtrees-statistics

Drops the 2.1 pin, requires webtrees-module-base ^2.2, aligns dev deps
to the fan/pedigree/descendants baseline. Fixes the singular 'statistic'
typo in the composer name."
```

---

## Task B2: Tooling parity — copy configs from fan-chart

**Files:**
- Create: `${STAT}/biome.json`
- Create: `${STAT}/phpstan.neon`
- Create: `${STAT}/phpunit.xml`
- Create: `${STAT}/rector.php`
- Create: `${STAT}/.php-cs-fixer.dist.php`
- Create: `${STAT}/.phplint.yml`
- Create: `${STAT}/Makefile`
- Create: `${STAT}/jsconfig.json`
- Create: `${STAT}/.github/workflows/ci.yml`
- Modify: `${STAT}/rollup.config.js`

- [ ] **Step 1: Copy verbatim configs from fan-chart.**

```bash
F=/volume2/docker/webtrees/app/vendor/magicsunday/webtrees-fan-chart
S=/volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics
cp $F/biome.json           $S/biome.json
cp $F/phpunit.xml          $S/phpunit.xml
cp $F/.php-cs-fixer.dist.php $S/.php-cs-fixer.dist.php
cp $F/.phplint.yml         $S/.phplint.yml
cp $F/rector.php           $S/rector.php
cp $F/Makefile             $S/Makefile
cp $F/phpstan.neon         $S/phpstan.neon
mkdir -p $S/.github/workflows
cp $F/.github/workflows/ci.yml $S/.github/workflows/ci.yml
```

- [ ] **Step 2: Create `jsconfig.json` matching fan-chart's setup (jest-aware).**

Create `${STAT}/jsconfig.json`:

```json
{
    "compilerOptions": {
        "target": "ES2022",
        "module": "ESNext",
        "moduleResolution": "Node",
        "allowJs": true,
        "checkJs": true,
        "strict": true,
        "noEmit": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "types": ["jest"]
    },
    "include": ["resources/js/**/*"]
}
```

- [ ] **Step 3: Rewrite `${STAT}/rollup.config.js` (existing one references old path layout).**

Replace `${STAT}/rollup.config.js` content with:

```js
import { nodeResolve } from "@rollup/plugin-node-resolve";
import terser from "@rollup/plugin-terser";

const banner = `/*!
 * webtrees-statistics — SVG statistics charts for webtrees.
 * Licensed under GPL-3.0-or-later.
 */`;

export default {
    input: "resources/js/modules/index.js",
    output: [
        {
            file: "resources/js/webtrees-statistics.js",
            format: "iife",
            name: "WebtreesStatistics",
            banner,
            sourcemap: false,
        },
        {
            file: "resources/js/webtrees-statistics.min.js",
            format: "iife",
            name: "WebtreesStatistics",
            banner,
            sourcemap: false,
            plugins: [terser()],
        },
    ],
    plugins: [nodeResolve()],
};
```

- [ ] **Step 4: Adjust CI workflow to drop fan-chart-specific steps.**

Edit `${STAT}/.github/workflows/ci.yml` and remove these blocks (they reference fan-chart-only assets):

- The `cpd` (Copy/Paste Detection) step
- The `dist-smoke` step (we don't ship a release zip in Phase 1)
- The `js-typecheck` step (we add it back once typed; Phase 1 has no `.d.ts` set)
- The `composer ci:compliance` reference if it appears anywhere

Result: workflow runs install + php-lint + js-lint + js-format + phpstan + rector + php-unit + js-unit + cgl-dry-run.

- [ ] **Step 5: Adjust `Makefile` for the module.**

The copied Makefile includes `Make/*.mk`. Create the bare minimum `Make/help.mk`:

```bash
mkdir -p $S/Make
cat > $S/Make/help.mk <<'EOF'
.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "} {printf "  \033[1;36m%-22s\033[0m %s\n", $$1, $$2}'

.PHONY: install
install: ## composer install
	$(COMPOSE_RUN) bash -lc "composer install --no-progress --no-interaction && npm ci"

.PHONY: build
build: ## Build the JS bundle
	$(COMPOSE_RUN) bash -lc "npm run prepare"

.PHONY: test
test: ## Run composer ci:test
	$(COMPOSE_RUN) bash -lc "composer ci:test"

.PHONY: link-base
link-base: ## Symlink local webtrees-module-base into vendor
	@if [ -d ../webtrees-module-base ]; then \
		rm -rf .build/vendor/magicsunday/webtrees-module-base; \
		ln -s ../../../../webtrees-module-base .build/vendor/magicsunday/webtrees-module-base; \
		echo "Linked ../webtrees-module-base"; \
	fi

.PHONY: link-chart-lib
link-chart-lib: ## Symlink local webtrees-chart-lib into node_modules
	@if [ -d ../webtrees-chart-lib ]; then \
		rm -rf node_modules/@magicsunday/webtrees-chart-lib; \
		mkdir -p node_modules/@magicsunday; \
		ln -s ../../../webtrees-chart-lib node_modules/@magicsunday/webtrees-chart-lib; \
		echo "Linked ../webtrees-chart-lib"; \
	fi
EOF
```

- [ ] **Step 6: Audit-loop.**

- [ ] **Step 7: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    biome.json phpstan.neon phpunit.xml rector.php .php-cs-fixer.dist.php \
    .phplint.yml Makefile jsconfig.json rollup.config.js \
    .github/workflows/ci.yml Make/help.mk
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Adopt tooling parity to fan/pedigree/descendants chart modules

Copies biome/phpstan/phpunit/rector/php-cs-fixer/phplint/Makefile/CI from
fan-chart verbatim, drops the cpd + dist-smoke + js-typecheck steps that
do not apply here yet. Adds link-base + link-chart-lib Make targets for
local sibling-repo development."
```

---

## Task B3: package.json — chart-lib v1.6.0 + modular d3

**Files:**
- Modify: `${STAT}/package.json`

- [ ] **Step 1: Rewrite package.json.**

Replace `${STAT}/package.json` with:

```json
{
    "name": "webtrees-statistics",
    "version": "1.0.0-dev",
    "description": "SVG-based statistics charts for webtrees.",
    "keywords": ["webtrees", "module", "statistics", "chart"],
    "type": "module",
    "homepage": "https://github.com/magicsunday/webtrees-statistics",
    "license": "GPL-3.0-or-later",
    "author": "Rico Sonntag <mail@ricosonntag.de>",
    "repository": {
        "type": "git",
        "url": "https://github.com/magicsunday/webtrees-statistics.git"
    },
    "scripts": {
        "prepare": "rollup --config rollup.config.js",
        "watch": "rollup --watch --config rollup.config.js",
        "test": "node --no-warnings --experimental-vm-modules node_modules/jest/bin/jest.js --passWithNoTests",
        "lint": "biome lint --error-on-warnings resources/js/modules/",
        "lint:fix": "biome lint --fix --unsafe resources/js/modules/",
        "format": "biome format --write resources/js/modules/ resources/js/tests/",
        "format:check": "biome format resources/js/modules/ resources/js/tests/"
    },
    "dependencies": {
        "@magicsunday/webtrees-chart-lib": "github:magicsunday/webtrees-chart-lib#v1.6.0",
        "d3-array": "^3.2",
        "d3-fetch": "^3.0",
        "d3-geo": "^3.1",
        "d3-scale": "^4.0",
        "d3-scale-chromatic": "^3.0",
        "d3-selection": "^3.0",
        "d3-shape": "^3.2"
    },
    "devDependencies": {
        "@biomejs/biome": "2.4.11",
        "@rollup/plugin-node-resolve": "^16.0",
        "@rollup/plugin-terser": "^1.0",
        "@types/jest": "^30.0",
        "jest": "^30.0",
        "jest-environment-jsdom": "^30.0",
        "rollup": "^4.0"
    }
}
```

- [ ] **Step 2: Install + sanity-check.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && npm install --no-audit --no-fund"
```

Expected: `package-lock.json` written, no fatal errors.

- [ ] **Step 3: Audit-loop.**

- [ ] **Step 4: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    package.json package-lock.json
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Pin chart-lib v1.6.0 + adopt modular d3 dependency set

chart-lib is sourced from the GitHub tag (no npm publish). Modular d3
packages mirror fan-chart. Drops the d3 umbrella package; widgets pull
exactly what they need. Adds jest, jest-environment-jsdom, biome dev
deps for tooling parity."
```

---

## Task B4: Module.php — add ModuleAssetUrl + ModuleConfig interfaces

**Files:**
- Modify: `${STAT}/src/Module.php`

- [ ] **Step 1: Add the two new interfaces + traits.**

In `${STAT}/src/Module.php`, replace lines 14–37 (the class header block including imports + class line + traits) with:

```php
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleAssetUrlInterface;
use Fisharebest\Webtrees\Module\ModuleAssetUrlTrait;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\StatisticsChartModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Statistics chart module.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class Module extends StatisticsChartModule implements
    ModuleAssetUrlInterface,
    ModuleConfigInterface,
    ModuleCustomInterface
{
    use ModuleAssetUrlTrait;
    use ModuleConfigTrait;
    use ModuleCustomTrait;
    use ModuleChartTrait;
```

- [ ] **Step 2: Audit-loop.**

- [ ] **Step 3: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add src/Module.php
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Add ModuleAssetUrl + ModuleConfig interfaces to Module

Matches the interface set of fan/pedigree/descendants chart modules.
ModuleAssetUrlTrait enables version-busted asset URLs; ModuleConfigTrait
provides the admin-config plumbing for future settings (no settings in
Phase 1 — interface presence only)."
```

---

## Task B5: Add ComingSoon template + five stub actions

**Files:**
- Create: `${STAT}/resources/views/modules/statistics-chart/Templates/ComingSoon.phtml`
- Create: `${STAT}/resources/views/modules/statistics-chart/Templates/Relationships.phtml`
- Create: `${STAT}/resources/views/modules/statistics-chart/Templates/Age.phtml`
- Create: `${STAT}/resources/views/modules/statistics-chart/Templates/Weddings.phtml`
- Create: `${STAT}/resources/views/modules/statistics-chart/Templates/Divorces.phtml`
- Create: `${STAT}/resources/views/modules/statistics-chart/Templates/Children.phtml`
- Modify: `${STAT}/src/Module.php`
- Create: `${STAT}/tests/ModuleTest.php`

- [ ] **Step 1: Write failing tests for stub actions.**

Create `${STAT}/tests/ModuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use MagicSunday\Webtrees\Statistic\Module;
use PHPUnit\Framework\TestCase;

/**
 * Module-level smoke tests for the public action surface.
 */
final class ModuleTest extends TestCase
{
    public function testStubActionMethodsExist(): void
    {
        self::assertTrue(method_exists(Module::class, 'getRelationshipsAction'));
        self::assertTrue(method_exists(Module::class, 'getAgeAction'));
        self::assertTrue(method_exists(Module::class, 'getWeddingsAction'));
        self::assertTrue(method_exists(Module::class, 'getDivorcesAction'));
        self::assertTrue(method_exists(Module::class, 'getChildrenAction'));
    }

    public function testCustomVersionMatchesPhaseOne(): void
    {
        self::assertSame('1.0.0-dev', Module::CUSTOM_VERSION);
    }
}
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer install --no-progress --no-interaction && composer ci:test:php:unit"
```

Expected: tests fail — method doesn't exist.

- [ ] **Step 3: Create the ComingSoon template.**

Create `${STAT}/resources/views/modules/statistics-chart/Templates/ComingSoon.phtml`:

```php
<?php

declare(strict_types=1);

use Fisharebest\Webtrees\I18N;

/**
 * @var string $module
 * @var string $tabLabel
 */
?>
<div class="wt-statistics-coming-soon py-5 text-center">
    <h3 class="h5 text-muted"><?= e($tabLabel) ?></h3>
    <p class="text-muted mb-0">
        <?= I18N::translate('This statistic is planned for a future release.') ?>
    </p>
</div>
```

- [ ] **Step 4: Create the five stub sub-templates (each a one-liner that wraps ComingSoon).**

Create `${STAT}/resources/views/modules/statistics-chart/Templates/Relationships.phtml`:

```php
<?php

declare(strict_types=1);

/** @var string $module */
?>
<?= view($module . '::modules/statistics-chart/Templates/ComingSoon', ['module' => $module, 'tabLabel' => $tabLabel]) ?>
```

Repeat the same file body verbatim for `Age.phtml`, `Weddings.phtml`, `Divorces.phtml`, `Children.phtml` (each gets its own file; bodies are identical).

- [ ] **Step 5: Add the five stub action methods to `Module.php`.**

Append the following methods to `${STAT}/src/Module.php` (before the closing `}`):

```php
    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getRelationshipsAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse(I18N::translate('Relationships'), 'Relationships');
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getAgeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse(I18N::translate('Age'), 'Age');
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getWeddingsAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse(I18N::translate('Weddings'), 'Weddings');
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getDivorcesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse(I18N::translate('Divorces'), 'Divorces');
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getChildrenAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse(I18N::translate('Children'), 'Children');
    }

    /**
     * Render the shared "Coming soon" placeholder.
     *
     * @param string $tabLabel  Translated tab label, shown as heading
     * @param string $template  Sub-template basename under Templates/
     *
     * @return ResponseInterface
     */
    private function stubResponse(string $tabLabel, string $template): ResponseInterface
    {
        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/Templates/' . $template,
            [
                'module'   => $this->name(),
                'tabLabel' => $tabLabel,
            ]
        );
    }
```

- [ ] **Step 6: Run tests to verify they pass.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer ci:test:php:unit"
```

Expected: PASS.

- [ ] **Step 7: Audit-loop.**

- [ ] **Step 8: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    src/Module.php tests/ModuleTest.php \
    resources/views/modules/statistics-chart/Templates/ComingSoon.phtml \
    resources/views/modules/statistics-chart/Templates/Relationships.phtml \
    resources/views/modules/statistics-chart/Templates/Age.phtml \
    resources/views/modules/statistics-chart/Templates/Weddings.phtml \
    resources/views/modules/statistics-chart/Templates/Divorces.phtml \
    resources/views/modules/statistics-chart/Templates/Children.phtml
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Add stub actions + ComingSoon template for unfinished tabs

The five tabs without real data (Relationships, Age, Weddings, Divorces,
Children) now return a neutral 'Coming soon' partial instead of 404. The
five sub-templates wrap the shared ComingSoon view with their tab label."
```

---

## Task B6: Statistic — inject StatisticsData, drop 4-service chain

**Files:**
- Modify: `${STAT}/src/Statistic.php`
- Create: `${STAT}/tests/StatisticTest.php`

- [ ] **Step 1: Write a failing test that asserts the new constructor shape.**

Create `${STAT}/tests/StatisticTest.php`:

```php
<?php

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use MagicSunday\Webtrees\Statistic\Statistic;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Behavioural checks for the Statistic aggregator service.
 */
final class StatisticTest extends TestCase
{
    public function testConstructorTakesTreeAndStatisticsData(): void
    {
        $params = (new ReflectionClass(Statistic::class))
            ->getConstructor()
            ->getParameters();

        self::assertSame('tree', $params[0]->getName());
        self::assertSame(
            'Fisharebest\\Webtrees\\Tree',
            $params[0]->getType()->getName(),
        );
        self::assertSame('data', $params[1]->getName());
        self::assertSame(
            'Fisharebest\\Webtrees\\StatisticsData',
            $params[1]->getType()->getName(),
        );
    }

    public function testNoReferenceToRemovedRepositoryInterfaces(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Statistic.php');
        self::assertStringNotContainsString(
            'Statistics\\Repository\\Interfaces',
            $source,
            'Statistic must not import the removed 2.1 Repository interfaces',
        );
        self::assertStringNotContainsString(
            'Statistics\\Google\\ChartDistribution',
            $source,
            'Statistic must not import the removed 2.1 ChartDistribution',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer ci:test:php:unit -- --filter StatisticTest"
```

Expected: FAIL (constructor signature mismatch + source still contains removed imports).

- [ ] **Step 3: Rewrite Statistic.php with the new signature.**

Replace the entire content of `${STAT}/src/Statistic.php` with:

```php
<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\EventRepository;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Repository\IndividualRepository;
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;

/**
 * Aggregator service for statistics-chart partials.
 *
 * Delegates everything that webtrees 2.2 core exposes via StatisticsData,
 * and adds module-specific gaps via the local repositories.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class Statistic
{
    private IndividualRepository $individualRepository;

    private FamilyRepository $familyRepository;

    private EventRepository $eventRepository;

    private NameRepository $nameRepository;

    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core data accessor (replaces the 2.1 service chain)
     */
    public function __construct(
        private readonly Tree $tree,
        private readonly StatisticsData $data,
    ) {
        $this->individualRepository = new IndividualRepository($tree, $data);
        $this->familyRepository     = new FamilyRepository($tree, $data);
        $this->eventRepository      = new EventRepository($tree, $data);
        $this->nameRepository       = new NameRepository($tree, $data);
    }

    /**
     * @return int
     */
    public function getTotalIndividuals(): int
    {
        return $this->individualRepository->getTotalIndividuals();
    }

    /**
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getTotalIndividualsData(): array
    {
        return [
            ['label' => I18N::translate('Male'),    'value' => $this->individualRepository->getTotalSexMale(),    'class' => 'male'],
            ['label' => I18N::translate('Female'),  'value' => $this->individualRepository->getTotalSexFemale(),  'class' => 'female'],
            ['label' => I18N::translate('Unknown'), 'value' => $this->individualRepository->getTotalSexUnknown(), 'class' => 'unknown'],
        ];
    }

    /**
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getTotalLivingDeceasedData(): array
    {
        return [
            ['label' => I18N::translate('Living'),   'value' => $this->individualRepository->getTotalLiving(),   'class' => 'living'],
            ['label' => I18N::translate('Deceased'), 'value' => $this->individualRepository->getTotalDeceased(), 'class' => 'deceased'],
        ];
    }

    /**
     * Returns the marital status breakdown for the donut chart.
     *
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getFamilyStatusData(): array
    {
        $totalMarried = $this->familyRepository->getTotalMarriedMales()
            + $this->familyRepository->getTotalMarriedFemales();

        $totalSingle = max(
            0,
            $this->individualRepository->getTotalIndividuals() - $totalMarried,
        );

        return [
            ['label' => I18N::translate('Married'),  'value' => $totalMarried, 'class' => 'married'],
            ['label' => I18N::translate('Single'),   'value' => $totalSingle,  'class' => 'single'],
            ['label' => I18N::translate('Widowed'),  'value' => $this->familyRepository->getTotalWidowed(),  'class' => 'widowed'],
            ['label' => I18N::translate('Divorced'), 'value' => $this->familyRepository->getTotalDivorced(), 'class' => 'divorced'],
        ];
    }

    /**
     * @return int
     */
    public function getTotalSurnames(): int
    {
        return $this->nameRepository->getTotalSurnames();
    }

    /**
     * @param int $limit
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopSurnames(int $limit): array
    {
        $data = $this->nameRepository->getTopSurnames($limit);

        uasort(
            $data,
            static fn (array $x, array $y): int => $x['name'] <=> $y['name'],
        );

        return array_values(array_map(
            static fn (array $entry): array => [
                'label' => $entry['name'],
                'value' => $entry['count'],
            ],
            $data,
        ));
    }

    /**
     * @return int
     */
    public function getTotalMaleGivenNames(): int
    {
        return $this->nameRepository->getTotalMaleGivenNames();
    }

    /**
     * @param int $limit
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopMaleGivenNames(int $limit): array
    {
        return $this->shapeNameList($this->nameRepository->getTopMaleGivenNames($limit));
    }

    /**
     * @return int
     */
    public function getTotalFemaleGivenNames(): int
    {
        return $this->nameRepository->getTotalFemaleGivenNames();
    }

    /**
     * @param int $limit
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopFemaleGivenNames(int $limit): array
    {
        return $this->shapeNameList($this->nameRepository->getTopFemaleGivenNames($limit));
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByMonth(): array
    {
        return $this->eventRepository->getBirthsByMonth();
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByCentury(): array
    {
        return $this->eventRepository->getBirthsByCentury();
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        return $this->eventRepository->getBirthsByZodiacSign();
    }

    /**
     * @return array<int, array{countryCode: string, label: string, count: int}>
     */
    public function getBirthsByCountry(): array
    {
        return $this->eventRepository->getBirthsByCountry();
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByMonth(): array
    {
        return $this->eventRepository->getDeathsByMonth();
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByCentury(): array
    {
        return $this->eventRepository->getDeathsByCentury();
    }

    /**
     * @return array<int, array{countryCode: string, label: string, count: int}>
     */
    public function getDeathsByCountry(): array
    {
        return $this->eventRepository->getDeathsByCountry();
    }

    /**
     * Shape a [{name, count}] list into [{label, value}] sorted alphabetically by name.
     *
     * @param array<int, array{name: string, count: int}> $rows
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function shapeNameList(array $rows): array
    {
        uasort(
            $rows,
            static fn (array $x, array $y): int => $x['name'] <=> $y['name'],
        );

        return array_values(array_map(
            static fn (array $entry): array => [
                'label' => $entry['name'],
                'value' => $entry['count'],
            ],
            $rows,
        ));
    }
}
```

- [ ] **Step 4: Run tests to verify the structural assertions pass.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer ci:test:php:unit -- --filter StatisticTest"
```

Expected: PASS for both `StatisticTest` assertions.

(Repository delegation methods are still red — that's fixed in Task B7.)

- [ ] **Step 5: Audit-loop.**

- [ ] **Step 6: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    src/Statistic.php tests/StatisticTest.php
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Refactor Statistic service to consume webtrees 2.2 StatisticsData

Replaces the 2.1 Repository/Google/Service constructor chain with a
single StatisticsData injection (Tree + data). All hardcoded German
labels become I18N::translate, the 38/0 widow/divorce placeholders are
gone, and the donut-data shape is typed end-to-end. Repository
delegation is wired up in the next commit."
```

---

## Task B7: Repositories — thin delegation to StatisticsData

**Files:**
- Modify: `${STAT}/src/Repository/IndividualRepository.php`
- Modify: `${STAT}/src/Repository/FamilyRepository.php`
- Modify: `${STAT}/src/Repository/NameRepository.php`
- Modify: `${STAT}/src/Repository/EventRepository.php`
- Create: `${STAT}/tests/Repository/IndividualRepositoryTest.php`

- [ ] **Step 1: Write a behavioural test for IndividualRepository delegation.**

Create `${STAT}/tests/Repository/IndividualRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Repository;

use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\IndividualRepository;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for IndividualRepository — verifies that calls
 * are forwarded to the injected StatisticsData with the expected
 * parameters and return values.
 */
final class IndividualRepositoryTest extends TestCase
{
    public function testGetTotalIndividualsDelegatesToData(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->expects(self::once())->method('countIndividuals')->willReturn(42);

        $repo = new IndividualRepository($tree, $data);

        self::assertSame(42, $repo->getTotalIndividuals());
    }

    public function testGetTotalSexMaleDelegatesWithMArgument(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->expects(self::once())
            ->method('countIndividualsBySex')
            ->with('M')
            ->willReturn(20);

        $repo = new IndividualRepository($tree, $data);

        self::assertSame(20, $repo->getTotalSexMale());
    }

    public function testGetTotalSexFemaleDelegatesWithFArgument(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->expects(self::once())
            ->method('countIndividualsBySex')
            ->with('F')
            ->willReturn(22);

        $repo = new IndividualRepository($tree, $data);

        self::assertSame(22, $repo->getTotalSexFemale());
    }

    public function testGetTotalSexUnknownDelegatesWithUArgument(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->expects(self::once())
            ->method('countIndividualsBySex')
            ->with('U')
            ->willReturn(0);

        $repo = new IndividualRepository($tree, $data);

        self::assertSame(0, $repo->getTotalSexUnknown());
    }

    public function testGetTotalLivingAndDeceasedDelegate(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->method('countIndividualsLiving')->willReturn(8);
        $data->method('countIndividualsDeceased')->willReturn(34);

        $repo = new IndividualRepository($tree, $data);

        self::assertSame(8, $repo->getTotalLiving());
        self::assertSame(34, $repo->getTotalDeceased());
    }
}
```

- [ ] **Step 2: Rewrite IndividualRepository.**

Replace `${STAT}/src/Repository/IndividualRepository.php` content with:

```php
<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;

/**
 * Thin delegating wrapper around webtrees core StatisticsData for individual stats.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class IndividualRepository
{
    public function __construct(
        private readonly Tree $tree,
        private readonly StatisticsData $data,
    ) {
    }

    /**
     * @return int Total number of individuals in the tree
     */
    public function getTotalIndividuals(): int
    {
        return $this->data->countIndividuals();
    }

    /**
     * @return int Number of individuals with sex = M
     */
    public function getTotalSexMale(): int
    {
        return $this->data->countIndividualsBySex('M');
    }

    /**
     * @return int Number of individuals with sex = F
     */
    public function getTotalSexFemale(): int
    {
        return $this->data->countIndividualsBySex('F');
    }

    /**
     * @return int Number of individuals with unknown sex
     */
    public function getTotalSexUnknown(): int
    {
        return $this->data->countIndividualsBySex('U');
    }

    /**
     * @return int Living individuals
     */
    public function getTotalLiving(): int
    {
        return $this->data->countIndividualsLiving();
    }

    /**
     * @return int Deceased individuals
     */
    public function getTotalDeceased(): int
    {
        return $this->data->countIndividualsDeceased();
    }
}
```

- [ ] **Step 3: Rewrite NameRepository.**

Replace `${STAT}/src/Repository/NameRepository.php` content with:

```php
<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;

/**
 * Thin delegating wrapper around webtrees core StatisticsData for surname / given-name stats.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class NameRepository
{
    private const DEFAULT_THRESHOLD = 1;

    public function __construct(
        private readonly Tree $tree,
        private readonly StatisticsData $data,
    ) {
    }

    /**
     * @return int Total number of distinct surnames in the tree
     */
    public function getTotalSurnames(): int
    {
        return count($this->data->commonSurnames(0, 1, 'count'));
    }

    /**
     * @param int $limit Maximum number of surnames to return
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function getTopSurnames(int $limit): array
    {
        return $this->reshapeNameRows(
            $this->data->commonSurnames($limit, self::DEFAULT_THRESHOLD, 'count'),
        );
    }

    /**
     * @return int Total number of distinct male given names
     */
    public function getTotalMaleGivenNames(): int
    {
        return $this->data->commonGivenNames('M', 1, 0)->count();
    }

    /**
     * @param int $limit Maximum number of given names to return
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function getTopMaleGivenNames(int $limit): array
    {
        return $this->reshapeGivenNameRows(
            $this->data->commonGivenNames('M', self::DEFAULT_THRESHOLD, $limit),
        );
    }

    /**
     * @return int Total number of distinct female given names
     */
    public function getTotalFemaleGivenNames(): int
    {
        return $this->data->commonGivenNames('F', 1, 0)->count();
    }

    /**
     * @param int $limit Maximum number of given names to return
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function getTopFemaleGivenNames(int $limit): array
    {
        return $this->reshapeGivenNameRows(
            $this->data->commonGivenNames('F', self::DEFAULT_THRESHOLD, $limit),
        );
    }

    /**
     * Convert StatisticsData::commonSurnames output (array of arrays keyed by name)
     * into the [{name, count}] shape used by the chart layer.
     *
     * @param array<string, array<string, int>> $rows
     *
     * @return array<int, array{name: string, count: int}>
     */
    private function reshapeNameRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $surname => $variants) {
            $out[] = ['name' => $surname, 'count' => array_sum($variants)];
        }

        return $out;
    }

    /**
     * Convert StatisticsData::commonGivenNames output (Collection of name → count)
     * into the [{name, count}] shape used by the chart layer.
     *
     * @param iterable<string, int> $rows
     *
     * @return array<int, array{name: string, count: int}>
     */
    private function reshapeGivenNameRows(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $name => $count) {
            $out[] = ['name' => $name, 'count' => (int) $count];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the tests.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer ci:test:php:unit -- --filter Individual"
```

Expected: PASS.

- [ ] **Step 5: Audit-loop.**

- [ ] **Step 6: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    src/Repository/IndividualRepository.php \
    src/Repository/NameRepository.php \
    tests/Repository/IndividualRepositoryTest.php
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Refactor Individual + Name repositories as thin delegators

Both classes now take (Tree, StatisticsData) and forward each call to
the corresponding core method, with shape adaptation where the chart
layer needs a different format. No behavioural change versus the 2.1
implementation."
```

---

## Task B8: FamilyRepository — delegation + new Widowed/Divorced queries

**Files:**
- Modify: `${STAT}/src/Repository/FamilyRepository.php`
- Create: `${STAT}/tests/Repository/FamilyRepositoryTest.php`

- [ ] **Step 1: Write the failing behavioural test.**

Create `${STAT}/tests/Repository/FamilyRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Repository;

use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for FamilyRepository.
 */
final class FamilyRepositoryTest extends TestCase
{
    public function testMarriedDelegateToData(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->method('countMarriedMales')->willReturn(11);
        $data->method('countMarriedFemales')->willReturn(13);

        $repo = new FamilyRepository($tree, $data);

        self::assertSame(11, $repo->getTotalMarriedMales());
        self::assertSame(13, $repo->getTotalMarriedFemales());
    }

    public function testGetTotalWidowedReturnsIntegerCount(): void
    {
        $tree = $this->createStub(Tree::class);
        $tree->method('id')->willReturn(1);
        $data = $this->createStub(StatisticsData::class);

        $repo = new FamilyRepository($tree, $data);

        // Smoke: the method exists, returns int, never throws on empty tree.
        // Real DB-backed assertions live in integration tests, not here.
        self::assertIsInt($repo->getTotalWidowed());
    }

    public function testGetTotalDivorcedReturnsIntegerCount(): void
    {
        $tree = $this->createStub(Tree::class);
        $tree->method('id')->willReturn(1);
        $data = $this->createStub(StatisticsData::class);

        $repo = new FamilyRepository($tree, $data);

        self::assertIsInt($repo->getTotalDivorced());
    }
}
```

- [ ] **Step 2: Rewrite FamilyRepository.**

Replace `${STAT}/src/Repository/FamilyRepository.php` content with:

```php
<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\JoinClause;

/**
 * Family statistics: married counts delegate to core, widowed/divorced
 * are queried locally because StatisticsData does not expose them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class FamilyRepository
{
    public function __construct(
        private readonly Tree $tree,
        private readonly StatisticsData $data,
    ) {
    }

    /**
     * @return int Number of married males
     */
    public function getTotalMarriedMales(): int
    {
        return $this->data->countMarriedMales();
    }

    /**
     * @return int Number of married females
     */
    public function getTotalMarriedFemales(): int
    {
        return $this->data->countMarriedFemales();
    }

    /**
     * Count individuals whose spouse has a DEAT record and who do not have one themselves.
     *
     * Operates on the family table: any family record where exactly one
     * partner is deceased contributes one widowed individual.
     *
     * @return int
     */
    public function getTotalWidowed(): int
    {
        return DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->join('individuals as husb', static function (JoinClause $join): void {
                $join->on('husb.i_id', '=', 'families.f_husb')
                    ->on('husb.i_file', '=', 'families.f_file');
            })
            ->join('individuals as wife', static function (JoinClause $join): void {
                $join->on('wife.i_id', '=', 'families.f_wife')
                    ->on('wife.i_file', '=', 'families.f_file');
            })
            ->where(static function ($query): void {
                $query
                    ->where(static function ($q): void {
                        $q->where('husb.i_gedcom', 'LIKE', "%\n1 DEAT%")
                            ->where('wife.i_gedcom', 'NOT LIKE', "%\n1 DEAT%");
                    })
                    ->orWhere(static function ($q): void {
                        $q->where('wife.i_gedcom', 'LIKE', "%\n1 DEAT%")
                            ->where('husb.i_gedcom', 'NOT LIKE', "%\n1 DEAT%");
                    });
            })
            ->count();
    }

    /**
     * Count families with a DIV (divorce) fact in their GEDCOM.
     *
     * @return int
     */
    public function getTotalDivorced(): int
    {
        return DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->where('f_gedcom', 'LIKE', "%\n1 DIV%")
            ->count();
    }
}
```

- [ ] **Step 3: Run the tests.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer ci:test:php:unit -- --filter FamilyRepository"
```

Expected: PASS.

- [ ] **Step 4: Audit-loop.**

- [ ] **Step 5: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    src/Repository/FamilyRepository.php tests/Repository/FamilyRepositoryTest.php
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Implement Widowed + Divorced counts in FamilyRepository

Marriage counts continue to delegate to StatisticsData. Widowed is a
JOIN against the individual GEDCOM looking for asymmetric DEAT presence
between spouses; Divorced is a substring match against '1 DIV' in
family GEDCOM. Both replace the hardcoded 38/0 placeholders in
Statistic::getFamilyStatusData."
```

---

## Task B9: EventRepository — delegation, country mapping, dead-code purge

**Files:**
- Modify: `${STAT}/src/Repository/EventRepository.php`
- Create: `${STAT}/tests/Repository/EventRepositoryTest.php`

- [ ] **Step 1: Write failing behavioural tests.**

Create `${STAT}/tests/Repository/EventRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Repository;

use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\EventRepository;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for EventRepository.
 */
final class EventRepositoryTest extends TestCase
{
    public function testBirthsByCenturyDelegatesToData(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->expects(self::once())
            ->method('countEventsByCentury')
            ->with('BIRT')
            ->willReturn(['19th' => 12, '20th' => 50]);

        $repo = new EventRepository($tree, $data);

        self::assertSame(['19th' => 12, '20th' => 50], $repo->getBirthsByCentury());
    }

    public function testDeathsByMonthAreTranslatedNominative(): void
    {
        $tree = $this->createStub(Tree::class);
        $data = $this->createMock(StatisticsData::class);
        $data->expects(self::once())
            ->method('countEventsByMonth')
            ->with('DEAT', 0, 0)
            ->willReturn(['JAN' => 5, 'FEB' => 3]);

        $repo = new EventRepository($tree, $data);
        $result = $repo->getDeathsByMonth();

        // Result keys are translated month names; we don't assert a locale-
        // specific string here — only that JAN/FEB tokens are gone.
        self::assertArrayNotHasKey('JAN', $result);
        self::assertArrayNotHasKey('FEB', $result);
        self::assertCount(12, $result, 'all 12 months must be present');
    }

    public function testZodiacSignReturnsAllTwelveKeys(): void
    {
        $tree = $this->createStub(Tree::class);
        $tree->method('id')->willReturn(1);
        $data = $this->createStub(StatisticsData::class);

        $repo = new EventRepository($tree, $data);
        $result = $repo->getBirthsByZodiacSign();

        // Empty tree → 12 keys, all zero
        self::assertSame(
            [
                'Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo',
                'Libra', 'Scorpio', 'Sagittarius', 'Capricornus',
                'Aquarius', 'Pisces',
            ],
            array_keys($result),
        );
    }
}
```

- [ ] **Step 2: Rewrite EventRepository.**

Replace `${STAT}/src/Repository/EventRepository.php` content with:

```php
<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Event statistics. Most queries delegate to StatisticsData; the
 * zodiac-sign grouping and the country-code-keyed result shape are
 * implemented locally because core does not expose them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class EventRepository
{
    private const EVENT_BIRTH = 'BIRT';
    private const EVENT_DEATH = 'DEAT';

    private const ZODIAC_SIGNS = [
        'Aries'       => ['from' => [3, 21],  'to' => [4, 20]],
        'Taurus'      => ['from' => [4, 22],  'to' => [5, 21]],
        'Gemini'      => ['from' => [5, 22],  'to' => [6, 21]],
        'Cancer'      => ['from' => [6, 22],  'to' => [7, 22]],
        'Leo'         => ['from' => [7, 23],  'to' => [8, 22]],
        'Virgo'       => ['from' => [8, 23],  'to' => [9, 22]],
        'Libra'       => ['from' => [9, 23],  'to' => [10, 22]],
        'Scorpio'     => ['from' => [10, 23], 'to' => [11, 22]],
        'Sagittarius' => ['from' => [11, 23], 'to' => [12, 20]],
        'Capricornus' => ['from' => [12, 21], 'to' => [1, 19]],
        'Aquarius'    => ['from' => [1, 20],  'to' => [2, 18]],
        'Pisces'      => ['from' => [2, 19],  'to' => [3, 20]],
    ];

    public function __construct(
        private readonly Tree $tree,
        private readonly StatisticsData $data,
    ) {
    }

    /**
     * @return array<string, int> Translated month name → birth count
     */
    public function getBirthsByMonth(): array
    {
        return $this->translateMonthKeys(
            $this->data->countEventsByMonth(self::EVENT_BIRTH, 0, 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByCentury(): array
    {
        return $this->data->countEventsByCentury(self::EVENT_BIRTH);
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        return $this->groupByZodiac(self::EVENT_BIRTH);
    }

    /**
     * @return array<int, array{countryCode: string, label: string, count: int}>
     */
    public function getBirthsByCountry(): array
    {
        return $this->groupByCountry(self::EVENT_BIRTH);
    }

    /**
     * @return array<string, int> Translated month name → death count
     */
    public function getDeathsByMonth(): array
    {
        return $this->translateMonthKeys(
            $this->data->countEventsByMonth(self::EVENT_DEATH, 0, 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByCentury(): array
    {
        return $this->data->countEventsByCentury(self::EVENT_DEATH);
    }

    /**
     * @return array<int, array{countryCode: string, label: string, count: int}>
     */
    public function getDeathsByCountry(): array
    {
        return $this->groupByCountry(self::EVENT_DEATH);
    }

    /**
     * Replace 'JAN' / 'FEB' / … keys with their nominative translation,
     * filling in missing months with 0 so the donut/bar chart always
     * sees a 12-key result.
     *
     * @param array<string, int> $byAbbrev
     *
     * @return array<string, int>
     */
    private function translateMonthKeys(array $byAbbrev): array
    {
        $translations = [
            'JAN' => I18N::translateContext('NOMINATIVE', 'January'),
            'FEB' => I18N::translateContext('NOMINATIVE', 'February'),
            'MAR' => I18N::translateContext('NOMINATIVE', 'March'),
            'APR' => I18N::translateContext('NOMINATIVE', 'April'),
            'MAY' => I18N::translateContext('NOMINATIVE', 'May'),
            'JUN' => I18N::translateContext('NOMINATIVE', 'June'),
            'JUL' => I18N::translateContext('NOMINATIVE', 'July'),
            'AUG' => I18N::translateContext('NOMINATIVE', 'August'),
            'SEP' => I18N::translateContext('NOMINATIVE', 'September'),
            'OCT' => I18N::translateContext('NOMINATIVE', 'October'),
            'NOV' => I18N::translateContext('NOMINATIVE', 'November'),
            'DEC' => I18N::translateContext('NOMINATIVE', 'December'),
        ];

        $out = [];
        foreach ($translations as $abbrev => $label) {
            $out[$label] = $byAbbrev[$abbrev] ?? 0;
        }

        return $out;
    }

    /**
     * Group BIRT/DEAT events by zodiac sign for individuals in this tree.
     *
     * @param string $event
     *
     * @return array<string, int>
     */
    private function groupByZodiac(string $event): array
    {
        $columns = [];
        foreach (self::ZODIAC_SIGNS as $name => $range) {
            $from = $range['from'];
            $to   = $range['to'];
            $columns[] = sprintf(
                'COUNT(CASE WHEN (d_day != 0 AND d_mon != 0 AND ((d_mon = %d AND d_day >= %d) OR (d_mon = %d AND d_day <= %d))) THEN 1 END) AS %s',
                $from[0],
                $from[1],
                $to[0],
                $to[1],
                $name,
            );
        }

        $row = (array) DB::table('dates')
            ->selectRaw(implode(', ', $columns))
            ->where('d_file', '=', $this->tree->id())
            ->where('d_fact', '=', $event)
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->first();

        $out = [];
        foreach (array_keys(self::ZODIAC_SIGNS) as $name) {
            $out[$name] = (int) ($row[$name] ?? 0);
        }

        return $out;
    }

    /**
     * Convert StatisticsData::countIndividualEventsByCountry output into
     * the [{countryCode, label, count}] shape consumed by the WorldMap widget.
     *
     * @param string $event
     *
     * @return array<int, array{countryCode: string, label: string, count: int}>
     */
    private function groupByCountry(string $event): array
    {
        $rows = $this->data->countIndividualEventsByCountry($this->tree, $event);

        $out = [];
        foreach ($rows as $countryCode => $count) {
            $out[] = [
                'countryCode' => (string) $countryCode,
                'label'       => (string) $countryCode,
                'count'       => (int) $count,
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count'],
        );

        return $out;
    }
}
```

- [ ] **Step 2 (continued): Run the tests.**

```bash
docker compose run --rm buildbox bash -lc "cd /workspace/app/vendor/magicsunday/webtrees-statistics && composer ci:test:php:unit -- --filter EventRepository"
```

Expected: PASS.

- [ ] **Step 3: Audit-loop.**

- [ ] **Step 4: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    src/Repository/EventRepository.php tests/Repository/EventRepositoryTest.php
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Refactor EventRepository to webtrees 2.2 + drop dead code

countEventsByMonth/Century delegate to StatisticsData. Zodiac stays
local (no core equivalent) with the inline-built-then-parameterised
SQL replaced by a single selectRaw using sprintf bindings.
countIndividualEventsByCountry reshapes the core return into the
{countryCode, label, count} triple the WorldMap widget expects.
Removes the ~100 lines of commented-out 2.1 logic (getTotalBirths,
getTotalDeaths, getEventsGroupedByCountry) — all superseded by core."
```

---

## Task B10: Drop hardcoded `38/0` use-sites cross-check + I18N audit grep

**Files:**
- Read: full `${STAT}/src/` and `${STAT}/resources/`

- [ ] **Step 1: Grep for any remaining hardcoded German + placeholder values.**

```bash
cd ${STAT}
grep -rn 'Verheiratet\|Allein lebend\|Verwitwet\|Geschieden' src/ resources/
grep -rn "'value' => 38" src/
grep -rn "'value' => 0" src/
```

Expected: no matches. If anything matches, fix it inline and re-run.

- [ ] **Step 2: Grep for stale references to removed types.**

```bash
grep -rn 'Statistics\\Repository\\\|Statistics\\Google\\\|ColorService\|CenturyService' src/ resources/
```

Expected: no matches in `src/`. If references remain, fix them.

- [ ] **Step 3: Audit-loop (sweep commit).**

If anything was changed, commit; otherwise skip the commit step (this is a verification gate).

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics status
```

If there are no changes, proceed to Task B11 directly.

---

## Task B11: JS rewrite — chart-lib widgets + thin entry

**Files:**
- Create: `${STAT}/resources/js/modules/widgets/donut.js`
- Create: `${STAT}/resources/js/modules/widgets/world-map.js`
- Create: `${STAT}/resources/js/modules/widgets/progress-list.js`
- Create: `${STAT}/resources/js/modules/widgets/tag-cloud.js`
- Modify: `${STAT}/resources/js/modules/index.js`
- Delete: `${STAT}/resources/js/modules/lib/` (whole subtree)
- Create: `${STAT}/resources/js/tests/widgets/donut.test.js`

- [ ] **Step 1: Write a failing test for the entry-point dispatcher.**

Create `${STAT}/resources/js/tests/widgets/donut.test.js`:

```js
import { describe, expect, test } from "@jest/globals";
import { renderWidgets } from "../../modules/index.js";

describe("renderWidgets dispatcher", () => {
    test("dispatches a donut widget when data-widget=donut", async () => {
        document.body.innerHTML = `
            <div id="d1"
                 data-widget="donut"
                 data-payload='[{"label":"Male","value":1,"class":"male"}]'></div>
        `;
        await renderWidgets(document.body);
        expect(document.querySelectorAll("#d1 svg path")).toHaveLength(1);
    });

    test("renders empty-state when payload is empty array", async () => {
        document.body.innerHTML = `
            <div id="d2" data-widget="donut" data-payload='[]'></div>
        `;
        await renderWidgets(document.body);
        expect(document.querySelector("#d2 .chart-empty-state")).not.toBeNull();
    });

    test("ignores nodes without data-widget attribute", async () => {
        document.body.innerHTML = '<div id="d3"></div>';
        await renderWidgets(document.body);
        expect(document.querySelector("#d3 svg")).toBeNull();
        expect(document.querySelector("#d3 .chart-empty-state")).toBeNull();
    });
});
```

- [ ] **Step 2: Run test to verify it fails.**

```bash
cd ${STAT} && npm test -- resources/js/tests/widgets/donut.test.js
```

Expected: FAIL — `renderWidgets` is not exported.

- [ ] **Step 3: Write the thin widget wrappers.**

Create `${STAT}/resources/js/modules/widgets/donut.js`:

```js
import { DonutChart } from "@magicsunday/webtrees-chart-lib";

/**
 * Instantiate a chart-lib DonutChart against the given element using the
 * payload pre-rendered into the data-payload attribute.
 *
 * @param {HTMLElement} el
 * @returns {void}
 */
export function renderDonut(el) {
    const data = parsePayload(el);
    const options = {};
    if (el.dataset.holeSize) {
        options.holeSize = Number(el.dataset.holeSize);
    }
    new DonutChart(el, options).draw(data);
}

/**
 * @param {HTMLElement} el
 * @returns {Array<object>}
 */
function parsePayload(el) {
    try {
        return JSON.parse(el.dataset.payload ?? "[]");
    } catch {
        return [];
    }
}
```

Create `${STAT}/resources/js/modules/widgets/world-map.js`:

```js
import { WorldMap } from "@magicsunday/webtrees-chart-lib";

/**
 * Fetch the geojson referenced by data-geojson-url, then render a chart-lib
 * WorldMap into the given element using the data-payload rows.
 *
 * @param {HTMLElement} el
 * @returns {Promise<void>}
 */
export async function renderWorldMap(el) {
    const data = JSON.parse(el.dataset.payload ?? "[]");
    const geoUrl = el.dataset.geojsonUrl;
    if (!geoUrl) {
        el.innerHTML = '<div class="chart-empty-state">Missing geojson URL</div>';
        return;
    }
    const response = await fetch(geoUrl);
    if (!response.ok) {
        el.innerHTML = '<div class="chart-empty-state">Failed to load map</div>';
        return;
    }
    const geojson = await response.json();
    new WorldMap(el, { geojson }).draw(data);
}
```

Create `${STAT}/resources/js/modules/widgets/progress-list.js`:

```js
import { ProgressList } from "@magicsunday/webtrees-chart-lib";

/**
 * @param {HTMLElement} el
 * @returns {void}
 */
export function renderProgressList(el) {
    const data = JSON.parse(el.dataset.payload ?? "[]");
    const options = {};
    if (el.dataset.maxItems) {
        options.maxItems = Number(el.dataset.maxItems);
    }
    new ProgressList(el, options).draw(data);
}
```

Create `${STAT}/resources/js/modules/widgets/tag-cloud.js`:

```js
import { TagCloud } from "@magicsunday/webtrees-chart-lib";

/**
 * @param {HTMLElement} el
 * @returns {void}
 */
export function renderTagCloud(el) {
    const data = JSON.parse(el.dataset.payload ?? "[]");
    const options = {};
    if (el.dataset.minFont) {
        options.minFont = Number(el.dataset.minFont);
    }
    if (el.dataset.maxFont) {
        options.maxFont = Number(el.dataset.maxFont);
    }
    new TagCloud(el, options).draw(data);
}
```

- [ ] **Step 4: Rewrite the entry-point.**

Replace `${STAT}/resources/js/modules/index.js` with:

```js
/*!
 * webtrees-statistics — entry-point: scan DOM for [data-widget], dispatch.
 */

import { renderDonut } from "./widgets/donut.js";
import { renderProgressList } from "./widgets/progress-list.js";
import { renderTagCloud } from "./widgets/tag-cloud.js";
import { renderWorldMap } from "./widgets/world-map.js";

const RENDERERS = {
    donut: renderDonut,
    map: renderWorldMap,
    list: renderProgressList,
    cloud: renderTagCloud,
};

/**
 * Find every [data-widget] inside the given root and dispatch to the matching renderer.
 *
 * @param {ParentNode} root  Defaults to document.
 * @returns {Promise<void>}
 */
export async function renderWidgets(root = document) {
    const elements = root.querySelectorAll("[data-widget]");
    const tasks = [];
    for (const el of elements) {
        const renderer = RENDERERS[el.dataset.widget];
        if (renderer) {
            tasks.push(Promise.resolve(renderer(el)));
        }
    }
    await Promise.all(tasks);
}

if (typeof document !== "undefined" && document.readyState !== "loading") {
    renderWidgets();
} else if (typeof document !== "undefined") {
    document.addEventListener("DOMContentLoaded", () => {
        renderWidgets();
    });
}
```

- [ ] **Step 5: Remove the legacy lib/ subtree.**

```bash
rm -rf ${STAT}/resources/js/modules/lib/
```

- [ ] **Step 6: Build the bundle.**

```bash
cd ${STAT} && npm run prepare
```

Expected: `resources/js/webtrees-statistics.js` + `webtrees-statistics.min.js` produced.

- [ ] **Step 7: Run JS tests.**

```bash
cd ${STAT} && npm test
```

Expected: all JS tests pass.

- [ ] **Step 8: Audit-loop.**

- [ ] **Step 9: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    resources/js/modules/index.js \
    resources/js/modules/widgets/ \
    resources/js/tests/widgets/donut.test.js \
    resources/js/webtrees-statistics.js \
    resources/js/webtrees-statistics.min.js
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics rm -r resources/js/modules/lib/
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Replace vendored d3 + local widget classes with chart-lib consumers

The entry-point scans for [data-widget] and dispatches to per-type
renderers that import the chart-lib widget and read options off
data-* attributes. The old resources/js/modules/lib/ subtree (vendored
d3, BaseChart, Chart, DonutChart, WorldMap) is gone — chart-lib owns
them now."
```

---

## Task B12: Views — Partials switch to data-widget pattern

**Files:**
- Modify: `${STAT}/resources/views/modules/statistics-chart/Partials/DonutChart.phtml`
- Modify: `${STAT}/resources/views/modules/statistics-chart/Partials/GeoMap.phtml`
- Modify: `${STAT}/resources/views/modules/statistics-chart/Partials/ProgressList.phtml`
- Modify: `${STAT}/resources/views/modules/statistics-chart/Partials/TagCloud.phtml`

- [ ] **Step 1: Read each existing partial and identify the data variable it currently expects.**

```bash
head -25 ${STAT}/resources/views/modules/statistics-chart/Partials/DonutChart.phtml
head -25 ${STAT}/resources/views/modules/statistics-chart/Partials/GeoMap.phtml
head -25 ${STAT}/resources/views/modules/statistics-chart/Partials/ProgressList.phtml
head -25 ${STAT}/resources/views/modules/statistics-chart/Partials/TagCloud.phtml
```

- [ ] **Step 2: Rewrite DonutChart partial.**

Replace `${STAT}/resources/views/modules/statistics-chart/Partials/DonutChart.phtml` with:

```php
<?php

declare(strict_types=1);

/**
 * @var string                                                $id
 * @var array<int, array{label: string, value: int, class?: string, fill?: string}> $data
 * @var int|null                                              $holeSize
 */
?>
<div
    id="<?= e($id) ?>"
    class="wt-statistics-donut"
    data-widget="donut"
    data-payload="<?= e(json_encode($data, JSON_THROW_ON_ERROR)) ?>"
    <?php if (isset($holeSize)): ?>data-hole-size="<?= e((string) $holeSize) ?>"<?php endif; ?>
></div>
```

- [ ] **Step 3: Rewrite GeoMap partial.**

Replace `${STAT}/resources/views/modules/statistics-chart/Partials/GeoMap.phtml` with:

```php
<?php

declare(strict_types=1);

/**
 * @var string                                                                              $id
 * @var array<int, array{countryCode: string, label: string, count: int}>                   $data
 * @var string                                                                              $geojsonUrl
 */
?>
<div
    id="<?= e($id) ?>"
    class="wt-statistics-map"
    data-widget="map"
    data-payload="<?= e(json_encode($data, JSON_THROW_ON_ERROR)) ?>"
    data-geojson-url="<?= e($geojsonUrl) ?>"
></div>
```

- [ ] **Step 4: Rewrite ProgressList partial.**

Replace `${STAT}/resources/views/modules/statistics-chart/Partials/ProgressList.phtml` with:

```php
<?php

declare(strict_types=1);

/**
 * @var string                                                $id
 * @var array<int, array{label: string, value: int, total?: int}> $data
 * @var int|null                                              $maxItems
 */
?>
<div
    id="<?= e($id) ?>"
    class="wt-statistics-list"
    data-widget="list"
    data-payload="<?= e(json_encode($data, JSON_THROW_ON_ERROR)) ?>"
    <?php if (isset($maxItems)): ?>data-max-items="<?= e((string) $maxItems) ?>"<?php endif; ?>
></div>
```

- [ ] **Step 5: Rewrite TagCloud partial.**

Replace `${STAT}/resources/views/modules/statistics-chart/Partials/TagCloud.phtml` with:

```php
<?php

declare(strict_types=1);

/**
 * @var string                                                $id
 * @var array<int, array{label: string, value: int}>          $data
 * @var int|null                                              $minFont
 * @var int|null                                              $maxFont
 */
?>
<div
    id="<?= e($id) ?>"
    class="wt-statistics-cloud"
    data-widget="cloud"
    data-payload="<?= e(json_encode($data, JSON_THROW_ON_ERROR)) ?>"
    <?php if (isset($minFont)): ?>data-min-font="<?= e((string) $minFont) ?>"<?php endif; ?>
    <?php if (isset($maxFont)): ?>data-max-font="<?= e((string) $maxFont) ?>"<?php endif; ?>
></div>
```

- [ ] **Step 6: Update existing template callers.**

For each of `Overview.phtml`, `Places.phtml`, `Births.phtml`, `Deaths.phtml` in `${STAT}/resources/views/modules/statistics-chart/Templates/`, ensure each `view(...)` call to a partial now passes the data the partial expects (label/value shape; not the old object form). Concrete edits depend on the existing template content — read each file, then edit only the partial-include line(s).

For the GeoMap partials specifically: the `geojsonUrl` argument is `$module->assetUrl('js/world-map.geojson')` — call it once and pass as `geojsonUrl`.

- [ ] **Step 7: Rebuild the bundle.**

```bash
cd ${STAT} && npm run prepare
```

- [ ] **Step 8: Audit-loop.**

- [ ] **Step 9: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    resources/views/modules/statistics-chart/Partials/ \
    resources/views/modules/statistics-chart/Templates/Overview.phtml \
    resources/views/modules/statistics-chart/Templates/Places.phtml \
    resources/views/modules/statistics-chart/Templates/Births.phtml \
    resources/views/modules/statistics-chart/Templates/Deaths.phtml \
    resources/js/webtrees-statistics.js \
    resources/js/webtrees-statistics.min.js
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Partials emit data-widget+JSON payload for client-side rendering

DonutChart/GeoMap/ProgressList/TagCloud partials become declarative
DIVs with data-* attributes; JS picks them up via the dispatcher.
Every interpolated value is wrapped with e() per the html-attribute
escape rule. GeoMap takes the geojson URL from $module->assetUrl()
instead of hard-coded paths."
```

---

## Task B13: CSS — chart-css-variable pattern + dark mode

**Files:**
- Create: `${STAT}/resources/css/webtrees-statistics.css`

- [ ] **Step 1: Author the stylesheet.**

Create `${STAT}/resources/css/webtrees-statistics.css`:

```css
/*
 * webtrees-statistics — chart styles
 *
 * Uses the chart-css-variable pattern so theme/dark-mode flip via
 * --chart-text-* / --chart-empty-fill without touching CSS.
 */

.wt-statistics-donut,
.wt-statistics-map,
.wt-statistics-list,
.wt-statistics-cloud {
    --chart-text-primary:   var(--bs-body-color, #212529);
    --chart-text-secondary: var(--bs-secondary-color, #6c757d);
    --chart-text-muted:     var(--bs-tertiary-color, #adb5bd);
    --chart-empty-fill:     var(--bs-tertiary-bg, #f1f3f5);
}

.wt-statistics-donut .donut-chart .slice.male     { fill: #4a90e2; }
.wt-statistics-donut .donut-chart .slice.female   { fill: #f06292; }
.wt-statistics-donut .donut-chart .slice.unknown  { fill: var(--chart-text-muted); }

.wt-statistics-donut .donut-chart .slice.living   { fill: #66bb6a; }
.wt-statistics-donut .donut-chart .slice.deceased { fill: #ef5350; }

.wt-statistics-donut .donut-chart .slice.married  { fill: #4a90e2; }
.wt-statistics-donut .donut-chart .slice.single   { fill: #ffa726; }
.wt-statistics-donut .donut-chart .slice.widowed  { fill: #ab47bc; }
.wt-statistics-donut .donut-chart .slice.divorced { fill: #ef5350; }

.wt-statistics-list .progress-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.wt-statistics-list .progress-list > li {
    display: grid;
    grid-template-columns: minmax(8rem, 12rem) 1fr auto;
    gap: 0.5rem;
    align-items: center;
    padding: 0.25rem 0;
}

.wt-statistics-list .progress-label {
    color: var(--chart-text-primary);
    overflow-wrap: anywhere;
}

.wt-statistics-list .progress-bar {
    background: var(--chart-empty-fill);
    border-radius: 0.25rem;
    overflow: hidden;
    height: 0.75rem;
}

.wt-statistics-list .progress-bar-fill {
    display: block;
    height: 100%;
    background: #4a90e2;
}

.wt-statistics-list .progress-value {
    color: var(--chart-text-secondary);
    font-variant-numeric: tabular-nums;
}

.wt-statistics-cloud .tag-cloud {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: baseline;
    color: var(--chart-text-primary);
}

.wt-statistics-cloud .tag-cloud > span {
    line-height: 1.1;
}

.wt-statistics-map .world-map .country {
    stroke: var(--chart-text-muted);
    stroke-width: 0.5;
}

.chart-empty-state {
    text-align: center;
    color: var(--chart-text-muted);
    padding: 1.5rem 0;
}
```

- [ ] **Step 2: Audit-loop.**

- [ ] **Step 3: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    resources/css/webtrees-statistics.css
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Add chart styles using the shared chart-css-variable pattern

Theme + dark-mode track Bootstrap CSS variables on the widget root, so
no media query is needed. Donut slice classes match the labels emitted
by Statistic.php. ProgressList uses overflow-wrap:anywhere to respect
long surnames without nowrap+max-width truncation."
```

---

## Task B14: README + cleanup

**Files:**
- Modify: `${STAT}/README.md`

- [ ] **Step 1: Drop the WIP banner and reflect 2.2 + Phase-1 state.**

Open `${STAT}/README.md` and replace the `# !!! WIP !!!` banner and surrounding intro with:

```markdown
# Statistics

This module provides SVG-based statistics charts for the [webtrees](https://www.webtrees.net) genealogy application.

> **Status:** under active development. The Overview, Places, Births and Deaths tabs render real data; Relationships, Age, Weddings, Divorces and Children show a placeholder until a future release.

## Installation

Requires webtrees 2.2.

### Using Composer

```shell
composer require magicsunday/webtrees-statistics:dev-main
```

The module installs into the `modules_v4` directory automatically.

### Using Git

```shell
git clone https://github.com/magicsunday/webtrees-statistics.git modules_v4/webtrees-statistics
```

## Development

```shell
make install
make build
make test
```

For local sibling-repo development against the chart-lib and module-base packages, see `make link-base` and `make link-chart-lib`.
```

(Keep any sections below this — license, contributors, badges.)

- [ ] **Step 2: Audit-loop.**

- [ ] **Step 3: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add README.md
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Update README — drop WIP banner, document Phase-1 tab state

Five tabs ship real data; five show a placeholder. Adds the make-based
install/build/test commands and links to the sibling-repo symlink
targets for local development."
```

---

## Task B15: Browser verification — golden path through all tabs

**Files:** (no code changes — verification only)

- [ ] **Step 1: Restart webtrees PHP-FPM + bust the JS cache.**

```bash
docker compose exec phpfpm sh -c "touch /var/www/html/index.php" || true
touch ${STAT}/resources/js/webtrees-statistics.min.js
```

- [ ] **Step 2: Open the statistics chart for the demo tree via Playwright.**

Drive Playwright headless against `http://webtrees.nas.lan/index.php?route=/tree/demo/webtrees-statistics/I1` (adjust the xref to a valid one). For each of the nine tabs:

- Click the tab.
- Wait for `[data-widget]` to be replaced by a non-empty SVG/UL/DIV (or `.chart-empty-state` on stubs).
- Screenshot to `/tmp/statistics-phase-1/<tab-slug>.png`.

Tabs to capture: `overview`, `relationships`, `places`, `age`, `births`, `deaths`, `weddings`, `divorces`, `children`.

- [ ] **Step 3: Assert behavior.**

- The five real tabs each show a chart with at least one path or list item.
- The five stub tabs each show `.wt-statistics-coming-soon` containing the translated "This statistic is planned for a future release." text.
- No browser console errors on any tab.

- [ ] **Step 4: Audit-loop on the screenshot bundle (no commit yet — verification only).**

- [ ] **Step 5: If anything fails, fix in a new task before moving on. Otherwise, proceed.**

---

## Task B16: Phase-1 sign-off + close-out commit

**Files:**
- Modify: `${STAT}/docs/superpowers/plans/2026-05-20-webtrees-statistics-2.2-phase-1.md` — flip every `- [ ]` to `- [x]` (this file).
- Optional: `${STAT}/CHANGELOG.md` (new) — record Phase 1 changes for future release notes.

- [ ] **Step 1: Mark the plan complete.**

Edit this plan file: replace every unchecked checkbox `- [ ]` with `- [x]`.

- [ ] **Step 2: Optionally write CHANGELOG.md.**

Create `${STAT}/CHANGELOG.md`:

```markdown
# Changelog

## [Unreleased] — Phase 1 (2026-05-20)

### Added
- Tooling parity with fan/pedigree/descendants chart modules (Biome, Rollup, Jest 30, PHPUnit 12/13, phpstan 2, rector 2, php-cs-fixer 3).
- chart-lib v1.6.0 consumption via npm pin to GitHub tag.
- Stub actions + ComingSoon template for the five tabs without real data yet.
- PHPUnit tests for Module, Statistic, and each repository.
- Jest test for the JS entry-point dispatcher.

### Changed
- Port to webtrees 2.2: `Fisharebest\Webtrees\StatisticsData` replaces the removed `Statistics\Repository\*` and `Statistics\Google\*` types.
- Family status donut uses real Widowed + Divorced queries instead of the 38/0 placeholders.
- All hardcoded German labels (`Verheiratet`, `Allein lebend`, `Verwitwet`, `Geschieden`) routed through `I18N::translate`.
- JS layer rebuilt around `[data-widget]` dispatcher; widgets imported from chart-lib instead of vendored d3.

### Removed
- Vendored d3 (`resources/js/modules/lib/d3.js`) and local widget classes.
- ~100 lines of commented-out code in EventRepository.
- Singular `webtrees-statistic` typo in the composer package name.
```

- [ ] **Step 3: Audit-loop.**

- [ ] **Step 4: Commit.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics add \
    docs/superpowers/plans/2026-05-20-webtrees-statistics-2.2-phase-1.md \
    CHANGELOG.md
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics commit -m "Mark Phase-1 plan complete + record changelog

All sixteen tasks closed. The module is now runnable on webtrees 2.2
with the structural blueprint of the other chart modules. The next
phase will implement real data for Relationships, Age, Weddings,
Divorces and Children, after which a 1.0 release tag becomes the
unblock for a public release."
```

- [ ] **Step 5: Push.**

```bash
git -C /volume2/docker/webtrees/app/vendor/magicsunday/webtrees-statistics push origin main
```

---

# Self-Review Notes

Spec coverage:

- §1 (Context): captured in plan header + Task B1 (composer fix), B6/B7/B8/B9 (StatisticsData migration).
- §2 (Goals): all five Phase-1 goals have at least one task — composer/install (B1), real-data tabs (B6–B12), stubs (B5), tooling parity (B2), chart-lib consumption (A6/A7/A8/B3/B11).
- §3 (Sequencing): Part A blocks Part B; Task A8 tags before Task B3 pins.
- §4 (chart-lib widgets): A1–A5 each ship a widget + test + audit + commit; A6 exports; A7 versions; A8 tags.
- §5.1 (composer.json): B1.
- §5.2 (module.php): no chart-lib PSR-4 line — confirmed in plan; module.php left untouched.
- §5.3 (Module class): B4 (interfaces) + B5 (stub actions).
- §5.4 (Statistic + reuse): B6 + B7 + B8 + B9 — every method in the spec mapping table has a task.
- §5.5 (German strings): B6 (Statistic.php I18N) + B10 (grep sweep).
- §5.6 (commented code triage): B9 deletes block 3; blocks 1+2 (`getTotalBirths`/`Deaths`) are absent from the new code path — replaced by direct StatisticsData calls if needed (not currently called).
- §5.7 (Views): B5 (stub templates), B12 (data-widget partials).
- §5.8 (JS): B11.
- §5.9 (CSS): B13.
- §5.10 (Tooling): B2.
- §5.11 (Stubs): B5.
- §5.12 (Testing): tests embedded in each task; PHPUnit added incrementally.
- §5.13 (commit plan): mirrored by Tasks B1–B16 with the same boundaries.
- §6 (audit-loop): captured as the mandatory step in every task plus the top-level policy block.
- §9 (Verification): B15 covers all 10 sub-bullets.

Placeholder scan: no `TBD` / `TODO`. Code blocks contain real code. Commands are concrete.

Type consistency: `BaseWidget` and subclass names match between A1–A5 tests and the index.js exports in A6 (alphabetical: BaseWidget, DonutChart, ProgressList, TagCloud, WorldMap). JS module paths in B11 reference the chart-lib name `@magicsunday/webtrees-chart-lib`, matching A6's export site. Repository signatures `(Tree, StatisticsData)` are consistent across B7/B8/B9 tests and impl.
