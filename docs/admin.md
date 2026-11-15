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
