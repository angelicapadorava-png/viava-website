// Automated isolation checks for the website release. Local temp folders only.
// A fake "host" stands in for Hostinger: it proves this tool's side of the process,
// not how Hostinger itself behaves.
//   node --test release/website-release.test.mjs
import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { after, before, test } from "node:test";
import { fileURLToPath } from "node:url";
import { buildWebsite, classify, compareToProduction, crmFingerprint, fingerprintDir, listTree, loadSensitiveValues, publishToClone, resolveTree, verifyWebsite } from "./website-release.mjs";

const repo = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const temp = mkdtempSync(join(tmpdir(), "viava-release-test-"));
const IDENTITY = ["-c", "user.name=Release Test", "-c", "user.email=release-test@example.com", "-c", "core.autocrlf=false"];
const git = (dir, ...args) => execFileSync("git", ["-C", dir, ...IDENTITY, ...args], { stdio: ["ignore", "pipe", "pipe"] }).toString().trim();
const hash = (file) => createHash("sha256").update(readFileSync(file)).digest("hex");
const walkRel = (dir, prefix = "") => readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walkRel(join(dir, e.name), `${prefix}${e.name}/`) : [`${prefix}${e.name}`]));
let built;
let counter = 0;
const freshDir = (label) => { const dir = join(temp, `${label}-${++counter}`); mkdirSync(dir, { recursive: true }); return dir; };

before(() => { built = buildWebsite({ repo, ref: "WORKTREE", out: freshDir("base") }); });
after(() => rmSync(temp, { recursive: true, force: true }));

test("the release passes every check, with real local secrets in the scan", () => {
  const { problems, files } = verifyWebsite(built.root, { sensitive: loadSensitiveValues(repo) });
  assert.deepEqual(problems, []);
  assert.ok(files.length > 50);
});

test("nothing prohibited is in the release", () => {
  const files = walkRel(built.root);
  for (const pattern of [/^agency-hq/, /node_modules/, /^tests?\//, /^release\//, /\.dev\.vars/, /\.wrangler/, /\.md$/i, /\.sqlite/, /_private/, /mail-config/, /crm-config/, /package.*json/, /wrangler/, /\.gitignore/, /^\.git\//, /assets\/source/]) {
    assert.deepEqual(files.filter((file) => pattern.test(file)), [], String(pattern));
  }
});

test("everything production serves is still there, or is a listed removal", () => {
  const { lost, dropped } = compareToProduction(repo, "main", built.root);
  assert.deepEqual(lost, []);
  assert.ok(dropped.includes("README.md") && dropped.includes("mail-config.example.php"));
});

const PLANTED = [
  ["agency-hq/src/index.ts", "CRM source"],
  ["agency-hq/package.json", "CRM package"],
  ["node_modules/x/index.js", "dependency"],
  ["tests/run.php", "test file"],
  ["test/x.php", "test folder"],
  [".dev.vars", "local secrets"],
  [".env", "environment file"],
  ["wrangler.jsonc", "build configuration"],
  ["package.json", "Node configuration"],
  ["tsconfig.json", "build configuration"],
  [".wrangler/state/v3/d1/db.sqlite", "local database"],
  ["backup.sql", "database export"],
  ["docs/V1-SPEC.md", "internal documentation"],
  ["NOTES.md", "internal documentation"],
  ["_private/01-audit-report.md", "private notes"],
  ["inventory.csv", "lead data"],
  ["mail-config.php", "mail secrets"],
  ["crm-config.php", "CRM secrets"],
  [".git/config", "Git metadata"],
  ["release/website.mjs", "build tool"],
  ["assets/backup.zip", "archive"],
  ["surprise.html", "a page nobody allowlisted"],
];
for (const [path, label] of PLANTED) {
  test(`fails if this appears in the release: ${path} (${label})`, () => {
    const out = freshDir("plant");
    cpSync(built.root, out, { recursive: true });
    mkdirSync(dirname(join(out, path)), { recursive: true });
    writeFileSync(join(out, path), "planted");
    const { problems } = verifyWebsite(out);
    assert.ok(problems.some((problem) => problem.includes(path)), `no problem named ${path}`);
  });
}

for (const [label, content] of [
  ["private key", "-----BEGIN RSA PRIVATE KEY-----"],
  ["GitHub token", `ghp_${"a".repeat(30)}`],
  ["hard-coded password", `<?php return ['password' => 'hunter2hunter2'];`],
  ["CRM secret", "HQ_SETUP_TOKEN=abc"],
  ["outside email", "write to someone@gmail.com"],
]) {
  test(`fails if an allowed file holds a ${label}`, () => {
    const out = freshDir("secret");
    cpSync(built.root, out, { recursive: true });
    writeFileSync(join(out, "viava-faq.html"), `${readFileSync(join(out, "viava-faq.html"), "utf8")}\n<!-- ${content} -->`);
    assert.ok(verifyWebsite(out).problems.some((problem) => problem.includes("viava-faq.html")));
  });
}

test("fails on a value from a private local file, without printing it", () => {
  const out = freshDir("value");
  cpSync(built.root, out, { recursive: true });
  const value = "a-private-local-value-1234";
  writeFileSync(join(out, "viava-faq.html"), `${readFileSync(join(out, "viava-faq.html"), "utf8")}${value}`);
  const { problems } = verifyWebsite(out, { sensitive: new Set([value]) });
  assert.ok(problems.some((problem) => problem.includes("private local file")));
  assert.ok(!problems.join("\n").includes(value));
});

for (const missing of ["assets/hero-home.jpg", "viava-styles.css", "lib/viava-worker-client.php", "email-templates/resource-delivery.php", "assets/downloads/sop-starter-kit.pdf", "viava-pricing.html", "email-assets/hero-workspace.jpg"]) {
  test(`fails if something the site needs is missing: ${missing}`, () => {
    const out = freshDir("missing");
    cpSync(built.root, out, { recursive: true });
    rmSync(join(out, missing));
    const { problems } = verifyWebsite(out);
    assert.ok(problems.length > 0, "the missing file went unnoticed");
  });
}

test("a tracked file nobody has classified stops the build", () => {
  const { problems } = classify([{ mode: "100644", type: "blob", sha: "x", path: "new-feature/thing.txt" }]);
  assert.equal(problems.length, 1);
});

test("a symbolic link can't ship", () => {
  const { problems, included } = classify([{ mode: "120000", type: "blob", sha: "x", path: "viava-link.html" }]);
  assert.equal(problems.length, 1);
  assert.equal(included.length, 0);
});

test("ignored files on disk can't get in, because builds read Git objects", () => {
  const clone = freshDir("ignored");
  git(temp, "clone", "-q", "--no-hardlinks", repo, clone);
  writeFileSync(join(clone, ".gitignore"), `${readFileSync(join(clone, ".gitignore"), "utf8")}\nviava-secret.html\n`);
  writeFileSync(join(clone, "viava-secret.html"), "secret page");
  const result = buildWebsite({ repo: clone, ref: "WORKTREE", out: freshDir("ignored-out") });
  assert.ok(!result.files.some((file) => file.path === "viava-secret.html"));
});

test("refuses to build over a folder that already has files", () => {
  const out = freshDir("occupied");
  writeFileSync(join(out, "keep.txt"), "x");
  assert.throws(() => buildWebsite({ repo, out }), /isn't empty/);
  assert.ok(existsSync(join(out, "keep.txt")));
});

test("two builds of the same source are identical", () => {
  const again = buildWebsite({ repo, ref: "WORKTREE", out: freshDir("again") });
  assert.equal(again.fingerprint, built.fingerprint);
});

test("a CRM change doesn't change the website release, and a website change doesn't change the CRM", () => {
  const clone = freshDir("paths");
  git(temp, "clone", "-q", "--no-hardlinks", repo, clone);
  const website = () => buildWebsite({ repo: clone, ref: "HEAD", out: freshDir("paths-out") }).fingerprint;
  const startWebsite = website();
  const startCrm = crmFingerprint(clone, "HEAD");

  const crmFile = join(clone, "agency-hq/src/index.ts");
  writeFileSync(crmFile, `${readFileSync(crmFile, "utf8")}\n// A CRM change.\n`);
  git(clone, "commit", "-qam", "CRM change");
  assert.equal(website(), startWebsite, "the website would have redeployed");
  const afterCrmChange = crmFingerprint(clone, "HEAD");
  assert.notEqual(afterCrmChange, startCrm);

  const page = join(clone, "viava-faq.html");
  writeFileSync(page, `${readFileSync(page, "utf8")}\n<!-- A website change. -->\n`);
  git(clone, "commit", "-qam", "Website change");
  assert.equal(crmFingerprint(clone, "HEAD"), afterCrmChange, "the CRM would have redeployed");
  assert.notEqual(website(), startWebsite);
});

// One simulated life of the release repository, the host, and the mail settings.
test("release, no-change release, rollback: the host never gets CRM files, and mail settings stay put", () => {
  const base = freshDir("host");
  const remote = join(base, "release.git");
  const clone = join(base, "release-clone");
  const home = join(base, "home");
  const webRoot = join(home, "public_html");
  git(base, "init", "-q", "--bare", "-b", "main", remote);
  git(base, "clone", "-q", remote, clone);
  git(clone, "config", "user.name", "Release Test");
  git(clone, "config", "user.email", "release-test@example.com");

  const mailConfig = join(home, "mail-config.php");
  const spool = join(home, "viava-private/leads-2026-11.jsonl");
  mkdirSync(dirname(spool), { recursive: true });
  writeFileSync(mailConfig, "<?php return ['host' => 'unchanged'];");
  writeFileSync(spool, "{}\n");
  const mailHash = hash(mailConfig);
  const spoolHash = hash(spool);
  const hostMatches = (root) => assert.equal(fingerprintDir(webRootOnly(webRoot)).fingerprint, fingerprintDir(root).fingerprint);

  // First release.
  const first = publishToClone({ root: built.root, clone, sourceLabel: "test-1" });
  assert.equal(first.changed, true);
  git(clone, "push", "-q", "origin", "main");
  git(home, "clone", "-q", remote, "public_html");
  hostMatches(built.root);

  // No .git history anywhere on the host mentions CRM files.
  const everything = git(webRoot, "log", "--all", "--name-only", "--format=");
  for (const banned of ["agency-hq", "tests/", "wrangler", "_private", "README.md", "package.json", "mail-config"]) {
    assert.ok(!everything.includes(banned), `${banned} reached the host's Git history`);
  }

  // The same release again: no commit, so no deploy.
  assert.equal(publishToClone({ root: built.root, clone, sourceLabel: "test-1-again" }).changed, false);

  // A website change.
  const changed = freshDir("changed");
  cpSync(built.root, changed, { recursive: true });
  writeFileSync(join(changed, "viava-faq.html"), `${readFileSync(join(changed, "viava-faq.html"), "utf8")}\n<!-- Next release. -->\n`);
  rmSync(join(changed, "assets/about-mug.png"));
  assert.equal(publishToClone({ root: changed, clone, sourceLabel: "test-2" }).changed, true);
  git(clone, "push", "-q", "origin", "main");
  git(webRoot, "pull", "-q", "origin", "main");
  hostMatches(changed);

  // Rollback.
  git(clone, "revert", "--no-edit", "HEAD");
  git(clone, "push", "-q", "origin", "main");
  git(webRoot, "pull", "-q", "origin", "main");
  hostMatches(built.root);

  assert.equal(hash(mailConfig), mailHash, "mail-config.php changed");
  assert.equal(hash(spool), spoolHash, "saved submissions changed");
});

function webRootOnly(webRoot) {
  const copy = freshDir("host-view");
  for (const name of readdirSync(webRoot)) if (name !== ".git") cpSync(join(webRoot, name), join(copy, name), { recursive: true });
  return copy;
}

test("publishing refuses the source repository, and anything holding source files", () => {
  const source = freshDir("source");
  git(source, "init", "-q");
  git(source, "remote", "add", "origin", "https://github.com/example/viava-website.git");
  assert.throws(() => publishToClone({ root: built.root, clone: source, sourceLabel: "x" }), /source repository/);

  const mixed = freshDir("mixed");
  git(mixed, "init", "-q");
  mkdirSync(join(mixed, "agency-hq"));
  assert.throws(() => publishToClone({ root: built.root, clone: mixed, sourceLabel: "x" }), /holds source files/);
  assert.ok(existsSync(join(mixed, "agency-hq")), "nothing was removed");
});

test("publishing refuses a release that fails its checks, and leaves the clone alone", () => {
  const clone = freshDir("refuse");
  git(clone, "init", "-q");
  writeFileSync(join(clone, "keep.txt"), "x");
  const bad = freshDir("bad");
  cpSync(built.root, bad, { recursive: true });
  writeFileSync(join(bad, ".dev.vars"), "X=1");
  assert.throws(() => publishToClone({ root: bad, clone, sourceLabel: "x" }), /failed its checks/);
  assert.ok(existsSync(join(clone, "keep.txt")));
});

test("the push guard blocks Agency HQ on any branch, including older history, until isolation is on", () => {
  const base = freshDir("guard");
  const remote = join(base, "origin.git");
  const work = join(base, "work");
  git(base, "init", "-q", "--bare", "-b", "main", remote);
  git(base, "clone", "-q", remote, work);
  git(work, "config", "user.name", "Release Test");
  git(work, "config", "user.email", "release-test@example.com");
  git(work, "config", "core.hooksPath", join(repo, "release/hooks").replace(/\\/g, "/"));
  writeFileSync(join(work, "viava-faq.html"), "x");
  git(work, "add", "-A");
  git(work, "commit", "-qm", "Website only");
  const push = (ref) => spawnSync("git", ["-C", work, "push", "-q", "origin", ref], { encoding: "utf8" });

  assert.equal(push("HEAD:refs/heads/main").status, 0, "website-only pushes are fine");

  mkdirSync(join(work, "agency-hq"));
  writeFileSync(join(work, "agency-hq/a.ts"), "x");
  git(work, "add", "-A");
  git(work, "commit", "-qm", "Add CRM");
  assert.notEqual(push("HEAD:refs/heads/main").status, 0, "main is protected");
  assert.notEqual(push("HEAD:refs/heads/feature").status, 0, "other branches are protected");

  rmSync(join(work, "agency-hq"), { recursive: true });
  git(work, "add", "-A");
  git(work, "commit", "-qm", "Remove CRM");
  assert.notEqual(push("HEAD:refs/heads/feature").status, 0, "history still holds it");

  git(work, "config", "viava.hostingerIsolated", "true");
  assert.equal(push("HEAD:refs/heads/feature").status, 0, "allowed once isolation is confirmed");
});

test("the source tree is what the classifier says, with no unclassified files", () => {
  const { problems } = classify(listTree(repo, resolveTree(repo, "WORKTREE").tree));
  assert.deepEqual(problems, []);
});
