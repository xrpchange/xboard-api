# Scripts Directory

This directory contains useful utility scripts for the XBoard API project.

## Email Server Scripts

### `email-server/`
Scripts for setting up and managing the email server (Postfix, Dovecot, OpenDKIM).

## Notes

- All scripts should be run from the project root directory
- Scripts use Laravel application bootstrap for configuration access
- For temporary test scripts, see `temp/` directory

## Local development

### `pull-server-env-xboard-api.ps1`

Download production Laravel `.env` from `server@xdexboard.com:/var/www/xboard-api/.env` for local XAMPP smoke tests alongside **xboard-client** web (`developmentExecutable/` on this PC).

```powershell
.\scripts\pull-server-env-xboard-api.ps1          # writes .env.server (gitignored)
.\scripts\pull-server-env-xboard-api.ps1 -Apply   # backs up local .env, applies server copy
```

After `-Apply`, review **`DB_*`** — use local MySQL unless you intentionally point at production DB. Never commit `.env` / `.env.server`.

See also **xboard-client** `docs/WEB_CLIENT_DEPLOY.md` § *Local API `.env` from production*.

