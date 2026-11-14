# Catalog

- **Products:** guest price in santim, a cost split (materials + labour +
  packaging, admin-only, never in the public API), weight, stock **or**
  made-to-order (+ making days), featured, members-only, `release_at`, and a
  status of draft, active or archived. Products are archived, never deleted.
- **Variants** (size/colour): optional price, weight and stock per variant.
  A variant removed in admin is deactivated, so order history keeps working.
- **Early access:** members see a product `catalog.early_access_hours`
  (default 48) before `release_at`. Guests see it from `release_at`.
- **Members-only product:** everyone can see it, only active members can
  buy it (`purchasable` in the API).
- **Price warning:** in admin when the lowest price is under
  `catalog.min_price_cost_ratio` (default 2.5×) × cost. Profitability with
  discounts and commissions is checked in PR 5.
- **Images:** uploads are re-encoded into AVIF (when the server's GD
  supports it, as the Docker image does), WebP and JPEG at 400/800/1600 px.
  Small images are never upscaled, and EXIF/GPS data is stripped.
- **API:** `GET /api/v1/products`, `GET /api/v1/products/{slug}`,
  `GET /api/v1/categories`. Admin: `/api/v1/admin/products` (CRUD, bulk,
  `low_stock` filter, images) and `/api/v1/admin/categories`.
- `CatalogChanged` fires on any catalog change. The page cache (PR 19) uses it
  to purge.
