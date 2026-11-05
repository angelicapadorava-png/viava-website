// Builds and checks the Hostinger website release. Standard library only.
//
// What goes in is an allowlist: a file ships only if a rule below names it. Files
// come from Git objects, never from loose files on disk, so ignored files such as
// private notes, local databases and secrets have no way in.

import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, posix, resolve } from "node:path";

/** Everything the public website needs. A file not matching one of these never ships. */
export const INCLUDE = [
  /^index\.html$/,
  /^blog-[a-z0-9-]+\.html$/,
  /^viava-[a-z0-9-]+\.html$/,
  /^viava-[a-z0-9-]+\.css$/,
  /^(contact|resource)-handler\.php$/,
  /^favicon\.ico$/,
  /^site\.webmanifest$/,
  /^\.htaccess$/,
  /^assets\/(?!source\/)[a-z0-9/_-]+\.(jpg|jpeg|png|webp|svg|ico|pdf)$/i,
  /^email-assets\/[a-z0-9_-]+\.(jpg|jpeg|png|gif)$/i,
  /^email-templates\/[a-z0-9-]+\.php$/,
  /^lib\/viava-[a-z-]+\.php$/,
  /^lib\/PHPMailer\/(Exception|PHPMailer|SMTP)\.php$/,
  /^lib\/PHPMailer\/LICENSE$/,
  /^tools\/drain-spool\.php$/,
];

/** Known not to ship. Anything matching neither list stops the build, so a new file gets a decision. */
export const EXCLUDE = [
  /^agency-hq\//,
  /^tests\//,
  /^release\//,
  /^\.claude\//,
  /^README\.md$/,
  /^\.gitignore$/,
  /^mail-config\.example\.php$/,
  /^assets\/downloads\/README\.md$/,
  /^assets\/source\//,
];

/** Served by production today and deliberately removed by the first isolated release. */
export const DROPPED_FROM_PRODUCTION = [/^README\.md$/, /^\.gitignore$/, /^mail-config\.example\.php$/, /^assets\/downloads\/README\.md$/, /^assets\/source\//];

/** Fails the release wherever it appears, even if a mistake in the rules above let it in. */
const FORBIDDEN_SEGMENTS = new Set([
  "agency-hq", "node_modules", "tests", "test", "e2e", "docs", "scripts", "migrations", "release", ".release",
  "_private", ".wrangler", ".claude", ".git", ".github", ".vscode", "recovery", "src", "public_html",
]);
const FORBIDDEN_FILES = [
  [/^\.dev\.vars/i, "local secrets"],
  [/^\.env/i, "local secrets"],
  [/^\.demo-login$/i, "demo sign-in"],
  [/^\.gitignore$/i, "development configuration"],
  [/^\.gitattributes$/i, "development configuration"],
  [/^(mail|crm)-config.*\.php$/i, "server-side configuration"],
  [/^package(-lock)?\.json$/i, "Node configuration"],
  [/^(wrangler|tsconfig|vitest|worker-configuration)\b/i, "build tool configuration"],
  [/\.(md|ts|tsx|mjs|cjs|map|lock)$/i, "source or documentation"],
  [/\.(sqlite|sqlite3|sqlite-shm|sqlite-wal|db|sql)$/i, "database"],
  [/\.(csv|eml|jsonl|log|bak|old|orig|swp|tmp)$/i, "data or leftover file"],
  [/\.(zip|tar|gz|tgz|7z|rar)$/i, "archive"],
  [/\.(pem|key|p12|pfx)$/i, "private key"],
];
const SECRET_PATTERNS = [
  [/-----BEGIN [A-Z ]*PRIVATE KEY-----/, "private key"],
  [/\b(ghp|gho|ghs|github_pat)_[A-Za-z0-9_]{20,}/, "GitHub token"],
  [/\bsk-[A-Za-z0-9_-]{20,}/, "API key"],
  [/\b(FORM_API_SECRET_K\d|HQ_SETUP_TOKEN|HQ_ENCRYPTION_KEY)\s*=/, "CRM secret"],
  [/['"](password|secret|api_token|access_client_secret)['"]\s*=>\s*['"][^'"]{6,}['"]/i, "hard-coded credential"],
];
const CRM_MARKERS = [/\bD1Database\b/, /\bwrangler\b/, /_private\//, /from\s+["']\.\/(session|http|intake)/];
const TEXT_FILE = /\.(html|css|php|webmanifest)$|^\.htaccess$|(^|\/)\.htaccess$/;
const ALLOWED_EMAIL_DOMAINS = new Set(["viavateam.com", "example.com", "yourcompany.com", "company.com"]);

const sha256 = (data) => createHash("sha256").update(data).digest("hex");

function git(repo, args, options = {}) {
  return execFileSync("git", ["-C", repo, ...args], { maxBuffer: 1 << 30, stdio: ["ignore", "pipe", "pipe"], ...options });
}

/** A commit-ish, or WORKTREE for what's on disk now. WORKTREE leaves HEAD and the real index alone. */
export function resolveTree(repo, ref) {
  if (ref !== "WORKTREE") return { tree: git(repo, ["rev-parse", `${ref}^{tree}`]).toString().trim(), source: git(repo, ["rev-parse", "--short", ref]).toString().trim() };
  const temp = mkdtempSync(join(tmpdir(), "viava-index-"));
  const env = { ...process.env, GIT_INDEX_FILE: join(temp, "index") };
  try {
    git(repo, ["read-tree", "HEAD"], { env });
    git(repo, ["add", "-A"], { env });
    return { tree: git(repo, ["write-tree"], { env }).toString().trim(), source: `${git(repo, ["rev-parse", "--short", "HEAD"]).toString().trim()}+working-tree` };
  } finally {
    rmSync(temp, { recursive: true, force: true });
  }
}

/** @return {{mode:string,type:string,sha:string,path:string}[]} */
export function listTree(repo, tree) {
  return git(repo, ["ls-tree", "-r", "-z", "--full-tree", tree]).toString().split("\0").filter(Boolean).map((line) => {
    const [meta, path] = line.split("\t");
    const [mode, type, sha] = meta.split(" ");
    return { mode, type, sha, path };
  });
}

/** Every tracked file must be a decision: shipped or excluded. */
export function classify(entries) {
  const included = [];
  const excluded = [];
  const problems = [];
  for (const entry of entries) {
    if (INCLUDE.some((rule) => rule.test(entry.path)) && !EXCLUDE.some((rule) => rule.test(entry.path))) {
      if (entry.mode === "120000" || entry.type !== "blob") problems.push(`${entry.path}: links and submodules can't ship`);
      else included.push(entry);
    } else if (EXCLUDE.some((rule) => rule.test(entry.path))) {
      excluded.push(entry);
    } else {
      problems.push(`${entry.path}: not in the release allowlist or the exclude list. Decide which it belongs to.`);
    }
  }
  return { included, excluded, problems };
}

function walk(dir, prefix = "") {
  const files = [];
  for (const name of readdirSync(dir)) {
    const path = prefix ? `${prefix}/${name}` : name;
    if (statSync(join(dir, name)).isDirectory()) files.push(...walk(join(dir, name), path));
    else files.push(path);
  }
  return files;
}

export function fingerprintDir(dir) {
  const files = walk(dir).sort().map((path) => {
    const data = readFileSync(join(dir, path));
    return { path, size: data.length, sha256: sha256(data) };
  });
  return { files, fingerprint: sha256(files.map((f) => `${f.path}\0${f.sha256}`).join("\n")) };
}

/** Writes <out>/public_html and <out>/manifest.json. The manifest stays out of the web root. */
export function buildWebsite({ repo, ref = "WORKTREE", out }) {
  if (existsSync(out) && readdirSync(out).length > 0) throw new Error(`${out} isn't empty. Refusing to write over it.`);
  const { tree, source } = resolveTree(repo, ref);
  const { included, excluded, problems } = classify(listTree(repo, tree));
  if (problems.length > 0) throw new Error(`The release isn't fully classified:\n- ${problems.join("\n- ")}`);
  const root = join(out, "public_html");
  for (const entry of included) {
    const target = join(root, ...entry.path.split("/"));
    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, git(repo, ["cat-file", "blob", entry.sha]));
  }
  const { files, fingerprint } = fingerprintDir(root);
  writeFileSync(join(out, "manifest.json"), `${JSON.stringify({ source, fingerprint, file_count: files.length, excluded_count: excluded.length, files }, null, 2)}\n`);
  return { root, source, fingerprint, files, excluded: excluded.map((e) => e.path) };
}

// ------------------------------------------------------------------- Checking

/** Values that must never appear in a release: read from local files, never printed. */
export function loadSensitiveValues(repo) {
  const values = new Set();
  for (const file of [".dev.vars", "agency-hq/.dev.vars"]) {
    if (!existsSync(join(repo, file))) continue;
    for (const line of readFileSync(join(repo, file), "utf8").split(/\r?\n/)) {
      const value = line.slice(line.indexOf("=") + 1).trim();
      if (line.includes("=") && value.length >= 8) values.add(value);
    }
  }
  const demo = join(repo, "agency-hq/.demo-login");
  if (existsSync(demo)) {
    const match = readFileSync(demo, "utf8").match(/Password: (.+)/);
    if (match) values.add(match[1].trim());
  }
  const recovery = join(repo, "_private/recovery");
  if (existsSync(recovery)) {
    for (const file of walk(recovery).filter((f) => f.endsWith(".csv"))) {
      for (const email of readFileSync(join(recovery, file), "utf8").match(/[\w.+-]+@[\w-]+\.[\w.-]+/g) ?? []) {
        if (!/@(viavateam\.com|example\.(com|net|org))$/i.test(email)) values.add(email);
      }
    }
  }
  return values;
}

function referenceProblems(root, files) {
  const present = new Set(files);
  const problems = [];
  const check = (from, raw, baseDir) => {
    let ref = raw.trim();
    if (!ref || ref.includes("${") || ref.includes("<?")) return;
    const absolute = ref.match(/^https?:\/\/(?:www\.)?viavateam\.com(\/[^\s]*)?$/i);
    if (absolute) ref = absolute[1] ?? "/";
    else if (/^(https?:|mailto:|tel:|sms:|data:|javascript:|\/\/|#)/i.test(ref)) return;
    ref = ref.split(/[?#]/)[0];
    if (!ref) return;
    let decoded;
    try { decoded = decodeURIComponent(ref); } catch { decoded = ref; }
    const path = decoded.startsWith("/") ? decoded.slice(1) : posix.normalize(posix.join(baseDir, decoded));
    if (path === "" || path === "." || path.endsWith("/")) {
      if (!present.has(`${path === "." ? "" : path}index.html`)) problems.push(`${from}: "${raw}" points at a folder with no index page`);
      return;
    }
    if (!present.has(path) && !files.some((file) => file.startsWith(`${path}/`))) problems.push(`${from}: "${raw}" isn't in the release`);
  };
  for (const file of files) {
    if (!TEXT_FILE.test(file)) continue;
    const text = readFileSync(join(root, file), "utf8");
    const baseDir = posix.dirname(file) === "." ? "" : posix.dirname(file);
    if (file.endsWith(".html")) {
      for (const match of text.matchAll(/\b(?:href|src|action|poster)\s*=\s*["']([^"']+)["']/gi)) check(file, match[1], baseDir);
      for (const match of text.matchAll(/\bsrcset\s*=\s*["']([^"']+)["']/gi)) for (const part of match[1].split(",")) check(file, part.trim().split(/\s+/)[0], baseDir);
      for (const match of text.matchAll(/<meta[^>]+content\s*=\s*["'](https?:\/\/(?:www\.)?viavateam\.com\/[^"']+\.(?:png|jpe?g|webp|svg))["']/gi)) check(file, match[1], baseDir);
      for (const match of text.matchAll(/url\(\s*["']?([^"')]+)["']?\s*\)/gi)) check(file, match[1], baseDir);
    }
    if (file.endsWith(".css")) for (const match of text.matchAll(/url\(\s*["']?([^"')]+)["']?\s*\)/gi)) check(file, match[1], baseDir);
    if (file === "site.webmanifest") for (const match of text.matchAll(/"src"\s*:\s*"([^"]+)"/g)) check(file, match[1], baseDir);
    if (file.endsWith(".php") && !file.startsWith("lib/PHPMailer/")) {
      // require __DIR__ . '/x', and dirname(__DIR__) . '/x' in the replay tool.
      for (const match of text.matchAll(/(?<!dirname\()__DIR__\s*\.\s*'(\/[^']+)'/g)) {
        if (!/\/(mail|crm)-config\.php$/.test(match[1])) check(file, match[1].slice(1), baseDir);
      }
      for (const match of text.matchAll(/dirname\(__DIR__\)\s*\.\s*'(\/[^']+)'/g)) {
        if (!/\/(mail|crm)-config\.php$/.test(match[1])) check(file, posix.join("..", match[1].slice(1)), baseDir);
      }
      for (const match of text.matchAll(/['"]([\w-]+\.html)(?:[?#][^'"]*)?['"]/g)) check(file, `/${match[1]}`, baseDir);
      for (const match of text.matchAll(/['"]([\w-]+\.pdf)['"]/g)) if (!present.has(`assets/downloads/${match[1]}`)) problems.push(`${file}: download "${match[1]}" isn't in the release`);
    }
  }
  return problems;
}

/** @return {{problems:string[], files:string[]}} problems never include a secret's value. */
export function verifyWebsite(root, { sensitive = new Set() } = {}) {
  const files = walk(root);
  const problems = [];

  for (const file of files) {
    const segments = file.split("/");
    const name = segments[segments.length - 1];
    if (segments.some((segment) => FORBIDDEN_SEGMENTS.has(segment.toLowerCase()))) problems.push(`Prohibited location: ${file}`);
    for (const [pattern, why] of FORBIDDEN_FILES) if (pattern.test(name)) problems.push(`Prohibited file (${why}): ${file}`);
    if (!INCLUDE.some((rule) => rule.test(file))) problems.push(`Not on the allowlist: ${file}`);
    const data = readFileSync(join(root, file));
    if (data.length > 25 * 1024 * 1024) problems.push(`Over 25 MB: ${file}`);
    if (TEXT_FILE.test(file)) {
      const text = data.toString("utf8");
      for (const [pattern, why] of SECRET_PATTERNS) if (pattern.test(text)) problems.push(`Looks like a ${why}: ${file}`);
      for (const pattern of CRM_MARKERS) if (pattern.test(text)) problems.push(`Looks like CRM or development content: ${file}`);
      for (const value of sensitive) if (text.includes(value)) problems.push(`Holds a value from a private local file: ${file}`);
      if (!file.startsWith("lib/PHPMailer/")) {
        for (const match of text.matchAll(/[\w.+-]+@([\w-]+(?:\.[\w-]+)+)/g)) {
          const domain = match[1].toLowerCase();
          if (!/\.(png|jpe?g|webp|svg|gif|css|js)$/.test(domain) && !ALLOWED_EMAIL_DOMAINS.has(domain)) problems.push(`Unexpected email domain "${domain}": ${file}`);
        }
      }
    } else if ([...sensitive].some((value) => data.includes(value))) {
      problems.push(`Holds a value from a private local file: ${file}`);
    }
  }

  for (const required of ["index.html", "contact-handler.php", "resource-handler.php", ".htaccess", "tools/drain-spool.php", "lib/viava-mailer.php", "lib/viava-lead-spool.php", "lib/viava-worker-client.php", "lib/PHPMailer/PHPMailer.php", "email-templates/contact-confirmation.php", "email-templates/resource-delivery.php", "viava-contact.html", "viava-styles.css"]) {
    if (!files.includes(required)) problems.push(`Missing a required file: ${required}`);
  }
  problems.push(...referenceProblems(root, files));
  return { problems, files };
}

/** Everything production serves today must still ship, or be a listed, deliberate removal. */
export function compareToProduction(repo, ref, root) {
  const { tree } = resolveTree(repo, ref);
  const current = new Set(walk(root));
  const production = listTree(repo, tree);
  const lost = [];
  const dropped = [];
  const changed = [];
  for (const entry of production) {
    if (!current.has(entry.path)) {
      (DROPPED_FROM_PRODUCTION.some((rule) => rule.test(entry.path)) ? dropped : lost).push(entry.path);
    } else if (git(repo, ["cat-file", "blob", entry.sha]).compare(readFileSync(join(root, entry.path))) !== 0) {
      changed.push(entry.path);
    }
  }
  const known = new Set(production.map((e) => e.path));
  return { lost, dropped, changed, added: [...current].filter((path) => !known.has(path)).sort() };
}

/** What decides whether the CRM needs a release. Nothing outside these paths counts. */
export function crmFingerprint(repo, ref = "WORKTREE") {
  const { tree } = resolveTree(repo, ref);
  const inputs = listTree(repo, tree).filter((entry) => /^agency-hq\/(src\/|public\/|migrations\/|wrangler\.|package(-lock)?\.json$)/.test(entry.path));
  return sha256(inputs.map((entry) => `${entry.path}\0${entry.sha}`).sort().join("\n"));
}

// ----------------------------------------------------------------- Publishing

/**
 * Copies a checked release into a local clone of the release repository and commits
 * it. Never pushes. An unchanged release makes no commit, so nothing can deploy.
 * Only the clone's own files are touched: it has no route to files above it.
 */
export function publishToClone({ root, clone, sourceLabel, sensitive = new Set() }) {
  const target = resolve(clone);
  if (!existsSync(join(target, ".git"))) throw new Error(`${target} isn't a Git clone.`);
  const origin = (() => {
    try { return git(target, ["remote", "get-url", "origin"]).toString().trim(); } catch { return ""; }
  })();
  if (/viava-website(\.git)?$/i.test(origin)) throw new Error("That clone points at the source repository. Releases go to the separate release repository.");
  if (existsSync(join(target, "agency-hq")) || existsSync(join(target, "package.json"))) throw new Error("That clone holds source files. Refusing to use it.");
  const { problems } = verifyWebsite(root, { sensitive });
  if (problems.length > 0) throw new Error(`The release failed its checks:\n- ${problems.join("\n- ")}`);

  for (const name of readdirSync(target)) {
    if (name !== ".git") rmSync(join(target, name), { recursive: true, force: true });
  }
  for (const file of walk(root)) {
    const destination = join(target, ...file.split("/"));
    mkdirSync(dirname(destination), { recursive: true });
    writeFileSync(destination, readFileSync(join(root, file)));
  }
  // Exactly what was checked goes in, byte for byte, on any machine's Git settings.
  git(target, ["-c", "core.autocrlf=false", "add", "-A"]);
  if (git(target, ["status", "--porcelain"]).toString().trim() === "") return { changed: false };
  git(target, ["-c", "core.autocrlf=false", "commit", "-q", "-m", `Website release from ${sourceLabel}`]);
  return { changed: true, commit: git(target, ["rev-parse", "--short", "HEAD"]).toString().trim() };
}
