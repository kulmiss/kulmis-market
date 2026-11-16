# Admin app (React)

- Based on **satnaing/shadcn-admin** (MIT, see `admin/LICENSE-shadcn-admin`)
  with shadcn/ui components, TanStack Router + Query, and Tailwind 4.
- The source is in `admin/`. It's **built into `public/admin-app/`** (committed,
  so shared hosting needs no Node) and opened at **`/admin`**. Laravel serves
  the app for any `/admin/*` path.
- It talks to `/api/v1/admin/*` on the same domain with the session cookie +
  `XSRF-TOKEN` (axios sends `X-XSRF-TOKEN`). No tokens in localStorage.
- **Sign-in:** email/phone + password → authenticator code (or a recovery
  code). An admin without 2FA is sent to **Set up 2FA** (QR, confirm, 8
  recovery codes) before any other screen.
- The navigation hides screens your role can't use. Screens of a
  switched-off feature move to the **Switched-off features** group at the end:
  its history stays and you can finish open work (ship open orders, pay asked
  cash-outs, answer messages). Each such screen says "… is switched off.
  Customers and members see nothing of it." Still blocked while off: changing
  a membership by hand, PDF receipts.
- **Feature switches page:** switching off first shows what it means ("37
  people have signals until their month ends (the last on 12 Dec 2026).
  Nobody can buy or renew.", open orders, waiting cash-outs…). For membership
  and signals, while off and people still have paid days: **End access now**,
  with "Add the days they did not use to their wallet". The page also lists
  **Texts that mention a switched-off feature** (FAQ, pages, banners, share
  kit, messages) and what customers see of each.
- Text fields (FAQ, pages, banners, share kit, message settings) warn while
  you type: "This text mentions Signals, which is off …" and suggest
  `{if signals}…{/if}`.
- No external fonts, analytics or trackers (system font stack, `noindex`).

## Develop
```bash
cd admin
npm ci --ignore-scripts
npm run dev        # http://localhost:5173/admin-app/ (proxies /api to :8090)
npm run build      # typecheck + build into ../public/admin-app
```
Commit the rebuilt `public/admin-app` together with the source change.

## Shared building blocks
- `components/km/page.tsx`: page header + title + actions
- `components/km/resource-table.tsx`: server-paginated table for any
  Laravel paginator, with row selection + bulk actions
- `components/km/filter-bar.tsx`, `money-input.tsx` (type birr, store santim),
  `status-badge.tsx`
- `hooks/use-mutate.ts`: POST/PUT, toast, refresh queries

## Data tables (every list)

Every table in the admin and the member area works the same way:

- **Search** box (waits until you stop typing), **filters**, **sort** by
  clicking a column name (again = high to low, a third time = off).
- **Columns**: show or hide columns. **Reset this table** puts it back.
- **Page size** 10 / 25 / 50 / 100 / 200 (member area: up to 100) and pages.
- **Select rows** for bulk actions; **CSV** saves every matching row (up to
  10,000), **CSV of selected** only the ticked ones. Cells that start with
  `= + - @` get a `'` so spreadsheets never run them as formulas.
- The header **stays on top** while you scroll.
- Your column choice, page size, sort and filters are **kept per table** in
  this browser.
- **Phones** get one card per row; filters and sort fold into a
  "Filters (2)" button that remembers if it was open.

Code: admin `admin/src/components/km/data-table.tsx` (server lists with
`path`, ready lists with `rows`); server sorting `App\Support\TableQuery`
(`?sort=amount` / `?sort=-amount`, only listed columns). Member area:
`App\Member\MemberTable` + `MemberTables` and
`resources/views/member/partials/table.blade.php`.

## Search everything (Ctrl/⌘ K)

The search box in the top bar (or Ctrl+K / ⌘K) looks in members (name,
phone, email, referral code), orders (number, phone), products (name, SKU),
payments and receipts (references), cash-outs, signals, coupons, badges,
settings and feature switches, and the admin pages. Results are grouped.

- Only what your role may open is searched; switched-off features are left out.
- `#123` finds by ID; `"Abebe Kebede"` in quotes finds the exact words;
  phone numbers work as 0911…, 911… or +251911….
- **Advanced**: look in one type, status, from/to date, amount from/to.
- Recent searches and quick actions (add a product, check receipts, pay
  cash-outs, post a signal, badge bonus, feature switches).
- Fast: one small query per group, by first letters on indexed columns
  (`2026_11_19_000200_add_search_indexes`), typing is debounced; the time is
  shown under the results (typically 10–20 ms).

API: `GET /api/v1/admin/search?q=…&type=&status=&from=&to=&min=&max=` (`App\Search\GlobalSearch`).
