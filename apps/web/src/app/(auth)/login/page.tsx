import Link from "next/link";
import { LoginForm } from "./login-form";

export const metadata = { title: "Sign in" };

const notices: Record<string, string> = {
  reset: "Your password is updated. Sign in with it now.",
  joined: "You have joined the organization. Sign in to continue.",
  expired: "Your session ended. Sign in again.",
};

export default async function LoginPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const params = await searchParams;
  const notice = Object.keys(notices).find((k) => params[k]);

  return (
    <>
      <h1 className="text-2xl font-semibold tracking-tight">Sign in</h1>
      {notice && (
        <p role="status" className="mt-4 border-l-2 border-signal pl-3 text-sm">
          {notices[notice]}
        </p>
      )}
      <LoginForm next={params.next ?? "/"} />
      <div className="mt-8 space-y-2 text-sm text-muted">
        <p>
          <Link href="/forgot-password" className="text-signal underline-offset-4 hover:underline">
            Forgot your password?
          </Link>
        </p>
        <p>
          New to RuleRadar?{" "}
          <Link href="/register" className="text-signal underline-offset-4 hover:underline">
            Create an organization
          </Link>
        </p>
      </div>
    </>
  );
}
