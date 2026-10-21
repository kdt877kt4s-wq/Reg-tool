import { NextResponse, type NextRequest } from "next/server";
import { apiFetch } from "@/lib/api";

/** Evidence download, streamed through this server so the API token stays here. */
export async function GET(_request: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^\d+$/.test(id)) return new NextResponse(null, { status: 404 });

  const res = await apiFetch(`/files/${id}/download`);
  if (!res) return new NextResponse("RuleRadar could not reach the server.", { status: 502 });
  if (!res.ok) {
    const json = (await res.json().catch(() => null)) as { message?: string } | null;
    const message = res.status === 404 ? "File not found." : (json?.message ?? "The file is not available.");
    return new NextResponse(message, { status: res.status, headers: { "Content-Type": "text/plain; charset=utf-8" } });
  }

  const headers = new Headers({ "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff" });
  for (const name of ["content-type", "content-disposition", "content-length"]) {
    const value = res.headers.get(name);
    if (value) headers.set(name, value);
  }
  return new NextResponse(res.body, { status: 200, headers });
}
