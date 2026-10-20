import Link from "next/link";
import { RegisterForm } from "./register-form";

export const metadata = { title: "Create an organization" };

export default function RegisterPage() {
  return (
    <>
      <h1 className="text-2xl font-semibold tracking-tight">Create your organization</h1>
      <p className="mt-3 text-sm text-muted">
        You will be its administrator. Next you will set up two-factor login and describe what your organization
        does, so RuleRadar can judge which regulations may apply.
      </p>
      <RegisterForm />
      <p className="mt-8 text-sm text-muted">
        Already have an account?{" "}
        <Link href="/login" className="text-signal underline-offset-4 hover:underline">
          Sign in
        </Link>
      </p>
    </>
  );
}
