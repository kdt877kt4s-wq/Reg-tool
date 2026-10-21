import { NextResponse, type NextRequest } from "next/server";
import { apiFetch } from "@/lib/api";

/**
 * Evidence upload. The browser posts the form here; this server adds the
 * API token and forwards the file, then sends the person back to the task.
 * Same-origin posts only.
 */
export async function POST(request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^\d+$/.test(id)) return new NextResponse(null, { status: 404 });

  const origin = request.headers.get("origin");
  if (!origin || origin !== request.nextUrl.origin) {
    return new NextResponse("Cross-site request refused.", { status: 403 });
  }

  const back = new URL(`/tasks/${id}`, request.nextUrl.origin);
  const incoming = await request.formData().catch(() => null);
  if (!incoming) {
    back.searchParams.set("evidence_error", "The upload could not be read. Try again.");
    return NextResponse.redirect(back, 303);
  }

  const body = new FormData();
  const file = incoming.get("file");
  if (file instanceof File && file.size > 0) body.append("file", file, file.name);
  const note = String(incoming.get("note") ?? "").trim();
  if (note) body.append("note", note);

  const res = await apiFetch(`/tasks/${id}/evidence`, { method: "POST", body, timeoutMs: 120_000 });
  if (!res) {
    back.searchParams.set("evidence_error", "RuleRadar could not reach the server. Try again.");
  } else if (!res.ok) {
    const json = (await res.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
    const first = json?.errors ? Object.values(json.errors)[0]?.[0] : undefined;
    back.searchParams.set("evidence_error", first ?? json?.message ?? "The evidence was not saved.");
  } else {
    back.searchParams.set("evidence", "added");
  }
  back.hash = "evidence";
  return NextResponse.redirect(back, 303);
}
