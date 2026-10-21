"use client";

import { useState } from "react";
import { formatDate } from "@/lib/format";

type Week = { week: string; total: number; matches: number };

// Validated pair (dataviz validator, light surface): matches in the signal
// blue, other publications in a lighter blue. The lighter step is below 3:1
// against white, so the table view below the chart carries the numbers.
const MATCH = "#2F5FD0";
const OTHER = "#7FA0EA";

/** Federal Register publications per week, matches to the profile stacked at the base. */
export function WeeklyChart({ weeks, showMatches }: { weeks: Week[]; showMatches: boolean }) {
  const [hover, setHover] = useState<number | null>(null);
  const max = Math.max(1, ...weeks.map((w) => w.total));
  const W = 640;
  const H = 160;
  const pad = { top: 8, bottom: 22, left: 28 };
  const plotH = H - pad.top - pad.bottom;
  const slot = (W - pad.left) / weeks.length;
  const bar = Math.min(28, slot * 0.6);
  const y = (v: number) => pad.top + plotH - (v / max) * plotH;
  const ticks = [0, Math.ceil(max / 2), max].filter((v, i, a) => a.indexOf(v) === i);
  const h = hover === null ? null : weeks[hover]!;

  return (
    <figure className="mt-4">
      <div className="flex flex-wrap gap-4 text-xs" aria-hidden>
        {showMatches && (
          <span className="flex items-center gap-1.5">
            <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: MATCH }} /> Match your profile
          </span>
        )}
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: OTHER }} /> {showMatches ? "Other publications" : "Publications"}
        </span>
      </div>
      <div className="relative mt-2">
        <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Weekly Federal Register publications; the table below lists the numbers.">
          {ticks.map((t) => (
            <g key={t}>
              <line x1={pad.left} x2={W} y1={y(t)} y2={y(t)} stroke="var(--color-rule)" strokeWidth={1} />
              <text x={pad.left - 6} y={y(t) + 3.5} textAnchor="end" fontSize={10} fill="var(--color-muted)">
                {t}
              </text>
            </g>
          ))}
          {weeks.map((w, i) => {
            const x = pad.left + i * slot + (slot - bar) / 2;
            const matches = showMatches ? w.matches : 0;
            const others = w.total - matches;
            const r = 4;
            return (
              <g key={w.week} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
                <rect x={pad.left + i * slot} y={pad.top} width={slot} height={plotH} fill="transparent" />
                {matches > 0 && (
                  <rect x={x} y={y(matches)} width={bar} height={y(0) - y(matches)} fill={MATCH} rx={others > 0 ? 0 : r} />
                )}
                {others > 0 && (
                  <path
                    // Rounded top only; a 2px gap separates it from the matches segment.
                    d={(() => {
                      const top = y(w.total);
                      const bottom = y(matches) - (matches > 0 ? 2 : 0);
                      const rr = Math.min(r, (bottom - top) / 2, bar / 2);
                      return `M${x},${bottom} V${top + rr} Q${x},${top} ${x + rr},${top} H${x + bar - rr} Q${x + bar},${top} ${x + bar},${top + rr} V${bottom} Z`;
                    })()}
                    fill={OTHER}
                  />
                )}
                {hover === i && <rect x={x - 2} y={pad.top} width={bar + 4} height={plotH} fill="var(--color-ink)" opacity={0.04} />}
                {(i % 3 === 0 || i === weeks.length - 1) && (
                  <text x={x + bar / 2} y={H - 6} textAnchor="middle" fontSize={10} fill="var(--color-muted)">
                    {formatDate(w.week)?.replace(/, \d{4}$/, "")}
                  </text>
                )}
              </g>
            );
          })}
        </svg>
        {h && hover !== null && (
          <div
            className="pointer-events-none absolute top-0 z-10 rounded-md border border-rule bg-paper px-3 py-2 text-xs shadow-sm"
            style={{ left: `${((pad.left + hover * slot + slot / 2) / W) * 100}%`, transform: "translateX(-50%)" }}
          >
            <p className="font-medium">Week of {formatDate(h.week)}</p>
            <p className="tabular-nums">{h.total} published</p>
            {showMatches && <p className="tabular-nums text-muted">{h.matches} match your profile</p>}
          </div>
        )}
      </div>
      <details className="mt-2 text-xs">
        <summary className="cursor-pointer text-muted">Show as a table</summary>
        <table className="mt-2 w-full max-w-md text-left tabular-nums">
          <thead className="text-muted">
            <tr>
              <th className="py-1 font-normal">Week of</th>
              <th className="py-1 text-right font-normal">Published</th>
              {showMatches && <th className="py-1 text-right font-normal">Match profile</th>}
            </tr>
          </thead>
          <tbody>
            {weeks.map((w) => (
              <tr key={w.week} className="border-t border-rule">
                <td className="py-1">{formatDate(w.week)}</td>
                <td className="py-1 text-right">{w.total}</td>
                {showMatches && <td className="py-1 text-right">{w.matches}</td>}
              </tr>
            ))}
          </tbody>
        </table>
      </details>
    </figure>
  );
}
