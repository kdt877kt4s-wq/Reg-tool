import { describe, expect, it } from "vitest";
import { commentDeadlineNote, daysUntil, dueNote, formatDate, monthGrid, parseMonth, shiftMonth, snippetParts } from "./format";

describe("formatDate", () => {
  it("formats calendar dates without shifting the day", () => {
    expect(formatDate("2026-10-02")).toBe("Oct 2, 2026");
    expect(formatDate("2026-01-01")).toBe("Jan 1, 2026");
  });

  it("returns null for missing or invalid dates", () => {
    expect(formatDate(null)).toBeNull();
    expect(formatDate("not a date")).toBeNull();
  });
});

describe("daysUntil", () => {
  it("counts whole calendar days", () => {
    const today = new Date("2026-10-20T23:30:00Z");
    expect(daysUntil("2026-10-20", today)).toBe(0);
    expect(daysUntil("2026-12-15", today)).toBe(56);
    expect(daysUntil("2026-10-19", today)).toBe(-1);
  });
});

describe("snippetParts", () => {
  it("splits highlight markers into parts without producing HTML", () => {
    expect(snippetParts("the [[[wage]]] [[[index]]] changed")).toEqual([
      { text: "the ", match: false },
      { text: "wage", match: true },
      { text: " ", match: false },
      { text: "index", match: true },
      { text: " changed", match: false },
    ]);
  });

  it("keeps markup in source text inert", () => {
    expect(snippetParts("<script>x</script>")).toEqual([{ text: "<script>x</script>", match: false }]);
  });
});

describe("commentDeadlineNote", () => {
  const today = new Date("2026-10-20T15:00:00Z");
  it("describes open, closing and closed comment periods", () => {
    expect(commentDeadlineNote("2026-12-15", today)).toBe("Comments close Dec 15, 2026 (56 days)");
    expect(commentDeadlineNote("2026-10-20", today)).toBe("Comments close today");
    expect(commentDeadlineNote("2026-10-01", today)).toBe("Comments closed Oct 1, 2026");
    expect(commentDeadlineNote(null, today)).toBeNull();
  });
});

describe("calendar helpers", () => {
  it("builds Sunday-first weeks covering the month", () => {
    const weeks = monthGrid(2026, 10);
    expect(weeks[0]![0]).toBe("2026-09-27");
    expect(weeks[0]![4]).toBe("2026-10-01");
    expect(weeks.at(-1)!.at(-1)).toBe("2026-10-31");
    expect(weeks.every((w) => w.length === 7)).toBe(true);
  });

  it("parses and shifts months", () => {
    expect(parseMonth("2026-02")).toEqual({ year: 2026, month: 2 });
    expect(parseMonth("2026-13", new Date("2026-10-20T12:00:00Z"))).toEqual({ year: 2026, month: 10 });
    expect(shiftMonth(2026, 1, -1)).toBe("2025-12");
    expect(shiftMonth(2026, 12, 1)).toBe("2027-01");
  });

  it("describes due dates", () => {
    const today = new Date("2026-10-20T12:00:00Z");
    expect(dueNote("2026-10-17", today)).toBe("Overdue by 3 days");
    expect(dueNote("2026-10-20", today)).toBe("Due today");
    expect(dueNote("2026-10-21", today)).toBe("Due in 1 day");
  });
});
