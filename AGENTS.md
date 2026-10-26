# Working on ldm-unraid with an AI agent

AI-assisted contributions are allowed here, under one rule: **the person who opens the pull request is the author.** You are responsible for the code, you must be able to explain every line of it when asked, and you must have run it.

[CONTRIBUTING.md](CONTRIBUTING.md) has the full guidelines. The short version for the agent:

- **Never run a mutating operation against a real Unraid server or a real dynamic disk** unless a human asked for that exact operation. No `dmsetup create` against disks with data, no read-write mounts, no plugin installs. Use the loop-device images from `tests/windows`.
- **Don't bump the version, tag, or edit the `md5` entity** in `ldm-unraid.plg`. User-facing changes go in its `<CHANGES>` block under `### Unreleased`.
- The `.plg` is XML: run `xmllint --noout ldm-unraid.plg` after touching it.
- Webgui POSTs are form-encoded (`$.post` with an object), so Unraid's CSRF token arrives in `$_POST`. Don't switch them to JSON bodies.
- Every change carries its docs: `README.md` for behaviour, the `.plg` changelog for anything a user sees.
- No credentials in code, tests or commit messages. No em dashes in prose, comments or messages.
- When you can't test a change on a real server, say so in the pull request instead of describing a test you didn't run.
