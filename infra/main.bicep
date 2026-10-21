// RuleRadar infrastructure for one environment (dev, staging, prod).
//
// Two passes (see .github/workflows/deploy-azure.yml and docs/deploy.md):
//   1. deployApps=false: data and platform layer (Postgres, Redis, Blob
//      storage with Defender malware scanning, Key Vault, registry, logs).
//   2. deployApps=true, after images are pushed: the API, its queue worker,
//      scheduler and migration job on Container Apps (and optionally the
//      web app, when it is not hosted on Vercel).

targetScope = 'resourceGroup'

@allowed(['dev', 'staging', 'prod'])
param environmentName string = 'dev'

param location string = resourceGroup().location

@description('PostgreSQL administrator login.')
param postgresAdminLogin string = 'ruleradaradmin'

@secure()
@description('PostgreSQL administrator password. Supply from a pipeline secret; never commit it.')
param postgresAdminPassword string

@description('Second pass: create the Container Apps (images must already be in the registry).')
param deployApps bool = false

@description('Image tag to run, e.g. the Git commit SHA.')
param imageTag string = 'latest'

@description('Also run the web app here. Leave false when the web app is on Vercel.')
param deployWeb bool = false

@description('Public URL of the web app (Vercel or custom domain). Links in emails point here.')
param frontendUrl string

@secure()
@description('Password for the ruleradar_app database role (created by infra/postgres-bootstrap.sql).')
param postgresAppPassword string

@secure()
@description('Laravel APP_KEY ("base64:..."). Generate once with: php artisan key:generate --show')
param appKey string

@secure()
@description('Shared secret in the Event Grid webhook URL for malware scan results.')
param malwareWebhookSecret string

@secure()
@description('Optional. Without it, AI analysis shows as not set up.')
param anthropicApiKey string = ''

@secure()
@description('Optional. Adds semantic passage retrieval.')
param voyageApiKey string = ''

@description('Turn on Microsoft Defender for Storage malware scanning of evidence (billed by Microsoft).')
param enableMalwareScanning bool = true

@description('Create the Event Grid subscription that sends scan results to the API. Needs the API running (third pass).')
param enableScanResultsWebhook bool = false

@description('SMTP for invitations, password resets and notifications. Empty: emails are only logged.')
param smtpHost string = ''
param smtpPort int = 587
param smtpUsername string = ''
@secure()
param smtpPassword string = ''
param mailFromAddress string = 'no-reply@example.com'

var prefix = 'rr${environmentName}'
var suffix = uniqueString(resourceGroup().id)
var tags = {
  app: 'ruleradar'
  environment: environmentName
}

resource logs 'Microsoft.OperationalInsights/workspaces@2022-10-01' = {
  name: '${prefix}-logs-${suffix}'
  location: location
  tags: tags
  properties: {
    sku: { name: 'PerGB2018' }
    retentionInDays: 30
  }
}

resource containerEnv 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: '${prefix}-cae-${suffix}'
  location: location
  tags: tags
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: logs.properties.customerId
        sharedKey: logs.listKeys().primarySharedKey
      }
    }
  }
}

resource registry 'Microsoft.ContainerRegistry/registries@2023-07-01' = {
  name: '${prefix}acr${suffix}'
  location: location
  tags: tags
  sku: { name: 'Basic' }
  properties: {
    adminUserEnabled: false
  }
}

resource postgres 'Microsoft.DBforPostgreSQL/flexibleServers@2022-12-01' = {
  name: '${prefix}-pg-${suffix}'
  location: location
  tags: tags
  sku: {
    name: environmentName == 'prod' ? 'Standard_D2ds_v4' : 'Standard_B1ms'
    tier: environmentName == 'prod' ? 'GeneralPurpose' : 'Burstable'
  }
  properties: {
    version: '16'
    administratorLogin: postgresAdminLogin
    administratorLoginPassword: postgresAdminPassword
    storage: { storageSizeGB: 32 }
    backup: {
      backupRetentionDays: environmentName == 'prod' ? 35 : 7
      geoRedundantBackup: 'Disabled'
    }
    highAvailability: { mode: 'Disabled' }
  }
}

// Allow-list the extensions the API migrations create.
resource postgresExtensions 'Microsoft.DBforPostgreSQL/flexibleServers/configurations@2022-12-01' = {
  parent: postgres
  name: 'azure.extensions'
  properties: {
    value: 'VECTOR,PG_TRGM'
    source: 'user-override'
  }
}

resource postgresDb 'Microsoft.DBforPostgreSQL/flexibleServers/databases@2022-12-01' = {
  parent: postgres
  name: 'ruleradar'
}

resource redis 'Microsoft.Cache/redis@2023-08-01' = {
  name: '${prefix}-redis-${suffix}'
  location: location
  tags: tags
  properties: {
    sku: { name: 'Basic', family: 'C', capacity: 0 }
    enableNonSslPort: false
    minimumTlsVersion: '1.2'
  }
}

resource storage 'Microsoft.Storage/storageAccounts@2023-01-01' = {
  name: '${prefix}st${suffix}'
  location: location
  tags: tags
  kind: 'StorageV2'
  sku: { name: 'Standard_ZRS' }
  properties: {
    minimumTlsVersion: 'TLS1_2'
    allowBlobPublicAccess: false
    supportsHttpsTrafficOnly: true
    encryption: {
      services: {
        blob: { enabled: true }
      }
      keySource: 'Microsoft.Storage'
    }
  }
}

resource blobService 'Microsoft.Storage/storageAccounts/blobServices@2023-01-01' = {
  parent: storage
  name: 'default'
  properties: {
    deleteRetentionPolicy: { enabled: true, days: 30 }
  }
}

// Private containers: source documents, tenant evidence, audit anchors.
resource containers 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-01-01' = [for name in ['regulatory-sources', 'tenant-files', 'audit-anchors']: {
  parent: blobService
  name: name
  properties: { publicAccess: 'None' }
}]

resource vault 'Microsoft.KeyVault/vaults@2023-07-01' = {
  name: '${prefix}-kv-${take(suffix, 8)}'
  location: location
  tags: tags
  properties: {
    tenantId: subscription().tenantId
    sku: { family: 'A', name: 'standard' }
    enableRbacAuthorization: true
    enableSoftDelete: true
    softDeleteRetentionInDays: 90
    enablePurgeProtection: true
  }
}


// Container Apps and the deploy runner reach Postgres over its public
// endpoint with TLS; "Allow Azure services" admits Azure-hosted callers only.
// Private networking (VNet integration) is the next hardening step.
resource postgresAzureServices 'Microsoft.DBforPostgreSQL/flexibleServers/firewallRules@2022-12-01' = {
  parent: postgres
  name: 'AllowAzureServices'
  properties: { startIpAddress: '0.0.0.0', endIpAddress: '0.0.0.0' }
}

// ---------- Malware scanning of evidence (Defender for Storage) ----------

resource scanResultsTopic 'Microsoft.EventGrid/topics@2022-06-15' = if (enableMalwareScanning) {
  name: '${prefix}-scan-${suffix}'
  location: location
  tags: tags
  properties: {
    inputSchema: 'EventGridSchema'
    publicNetworkAccess: 'Enabled'
  }
}

resource defenderForStorage 'Microsoft.Security/defenderForStorageSettings@2022-12-01-preview' = if (enableMalwareScanning) {
  name: 'current'
  scope: storage
  properties: {
    isEnabled: true
    overrideSubscriptionLevelSettings: true
    malwareScanning: {
      onUpload: { isEnabled: true, capGBPerMonth: 100 }
      scanResultsEventGridTopicResourceId: scanResultsTopic.id
    }
    sensitiveDataDiscovery: { isEnabled: false }
  }
}

// ---------- Secrets (Key Vault) ----------

// One resource per secret: values from list*() cannot drive a loop.
resource secretAppKey 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: vault
  name: 'app-key'
  properties: { value: appKey }
}

resource secretPostgresApp 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: vault
  name: 'postgres-app-password'
  properties: { value: postgresAppPassword }
}

resource secretRedis 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: vault
  name: 'redis-password'
  properties: { value: redis.listKeys().primaryKey }
}

resource secretStorage 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: vault
  name: 'storage-connection-string'
  properties: { value: 'DefaultEndpointsProtocol=https;AccountName=${storage.name};AccountKey=${storage.listKeys().keys[0].value};EndpointSuffix=${environment().suffixes.storage}' }
}

resource secretWebhook 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = {
  parent: vault
  name: 'malware-webhook-secret'
  properties: { value: malwareWebhookSecret }
}

resource secretAnthropic 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = if (!empty(anthropicApiKey)) {
  parent: vault
  name: 'anthropic-api-key'
  properties: { value: anthropicApiKey }
}

resource secretVoyage 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = if (!empty(voyageApiKey)) {
  parent: vault
  name: 'voyage-api-key'
  properties: { value: voyageApiKey }
}

resource secretSmtp 'Microsoft.KeyVault/vaults/secrets@2023-07-01' = if (!empty(smtpPassword)) {
  parent: vault
  name: 'smtp-password'
  properties: { value: smtpPassword }
}

// ---------- Identity for the apps: pull images, read secrets ----------

resource appIdentity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: '${prefix}-apps-${suffix}'
  location: location
  tags: tags
}

resource acrPull 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(registry.id, appIdentity.id, 'acrpull')
  scope: registry
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '7f951dda-4ed3-4680-a7ca-43fe172d538d')
    principalId: appIdentity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

resource kvSecretsUser 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(vault.id, appIdentity.id, 'kvsecretsuser')
  scope: vault
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '4633458b-17de-408a-b874-0445c86b69e6')
    principalId: appIdentity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

// ---------- Container Apps ----------

var apiImage = '${registry.properties.loginServer}/ruleradar-api:${imageTag}'
var apiHost = '${prefix}-api.${containerEnv.properties.defaultDomain}'

var secretRefs = concat([
  { name: 'app-key', keyVaultUrl: '${vault.properties.vaultUri}secrets/app-key', identity: appIdentity.id }
  { name: 'postgres-app-password', keyVaultUrl: '${vault.properties.vaultUri}secrets/postgres-app-password', identity: appIdentity.id }
  { name: 'redis-password', keyVaultUrl: '${vault.properties.vaultUri}secrets/redis-password', identity: appIdentity.id }
  { name: 'storage-connection-string', keyVaultUrl: '${vault.properties.vaultUri}secrets/storage-connection-string', identity: appIdentity.id }
  { name: 'malware-webhook-secret', keyVaultUrl: '${vault.properties.vaultUri}secrets/malware-webhook-secret', identity: appIdentity.id }
], empty(anthropicApiKey) ? [] : [
  { name: 'anthropic-api-key', keyVaultUrl: '${vault.properties.vaultUri}secrets/anthropic-api-key', identity: appIdentity.id }
], empty(voyageApiKey) ? [] : [
  { name: 'voyage-api-key', keyVaultUrl: '${vault.properties.vaultUri}secrets/voyage-api-key', identity: appIdentity.id }
], empty(smtpPassword) ? [] : [
  { name: 'smtp-password', keyVaultUrl: '${vault.properties.vaultUri}secrets/smtp-password', identity: appIdentity.id }
])

var apiEnv = concat([
  { name: 'APP_ENV', value: 'production' }
  { name: 'APP_DEBUG', value: 'false' }
  { name: 'APP_KEY', secretRef: 'app-key' }
  { name: 'APP_URL', value: 'https://${apiHost}' }
  { name: 'FRONTEND_URL', value: frontendUrl }
  { name: 'LOG_CHANNEL', value: 'stderr' }
  { name: 'TRUSTED_PROXIES', value: '*' }
  { name: 'DB_CONNECTION', value: 'pgsql' }
  { name: 'DB_HOST', value: postgres.properties.fullyQualifiedDomainName }
  { name: 'DB_PORT', value: '5432' }
  { name: 'DB_DATABASE', value: 'ruleradar' }
  { name: 'DB_USERNAME', value: 'ruleradar_app' }
  { name: 'DB_PASSWORD', secretRef: 'postgres-app-password' }
  { name: 'DB_SSLMODE', value: 'require' }
  { name: 'REDIS_CLIENT', value: 'phpredis' }
  { name: 'REDIS_HOST', value: 'tls://${redis.properties.hostName}' }
  { name: 'REDIS_PORT', value: '6380' }
  { name: 'REDIS_PASSWORD', secretRef: 'redis-password' }
  { name: 'CACHE_STORE', value: 'redis' }
  { name: 'QUEUE_CONNECTION', value: 'redis' }
  { name: 'SESSION_DRIVER', value: 'array' }
  { name: 'EVIDENCE_DISK', value: 'evidence_azure' }
  { name: 'AZURE_STORAGE_CONNECTION_STRING', secretRef: 'storage-connection-string' }
  { name: 'AZURE_STORAGE_CONTAINER', value: 'tenant-files' }
  { name: 'EVIDENCE_REQUIRE_SCAN', value: enableMalwareScanning ? 'true' : 'false' }
  { name: 'MALWARE_SCAN_WEBHOOK_SECRET', secretRef: 'malware-webhook-secret' }
  { name: 'MAIL_MAILER', value: empty(smtpHost) ? 'log' : 'smtp' }
  { name: 'MAIL_HOST', value: smtpHost }
  { name: 'MAIL_PORT', value: string(smtpPort) }
  { name: 'MAIL_USERNAME', value: smtpUsername }
  { name: 'MAIL_FROM_ADDRESS', value: mailFromAddress }
], empty(anthropicApiKey) ? [] : [{ name: 'ANTHROPIC_API_KEY', secretRef: 'anthropic-api-key' }],
   empty(voyageApiKey) ? [] : [{ name: 'VOYAGE_API_KEY', secretRef: 'voyage-api-key' }],
   empty(smtpPassword) ? [] : [{ name: 'MAIL_PASSWORD', secretRef: 'smtp-password' }])

var registries = [{ server: registry.properties.loginServer, identity: appIdentity.id }]
var identity = { type: 'UserAssigned', userAssignedIdentities: { '${appIdentity.id}': {} } }

resource api 'Microsoft.App/containerApps@2024-03-01' = if (deployApps) {
  name: '${prefix}-api'
  location: location
  tags: tags
  identity: identity
  dependsOn: [acrPull, kvSecretsUser, secretAppKey, secretPostgresApp, secretRedis, secretStorage, secretWebhook, secretAnthropic, secretVoyage, secretSmtp]
  properties: {
    managedEnvironmentId: containerEnv.id
    configuration: {
      ingress: { external: true, targetPort: 8080, transport: 'auto', allowInsecure: false }
      registries: registries
      secrets: secretRefs
    }
    template: {
      containers: [{
        name: 'api'
        image: apiImage
        args: ['web']
        env: apiEnv
        resources: { cpu: json('0.5'), memory: '1Gi' }
        probes: [
          { type: 'Liveness', httpGet: { path: '/up', port: 8080 }, periodSeconds: 30 }
          { type: 'Readiness', httpGet: { path: '/api/v1/health', port: 8080 }, periodSeconds: 15 }
        ]
      }]
      scale: { minReplicas: 1, maxReplicas: environmentName == 'prod' ? 4 : 2 }
    }
  }
}

resource worker 'Microsoft.App/containerApps@2024-03-01' = if (deployApps) {
  name: '${prefix}-worker'
  location: location
  tags: tags
  identity: identity
  dependsOn: [acrPull, kvSecretsUser, secretAppKey, secretPostgresApp, secretRedis, secretStorage, secretWebhook, secretAnthropic, secretVoyage, secretSmtp]
  properties: {
    managedEnvironmentId: containerEnv.id
    configuration: { registries: registries, secrets: secretRefs }
    template: {
      containers: [{ name: 'worker', image: apiImage, args: ['worker'], env: apiEnv, resources: { cpu: json('0.5'), memory: '1Gi' } }]
      scale: { minReplicas: 1, maxReplicas: 2 }
    }
  }
}

resource scheduler 'Microsoft.App/containerApps@2024-03-01' = if (deployApps) {
  name: '${prefix}-scheduler'
  location: location
  tags: tags
  identity: identity
  dependsOn: [acrPull, kvSecretsUser, secretAppKey, secretPostgresApp, secretRedis, secretStorage, secretWebhook, secretAnthropic, secretVoyage, secretSmtp]
  properties: {
    managedEnvironmentId: containerEnv.id
    configuration: { registries: registries, secrets: secretRefs }
    template: {
      containers: [{ name: 'scheduler', image: apiImage, args: ['scheduler'], env: apiEnv, resources: { cpu: json('0.25'), memory: '0.5Gi' } }]
      // Exactly one scheduler, so each job runs once.
      scale: { minReplicas: 1, maxReplicas: 1 }
    }
  }
}

resource migrateJob 'Microsoft.App/jobs@2024-03-01' = if (deployApps) {
  name: '${prefix}-migrate'
  location: location
  tags: tags
  identity: identity
  dependsOn: [acrPull, kvSecretsUser, secretAppKey, secretPostgresApp, secretRedis, secretStorage, secretWebhook, secretAnthropic, secretVoyage, secretSmtp]
  properties: {
    environmentId: containerEnv.id
    configuration: {
      triggerType: 'Manual'
      replicaTimeout: 900
      replicaRetryLimit: 0
      manualTriggerConfig: { parallelism: 1, replicaCompletionCount: 1 }
      registries: registries
      secrets: secretRefs
    }
    template: {
      containers: [{ name: 'migrate', image: apiImage, args: ['migrate'], env: apiEnv, resources: { cpu: json('0.5'), memory: '1Gi' } }]
    }
  }
}

resource web 'Microsoft.App/containerApps@2024-03-01' = if (deployApps && deployWeb) {
  name: '${prefix}-web'
  location: location
  tags: tags
  identity: identity
  dependsOn: [acrPull]
  properties: {
    managedEnvironmentId: containerEnv.id
    configuration: {
      ingress: { external: true, targetPort: 3000, transport: 'auto', allowInsecure: false }
      registries: registries
    }
    template: {
      containers: [{
        name: 'web'
        image: '${registry.properties.loginServer}/ruleradar-web:${imageTag}'
        env: [{ name: 'API_URL', value: 'https://${apiHost}' }]
        resources: { cpu: json('0.5'), memory: '1Gi' }
      }]
      scale: { minReplicas: 1, maxReplicas: 3 }
    }
  }
}

resource scanResultsSubscription 'Microsoft.EventGrid/topics/eventSubscriptions@2022-06-15' = if (enableMalwareScanning && enableScanResultsWebhook && deployApps) {
  parent: scanResultsTopic
  name: 'to-ruleradar-api'
  properties: {
    destination: {
      endpointType: 'WebHook'
      properties: { endpointUrl: 'https://${apiHost}/api/v1/webhooks/malware-scan?key=${malwareWebhookSecret}' }
    }
    filter: { includedEventTypes: ['Microsoft.Security.MalwareScanningResult'] }
    eventDeliverySchema: 'EventGridSchema'
  }
}

output containerEnvironmentId string = containerEnv.id
output registryLoginServer string = registry.properties.loginServer
output postgresHost string = postgres.properties.fullyQualifiedDomainName
output redisHost string = redis.properties.hostName
output storageAccountName string = storage.name
output keyVaultName string = vault.name
output apiUrl string = 'https://${apiHost}'
output postgresServerName string = postgres.name
output migrateJobName string = '${prefix}-migrate'
