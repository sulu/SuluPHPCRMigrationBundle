# Upgrade

## 0.x

### Added dependency on `sulu/sulu`

The bundle now requires `sulu/sulu` to resolve Sulu Admin form metadata. This
metadata is used to detect `text_line` and `text_area` fields so their values
are not JSON-decoded during migration.

Make sure `sulu/sulu` is installed and the `sulu_admin.form_metadata_provider`
service together with the `sulu_admin.templates.configuration` parameter are
available in the container.
