# Deploying RuleRadar

RuleRadar is two applications:

| Part | Code | What it needs | Where it runs |
| --- | --- | --- | --- |
| Web app (Next.js) | `apps/web` | Node.js 22 | Vercel, or Azure Container Apps |
| API (Laravel) | `apps/api` | PHP 8.3, PostgreSQL 16 with pgvector, Redis, private file storage, a queue worker and a scheduler | Azure Container Apps (this guide) or any container host |

The web app never talks to the database. Its server calls the API at the address in
`API_URL`, so **the web app does nothing useful until the API is deployed and `API_URL` points
to it.** Vercel hosts only the web app: it can't run the Laravel API, its background worker or
its scheduler.

If `API_URL` is missing or wrong, the web app shows "RuleRadar's web app is not connected to
its API" (missing or invalid setting) or "RuleRadar could not reach its server" (unreachable).
The reason is also written to the Vercel function logs as a line starting with `[ruleradar]`.

## 1. Deploy the API to Azure (one-time setup, then one click per release)

You need an Azure subscription and permission to create resources in it.

### 1a. Create a resource group and let GitHub deploy to it

Run in [Azure Cloud Shell](https://shell.azure.com) (Bash). Replace the region if you prefer.

```bash
RG=ruleradar-dev
REGION=centralus
REPO=kdt877kt4s-wq/Reg-tool
SUB=$(az account show --query id -o tsv)
TENANT=$(az account show --query tenantId -o tsv)

for p in Microsoft.App Microsoft.OperationalInsights Microsoft.DBforPostgreSQL Microsoft.Cache \
         Microsoft.KeyVault Microsoft.ContainerRegistry Microsoft.EventGrid Microsoft.Security \
         Microsoft.ManagedIdentity Microsoft.Storage; do
  az provider register -n $p
done

az group create -n $RG -l $REGION

# An identity GitHub signs in as (OpenID Connect: no password is stored anywhere).
APP_ID=$(az ad app create --display-name ruleradar-github-deploy --query appId -o tsv)
az ad sp create --id $APP_ID
az ad app federated-credential create --id $APP_ID --parameters "{
  \"name\": \"github-dev\",
  \"issuer\": \"https://token.actions.githubusercontent.com\",
  \"subject\": \"repo:$REPO:environment:dev\",
  \"audiences\": [\"api://AzureADTokenExchange\"]
}"
# Owner of this resource group only: the deployment creates role assignments
# (pull images, read Key Vault secrets) for the apps' managed identity.
az role assignment create --assignee $APP_ID --role Owner --scope /subscriptions/$SUB/resourceGroups/$RG

echo "AZURE_CLIENT_ID=$APP_ID  AZURE_TENANT_ID=$TENANT  AZURE_SUBSCRIPTION_ID=$SUB"
```

### 1b. Add the settings to GitHub

In GitHub, open the repository, then **Settings → Environments → New environment** and name it
`dev`. In that environment, add the following.

**Secrets:**

| Name | Value |
| --- | --- |
| `AZURE_CLIENT_ID`, `AZURE_TENANT_ID`, `AZURE_SUBSCRIPTION_ID` | Printed at the end of step 1a |
| `POSTGRES_ADMIN_PASSWORD` | A new strong password (database administrator) |
| `POSTGRES_APP_PASSWORD` | A different strong password (the restricted account the API uses) |
| `APP_KEY` | Run `echo "base64:$(openssl rand -base64 32)"` and paste the output |
| `MALWARE_WEBHOOK_SECRET` | Run `openssl rand -hex 32` and paste the output |
| `ANTHROPIC_API_KEY` | Optional. Without it, AI analysis shows as not set up |
| `VOYAGE_API_KEY` | Optional. Semantic passage retrieval |
| `SMTP_PASSWORD` | Optional. See email below |

**Variables:**

| Name | Value |
| --- | --- |
| `AZURE_RESOURCE_GROUP` | `ruleradar-dev` |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `MAIL_FROM_ADDRESS` | Optional. See email below |

Generate passwords with `openssl rand -base64 24` and store them only in GitHub. Never paste them into chat or commit them.

**Email.** Registering, signing in and setting up two-factor login don't need email.
Invitations, password resets and notifications do. Without SMTP settings, emails are written to
the API log instead of being sent. Any SMTP provider works, such as Azure Communication
Services Email or SendGrid.

### 1c. Run the deployment

In GitHub, open **Actions → Deploy API to Azure → Run workflow**. Choose `dev`, enter your Vercel
address (for example `https://ruleradar.vercel.app`) as the web app URL, and leave "Also host the
web app on Azure" off.

The workflow:

1. creates PostgreSQL, Redis, Blob storage with Microsoft Defender malware scanning, Key Vault,
   a container registry and logging;
2. builds the API image in Azure;
3. creates the database account `ruleradar_app`. It owns the tables but can't bypass row-level
   security, so each organization's data stays isolated;
4. starts the API, its queue worker and its scheduler;
5. runs the database migrations;
6. connects Defender's scan results to the API.

When it finishes, the run summary shows **API deployed: `https://rrdev-api.<...>.azurecontainerapps.io`**.
Copy that address. You can check it in a browser: `<address>/api/v1/health` should show
`"status": "ok"`.

The first run can fail while Azure finishes applying the new permissions for pulling images and
reading secrets. If a step reports an authorization or image-pull error, run the workflow again.

Every resource in the resource group is billed by Microsoft: PostgreSQL, Redis, Container Apps,
Defender for Storage malware scanning (charged per GB scanned), the registry and logs. Check
pricing for your region before choosing `prod`.

## 2. Point Vercel at the API

1. In Vercel, open the project, then **Settings → Environment Variables**.
2. Add **`API_URL`** with the address from step 1c (`https://...azurecontainerapps.io`, no
   trailing `/api/v1`). Select the Production environment, and Preview if you use it.
3. Check **Settings → General → Root Directory** is `apps/web`.
4. Open **Deployments**, then **⋯ → Redeploy** on the latest one. Environment variables only
   apply to new deployments.

`API_URL` is read only on the server. It isn't a secret, but it must not be renamed to
`NEXT_PUBLIC_API_URL`: the browser never calls the API directly. No API keys or database
settings belong in Vercel.

## 3. Create your organization

1. Open your Vercel address and choose **Create an organization**.
2. Enter the organization, your name, work email and a password of at least 12 characters.
3. Set up two-factor login with an authenticator app. It's required for administrators and
   compliance officers. Save the recovery codes.
4. Complete the regulatory profile. The dashboard opens.

To load Federal Register publications straight away instead of waiting for the next scheduled
poll (weekdays 06:30 and 12:30 Central), run this in Cloud Shell:

```bash
az containerapp exec -g ruleradar-dev -n rrdev-api --command "php artisan ruleradar:poll federal_register --days=30"
```

## Other container hosts

The API image (`apps/api/Dockerfile`) runs anywhere containers run. Start it with one of these
arguments:

- `web` (HTTP on port 8080);
- `worker`;
- `scheduler`;
- `migrate` (run once per release).

The host must provide:

- PostgreSQL 16 with the `vector` and `pg_trgm` extensions. Run `infra/postgres-bootstrap.sql`
  as an administrator. The API's own account must not be a superuser and must not have
  BYPASSRLS, or tenant isolation is lost.
- Redis (or set `CACHE_STORE=database`/`QUEUE_CONNECTION=database` after adding Laravel's cache
  and jobs tables).
- Private storage for evidence.
- HTTPS.
- The environment variables listed in `infra/main.bicep` (`apiEnv`).
