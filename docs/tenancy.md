# Tenancy

## Recordings know their workspace

When the plugin renders the recorder, it signs the current Filament tenant (`Filament::getTenant()`) into the recording's token, together with the person and the panel id. The ingest endpoint stores it as `tenant_type` and `tenant_id` on the recording. Nothing in the browser can change it, and the ingest request itself needs no tenant in its URL.

This works with Filament's built-in tenancy and with anything that sets the Filament tenant, [Packstub's Filament Tenancy](https://packstub.dev/docs/filament-tenancy) included.

## What each panel lists

| Panel | The Sessions resource and the stats widget show |
| --- | --- |
| A panel with tenancy (a current tenant) | The current workspace's recordings only |
| A panel without tenancy (an operator console) | Every recording, with a **Workspace** column (the tenant's `name`, or `#id`) |

### Naming workspaces

The Workspace column shows the tenant's `name`. When your tenant model calls it something else:

```php
use Packstub\SessionReplay\Models\ReplaySession;

SessionReplayPlugin::make()
    ->tenantLabelUsing(fn (ReplaySession $session): ?string => $session->tenant?->company_name);
```

Return null to fall back to `name`, then `#` and the key.

The resource does not use Filament's ownership relationship for this (recordings carry the workspace themselves), so your tenant model needs no `sessionReplays` relation.

To list every workspace's recordings inside a panel that does have tenancy:

```php
SessionReplayPlugin::make()->scopeToTenant(false);
```

Reserve that for panels only your own team can open. The `viewSessionReplay` gate receives the recording, so the rule can also live there:

```php
use Illuminate\Support\Facades\Gate;
use Packstub\SessionReplay\Models\ReplaySession;

Gate::define('viewSessionReplay', function ($user, ?ReplaySession $session = null): bool {
    if ($user->is_operator) {
        return true;
    }

    // The list: anyone who administers a workspace. The resource already lists the current workspace only.
    if ($session === null) {
        return $user->administeredTeams()->exists();
    }

    // One recording, and every file behind it: the workspace comes from the recording.
    return $session->tenant !== null && $user->isAdminOf($session->tenant);
});
```

`administeredTeams()` and `isAdminOf()` stand for your app's own checks. Take the workspace from the recording, as above, not from `Filament::getTenant()`: the player loads its data from the core's routes, which sit outside the panel, where there is no current tenant.

The usual split is described in [Installation](installation.md#record-in-one-panel-watch-in-another): record the customer panel with `->resource(false)`, watch in the operator panel with `->record(false)`.

## Database-per-tenant apps

When every workspace has its own database, keep the four replay tables and the recording disk **central**: operators see every workspace in one list, the ingest endpoint needs no tenant context, and no tenant database grows with recordings.

```dotenv
SESSION_REPLAY_DB_CONNECTION=central
```

```php
// config/session-replay.php
'run_migrations' => false,
```

```bash
php artisan vendor:publish --tag=session-replay-migrations
```

Then run the published migrations where your central migrations live. The models and the migrations both follow `storage.connection`. The core's [Storage guide](https://packstub.dev/docs/session-replay/storage#multi-tenant-apps) has the details.

The Workspace column and the `tenant` relation resolve the tenant model through its morph class, so the tenant model itself should live on the central connection too, which is where workspace records normally are.

## Impersonation

A recording made while someone is impersonating a customer is flagged with the impersonator's key: "Impersonated by #7" under the person in the list, an **Impersonated by** fact on the watch page, and the **Impersonated** filter to find or exclude them.

With [Packstub's Account Switcher](https://packstub.dev/docs/filament-account-switcher) installed this is automatic: the plugin asks `Packstub\AccountSwitcher\AccountSwitcher` whether someone is impersonating and takes that person's key.

With another impersonation package, tell the plugin where to look:

```php
SessionReplayPlugin::make()
    ->impersonatorUsing(fn (): int|string|null => session('impersonated_by'));
```

Return null when nobody is impersonating.

To leave support sessions out of recordings altogether, use `record()`:

```php
SessionReplayPlugin::make()
    ->record(fn ($user): bool => ! session()->has('impersonated_by'));
```
