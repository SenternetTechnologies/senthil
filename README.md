# Senternet Technologies — website + PHP JSON API

This repository contains the Senternet product landing page and an early-access registration API.

## Important hosting distinction

- The frontend can be published on GitHub Pages.
- GitHub Pages does **not** execute PHP.
- Deploy `api/index.php` to a PHP 8.1+ host separately, then configure the frontend endpoint in `assets/app.js`.
- The API stores only early-access requests in JSON. It is **not** a production email server, mailbox, authentication system, or browser backend.

## Files

- `index.html` — responsive product website
- `assets/styles.css` — responsive visual system
- `assets/app.js` — signup form/API integration
- `api/index.php` — JSON API with validation, origin allowlist, basic rate limiting, duplicate-email prevention, and file locking

## PHP deployment

Use PHP 8.1+ with `mbstring` enabled and HTTPS.

1. Deploy the `api/` directory to a PHP host (for example, `https://api.your-domain.example/api/index.php`).
2. Create a writable data directory **outside the public web root**, e.g. `/var/lib/senternet`.
3. Configure environment variables on the PHP host:
   - `SENTERNET_DATA_DIR=/var/lib/senternet`
   - `SENTERNET_ALLOWED_ORIGINS=https://senternettechnologies.github.io`
   - `SENTERNET_RATE_LIMIT_SECRET=<long-random-secret>`
4. Restrict the data directory to the PHP service account. Do not commit real user data, secrets, or JSON submissions to Git.
5. In `assets/app.js`, set `API_ENDPOINT` to the deployed HTTPS endpoint.
6. Test the GET health endpoint and submit a test request. Confirm that the JSON file is created outside the web root and cannot be downloaded publicly.

The API intentionally fails closed when `SENTERNET_DATA_DIR` is not configured. Do not store real visitor information in the public GitHub Pages repository.

## Local development

Serve the frontend with any static web server. Run the API with PHP's development server only for local testing:

```sh
SENTERNET_DATA_DIR=/tmp/senternet-data \
SENTERNET_ALLOWED_ORIGINS=http://localhost:8080 \
SENTERNET_RATE_LIMIT_SECRET='replace-with-a-long-random-secret' \
php -S 127.0.0.1:8081 -t .
```

In `assets/app.js`, temporarily set `API_ENDPOINT` to `http://127.0.0.1:8081/api/index.php`. Never use PHP's development server for public production traffic.

## Before public launch

- Add a privacy policy and terms that accurately describe collection and retention.
- Add a human-reviewed abuse/support process and an appropriate CAPTCHA or managed bot protection if abuse appears.
- Configure host-level WAF/rate limiting and monitor errors.
- Test backup/restore and permissions.
- Have the deployment reviewed for the actual hosting environment.

## Scope honesty

This is a production-oriented landing site and lead-capture API foundation, not a fully production-ready Yahoo-like email service or Chromium browser. Real email requires SMTP/IMAP infrastructure, spam filtering, domain authentication, mailbox storage, account security and ongoing deliverability operations. A browser product requires a maintained Chromium distribution, update signing, sandboxing and security patch operations.
