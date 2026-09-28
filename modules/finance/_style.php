<?php
/**
 * The finance portal's look, in one file.
 *
 * The colour roles live here rather than in each page so that a figure and a
 * chart on two different screens cannot end up in two different blues. Both
 * sets were run through the palette validator against this system's own
 * surfaces (#ffffff light, #1e293b dark) rather than assumed — the dark ordinal
 * ramp is a different set of steps from the light one, because the light one's
 * darkest step measures 1.81:1 against the dark surface and disappears into it.
 *
 * Included inside the page body, after the header, by every finance screen.
 */
?>
<style>
/*
 * Colour roles, defined once and referenced by role throughout, so the
 * light/dark values swap in one place. Both sets were run through the palette
 * validator against this system's own surfaces.
 */
.fin {
    --fin-surface:  #ffffff;
    --fin-plane:    #f8fafc;
    --fin-ink:      #0f172a;
    --fin-ink-2:    #52514e;
    --fin-muted:    #898781;
    --fin-grid:     #e1e0d9;
    --fin-axis:     #c3c2b7;
    --fin-ring:     rgba(11,11,11,.10);

    /* Categorical: 1 = money in, 2 = money out. Fixed order, never cycled. */
    --fin-in:       #2a78d6;
    --fin-out:      #eb6834;

    /* Ordinal ramp for the ageing bands: one hue, light to dark. */
    --fin-age-1:    #86b6ef;
    --fin-age-2:    #5598e7;
    --fin-age-3:    #2a78d6;
    --fin-age-4:    #184f95;

    /* Status. Fixed, never themed, never used for a series. */
    --fin-good:     #0ca30c;
    --fin-warning:  #fab219;
    --fin-critical: #d03b3b;
    --fin-up-good:  #006300;
}
[data-theme="dark"] .fin {
    --fin-surface:  #1e293b;
    --fin-plane:    #172033;
    --fin-ink:      #e2e8f0;
    --fin-ink-2:    #c3c2b7;
    --fin-muted:    #898781;
    --fin-grid:     #2c3a52;
    --fin-axis:     #384a66;
    --fin-ring:     rgba(255,255,255,.10);

    --fin-in:       #3987e5;
    --fin-out:      #d95926;

    /* Not a flip of the light ramp: the light ramp's darkest step measures
       1.81:1 against this surface and vanishes into it. These four were
       validated against #1e293b. */
    --fin-age-1:    #b7d3f6;
    --fin-age-2:    #86b6ef;
    --fin-age-3:    #5598e7;
    --fin-age-4:    #2a78d6;

    --fin-up-good:  #0ca30c;
}

.fin { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }

/* The filter row: one row, above everything it scopes. */
.fin-filters{display:flex;justify-content:space-between;align-items:center;gap:14px;
    flex-wrap:wrap;padding:12px 0 18px}
.fin-asat{font-size:12px;color:var(--fin-muted)}

/* Hero — exactly one per view. */
.fin-hero{background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:16px;
    padding:22px 24px;display:flex;justify-content:space-between;align-items:flex-start;
    gap:20px;flex-wrap:wrap}
.fin-hero .lbl{font-size:12px;letter-spacing:.06em;text-transform:uppercase;
    color:var(--fin-muted);font-weight:600}
.fin-hero .fig{font-size:clamp(34px,5vw,52px);font-weight:600;line-height:1.05;margin-top:6px;
    color:var(--fin-ink);letter-spacing:-.02em}
.fin-hero .note{font-size:13px;color:var(--fin-ink-2);margin-top:7px}

/* Stat tiles. */
.fin-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:14px}
@media (max-width:1100px){.fin-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:520px){.fin-tiles{grid-template-columns:1fr}}
.fin-tile{background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:14px;
    padding:15px 17px;display:flex;flex-direction:column;gap:3px;min-width:0}
.fin-tile .lbl{font-size:12px;color:var(--fin-muted);font-weight:600}
.fin-tile .val{font-size:25px;font-weight:600;color:var(--fin-ink);line-height:1.15;
    letter-spacing:-.01em}
.fin-tile .sub{font-size:12px;color:var(--fin-ink-2)}
.fin-d{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600}
.fin-d.good{color:var(--fin-up-good)}
.fin-d.bad{color:var(--fin-critical)}
.fin-d.flat{color:var(--fin-muted)}
.fin-spark{margin-top:7px;height:26px}

/* Cards. */
.fin-card{background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:14px;
    overflow:hidden}
.fin-card > header{padding:13px 17px;border-bottom:1px solid var(--fin-ring);
    display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.fin-card > header h2{font-size:14px;font-weight:600;margin:0;color:var(--fin-ink)}
.fin-card > header .hint{font-size:12px;color:var(--fin-muted)}
.fin-body{padding:17px}

/* A chart container tall enough to include its axis band. */
.fin-plot{position:relative;height:280px}
.fin-plot-sm{position:relative;height:210px}

/* Ageing: horizontal bars with the value beside the bar, never inside it. */
.fin-age{display:flex;flex-direction:column;gap:12px}
.fin-age-row{display:grid;grid-template-columns:104px minmax(0,1fr) auto;gap:11px;align-items:center}
.fin-age-row .band{font-size:12.5px;color:var(--fin-ink-2)}
.fin-age-row .track{height:9px;border-radius:5px;background:var(--fin-plane);overflow:hidden}
.fin-age-row .track span{display:block;height:100%;border-radius:5px}
.fin-age-row .amt{font-size:13px;font-weight:600;color:var(--fin-ink);
    font-variant-numeric:tabular-nums}

/* Tables — the twin of every chart. tabular figures belong here. */
.fin-table{width:100%;font-size:13px;border-collapse:collapse;font-variant-numeric:tabular-nums}
.fin-table th{text-align:left;font-weight:600;color:var(--fin-muted);font-size:11.5px;
    text-transform:uppercase;letter-spacing:.04em;padding:8px 12px;
    border-bottom:1px solid var(--fin-ring)}
.fin-table td{padding:9px 12px;border-bottom:1px solid var(--fin-ring);color:var(--fin-ink)}
.fin-table tr:last-child td{border-bottom:0}
.fin-table .num{text-align:right}
.fin-late{color:var(--fin-critical);font-weight:600}
.fin-swatch{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:6px}

/* A status note. Icon and words, so colour never carries it alone. */
.fin-note{display:flex;gap:10px;align-items:flex-start;padding:12px 15px;border-radius:12px;
    font-size:13px;line-height:1.5;border:1px solid var(--fin-ring);background:var(--fin-surface)}
.fin-note i{margin-top:2px}
.fin-note.warning i{color:var(--fin-warning)}
.fin-note.critical i{color:var(--fin-critical)}
.fin-note strong{color:var(--fin-ink)}
.fin-note{color:var(--fin-ink-2)}

.fin-grid2{display:grid;grid-template-columns:minmax(0,7fr) minmax(0,5fr);gap:16px;margin-top:16px}
@media (max-width:1100px){.fin-grid2{grid-template-columns:1fr}}
.fin-stack{display:flex;flex-direction:column;gap:16px}
.fin-meth{display:flex;justify-content:space-between;align-items:center;padding:10px 0;
    border-bottom:1px solid var(--fin-ring);font-size:13px;color:var(--fin-ink)}
.fin-meth:last-child{border-bottom:0}
.fin-empty{font-size:13px;color:var(--fin-muted)}
details.fin-tv{margin-top:14px}
details.fin-tv > summary{font-size:12px;color:var(--fin-muted);cursor:pointer}
</style>
