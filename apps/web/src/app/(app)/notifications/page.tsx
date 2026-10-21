import Link from "next/link";
import { apiRequest, type AppNotification } from "@/lib/api";
import { formatDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";
import { openNotificationAction, readAllNotificationsAction } from "./actions";

export const metadata = { title: "Notifications" };

export default async function NotificationsPage() {
  const res = await apiRequest<{ data: AppNotification[]; unread_count: number }>("/notifications?limit=100");

  return (
    <div className="max-w-3xl">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Notifications</h1>
          <p className="mt-2 text-muted">
            Assignments, reviews, due dates, profile matches and changed sources. Choose which also arrive by email in{" "}
            <Link href="/settings#notifications-heading" className="text-signal underline-offset-4 hover:underline">
              settings
            </Link>
            .
          </p>
        </div>
        {res.ok && res.data.unread_count > 0 && (
          <form action={readAllNotificationsAction}>
            <button type="submit" className="text-sm text-signal underline-offset-4 hover:underline">
              Mark all as read
            </button>
          </form>
        )}
      </div>

      {!res.ok ? (
        <p role="alert" className="mt-8 border-l-2 border-caution pl-3 text-sm">
          {res.error.message}
        </p>
      ) : res.data.data.length === 0 ? (
        <p className="mt-8 text-sm text-muted">Nothing yet.</p>
      ) : (
        <ol className="mt-8 divide-y divide-rule border-y border-rule">
          {res.data.data.map((n) => (
            <li key={n.id} className={cn("relative py-4 pl-5", !n.read_at && "bg-signal/[0.03]")}>
              {!n.read_at && <span className="absolute left-1.5 top-[1.45rem] h-2 w-2 rounded-full bg-signal" aria-label="Unread" />}
              <form action={openNotificationAction}>
                <input type="hidden" name="id" value={n.id} />
                <input type="hidden" name="path" value={n.path ?? ""} />
                <button type="submit" className={cn("text-left text-[15px] leading-snug hover:text-signal hover:underline hover:underline-offset-4", !n.read_at && "font-medium")}>
                  {n.title}
                </button>
              </form>
              {n.body && <p className="mt-1 whitespace-pre-line text-sm text-muted">{n.body}</p>}
              <p className="mt-1 text-xs text-muted">
                {formatDateTime(n.created_at)}
                {n.emailed && " · Also emailed"}
              </p>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}
