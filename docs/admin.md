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
- The navigation hides screens of switched-off features and screens your
  role can't use.
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
