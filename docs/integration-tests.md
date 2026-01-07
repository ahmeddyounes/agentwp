# Integration tests

## Setup
- `npm install`
- `npm run wp-env:start`

Optional reset if the database gets dirty:
- `npm run wp-env:reset`

## Run
- `npm run test:e2e`

## Environment variables
- `AGENTWP_BASE_URL` (default `http://localhost:8888`)
- `WP_USERNAME` / `WP_PASSWORD` (default `admin` / `password`)
- `AGENTWP_OPENAI_MODE` (`playback` default, or `record`, or `live`)
- `AGENTWP_OPENAI_API_KEY` (required for `live` or `record`)
- `AGENTWP_OPENAI_FIXTURES` (defaults to `wp-content/plugins/agentwp/tests/fixtures/openai`)

## Notes
- The wp-env config loads test-only endpoints under `/wp-json/agentwp-test/v1`.
- VCR fixtures are keyed by request method + URL. Record mode writes new fixtures into the fixtures directory.
