# Help, guides and first-time guidance

| Part | Where | Code |
|---|---|---|
| Member Help articles | `/help`, `/help/{slug}` | defaults `resources/help/*.md` (front matter: title, topic, order, feature, keywords); changes and new ones in `help_articles`; `App\Help\HelpArticles` |
| Live numbers | `{membership_price}`, `{cashout_min}`… in articles, FAQ answers and the guide | `App\Help\HelpTokens` |
| "Was this helpful?" | under each article | `POST /api/v1/help/{slug}/feedback`, counts in `help_feedback`; switch `site.help_feedback` |
| Admin editing | Admin → Help centre → Articles (preview, reset to the original, add) | `Admin\HelpArticleController` |
| "?" on member pages | Home, Team, Earnings, Wallet, Share kit, Signals | `member/partials/help-link.blade.php` |
| Welcome tour | first visit to `/account`, again with `/account?tour=1` | `member/partials/welcome-tour.blade.php`, `users.welcome_tour_seen_at`, switch `account.welcome_tour` |
| Setup wizard | `/admin/setup` on the Super Admin's first sign-in | `App\Setup\SetupWizard`, `Admin\SetupController`, `admin/src/features/setup`; settings `setup.*` (hidden from Settings); switch `admin.setup_wizard` |
| Super Admin guide | Admin → Guide, "?" in the top bar | `docs/admin-guide/*.md` (front matter: title, part, order, pages), `App\Help\AdminGuide`, `Admin\GuideController` |
| PDF | Admin → Guide → Download as PDF; `docs/admin-guide.pdf` | `php artisan km:guide-pdf` |
| Pictures | `public/help-screens` (members), `docs/screenshots` (guide) | `node scripts/screenshots.mjs` |

Articles and guide pages about a switched-off feature are hidden (`feature:` line, `{if …}` parts, and the feature words), like the rest of the site.
