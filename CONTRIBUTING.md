# Working on Kulmis Market

## Keep the guides right (same PR, every time)
When a feature changes, update its help **in the same pull request**:

| You changed… | Also update |
|---|---|
| an admin screen | its page in `docs/admin-guide/` (and its `pages:` line if the screen moved) |
| something members see | the matching article in `resources/help/` |
| a number (price, rate, days, minimum) | nothing by hand: write it as a `{token}` (list: `App\Help\HelpTokens`); add a token if it is new |
| a new admin screen | add it to a guide page's `pages:` line (a test fails otherwise) |
| a screen in a picture | re-take the pictures: `node scripts/screenshots.mjs` (demo data, your own computer) |
| the guide | `php artisan km:guide-pdf` to refresh `docs/admin-guide.pdf` |

Tests that keep this honest: `HelpGuideTest` (no number typed by hand, no unknown `{token}`, no link to a missing page or picture, every "?" points to a real article) and `AdminGuideTest` (every admin screen has a guide page, links and pictures exist).

## Other rules
- Every new feature gets a feature switch with an easy-English description (`config/features.php`); switched off, customers see nothing of it.
- Easy English everywhere, short sentences. Works on a phone first.
- Run the local checks before pushing: Pint, Larastan, tests on SQLite and MariaDB.
- Secrets only in `.env`, never in the code or in chat.
