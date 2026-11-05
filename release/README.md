# Releasing the website and Agency HQ

Two applications with two separate release paths. **Neither is set up in production yet.** Hostinger still deploys `main` of `viava-website`. Every step marked "needs approval" needs the owner's explicit approval at that time.

| | Marketing website | Agency HQ |
|---|---|---|
| Address | viavateam.com | agency.viavateam.com |
| Runs on | Hostinger | Cloudflare Workers and D1 |
| Source | This repository, allowlisted files only | `agency-hq/` |
| Goes live when | A commit is pushed to the release repository | Someone runs `wrangler deploy` |
| A change to the other app | Same fingerprint, no commit, no deploy | Not an input, no deploy |

## Today's safety rule

**Don't push this branch or merge to `main` of `viava-website`.** Auto-deployment is on, so a merge would publish `agency-hq/` into `public_html`. That repository is also public, so pushing any branch would publish the CRM's source. Hostinger's docs don't say whether its copy fetches other branches.

`release/hooks/pre-push` blocks these pushes, on this PC. To set it up on a new checkout:

```bash
git config core.hooksPath release/hooks
```

Lift it only after Hostinger is connected to the release repository:

```bash
git config viava.hostingerIsolated true
```

## Website release

Run from the repository root, in this order.

1. Check everything, using a temp folder. Add `--full` for the Worker outage suite.

```bash
node release/check.mjs --full
```

2. Build. Use a commit, not the working tree, for a real release.

```bash
node release/website.mjs build --ref HEAD
```

3. Commit it to your local clone of the release repository. This runs the checks again, and doesn't push. An unchanged website makes no commit.

```bash
node release/website.mjs publish --clone "../viava-website-release"
```

4. **Needs approval.** Push, which deploys straight away:

```bash
git -C "../viava-website-release" push origin main
```

5. Run the live checks in `agency-hq/docs/RELEASE-CHECKLIST.md`. Allow 30 to 60 seconds for the host to catch up.

### Website rollback

Hostinger's docs describe no way to redeploy an earlier deployment. Roll back by reverting in the release repository, which keeps the history. **Needs approval.**

```bash
git -C "../viava-website-release" revert --no-edit HEAD
```

```bash
git -C "../viava-website-release" push origin main
```

Files above `public_html`, such as `mail-config.php` and `viava-private/`, aren't part of any of this.

## Adding a file to the website

Add a rule to `INCLUDE` in `release/website-release.mjs`. A tracked file that's in neither `INCLUDE` nor `EXCLUDE` stops the build, so nothing is shipped or dropped by accident.

## Agency HQ release

Run from `agency-hq/`.

1. Check it. This does a dry run and deploys nothing.

```bash
npm run release:check
```

```bash
npm test
```

2. **Needs approval, first time only.** Copy `wrangler.production.example.jsonc` to `wrangler.production.jsonc`, which Git ignores, and fill in the real database ID.

3. Back up first. **Needs approval.**

```bash
npx wrangler d1 export viava-agency-hq --remote --config wrangler.production.jsonc --output ../_private/backups/before-release.sql
```

4. Apply new migrations, if there are any. **Needs approval.**

```bash
npx wrangler d1 migrations apply viava-agency-hq --remote --config wrangler.production.jsonc
```

5. Deploy. **Needs approval.**

```bash
npx wrangler deploy --config wrangler.production.jsonc
```

`npm run deploy` stays disabled on purpose, so a real deploy can't be started by habit. There's no GitHub Action or Cloudflare build connected to this repository, so nothing deploys on a push.

### Agency HQ rollback

**Needs approval.** For code:

```bash
npx wrangler rollback --config wrangler.production.jsonc
```

For data, use D1 Time Travel, or restore an export into a new, empty database with `scripts/restore-local.mjs` or its reordering step. Rolling back code doesn't undo a migration, so check that the older code works with the newer tables first.

## Moving Hostinger to the release repository

**Not done. Every step needs approval.** `main` of `viava-website` stays at `a6d2070` until this is finished, which keeps the way back open.

**Rehearse first, on a throwaway Hostinger site or subdomain,** connected to the release repository. It answers what the docs don't:
- Does a deploy delete files that have left the repository?
- What happens to an existing `.git` folder?
- Do the `.htaccess` rules work on this server?
- Can PHP create the private folder, and does the replay script run from cron?

Use no real mail settings there, and no real form submissions.

**Then, in production:**
1. Create a manual backup in hPanel, and download a copy.
2. Note the hash of `mail-config.php`, its size and its date.
3. Turn Auto-deployment off.
4. Disconnect `viava-website` for this site only, using **Disconnect from repository**. Don't use **Disconnect GitHub**, which affects every site on the account.
5. Connect `viava-website-release`, branch `main`, directory `public_html`, and let it deploy.
6. Check that `README.md`, `.gitignore`, `mail-config.example.php` and `assets/source/` are gone from the server. Remove any left behind, with approval. Check what's in `public_html/.git` now.
7. Check that `mail-config.php` still matches step 2.
8. Run the live checks, then send one real test inquiry.
9. Only then, turn off the push guard and merge the source branch.

**If it goes wrong:** disconnect, reconnect `viava-website` on `main` with `public_html`, and redeploy. That restores `a6d2070`. Restore the hPanel backup for anything else.

## What the local checks can't prove

The fake host in `website-release.test.mjs` uses a plain Git clone and pull. It proves this tool's side only. How Hostinger itself behaves has to be shown in the rehearsal.
