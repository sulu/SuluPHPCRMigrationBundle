# Upgrade

## 1.0.3

### Snippet area permissions of already migrated installs

Installs migrated with 1.0.0 to 1.0.2 keep the `sulu.webspaces.{webspace}.default-snippets`
permission context, so roles still get a 403 on the snippet areas of a webspace. They also
hold `sulu.snippet.snippet_areas` rows, which Sulu 3.0.2 no longer uses. Do not re-run the
migration for this, it overwrites the migrated content.

Rename the context directly in the database:

```sql
UPDATE se_permissions
SET context = REPLACE(context, '.default-snippets', '.snippet-areas')
WHERE context LIKE 'sulu.webspaces.%.default-snippets'
AND NOT EXISTS (
    SELECT 1 FROM (SELECT context, idRoles FROM se_permissions) AS existing
    WHERE existing.context = REPLACE(se_permissions.context, '.default-snippets', '.snippet-areas')
    AND existing.idRoles = se_permissions.idRoles
);

DELETE FROM se_permissions WHERE context LIKE 'sulu.webspaces.%.default-snippets';
```

Then move the `sulu.snippet.snippet_areas` rows to the webspaces with the SQL of the
`3.0.2` section in the `UPGRADE-3.x.md` of `sulu/sulu`.

## 0.x

### Added dependency on `sulu/sulu`

The bundle now requires `sulu/sulu` to resolve Sulu Admin form metadata. This
metadata is used to detect `text_line` and `text_area` fields so their values
are not JSON-decoded during migration.

Make sure `sulu/sulu` is installed and the `sulu_admin.form_metadata_provider`
service together with the `sulu_admin.templates.configuration` parameter are
available in the container.
