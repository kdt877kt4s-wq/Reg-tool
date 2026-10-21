import { NextResponse, type NextRequest } from "next/server";
import { apiFetch } from "@/lib/api";

const REPORTS = new Set(["regulatory-activity", "tasks"]);
const PARAMS = ["from", "to", "scope", "status"];

/** CSV export, streamed through this server so the API token stays here. */
export async function GET(request: NextRequest, { params }: { params: Promise<{ report: string }> }) {
  const { report } = await params;
  if (!REPORTS.has(report)) return new NextResponse(null, { status: 404 });

  const query = new URLSearchParams({ format: "csv" });
  for (const key of PARAMS) {
    const value = request.nextUrl.searchParams.get(key);
    if (value) query.set(key, value);
  }

  const res = await apiFetch(`/reports/${report}?${query.toString()}`);
  if (!res) return new NextResponse("RuleRadar could not reach the server.", { status: 502 });
  if (!res.ok) {
    const json = (await res.json().catch(() => null)) as { message?: string } | null;
    return new NextResponse(json?.message ?? "The report is not available.", { status: res.status, headers: { "Content-Type": "text/plain; charset=utf-8" } });
  }
  const headers = new Headers({ "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff" });
  for (const name of ["content-type", "content-disposition"]) {
    const value = res.headers.get(name);
    if (value) headers.set(name, value);
  }
  return new NextResponse(res.body, { status: 200, headers });
}
