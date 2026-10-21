"use client";

import { buttonClass } from "@/components/ui/button-styles";

export function PrintButton() {
  return (
    <button type="button" onClick={() => window.print()} className={buttonClass("secondary", "print:hidden")}>
      Print or save as PDF
    </button>
  );
}
