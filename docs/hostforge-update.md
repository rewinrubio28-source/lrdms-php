# Updating the existing HostForge deployment

Pushing main triggers the configured redeploy. Git does not transfer the local MySQL database, uploaded records, backups, or `.env` values.

Before serving the updated app, take a server database/storage backup and run this from the application's server terminal (not the local XAMPP terminal):

```sh
cd /var/www/html
php database/upgrade.php
```

Wait for `UPGRADE COMPLETE`. If it fails, resolve the reported cause before continuing. Active requests may prevent the maintenance lock; retry in a quiet period. The runner applies only the seven migrations below, without demo seeds, and checks the required tables/columns. Re-running preserves existing record review outcomes. The Add User form also depends on organization tables; a missing table previously stopped the response before Bootstrap could load.

Individual migration commands, in dependency order:

```sh
php database/migrate_revision_v5.php
php database/migrate_organization_permissions.php
php database/migrate_action_permissions.php
php database/migrate_council_term.php
php database/migrate_profile_photos.php
php database/migrate_record_followups.php
php database/migrate_retrieval.php
```

These assume the pre-existing application schema/migrations are already installed. Migration execution is separate from the image build and is not automatic. New action permissions must be explicitly assigned to the intended roles in User Management; review Register Record and Manage Visibility before testing intake.

Keep the deployed DB, email, API, and storage environment variables configured in HostForge. No local secrets are committed. Existing local files require persistent storage mounted at `uploads/`; otherwise container replacement loses them. Remote storage requires its existing bucket configuration. The local-file backup tool does not back up remote bucket objects.

The Apache image must honor `.htaccess`: uploads route through authorization, while backups, runtime locks, and database scripts deny HTTP access. Database scripts remain available through the server terminal. `.runtime/` must be writable by the PHP user for the maintenance lock.

After deployment and migrations, verify login, incoming records, registration permissions, repository/search, document preview, version history/live search, and audit export. Confirm direct backup/database URLs return 403. Confirm the deployment is running the pushed commit in HostForge's deployment logs. A successful Git push alone does not establish that deployment succeeded.
