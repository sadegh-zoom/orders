# WordPress & WooCommerce Selective Role + User Migrator

Version 3.0.0

Install the same plugin on both the source and destination sites.

## New in v3

The export page lists every registered role with a checkbox.

You can:
- Select All
- Select None
- Select only the exact roles you want to migrate

## What gets exported

For the selected roles only:
- Role definitions
- Full capabilities
- Users who have at least one selected role
- Only the selected role assignments for those users
- User profile fields
- Password hashes
- User metadata, including common WooCommerce customer billing/shipping metadata

Example:
If a user has `customer`, `vip_customer`, and `subscriber`, and you select only `customer` + `vip_customer`, that user is exported with those two roles only.

## Import behavior

- Selected role definitions are created/updated on destination.
- Users are matched by email first, then username.
- New users keep their source password hash.
- Existing user passwords change only if explicitly enabled.
- For existing users, only the roles contained in the import are synchronized.
- Unrelated roles already present on the destination user are preserved.
- The currently logged-in administrator can be protected.
- Session tokens and WordPress Application Passwords are excluded.

## Security

The JSON includes personal user data and password hashes. Keep it private and delete it after migration.
