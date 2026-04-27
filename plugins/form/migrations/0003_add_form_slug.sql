-- Optional human-readable slug per form. Themes reference forms by slug
-- (e.g. theme.json declares `{"component":"form","params":{"slug":"newsletter"}}`),
-- so the slot keeps working when the underlying form id changes.
ALTER TABLE plugin_form_forms ADD COLUMN slug VARCHAR(120) NULL;
