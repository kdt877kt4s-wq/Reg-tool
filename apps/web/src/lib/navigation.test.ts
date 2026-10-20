import { describe, expect, it } from "vitest";
import { NAV_ITEMS, availabilityNote, isAvailable } from "./navigation";

describe("navigation", () => {
  it("lists the nine primary sections in brief order", () => {
    expect(NAV_ITEMS.map((i) => i.label)).toEqual([
      "Overview",
      "Regulatory intelligence",
      "Impact assessments",
      "Policy analysis",
      "Compliance tasks",
      "Regulatory calendar",
      "Reports",
      "AI assistant",
      "Organization settings",
    ]);
  });

  it("only makes built sections available", () => {
    const overview = NAV_ITEMS[0]!;
    const tasks = NAV_ITEMS.find((i) => i.label === "Compliance tasks")!;
    expect(isAvailable(overview, 0)).toBe(true);
    expect(isAvailable(tasks, 0)).toBe(false);
    expect(isAvailable(tasks, 4)).toBe(true);
  });

  it("labels unbuilt sections with the stage they arrive in", () => {
    const tasks = NAV_ITEMS.find((i) => i.label === "Compliance tasks")!;
    const assistant = NAV_ITEMS.find((i) => i.label === "AI assistant")!;
    expect(availabilityNote(tasks, 0)).toBe("Arrives in Stage 4");
    expect(availabilityNote(assistant, 0)).toBe("After the MVP");
    expect(availabilityNote(NAV_ITEMS[0]!, 0)).toBeNull();
  });
});
