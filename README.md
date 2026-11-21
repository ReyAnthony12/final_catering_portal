# Catering Services Portal

Source snapshot for the Catering Services Portal.

## Local setup

1. Create a MySQL database and import a schema-only SQL export from a trusted local backup.
2. Copy `.env.example` to `.env` and set the local database and SMTP credentials.
3. Serve this folder with PHP and MySQL enabled.

Never commit `.env`, database backups containing user records, or private uploads.
