# Security Policy

## Supported versions

| Version | Supported          |
| ------- | ------------------ |
| 2.x     | :white_check_mark: |
| < 2.0   | :x:                |

## Reporting a vulnerability

Atlas Server is a personal single-tenant API. Because it handles sensitive
personal data (HealthKit, Rize, voice, documents), security issues are taken
seriously.

Please report vulnerabilities privately via GitHub Security Advisories:

<https://github.com/Vitorepf/atlas-server/security/advisories/new>

Do not open public issues for undisclosed vulnerabilities.

## Response time target

- Initial acknowledgement: 7 days
- Severity assessment and fix plan: 14 days
- Patch release for critical issues: 30 days

## Security scanning in CI

The repository runs secret scanning (gitleaks) and static analysis (Semgrep,
CodeQL) on every pull request. See `.github/workflows/security.yml`.

## Scope

The server is intended for single-user, self-hosted deployment. Do not expose
it to the public internet without a reverse proxy, TLS, and authentication.
