// Command line for the Hostinger website release. Nothing here pushes or deploys.
//
//   node release/website.mjs build   [--ref <commit>|WORKTREE] [--out .release/website]
//   node release/website.mjs verify  [--out .release/website]
//   node release/website.mjs publish --clone <local clone of the release repository> [--out .release/website]

import { existsSync, readFileSync, rmSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { buildWebsite, compareToProduction, loadSensitiveValues, publishToClone, verifyWebsite } from "./website-release.mjs";

const repo = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const [command, ...rest] = process.argv.slice(2);
const options = {};
for (let i = 0; i < rest.length; i += 2) options[rest[i].replace(/^--/, "")] = rest[i + 1];
const out = resolve(repo, options.out ?? ".release/website");
const root = join(out, "public_html");

function verify() {
  const { problems, files } = verifyWebsite(root, { sensitive: loadSensitiveValues(repo) });
  if (problems.length > 0) {
    console.error(`FAILED: ${problems.length} problem(s)\n- ${problems.join("\n- ")}`);
    process.exit(1);
  }
  const production = compareToProduction(repo, options.production ?? "main", root);
  if (production.lost.length > 0) {
    console.error(`FAILED: production files missing from the release:\n- ${production.lost.join("\n- ")}`);
    process.exit(1);
  }
  console.log(`Checks passed: ${files.length} files.`);
  console.log(`Compared with ${options.production ?? "main"}: ${production.changed.length} changed, ${production.added.length} added, ${production.dropped.length} deliberately removed.`);
}

try {
  if (command === "build") {
    // Only this tool's own default folder is ever cleared.
    if (!options.out) rmSync(out, { recursive: true, force: true });
    const built = buildWebsite({ repo, ref: options.ref ?? "WORKTREE", out });
    console.log(`Built ${built.files.length} files from ${built.source}, leaving out ${built.excluded.length}.`);
    console.log(`Fingerprint: ${built.fingerprint}`);
    verify();
  } else if (command === "verify") {
    verify();
  } else if (command === "publish") {
    if (!options.clone) throw new Error("--clone is required.");
    if (!existsSync(join(out, "manifest.json"))) throw new Error("Build the release first.");
    const { source } = JSON.parse(readFileSync(join(out, "manifest.json"), "utf8"));
    const result = publishToClone({ root, clone: options.clone, sourceLabel: source, sensitive: loadSensitiveValues(repo) });
    if (!result.changed) {
      console.log("No change in the website. Nothing committed, so nothing would deploy.");
    } else {
      console.log(`Committed ${result.commit} in the release clone. Nothing has been pushed.`);
      console.log(`To deploy, with the owner's approval: git -C "${resolve(options.clone)}" push origin main`);
    }
  } else {
    console.error("Usage: node release/website.mjs build|verify|publish");
    process.exit(2);
  }
} catch (error) {
  console.error(error.message);
  process.exit(1);
}
