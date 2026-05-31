#!/usr/bin/env bash
#
# Push the current branch to git, then trigger a Laravel Forge deployment.
#
# Forge deploys a site when its per-site "Deploy Hook" URL is requested. This
# script pushes your already-made commits, then pings that hook so Forge runs
# the site's deploy script (git pull, composer install, migrate, asset build,
# queue restart — whatever you've configured on the site).
#
# The hook URL is a secret, so it is NOT stored in the repo. Provide it via:
#   1) a gitignored file at the repo root named ".forge-deploy"
#      (single line: the full https://forge.laravel.com/servers/.../deploy/http?token=... URL)
#   2) or the FORGE_DEPLOY_HOOK environment variable
#
# Usage:
#   bin/deploy.sh                 # push current branch + trigger deploy
#   bin/deploy.sh --no-deploy     # push only, skip the Forge hook
#   bin/deploy.sh --branch main   # push a specific branch
#   bin/deploy.sh --status        # after deploying, poll Forge for the result
#
set -euo pipefail

# --- locate repo root regardless of where the script is called from ----------
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# --- args --------------------------------------------------------------------
BRANCH=""
DO_DEPLOY=1
SHOW_STATUS=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --no-deploy) DO_DEPLOY=0; shift ;;
        --branch) BRANCH="${2:-}"; shift 2 ;;
        --status) SHOW_STATUS=1; shift ;;
        -h|--help)
            # Print the leading comment block (stop at the first non-comment line).
            awk 'NR>1 && /^#/ { sub(/^# ?/, ""); print; next } NR>1 { exit }' "${BASH_SOURCE[0]}"
            exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

# --- colours (fall back to plain if not a tty) -------------------------------
if [[ -t 1 ]]; then
    BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; RESET=$'\033[0m'
else
    BOLD=""; GREEN=""; YELLOW=""; RED=""; RESET=""
fi
say()  { echo "${BOLD}==>${RESET} $*"; }
ok()   { echo "${GREEN}✓${RESET} $*"; }
warn() { echo "${YELLOW}!${RESET} $*"; }
die()  { echo "${RED}✗ $*${RESET}" >&2; exit 1; }

# --- resolve branch ----------------------------------------------------------
if [[ -z "$BRANCH" ]]; then
    BRANCH="$(git rev-parse --abbrev-ref HEAD)"
fi
[[ "$BRANCH" == "HEAD" ]] && die "Detached HEAD — checkout a branch or pass --branch."

# --- guard against deploying a dirty tree ------------------------------------
if [[ -n "$(git status --porcelain)" ]]; then
    warn "You have uncommitted changes — they will NOT be pushed or deployed:"
    git status --short
    echo
    read -r -p "Continue pushing the committed work anyway? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || die "Aborted. Commit your changes first."
fi

# --- push (with exponential backoff on transient network failures) -----------
say "Pushing ${BOLD}${BRANCH}${RESET} to origin…"
attempt=0
until git push -u origin "$BRANCH"; do
    attempt=$((attempt + 1))
    if [[ $attempt -ge 4 ]]; then
        die "git push failed after $attempt attempts."
    fi
    wait=$((2 ** attempt))
    warn "push failed — retrying in ${wait}s (attempt $attempt/4)…"
    sleep "$wait"
done
ok "Pushed ${BRANCH}."

# --- trigger Forge deploy ----------------------------------------------------
if [[ "$DO_DEPLOY" -eq 0 ]]; then
    say "Skipping Forge deploy (--no-deploy)."
    exit 0
fi

HOOK="${FORGE_DEPLOY_HOOK:-}"
if [[ -z "$HOOK" && -f "$REPO_ROOT/.forge-deploy" ]]; then
    HOOK="$(tr -d '[:space:]' < "$REPO_ROOT/.forge-deploy")"
fi

if [[ -z "$HOOK" ]]; then
    warn "No Forge deploy hook configured — pushed, but did not trigger a deploy."
    warn "Add the hook URL to a gitignored '.forge-deploy' file, or set FORGE_DEPLOY_HOOK."
    warn "Find it in Forge: Site → Apps → 'Deploy Hook'."
    exit 0
fi

say "Triggering Forge deploy…"
http_code="$(curl -sS -o /tmp/forge_deploy_resp.txt -w '%{http_code}' "$HOOK" || true)"
if [[ "$http_code" =~ ^2 ]]; then
    ok "Forge accepted the deploy request (HTTP $http_code)."
    [[ -s /tmp/forge_deploy_resp.txt ]] && cat /tmp/forge_deploy_resp.txt
else
    die "Forge deploy hook returned HTTP $http_code. Response: $(cat /tmp/forge_deploy_resp.txt 2>/dev/null)"
fi

# --- optional: poll deployment status via the Forge API ----------------------
# Requires FORGE_API_TOKEN, FORGE_SERVER_ID and FORGE_SITE_ID (see README).
if [[ "$SHOW_STATUS" -eq 1 ]]; then
    if [[ -z "${FORGE_API_TOKEN:-}" || -z "${FORGE_SERVER_ID:-}" || -z "${FORGE_SITE_ID:-}" ]]; then
        warn "--status needs FORGE_API_TOKEN, FORGE_SERVER_ID and FORGE_SITE_ID. Skipping."
        exit 0
    fi
    say "Polling Forge for deployment status…"
    api="https://forge.laravel.com/api/v1/servers/${FORGE_SERVER_ID}/sites/${FORGE_SITE_ID}/deployment-history"
    for _ in $(seq 1 20); do
        sleep 6
        latest="$(curl -sS -H "Authorization: Bearer ${FORGE_API_TOKEN}" -H "Accept: application/json" "$api" \
            | sed -n 's/.*"status":"\([^"]*\)".*/\1/p' | head -1)"
        [[ -z "$latest" ]] && continue
        echo "   status: $latest"
        case "$latest" in
            finished) ok "Deployment finished."; exit 0 ;;
            failed)   die "Deployment failed — check Forge logs." ;;
        esac
    done
    warn "Timed out waiting for Forge to finish (still deploying?)."
fi
