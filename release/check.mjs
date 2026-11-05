// The one command to run before any website release: builds into a temp folder, and
// runs every isolation check and form suite against that build. Touches nothing real.
//   node release/check.mjs            (add --full for the Worker outage suite, ~3 min)
import { spawnSync } from "node:child_process";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { buildWebsite, compareToProduction, loadSensitiveValues, verifyWebsite } from "./website-release.mjs";

const repo = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const out = mkdtempSync(join(tmpdir(), "viava-release-check-"));
const results = [];
function run(label, command, args, env = {}) {
  const result = spawnSync(command, args, { cwd: repo, encoding: "utf8", env: { ...process.env, ...env }, shell: process.platform === "win32" && command !== process.execPath });
  const text = `${result.stdout ?? ""}\n${result.stderr ?? ""}`;
  const summary = text.match(/(\d+) passed, (\d+) failed/)?.[0] ?? (text.match(/(?:ℹ|#) pass \d+[\s\S]*?(?:ℹ|#) fail \d+/)?.[0].replace(/\s*(?:ℹ|#)\s*/g, " ").trim()) ?? "";
  const ok = result.status === 0;
  results.push(ok);
  console.log(`${ok ? "PASS" : "FAIL"}  ${label}${summary ? ` (${summary})` : ""}`);
  if (!ok) console.log(text.split("\n").filter((line) => /^FAIL|not ok|✖|Error|failed/i.test(line)).slice(0, 25).join("\n"));
}

try {
  const built = buildWebsite({ repo, ref: "WORKTREE", out });
  console.log(`Built ${built.files.length} files from ${built.source}, leaving out ${built.excluded.length}. Fingerprint ${built.fingerprint.slice(0, 16)}`);
  const { problems } = verifyWebsite(built.root, { sensitive: loadSensitiveValues(repo) });
  results.push(problems.length === 0);
  console.log(`${problems.length === 0 ? "PASS" : "FAIL"}  allowlist, prohibited files, secrets, and every link and require resolves`);
  for (const problem of problems) console.log(`      ${problem}`);
  const production = compareToProduction(repo, "main", built.root);
  results.push(production.lost.length === 0);
  console.log(`${production.lost.length === 0 ? "PASS" : "FAIL"}  everything production serves is kept (${production.changed.length} changed, ${production.added.length} added, ${production.dropped.length} deliberately removed)`);
  for (const lost of production.lost) console.log(`      lost: ${lost}`);

  const env = { VIAVA_TEST_SITE_ROOT: built.root, WRANGLER_SEND_METRICS: "false" };
  run("isolation tests", process.execPath, ["--test", "release/website-release.test.mjs"]);
  run("forms from the release, laid out like the host", "php", ["tests/release-structure.php"], env);
  run("the full form suite, run against the release", "php", ["tests/run.php"], env);
  if (process.argv.includes("--full")) run("forms to Worker to D1, with outages and replay, against the release", "php", ["tests/integration.php"], env);
} catch (error) {
  results.push(false);
  console.log(`FAIL  ${error.message}`);
} finally {
  rmSync(out, { recursive: true, force: true });
}
const allPassed = results.every(Boolean);
console.log(allPassed ? "\nAll release checks passed." : "\nRelease checks FAILED.");
process.exit(allPassed ? 0 : 1);
