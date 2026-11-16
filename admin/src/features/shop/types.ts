export type Variant = {
  id?: number; name: string; sku?: string | null; options?: Record<string, string> | null
  price?: number | null; stock?: number | null; weight_grams?: number | null; is_active?: boolean
}
export type ProductImage = { id: number; alt: string | null; thumb: string }
export type Warning = { code: string; message: string }
export type Product = {
  id: number; name: string; slug: string; short_description: string | null; description: string | null
  category_id: number | null; category?: { id: number; name: string } | null
  price: number; cost_materials: number; cost_labour: number; cost_packaging: number; total_cost: number
  weight_grams: number; stock_mode: 'stock' | 'made_to_order'; stock: number | null; making_days: number | null
  is_featured: boolean; is_members_only: boolean; release_at: string | null; status: 'draft' | 'active' | 'archived'
  kind?: 'physical' | 'digital' | 'addon'; member_discount?: boolean; booking_at?: string | null
  preorder_deposit_bps?: number | null; preorder_ships_on?: string | null; low_stock_alert?: number | null
  seo_title: string | null; seo_description: string | null
  tags?: { name: string }[]; variants: Variant[]; images: ProductImage[]
  available_stock: number | null; warnings: Warning[]
  profit?: { price: number; member_price: number; commission: number; payment_fee: number; cost: number; courier?: number; profit: number }
}
export type Category = { id: number; name: string; slug: string; parent_id: number | null; description: string | null; position: number; is_active: boolean; products_count?: number }
export type OrderItem = { id: number; name: string; quantity: number; list_unit_price: number; unit_price: number; unit_cost: number; line_total: number; made_to_order: boolean; making_days: number | null }
export type Order = {
  id: number; number: string; status: string; first_name: string; last_name: string; phone: string; email: string | null
  city: string; address: string; notes: string | null; is_member_order: boolean
  list_subtotal: number; member_discount: number; coupon_code: string | null; coupon_discount: number; merchandise_total: number
  shipping: number; shipping_discount: number; total: number; wallet_used: number; payment_fee: number; refunded_amount: number
  courier_id: number | null; tracking_number: string | null; referral_code: string | null
  created_at: string; paid_at: string | null; shipped_at: string | null; delivered_at: string | null
  deposit_amount?: number; deposit_paid?: number; gift_card_used?: number; extras_total?: number; extras?: { key: string; label: string; amount: number }[] | null
  items?: OrderItem[]; history?: { id: number; from: string | null; to: string; note: string | null; created_at: string; notified: Record<string, string> | null }[]
  items_count?: number; allowed_next?: string[]
}
export type Courier = { id: number; name: string; phone: string | null; tracking_url: string | null; is_active: boolean }
export type Zone = { id: number; name: string; cities: string[]; base_fee: number; included_grams: number; per_kg_fee: number; courier_cost: number | null; courier_per_kg: number | null; courier_warning?: string | null; eta_days_min: number | null; eta_days_max: number | null; is_default: boolean; is_active: boolean; position: number }
export type Payment = {
  id: number; uuid: string; provider: string; tx_ref: string; amount: number; fee: number; refunded_amount: number; status: string
  provider_reference: string | null; proof_path: string | null; proof_reference: string | null; payer_account: string | null
  reject_reason: string | null; paid_at: string | null; created_at: string; payable_type: string; payable_id: number
  /** "Membership · Sara Tesfaye", "Order KM… · Guest" */
  for?: string
  refund_preview?: { taken_back: number; people: number; already_used: { name: string; amount: number }[] } | null
  payable?: { number?: string; first_name?: string } | null; refunds?: { id: number; amount: number; reason: string; method: string; created_at: string }[]
}
