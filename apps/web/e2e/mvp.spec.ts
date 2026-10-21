import { readFileSync } from "node:fs";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { totp } from "./totp";

/**
 * The MVP success path from the brief, end to end in a real browser:
 * an administrator creates the organization and invites a compliance
 * officer; the officer configures the profile, opens a real (recorded)
 * Federal Register publication, reads a cited analysis, approves the
 * assessment and assigns the resulting task; the owner submits evidence
 * and the officer accepts it.
 *
 * The analysis comes from the e2e-only scripted stand-in (no AI credits);
 * the page labels it as test output.
 */

const run = Date.now().toString(36);
const PASSWORD = "correct horse battery staple 42";
const admin = { name: "Avery Admin", email: `avery.${run}@example.test` };
const officer = { name: "Priya Officer", email: `priya.${run}@example.test` };
const DOC_TITLE = /Inpatient Psychiatric Facilities Prospective Payment System-Rate Update; Correction/;
const ACTION = "Review the cited passage with the claims team";

const lastStep = new Map<string, number>();

/** A TOTP code not used before for this secret (the API rejects replays). */
async function freshCode(secret: string): Promise<string> {
  let step = Math.floor(Date.now() / 30_000);
  while (lastStep.get(secret) === step) {
    await new Promise((r) => setTimeout(r, 1_000));
    step = Math.floor(Date.now() / 30_000);
  }
  lastStep.set(secret, step);
  return totp(secret);
}

async function enrollTwoFactor(page: Page): Promise<string> {
  await expect(page).toHaveURL(/\/security\/two-factor/);
  await page.getByRole("button", { name: "Set up two-factor login" }).click();
  const secret = (await page.locator("code").first().textContent())!.trim();
  await page.getByLabel("6-digit code").fill(await freshCode(secret));
  await page.getByRole("button", { name: "Turn on two-factor login" }).click();
  await expect(page.getByText("Two-factor login is on.")).toBeVisible();
  await page.getByRole("link", { name: "I saved my codes, continue" }).click();
  return secret;
}

/** The invitation link from the email the API wrote to its log. */
function invitationToken(email: string): string {
  const path = process.env.E2E_API_LOG;
  if (!path) throw new Error("Set E2E_API_LOG to the API's laravel.log");
  // Undo quoted-printable soft line breaks and escapes in the logged email.
  const log = readFileSync(path, "utf8").replace(/=\r?\n/g, "").replace(/=3D/g, "=");
  const start = log.lastIndexOf(`To: ${email}`);
  const match = /\/invite\/([A-Za-z0-9_-]{20,})/.exec(log.slice(start >= 0 ? start : 0));
  if (!match) throw new Error(`No invitation link for ${email} in the API log`);
  return match[1]!;
}

async function newPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext();
  return context.newPage();
}

test("compliance MVP: profile to cited analysis to approved, evidenced task", async ({ browser }) => {
  const a = await newPage(browser);
  const o = await newPage(browser);

  // 1. The administrator creates the organization and turns on two-factor login.
  await a.goto("/register");
  await a.getByLabel("Organization name").fill(`Lone Star Health Plan ${run}`);
  await a.getByLabel("Your name").fill(admin.name);
  await a.getByLabel("Work email").fill(admin.email);
  await a.getByLabel("Password", { exact: true }).fill(PASSWORD);
  await a.getByLabel("Confirm password").fill(PASSWORD);
  await a.getByRole("button", { name: "Create organization" }).click();
  await enrollTwoFactor(a);
  await expect(a).toHaveURL(/\/settings\/profile\?welcome=1/);

  // 2. ...and invites a compliance officer.
  await a.goto("/settings");
  await a.getByLabel("Email", { exact: true }).fill(officer.email);
  await a.getByLabel("Role", { exact: true }).selectOption({ label: "Compliance officer" });
  await a.getByRole("button", { name: "Send invitation" }).click();
  await expect(a.getByText(`Invitation sent to ${officer.email}.`)).toBeVisible();

  // 3. The officer accepts, signs in and enrolls two-factor login.
  await o.goto(`/invite/${invitationToken(officer.email)}`);
  await o.getByLabel("Your name").fill(officer.name);
  await o.getByLabel("Choose a password").fill(PASSWORD);
  await o.getByLabel("Confirm password").fill(PASSWORD);
  await o.getByRole("button", { name: "Accept invitation" }).click();
  await expect(o).toHaveURL(/\/login\?joined=1/);
  await o.getByLabel("Work email").fill(officer.email);
  await o.getByLabel("Password").fill(PASSWORD);
  await o.getByRole("button", { name: /sign in/i }).click();
  await enrollTwoFactor(o);

  // 4. The officer configures the regulatory profile.
  await o.goto("/settings/profile");
  await o.getByRole("checkbox", { name: "Medicare Advantage organization" }).check();
  await o.getByRole("checkbox", { name: "Medicare Advantage (Part C)" }).check();
  await o.getByRole("checkbox", { name: "Claims operations" }).check();
  await o.getByRole("checkbox", { name: /^TX\b/ }).check();
  await o.getByRole("button", { name: "Save profile" }).click();
  await expect(o.getByText("Regulatory profile saved.")).toBeVisible();

  // 5. ...opens a real Federal Register publication and runs the analysis.
  await o.goto("/regulations?q=Inpatient%20Psychiatric");
  await o.getByRole("link", { name: DOC_TITLE }).first().click();
  await expect(o.getByRole("heading", { level: 1 })).toContainText("Inpatient Psychiatric");
  const documentId = /\/regulations\/(\d+)/.exec(o.url())![1];
  await o.getByRole("button", { name: "Analyze impact for my organization" }).click();
  await expect(o.getByText("Test output, not a real analysis")).toBeVisible({ timeout: 60_000 });
  await expect(o.getByText("AI interpretation").first()).toBeVisible();
  await expect(o.getByText(ACTION).first()).toBeVisible();

  // 6. ...reviews the impact and approves the assessment with the action accepted.
  await o.getByRole("button", { name: "Start an assessment from this analysis" }).click();
  await o.getByText("Applies to us", { exact: true }).click();
  await o.getByLabel("Rationale").fill("We price inpatient psychiatric claims from Medicare rates under two contracts.");
  await o.getByRole("radiogroup", { name: `Decision on ${ACTION}` }).getByRole("radio", { name: "Accept" }).click();
  await o.getByRole("button", { name: "Save and approve" }).click();
  await expect(o.getByText(/Assessment approved\. 1 task created/)).toBeVisible();

  // 7. ...and assigns the task with an internal target date.
  await o.getByRole("link", { name: "Open the tasks created from these actions" }).click();
  await o.getByRole("link", { name: ACTION }).click();
  await o.getByRole("link", { name: "Edit or reassign" }).click();
  await o.getByLabel("Owner").selectOption({ label: `${admin.name} (Organization administrator)` });
  const due = new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10);
  await o.getByLabel("Date").fill(due);
  await o.getByRole("button", { name: "Save changes" }).click();
  await expect(o.getByText("Task saved.")).toBeVisible();
  const taskUrl = o.url().replace(/\?.*$/, "");

  // 8. The owner is notified, does the work and submits evidence.
  await a.goto("/notifications");
  await a.getByRole("button", { name: `Assigned to you: ${ACTION}` }).click();
  await expect(a).toHaveURL(/\/tasks\/\d+$/);
  await a.getByRole("button", { name: "Mark as started" }).click();
  await expect(a.getByText("Marked as started.")).toBeVisible();
  await a.getByLabel("File").setInputFiles({
    name: "ipf-claims-review.pdf",
    mimeType: "application/pdf",
    buffer: Buffer.from("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"),
  });
  await a.getByLabel("Note").fill("Checked 20 IPF claims against the corrected factor; no repricing needed.");
  await a.getByRole("button", { name: "Add evidence" }).click();
  await expect(a.getByText("Evidence added.")).toBeVisible();
  await expect(a.getByText("Not scanned for malware")).toBeVisible();

  const download = a.waitForEvent("download");
  await a.getByRole("link", { name: "ipf-claims-review.pdf" }).click();
  expect((await download).suggestedFilename()).toBe("ipf-claims-review.pdf");

  await a.getByRole("button", { name: "Submit for review" }).click();
  await expect(a.getByText("Submitted for review.")).toBeVisible();
  // The owner cannot accept their own work.
  await expect(a.getByRole("button", { name: "Accept as done" })).toHaveCount(0);

  // 9. The officer accepts it: tracked to completion with evidence.
  await o.goto(taskUrl);
  await o.getByRole("button", { name: "Accept as done" }).click();
  await expect(o.getByText("Accepted as done.")).toBeVisible();
  await expect(o.getByText("Done", { exact: true }).first()).toBeVisible();
  await expect(o.getByText("Accepted as done").last()).toBeVisible();

  // 10. The evidence pack ties it together and the audit log checks out.
  await o.goto(`/reports/evidence/${documentId}`);
  await expect(o.getByRole("heading", { name: "Evidence pack" })).toBeVisible();
  await expect(o.getByText(/The organization's audit log is intact/)).toBeVisible();
  await expect(o.getByText("ipf-claims-review.pdf")).toBeVisible();
  await o.goto("/reports/tasks");
  const csv = o.waitForEvent("download");
  await o.getByRole("link", { name: "Download CSV" }).click();
  expect((await csv).suggestedFilename()).toMatch(/^ruleradar-task-status-.*\.csv$/);

  // The calendar and overview render for the officer.
  await o.goto("/calendar");
  await expect(o.getByRole("heading", { name: "Regulatory calendar" })).toBeVisible();
  await o.goto("/");
  await expect(o.getByText(`Welcome, ${officer.name.split(" ")[0]}`)).toBeVisible();
});
