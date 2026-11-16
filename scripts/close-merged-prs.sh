#!/usr/bin/env bash
# Close every open PR that brings nothing new to main. A PR counts as done when:
#   1. all its commits are already in main (normal merge, like a stack's top PR), or
#   2. merging it would not change main at all (squash or rebase merge), or
#   3. all its commits are inside a PR that was already merged (lower PRs of a
#      stack whose top PR was squash-merged).
# Usage: scripts/close-merged-prs.sh [main-ref] [--dry-run]
set -euo pipefail

MAIN="${1:-origin/main}"
DRY="${2:-}"
main_tree=$(git rev-parse "$MAIN^{tree}")

# Heads of the last merged PRs (for rule 3).
merged_heads=()
while read -r number sha; do
    if git fetch -q origin "pull/$number/head" 2>/dev/null; then
        merged_heads+=("$sha")
    fi
done < <(gh api "repos/{owner}/{repo}/pulls?state=closed&base=main&sort=updated&direction=desc&per_page=30" \
    --jq '.[] | select(.merged_at != null) | "\(.number) \(.head.sha)"')

already_in_main() {
    local sha=$1
    git merge-base --is-ancestor "$sha" "$MAIN" && return 0
    local merged
    if merged=$(git merge-tree --write-tree "$MAIN" "$sha" 2>/dev/null | head -1) && [ "$merged" = "$main_tree" ]; then
        return 0
    fi
    local head
    for head in "${merged_heads[@]}"; do
        git merge-base --is-ancestor "$sha" "$head" && return 0
    done
    return 1
}

gh api --paginate "repos/{owner}/{repo}/pulls?state=open&per_page=100" --jq '.[] | "\(.number) \(.head.sha)"' |
while read -r number sha; do
    git fetch -q origin "pull/$number/head" 2>/dev/null || { echo "#$number: could not fetch, skipped"; continue; }
    if already_in_main "$sha"; then
        if [ "$DRY" = "--dry-run" ]; then
            echo "#$number: already in main (would close)"
        else
            gh api -X POST "repos/{owner}/{repo}/issues/$number/comments" -f body="Already in \`main\`, so closed automatically. Nothing is lost." >/dev/null
            gh api -X PATCH "repos/{owner}/{repo}/pulls/$number" -f state=closed >/dev/null
            echo "#$number: closed (already in main)"
        fi
    else
        echo "#$number: has new changes, left open"
    fi
done
