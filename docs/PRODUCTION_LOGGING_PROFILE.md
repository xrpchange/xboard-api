# Production Logging Profile (Minimal)

This profile keeps production logs concise while preserving operationally critical signals.

## Goals

- Keep only actionable problems in routine logs.
- Avoid high-volume debug/info noise in production.
- Preserve enough context to troubleshoot failures without full debug mode.

## Baseline `.env` for production

```dotenv
LOG_CHANNEL=daily
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DEPRECATIONS_CHANNEL=null
LOG_DAILY_DAYS=14
```

Notes:
- `LOG_LEVEL=warning` suppresses `debug` and `info` entries (including optional trace-style logs).
- Use `LOG_LEVEL=error` for an even stricter profile if warnings are still too noisy.

## What remains visible with `LOG_LEVEL=warning`

- `Log::warning(...)`
- `Log::error(...)`
- `Log::critical(...)`
- uncaught exceptions and framework errors

This means only problem-oriented events stay in production logs by default.

## Logging policy by severity (team convention)

- **debug**: local/dev diagnostics only, temporary tracing, payload previews.
- **info**: lifecycle markers and non-critical business milestones.
- **warning**: recoverable failures, skipped actions that may require operator attention.
- **error/critical**: failed business operations, data consistency risks, infrastructure failures.

## Sandbox virtual bank receipt flow (current policy)

- Optional pre-send trace for synthetic receipt email is `debug` (suppressed in production under this profile).
- Actual send failure remains `warning` and is visible in production.

## Temporary incident mode

When investigating a live issue, temporarily raise verbosity:

```dotenv
LOG_LEVEL=debug
```

After incident triage, revert to the baseline profile above.

## Next step (optional hardening)

If needed, move high-volume subsystems to dedicated channels (for example, separate "payments" or "mail" channels) and keep the default channel strict. This allows targeted verbosity without globally increasing noise.
