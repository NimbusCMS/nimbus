<!-- Keep PRs small and focused on one concern. -->

## What & why

<!-- What does this change, and what problem does it solve? Link the issue/ADR. -->

Closes #

## How it was tested

<!-- New/updated tests; a regression test that fails before and passes after. -->

- [ ] `composer check` is green (composer audit + PHPStan L6 + full test suite)
- [ ] `composer format:check` shows no diff

## Contract & scope

- [ ] Classified correctly (core / plugin / theme / application / tooling) per `docs/CHARTER.md`
- [ ] No change to the public plugin API or `/api/v1` wire contract — or it is documented in `docs/COMPATIBILITY.md`
- [ ] No merged ADR relitigated (a new ADR is proposed if a decision changes)
- [ ] Security-relevant? Auth / tokens / scopes / SQL / templates / upload / redirects / the plugin boundary were considered
