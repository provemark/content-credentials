# Security Policy

## Supported versions

This project is pre-1.0. Security fixes are applied to the latest `0.x` release.

## Reporting a vulnerability

Please report security issues **privately** — do not open a public issue.

Use GitHub's private vulnerability reporting on this repository
(**Security → Report a vulnerability**). Include steps to reproduce and the
affected version. You'll get an acknowledgement as soon as possible.

## Handling of keys and secrets

This library never handles production signing keys directly — signing is
delegated to the separate signing service. When reporting or reproducing:

- **Never** include real private keys, production certificates, or API tokens in
  reports, issues, or pull requests. The repository intentionally contains only
  the c2pa-rs **test** certificates; the private test key is gitignored.
- The `CONTENTAUTH_API_KEY` and service URL are configuration, provided via the
  environment; they must not be committed.
